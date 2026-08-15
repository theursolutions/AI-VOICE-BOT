<?php

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Session;

/**
 * Everything the profile panel shows about one person.
 *
 * The engagement score is the part worth explaining. It is NOT a model and
 * makes no prediction — it is a transparent tally of things that either did
 * or did not happen, and it always ships its own reasons. A number an agent
 * cannot interrogate is a number they will either over-trust or ignore, and
 * both are worse than no number.
 */
class ContactProfile
{
    /**
     * Signals, and what each is worth.
     *
     * Weighted by how much effort the customer spent, because effort is the
     * only honest proxy for intent we can observe. Giving a phone number
     * costs more than sending "hi".
     */
    private const SIGNALS = [
        'contact_details' => 30,   // shared an email or phone
        'sustained'       => 25,   // a real back-and-forth, not one message
        'returning'       => 15,   // came back, or uses more than one channel
        'qualified'       => 30,   // a human marked the lead qualified/converted
    ];

    public function build(Contact $contact): array
    {
        $sessions = Session::where('contact_id', $contact->id)
            ->orderByDesc('last_activity_at')
            ->get(['id', 'channel', 'channel_account', 'status', 'handoff_status',
                   'started_at', 'last_activity_at', 'external_id']);

        $sessionIds = $sessions->pluck('id');

        $inbound  = $sessionIds->isEmpty() ? 0
            : Message::whereIn('session_id', $sessionIds)->where('role', 'user')->count();
        $outbound = $sessionIds->isEmpty() ? 0
            : Message::whereIn('session_id', $sessionIds)->where('role', 'assistant')->count();

        $leads = Lead::where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->get(['id', 'session_id', 'status', 'confidence', 'fields', 'created_at']);

        // Per-channel message counts — "which channel do they actually use?"
        $byChannel = [];
        foreach ($sessions as $s) {
            $channel = $s->channel === 'messenger' ? 'facebook' : $s->channel;
            $byChannel[$channel] = ($byChannel[$channel] ?? 0)
                + Message::where('session_id', $s->id)->count();
        }
        arsort($byChannel);

        return [
            'id'         => $contact->id,
            'name'       => $contact->displayName(),
            'raw_name'   => $contact->name,
            'email'      => $contact->email,
            'phone'      => $contact->phone,
            'avatar'     => $contact->avatar,
            'notes'      => $contact->notes,
            'first_seen' => $contact->first_seen_at,
            'last_seen'  => $contact->last_seen_at,

            'identities' => $contact->identities->map(fn ($i) => [
                'channel' => $i->channel,
                'label'   => $i->label(),
                'id'      => $i->external_id,
            ])->values()->all(),

            // Profiles already folded in, so a merge can be explained months
            // later rather than looking like data appearing from nowhere.
            'merged'     => array_values((array) data_get($contact->metadata, 'merged', [])),
            'suggested'  => $this->suggestedMerges($contact),

            'messages'   => [
                'total'      => $inbound + $outbound,
                'inbound'    => $inbound,
                'outbound'   => $outbound,
                'by_channel' => $byChannel,
            ],
            'conversations' => $sessions->map(fn ($s) => [
                'id'       => $s->id,
                'channel'  => $s->channel,
                'status'   => $s->status,
                'handoff'  => $s->handoff_status,
                'last_at'  => $s->last_activity_at,
            ])->values()->all(),

            'leads' => [
                'count' => $leads->count(),
                'items' => $leads->map(fn (Lead $l) => [
                    'id'         => $l->id,
                    'status'     => $l->status,
                    'confidence' => $l->confidence !== null ? (int) round($l->confidence * 100) : null,
                    'created_at' => $l->created_at,
                ])->values()->all(),
            ],

            'engagement' => $this->engagement($contact, $inbound, $sessions->count(), $leads),
        ];
    }

    /**
     * A transparent score with its own reasoning attached.
     *
     * @param \Illuminate\Support\Collection<int,Lead> $leads
     */
    private function engagement(Contact $contact, int $inbound, int $sessionCount, $leads): array
    {
        $score   = 0;
        $reasons = [];

        if ($contact->email || $contact->phone) {
            $score += self::SIGNALS['contact_details'];
            $reasons[] = ['+' . self::SIGNALS['contact_details'], 'Shared contact details'];
        } else {
            $reasons[] = ['0', 'No email or phone shared'];
        }

        // Five inbound messages is roughly where "asking a question" becomes
        // "having a conversation".
        if ($inbound >= 5) {
            $score += self::SIGNALS['sustained'];
            $reasons[] = ['+' . self::SIGNALS['sustained'], "Sustained conversation ({$inbound} messages)"];
        } elseif ($inbound <= 1) {
            $reasons[] = ['0', 'Only one message — nothing to judge yet'];
        }

        $channels = $contact->identities->pluck('channel')->unique()->count();
        if ($sessionCount > 1 || $channels > 1) {
            $score += self::SIGNALS['returning'];
            $reasons[] = ['+' . self::SIGNALS['returning'], $channels > 1
                ? "Reaches you on {$channels} channels"
                : 'Came back for another conversation'];
        }

        $best = $leads->pluck('status')->intersect(['qualified', 'converted'])->first();
        if ($best) {
            $score += self::SIGNALS['qualified'];
            $reasons[] = ['+' . self::SIGNALS['qualified'], 'Lead marked ' . $best];
        }

        // A human calling it disqualified outranks every positive signal —
        // they looked at it, and they are right.
        if ($leads->pluck('status')->contains('disqualified')) {
            $score = min($score, 15);
            $reasons[] = ['—', 'An agent marked this lead disqualified'];
        }

        return [
            'score'   => min(100, $score),
            'label'   => match (true) {
                $score >= 70 => 'Hot',
                $score >= 40 => 'Warm',
                $score >= 15 => 'Cold',
                default      => 'Unqualified',
            },
            'reasons' => $reasons,
        ];
    }

    /**
     * Contacts that look like the same person but were NOT auto-merged.
     *
     * Only weak signals land here — a shared name, or the same handle on a
     * different business account. Merging on these automatically would show
     * one customer another's history, so a person decides.
     */
    private function suggestedMerges(Contact $contact): array
    {
        if (blank($contact->name)) {
            return [];
        }

        return Contact::where('project_id', $contact->project_id)
            ->where('id', '!=', $contact->id)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($contact->name))])
            ->limit(5)
            ->get(['id', 'name', 'email', 'phone', 'last_seen_at'])
            ->map(fn (Contact $c) => [
                'id'      => $c->id,
                'name'    => $c->name,
                'email'   => $c->email,
                'phone'   => $c->phone,
                'reason'  => 'Same name — check before merging',
            ])->values()->all();
    }
}
