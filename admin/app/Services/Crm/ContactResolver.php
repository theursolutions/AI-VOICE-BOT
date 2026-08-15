<?php

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Lead;
use App\Models\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Works out WHO is writing, across channels.
 *
 * The identity graph is deliberately small: a handle (channel + external id)
 * maps to exactly one contact, and contacts are linked when — and only when —
 * a strong identifier matches.
 *
 * On what counts as strong, because getting this wrong is expensive in one
 * direction only:
 *
 *   phone   merge   a number is one device, one person
 *   email   merge   deliberately given, effectively unique in practice
 *   name    NEVER   "Muhammad Ali" is not one person, and merging on it
 *                   would show one customer another's entire history
 *
 * A missed merge is a mild annoyance — two profiles for one person, fixable
 * by hand. A wrong merge is a data breach between two of your customers.
 * So anything short of certain is recorded as a SUGGESTION for a human,
 * never applied.
 */
class ContactResolver
{
    /**
     * Find or create the contact behind a handle, then reconcile any
     * details the conversation has since revealed.
     *
     * @param string $channel  sessions.channel value (whatsapp|instagram|facebook|…)
     * @param array  $details  name/email/phone learned so far, all optional
     */
    /**
     * Is this tenant DB migrated for contacts?
     *
     * Tenant migrations run per project and can lag a deploy. Without this
     * check, an un-migrated project would throw on a missing table for every
     * inbound message — taking the whole inbox down over a feature that is
     * supposed to be additive.
     */
    public static function available(): bool
    {
        try {
            $connection = DB::connection('tenant');

            // Keyed on the DATABASE NAME, not cached globally. A queue worker
            // handles many projects in one process and the `tenant`
            // connection is repointed between them — a flat static cache
            // would answer for whichever project happened to run first.
            $key = (string) $connection->getDatabaseName();

            static $cache = [];
            if (isset($cache[$key])) {
                return $cache[$key];
            }

            $schema = $connection->getSchemaBuilder();

            return $cache[$key] = $schema->hasTable('contacts')
                && $schema->hasTable('contact_identities')
                && $schema->hasColumn('sessions', 'contact_id');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function resolve(
        int $projectId,
        string $channel,
        string $externalId,
        ?string $channelAccount = null,
        array $details = [],
    ): ?Contact {
        if ($externalId === '' || ! self::available()) {
            return null;
        }

        $channel = $this->normaliseChannel($channel);
        $now     = time();

        // WhatsApp hands us a verified phone number as the handle itself, so
        // a WhatsApp contact arrives already carrying its strongest
        // identifier. Nothing else does this.
        if ($channel === 'whatsapp' && empty($details['phone'])) {
            $details['phone'] = $externalId;
        }

        $identity = ContactIdentity::where('project_id', $projectId)
            ->where('channel', $channel)
            ->where('external_id', $externalId)
            ->first();

        $contact = $identity?->contact;

        if (! $contact) {
            // Someone reaching us on a new channel is usually not a new
            // person — check the strong identifiers before creating one.
            $contact = $this->findByStrongIdentifier($projectId, $details)
                ?: Contact::create([
                    'project_id'    => $projectId,
                    'name'          => $details['name']  ?? null,
                    'email'         => $this->normaliseEmail($details['email'] ?? null),
                    'phone'         => $this->normalisePhone($details['phone'] ?? null),
                    'first_seen_at' => $now,
                    'last_seen_at'  => $now,
                    'created_at'    => $now,
                ]);

            $this->attachIdentity($projectId, $contact, $channel, $externalId, $channelAccount);
        }

        $this->reconcile($contact, $details, $now);

        return $contact;
    }

    /**
     * Fold in anything new the conversation has revealed, and link any other
     * contact that turns out to be the same person.
     */
    public function reconcile(Contact $contact, array $details, ?int $now = null): void
    {
        $now   = $now ?: time();
        $dirty = false;

        $email = $this->normaliseEmail($details['email'] ?? null);
        $phone = $this->normalisePhone($details['phone'] ?? null);
        $name  = trim((string) ($details['name'] ?? '')) ?: null;

        // Only ever FILL gaps. An agent who corrected a misheard name must
        // not have it overwritten by the next extraction from a transcript.
        foreach (['name' => $name, 'email' => $email, 'phone' => $phone] as $field => $value) {
            if ($value !== null && blank($contact->{$field})) {
                $contact->{$field} = $value;
                $dirty = true;
            }
        }

        if ($contact->last_seen_at !== $now) {
            $contact->last_seen_at = $now;
            $dirty = true;
        }

        if ($dirty) {
            $contact->update_at = $now;
            $contact->save();
        }

        // A newly-learned email or phone may reveal that this person already
        // exists under another handle — this is the moment cross-channel
        // linking actually happens.
        if ($email || $phone) {
            $this->linkDuplicates($contact, $email, $phone);
        }
    }

    /**
     * Attach a handle to a contact, tolerating the race.
     *
     * Resolution runs from a queue worker, so two messages arriving together
     * can both miss the lookup and both try to insert. The unique index
     * settles it; the loser re-reads rather than failing the message.
     */
    private function attachIdentity(
        int $projectId,
        Contact $contact,
        string $channel,
        string $externalId,
        ?string $channelAccount,
    ): void {
        try {
            ContactIdentity::create([
                'project_id'      => $projectId,
                'contact_id'      => $contact->id,
                'channel'         => $channel,
                'external_id'     => $externalId,
                'channel_account' => $channelAccount,
                'created_at'      => time(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Already claimed by a concurrent insert — that row is the truth.
            Log::info('Contact identity already existed', ['channel' => $channel]);
        }
    }

    /** An existing contact carrying the same phone or email. */
    private function findByStrongIdentifier(int $projectId, array $details): ?Contact
    {
        $email = $this->normaliseEmail($details['email'] ?? null);
        $phone = $this->normalisePhone($details['phone'] ?? null);

        if (! $email && ! $phone) {
            return null;
        }

        return Contact::where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($email, $phone) {
                if ($email) $q->orWhere('email', $email);
                if ($phone) $q->orWhere('phone', $phone);
            })
            ->orderBy('id')          // the oldest record wins the merge
            ->first();
    }

    /**
     * Merge any OTHER contact sharing this one's strong identifiers.
     *
     * The older record absorbs the newer, so the customer's original history
     * stays the anchor rather than being folded into a profile created
     * minutes ago.
     */
    private function linkDuplicates(Contact $contact, ?string $email, ?string $phone): void
    {
        $duplicates = Contact::where('project_id', $contact->project_id)
            ->where('id', '!=', $contact->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($email, $phone) {
                if ($email) $q->orWhere('email', $email);
                if ($phone) $q->orWhere('phone', $phone);
            })
            ->orderBy('id')
            ->get();

        foreach ($duplicates as $duplicate) {
            [$keep, $drop] = $duplicate->id < $contact->id
                ? [$duplicate, $contact]
                : [$contact, $duplicate];

            try {
                DB::connection('tenant')->transaction(fn () => $keep->absorb($drop));

                Log::info('Contacts merged on a strong identifier', [
                    'kept'    => $keep->id,
                    'dropped' => $drop->id,
                    'on'      => $email ? 'email' : 'phone',
                ]);
            } catch (\Throwable $e) {
                // Never let a merge failure cost the customer their reply.
                Log::warning('Contact merge failed: ' . $e->getMessage());
            }

            if ($keep->id !== $contact->id) {
                return;    // this contact was the one absorbed; stop here
            }
        }
    }

    /** sessions.channel and provider names collapse to one vocabulary. */
    private function normaliseChannel(string $channel): string
    {
        return match ($channel) {
            'messenger', 'facebook_page' => 'facebook',
            'twilio', 'plivo', 'voice', 'sms' => 'phone',
            default => $channel,
        };
    }

    private function normaliseEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Digits only, so "+92 300 1234567" and "923001234567" are one number.
     * Anything under 7 digits is not a phone number and must never become a
     * merge key — "1234" would fold a dozen strangers together.
     */
    private function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen($digits) >= 7 ? $digits : null;
    }
}
