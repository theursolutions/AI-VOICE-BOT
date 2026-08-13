<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\InstagramLoginService;
use Msd\MetaChannels\Services\OAuthService;

/**
 * Diagnoses and repairs the "connected but no messages arrive" failure.
 *
 * A Facebook Page or Instagram account can be perfectly connected — valid
 * token, enabled, visible in the UI — and still deliver nothing, because
 * being connected and being SUBSCRIBED are different things. Until the app
 * is subscribed to the Page's webhooks, Meta has no reason to send us
 * anything, and there is no error to find because nothing failed.
 *
 * Onboarding now subscribes automatically. This command exists for
 * connections made before that, and as the first thing to run whenever a
 * channel goes quiet.
 *
 *   php artisan meta:subscribe            # report what is subscribed
 *   php artisan meta:subscribe --fix      # subscribe anything that is not
 */
class MetaSubscribe extends Command
{
    protected $signature = 'meta:subscribe
                            {--fix : Subscribe any channel that is not already subscribed}
                            {--project= : Only channels on this project id}';

    protected $description = 'Check (and optionally repair) Meta webhook subscriptions for connected channels';

    /** What we need Meta to send us, given what the webhook controller handles. */
    private const WANTED = ['messages', 'messaging_postbacks'];

    public function __construct(
        private OAuthService $oauth,
        private InstagramLoginService $instagram,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->oauth->isConfigured() && ! $this->instagram->isConfigured()) {
            $this->error('Meta app not configured — set META_APP_ID and META_APP_SECRET.');
            return self::FAILURE;
        }

        $connections = ChannelConnection::query()
            ->when($this->option('project'), fn ($q) => $q->where('project_id', (int) $this->option('project')))
            ->orderBy('provider')
            ->get();

        if ($connections->isEmpty()) {
            $this->line('No channel connections found.');
            return self::SUCCESS;
        }

        $problems = 0;

