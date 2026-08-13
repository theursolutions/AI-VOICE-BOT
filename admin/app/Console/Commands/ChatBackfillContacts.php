<?php

namespace App\Console\Commands;

use App\Meta\ContactAvatars;
use App\Models\Project;
use App\Models\Session;
use App\Services\Tenant\TenantManager;
use Illuminate\Console\Command;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\GraphClient;

/**
 * Fills in customer names and profile photos on conversations that already
 * exist.
 *
 * Two reasons a thread ends up faceless, and this fixes both:
 *
 *   - it started before the profile lookup was written, so the name was
 *     never fetched at all;
 *   - the photo was stored as Meta's signed CDN URL, which has since
 *     expired, so the image is broken and cannot be recovered from what we
 *     saved.
 *
 * Both otherwise heal only when that customer next sends a message, which
 * for a quiet conversation may be never.
 *
 *   php artisan chat:backfill-contacts --dry-run
 *   php artisan chat:backfill-contacts
 *   php artisan chat:backfill-contacts --project=3 --force
 */
class ChatBackfillContacts extends Command
{
    protected $signature = 'chat:backfill-contacts
                            {--project= : Only this project id}
                            {--limit=500 : Maximum conversations per project}
                            {--force : Re-fetch even where a name and photo are already stored}
                            {--dry-run : Report what would change, write nothing}';

    protected $description = 'Fetch missing customer names and download profile photos for existing Meta conversations';

    /** sessions.channel values that carry a Meta platform id. */
    private const CHANNELS = ['instagram', 'facebook', 'messenger'];

    public function __construct(
        private TenantManager $tenants,
        private ContactAvatars $avatars,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $projects = Project::query()
            ->when($this->option('project'), fn ($q) => $q->where('id', (int) $this->option('project')))
            ->whereIn('id', ChannelConnection::distinct()->pluck('project_id')->filter())
            ->get();

        if ($projects->isEmpty()) {
            $this->line('No projects with Meta channels.');
            return self::SUCCESS;
        }

        $named = 0;
        $imaged = 0;
        $skipped = 0;

        foreach ($projects as $project) {
            $this->tenants->useFor($project);

            // Connections are keyed by the business account the conversation
            // arrived on: each Page has its own token, and a PSID is only
            // resolvable with the token of the Page it messaged.
            $conns = ChannelConnection::where('project_id', $project->id)
                ->get()
                ->keyBy(fn ($c) => (string) $c->external_id);

            $sessions = Session::where('project_id', $project->id)
                ->whereIn('channel', self::CHANNELS)
                ->orderByDesc('last_activity_at')
                ->limit((int) $this->option('limit'))
                ->get();

            foreach ($sessions as $s) {
                $meta = (array) data_get($s->metadata, 'meta', []);

                $needsName  = $force || trim((string) $s->customer_name) === '';
                $needsPhoto = $force || empty($meta['avatar']);

                if (! $needsName && ! $needsPhoto) {
                    continue;
                }

                $conn = $conns[(string) $s->channel_account] ?? null;
                if (! $conn || ! $conn->access_token) {
                    $this->warn("  ⚠ session {$s->id} — no token for account {$s->channel_account}; reconnect that channel");
                    $skipped++;
                    continue;
                }

                $provider = $conn->provider;

                try {
                    $profile = GraphClient::forConnection($conn)
                        ->messengerProfile((string) $s->external_id, $provider);
                } catch (\Throwable $e) {
                    $this->error("  ✗ session {$s->id} — lookup failed: " . $e->getMessage());
                    $skipped++;
                    continue;
                }

                if (empty($profile['name']) && empty($profile['profile_pic'])) {
                    // Overwhelmingly a permissions problem rather than a bug:
                    // Meta returns an empty profile when the app lacks
                    // Advanced Access, and says nothing about why.
                    $skipped++;
                    continue;
                }

                $changes = [];

                if ($needsName && ! empty($profile['name'])) {
                    $changes[] = 'name "' . $profile['name'] . '"';
                    if (! $dry) {
                        $s->customer_name = $profile['name'];
                    }
                    $named++;
                }

                if ($needsPhoto && ! empty($profile['profile_pic'])) {
                    if ($dry) {
                        $changes[] = 'photo';
                        $imaged++;
                    } elseif ($stored = $this->avatars->store($profile['profile_pic'], $provider, (string) $s->external_id)) {
                        $bag = (array) $s->metadata;
                        $bag['meta'] = array_merge($meta, [
                            'avatar'     => $stored,
                            'avatar_src' => $profile['profile_pic'],
                            'avatar_at'  => time(),
                        ]);
                        $s->metadata = $bag;
                        $changes[] = 'photo';
                        $imaged++;
                    }
                }

                if (! $changes) {
                    continue;
                }

                if (! $dry) {
                    $s->save();
                }

                $this->line(($dry ? '  would update  ' : '  updated  ')
                    . "session {$s->id} — " . implode(', ', $changes));
            }
        }

        $this->tenants->reset();
        $this->newLine();
        $this->info(($dry ? 'Would set ' : 'Set ') . "{$named} name(s) and {$imaged} photo(s).");

        if ($skipped > 0) {
            $this->warn("{$skipped} conversation(s) could not be resolved.");
            $this->line('Meta returns an empty profile when the app lacks Advanced Access for');
            $this->line('pages_user_profile (Messenger) or instagram_business_manage_messages.');
            $this->line('That is expected before App Review — it is not a fault in this command.');
        }

        return self::SUCCESS;
    }
}
