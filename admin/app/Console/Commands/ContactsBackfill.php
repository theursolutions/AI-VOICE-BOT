<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Session;
use App\Services\Crm\ContactResolver;
use App\Services\Tenant\TenantManager;
use Illuminate\Console\Command;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Builds contacts from conversations that already exist.
 *
 * Without this, contacts only appear for people who write AFTER the deploy —
 * so the profile panel would be empty for every customer you already have,
 * and the cross-channel linking that justifies the feature would have almost
 * nothing to link.
 *
 * Processed OLDEST FIRST on purpose. Merging keeps the older record as the
 * anchor, so replaying history in order produces the same contact graph a
 * live system would have built.
 *
 *   php artisan contacts:backfill --dry-run
 *   php artisan contacts:backfill
 *   php artisan contacts:backfill --project=1
 */
class ContactsBackfill extends Command
{
    protected $signature = 'contacts:backfill
                            {--project= : Only this project id}
                            {--limit=5000 : Maximum sessions per project}
                            {--dry-run : Report what would change, write nothing}';

    protected $description = 'Create contacts from existing conversations and link them across channels';

    public function __construct(
        private TenantManager $tenants,
        private ContactResolver $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $projects = Project::query()
            ->when($this->option('project'), fn ($q) => $q->where('id', (int) $this->option('project')))
            ->where('is_active', 'Yes')
            ->get();

        $created = 0;
        $linked  = 0;
        $skipped = 0;

        foreach ($projects as $project) {
            $this->tenants->useFor($project);

            if (! ContactResolver::available()) {
                $this->warn("  ⚠ project {$project->id} ({$project->name}) — not migrated; run tenant:migrate");
                continue;
            }

            $sessions = Session::where('project_id', $project->id)
                ->whereNull('contact_id')
                ->orderBy('id')                      // oldest first — see above
                ->limit((int) $this->option('limit'))
                ->get(['id', 'channel', 'channel_account', 'external_id',
                       'customer_name', 'customer_phone', 'customer_email', 'started_at']);

            if ($sessions->isEmpty()) {
                continue;
            }

            $this->line("→ project {$project->id} ({$project->name}): {$sessions->count()} session(s)");

            foreach ($sessions as $session) {
                $handle = (string) ($session->external_id ?: '');

                if ($handle === '') {
                    // Web chat sessions with no external id: the session id
                    // is the only stable handle they have.
                    $handle = (string) $session->id;
                }

                $details = array_filter([
                    'name'  => $session->customer_name,
                    'phone' => $session->customer_phone,
                    'email' => $session->customer_email,
                ]);

                // Pull anything the lead extraction learned but the session
                // never recorded — usually the email, which is exactly what
                // links an Instagram handle to a WhatsApp number.
                $lead = Lead::where('session_id', $session->id)->orderByDesc('id')->first(['fields']);
                foreach (['name', 'email', 'phone'] as $key) {
                    $value = data_get($lead?->fields, $key);
                    if (is_scalar($value) && trim((string) $value) !== '' && empty($details[$key])) {
                        $details[$key] = (string) $value;
                    }
                }

                if ($dry) {
                    $this->line("   would resolve session {$session->id} ({$session->channel})"
                        . ($details ? ' — ' . implode(', ', array_keys($details)) : ' — no details'));
                    $created++;
                    continue;
                }

                try {
                    $contact = $this->resolver->resolve(
                        projectId:      $project->id,
                        channel:        (string) $session->channel,
                        externalId:     $handle,
                        channelAccount: $session->channel_account,
                        details:        $details,
                    );
                } catch (\Throwable $e) {
                    $this->error("   ✗ session {$session->id}: " . $e->getMessage());
                    $skipped++;
                    continue;
                }

                if (! $contact) {
                    $skipped++;
                    continue;
                }

                $session->contact_id = $contact->id;
                $session->save();

                Lead::where('session_id', $session->id)
                    ->whereNull('contact_id')
                    ->update(['contact_id' => $contact->id]);

                $contact->wasRecentlyCreated ? $created++ : $linked++;
            }
        }

        $this->tenants->reset();
        $this->newLine();

        $this->info($dry
            ? "Would create or resolve {$created} contact(s)."
            : "Created {$created} contact(s), linked {$linked} session(s) to existing ones.");

        if ($skipped > 0) {
            $this->warn("{$skipped} session(s) could not be resolved.");
        }

        return self::SUCCESS;
    }
}