        foreach ($connections as $c) {
            $label = $c->provider . ' · ' . ($c->name ?: $c->external_id);

            // WhatsApp subscribes at the WhatsApp Business Account level, not
            // per number, so there is no per-row subscription to check. Use
            // the pass to backfill the dialable number instead — connections
            // made before it was recorded show only a phone_number_id, which
            // nobody can call.
            if ($c->provider === ChannelConnection::PROVIDER_WHATSAPP) {
                $this->backfillWhatsappNumber($c, $label);
                continue;
            }

            // Instagram Login accounts subscribe themselves on
            // graph.instagram.com — they have no linked Page to subscribe,
            // and checking for one would report a healthy account as broken.
            if (($c->metadata['login'] ?? null) === 'instagram') {
                $problems += $this->checkInstagramLogin($c, $label);
                continue;
            }

            $pageId = $c->provider === ChannelConnection::PROVIDER_INSTAGRAM
                ? (string) ($c->metadata['page_id'] ?? '')
                : (string) $c->external_id;

            if ($pageId === '') {
                $this->warn("  ⚠ {$label} — no page id recorded; reconnect this channel");
                $problems++;
                continue;
            }
            if (! $c->access_token) {
                $this->warn("  ⚠ {$label} — no access token stored; reconnect this channel");
                $problems++;
                continue;
            }

            try {
                $fields = $this->oauth->pageSubscribedFields($pageId, $c->access_token);
            } catch (\Throwable $e) {
                $this->error("  ✗ {$label} — could not read subscriptions: " . $e->getMessage());
                $problems++;
                continue;
            }

            $missing = array_values(array_diff(self::WANTED, $fields));

            if (! $missing) {
                $this->info("  ok     {$label} — subscribed to " . implode(', ', $fields));
                continue;
            }

            $problems++;

            if (! $this->option('fix')) {
                $this->warn("  ⚠ {$label} — NOT subscribed to: " . implode(', ', $missing)
                    . '  (run with --fix)');
                continue;
            }

            try {
                // Send the full wanted set, not just the missing ones: Meta
                // replaces the subscription rather than merging into it.
                $this->oauth->subscribeAppToPage($pageId, $c->access_token, self::WANTED);
                $this->info("  fixed  {$label} — subscribed to " . implode(', ', self::WANTED));
                $problems--;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$label} — subscribe failed: " . $e->getMessage());
            }
        }

        $this->newLine();

        if ($problems > 0) {
            $this->warn("{$problems} channel(s) need attention.");
            if (! $this->option('fix')) {
                $this->line('Re-run with --fix to subscribe them.');
            } else {
                $this->line('A persistent failure usually means the page token lacks');
                $this->line('pages_manage_metadata — reconnect the channel to re-request it.');
            }

            return self::FAILURE;
        }

        $this->info('All channels are subscribed.');
        $this->newLine();
        $this->line('If messages still do not arrive, check in this order:');
        $this->line('  1. App dashboard → Webhooks → the `page` object is subscribed to `messages`');
        $this->line('  2. The queue worker is running   (dc logs queue --tail=50)');
        $this->line('  3. Webhook deliveries in the app dashboard show 200 responses');

        return self::SUCCESS;
    }

    /**
     * Check (and with --fix, repair) an Instagram-Login account's own
     * subscription. Also reports the token deadline, because these tokens
     * always expire — unlike Page tokens, which never do.
     *
     * @return int 1 if the channel needs attention, 0 if healthy
     */
    private function checkInstagramLogin(ChannelConnection $c, string $label): int
    {
        if (! $c->access_token) {
            $this->warn("  ⚠ {$label} — no access token stored; reconnect this channel");
            return 1;
        }

        try {
            $fields = $this->instagram->subscribedFields($c->external_id, $c->access_token);
        } catch (\Throwable $e) {
            $this->error("  ✗ {$label} — could not read subscriptions: " . $e->getMessage());
            return 1;
        }

        $missing = array_values(array_diff(['messages'], $fields));

        if (! $missing) {
            $days = $c->tokenExpiresInDays();
            $note = $days === null ? '' : " · token expires in {$days}d";
            $this->info("  ok     {$label} — subscribed to " . implode(', ', $fields) . $note);
            return 0;
        }

        if (! $this->option('fix')) {
            $this->warn("  ⚠ {$label} — NOT subscribed to: " . implode(', ', $missing) . '  (run with --fix)');
            return 1;
        }

        try {
            $this->instagram->subscribe($c->external_id, $c->access_token);
            $this->info("  fixed  {$label} — subscribed");
            return 0;
        } catch (\Throwable $e) {
            $this->error("  ✗ {$label} — subscribe failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Fetch and store a WhatsApp number's dialable form, quality rating and
     * verification status from Graph.
     *
     * Read-only unless --fix is given, so a plain run stays a diagnostic.
     */
    private function backfillWhatsappNumber(ChannelConnection $c, string $label): void
    {
        $existing = $c->metadata['display_phone_number'] ?? null;

        if ($existing) {
            $this->info("  ok     {$label} — {$existing}");
            return;
        }
        if (! $c->access_token || ! $c->external_id) {
            $this->warn("  ⚠ {$label} — no token or phone_number_id; reconnect this channel");
            return;
        }
        if (! $this->option('fix')) {
            $this->warn("  ⚠ {$label} — number not recorded (run with --fix to fetch it)");
            return;
        }

        try {
            $data = $this->oauth->whatsappNumberDetails($c->external_id, $c->access_token);
        } catch (\Throwable $e) {
            $this->error("  ✗ {$label} — could not read the number: " . $e->getMessage());
            return;
        }

        $number = $data['display_phone_number'] ?? null;
        if (! $number) {
            $this->warn("  ⚠ {$label} — Graph returned no display_phone_number");
            return;
        }

        // Merge rather than replace: waba_id and business_id must survive.
        $c->metadata = array_merge((array) $c->metadata, [
            'display_phone_number' => $number,
            'quality_rating'       => $data['quality_rating'] ?? null,
            'verification_status'  => $data['code_verification_status'] ?? null,
        ]);
        if (! $c->name || $c->name === 'WhatsApp') {
            $c->name = $data['verified_name'] ?? $number;
        }
        $c->save();

        $this->info("  fixed  {$label} — recorded {$number}");
    }
}
