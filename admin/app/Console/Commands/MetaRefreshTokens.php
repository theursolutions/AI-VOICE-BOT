<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\InstagramLoginService;

/**
 * Keeps Instagram-Login tokens alive.
 *
 * Facebook Page tokens derived from a long-lived user token never expire, so
 * this problem simply did not exist before Instagram Login. Its tokens are
 * different: 60 days, always, with no permanent equivalent. Left alone every
 * Instagram account silently stops working two months after it is connected,
 * and the only symptom is replies quietly failing to send.
 *
 * Two properties of Meta's refresh endpoint shape the schedule:
 *
 *   - a token must be at least 24 hours old to be refreshed
 *   - an EXPIRED token cannot be refreshed at all; the customer has to
 *     reconnect from scratch
 *
 * So this runs daily and refreshes anything inside the window, rather than
 * waiting for the deadline. A failure here is worth a real log line: it is
 * the difference between a working channel and one that dies next month.
 *
 *   php artisan meta:refresh-tokens
 *   php artisan meta:refresh-tokens --days=30   # widen the window
 *   php artisan meta:refresh-tokens --dry-run
 */
class MetaRefreshTokens extends Command
{
    protected $signature = 'meta:refresh-tokens
                            {--days=20 : Refresh tokens expiring within this many days}
                            {--dry-run : Report what would be refreshed, change nothing}';

    protected $description = 'Refresh Instagram Login access tokens before their 60-day expiry';

    public function __construct(private InstagramLoginService $instagram)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->instagram->isConfigured()) {
            $this->line('Instagram Login not configured — nothing to refresh.');
            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $dry  = (bool) $this->option('dry-run');

        $due = ChannelConnection::query()
            ->where('provider', ChannelConnection::PROVIDER_INSTAGRAM)
            ->enabled()
            ->tokenExpiringWithin($days)
            ->get()
            // metadata is a JSON cast, so this filter cannot be pushed into
            // SQL portably (MySQL and SQLite disagree on JSON operators).
            ->filter(fn ($c) => ($c->metadata['login'] ?? null) === 'instagram');

        if ($due->isEmpty()) {
            $this->info("No Instagram tokens expire within {$days} days.");
            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($due as $c) {
            $label = ($c->name ?: $c->external_id) . ' (project ' . $c->project_id . ')';
            $left  = $c->tokenExpiresInDays();

            if (! $c->access_token) {
                $this->warn("  ⚠ {$label} — no token stored; reconnect this account");
                $failed++;
                continue;
            }

            if ($dry) {
                $this->line("  would refresh  {$label} — {$left}d left");
                continue;
            }

            try {
                $fresh = $this->instagram->refresh($c->access_token);
            } catch (\Throwable $e) {
                // Name the dead-end explicitly. Once a token has lapsed there
                // is no recovery path in code, and saying so beats a retry
                // loop that can never succeed.
                $expired = $left !== null && $left <= 0;
                $this->error("  ✗ {$label} — refresh failed: " . $e->getMessage()
                    . ($expired ? ' (already expired — the customer must reconnect)' : ''));

                Log::warning('Instagram token refresh failed', [
                    'connection' => $c->id,
                    'ig_id'      => $c->external_id,
                    'expired'    => $expired,
                    'error'      => $e->getMessage(),
                ]);

                $failed++;
                continue;
            }

            $c->access_token      = $fresh['token'];
            $c->token_obtained_at = now();
            $c->token_expires_at  = $fresh['expires_at'];
            $c->save();

            $this->info('  refreshed  ' . $label . ' — now expires '
                . ($fresh['expires_at']?->toDateString() ?? 'never'));
        }

        $this->newLine();

        if ($failed > 0) {
            $this->warn("{$failed} account(s) could not be refreshed.");
            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
