<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * "Is my Meta app actually wired up?" — answered in one command.
 *
 * Channel onboarding fails in a lot of places for a lot of reasons, and
 * most of them are configuration rather than code: a missing secret, a
 * redirect URI that differs by one character, an app whose Facebook Login
 * product was never added. Discovering those by clicking through the OAuth
 * flow and reading a Graph error is slow and miserable.
 *
 * This talks to Graph with an app access token (app_id|app_secret), which
 * needs no user and no App Review, and reports what it finds.
 *
 *   php artisan meta:doctor
 */
class MetaDoctor extends Command
{
    protected $signature = 'meta:doctor';

    protected $description = 'Check the Meta app credentials, redirect URI and channel-onboarding configuration';

    private int $problems = 0;

    public function handle(): int
    {
        $cfg = config('meta.app');

        $this->newLine();
        $this->line('<options=bold>Meta channel onboarding — configuration check</>');
        $this->newLine();

        // ── Credentials ──────────────────────────────────────────────
        $id     = (string) ($cfg['id'] ?? '');
        $secret = (string) ($cfg['secret'] ?? '');

        $this->check('META_APP_ID', $id !== '', $id !== '' ? $id : 'not set — onboarding cannot start');
        $this->check('META_APP_SECRET', $secret !== '', $secret !== '' ? str_repeat('•', 12) . substr($secret, -4) : 'not set');

        if ($id === '' || $secret === '') {
            $this->newLine();
            $this->error('Set META_APP_ID and META_APP_SECRET in admin/.env, then re-run. See docs/META_ONBOARDING.md §2.');
            return self::FAILURE;
        }

        // ── Do the credentials actually work? ────────────────────────
        // An app access token is just id|secret and requires no review, so
        // this is the cheapest possible proof the pair is valid.
        $app = $this->graph('app', ['fields' => 'id,name,category,link', 'access_token' => "{$id}|{$secret}"]);

        if (isset($app['__error'])) {
            $this->check('Credentials valid', false, $app['__error']);
            $this->newLine();
            $this->error('Meta rejected the app id/secret pair. Copy them again from developers.facebook.com → your app → Settings → Basic.');
            return self::FAILURE;
        }
        $this->check('Credentials valid', true, 'app "' . ($app['name'] ?? '?') . '" (id ' . ($app['id'] ?? '?') . ')');

        // ── Redirect URI ─────────────────────────────────────────────
        $expected = url('/meta/oauth/callback');
        $declared = (string) config('services.facebook.redirect', '');

        $this->check(
            'OAuth callback route',
            true,
            $expected . '   ← must be listed verbatim under Facebook Login → Settings → Valid OAuth Redirect URIs',
        );

        if ($declared !== '' && rtrim($declared, '/') !== rtrim($expected, '/')) {
            $this->check('META_OAUTH_REDIRECT matches', false, "env says {$declared}, app builds {$expected}");
        }

        if (! str_starts_with($expected, 'https://')) {
            $this->warn('  ⚠ The callback is not HTTPS. Facebook refuses plain-http redirects except for localhost —');
            $this->warn('    test against the deployed site, or tunnel with ngrok/cloudflared.');
        }

        // ── Scopes ───────────────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>Permissions requested per provider</> (all need App Review before customers can use them)');
        foreach ((array) ($cfg['scopes'] ?? []) as $provider => $scopes) {
            $this->line(sprintf('  %-14s %s', $provider, $scopes));
        }

        // ── Optional pieces ──────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>Optional</>');

        $waConfig = (string) ($cfg['wa_config_id'] ?? '');
        $this->line($waConfig !== ''
            ? '  ✅ Embedded Signup configured (' . $waConfig . ') — "Connect WhatsApp" uses Meta\'s popup'
            : '  ○  Embedded Signup not configured — "Connect WhatsApp" falls back to the redirect flow.'
              . PHP_EOL . '     That is expected until Tech Provider status is granted; set META_WA_CONFIG_ID afterwards.');

        $verify = (string) config('meta.whatsapp.verify_token', '');
        $this->line($verify !== ''
            ? '  ✅ Webhook verify token set'
            : '  ○  META_WHATSAPP_VERIFY_TOKEN not set — inbound WhatsApp webhooks cannot be subscribed.');

        // The single webhook URL Meta calls for WhatsApp, Messenger AND
        // Instagram. Historically printed as /api/meta/webhook, which does not
        // exist — an operator who pasted it into the dashboard got a failed
        // verification and nothing explaining why.
        $this->line('     Webhook callback URL: ' . url('/api/whatsapp/webhook'));

        // ── Instagram Login ──────────────────────────────────────────
        $this->newLine();
        $this->line('<options=bold>Instagram API with Instagram Login</> (separate product, separate credentials)');

        $ig = (array) config('meta.instagram', []);

        if (empty($ig['app_id']) || empty($ig['app_secret'])) {
            $this->line('  ○  Not configured. "Connect Instagram" will use Facebook Login instead,');
            $this->line('     which only works for IG accounts linked to a Facebook Page.');
            $this->line('     To enable: App dashboard → Instagram → API setup with Instagram login,');
            $this->line('     then set INSTAGRAM_APP_ID and INSTAGRAM_APP_SECRET.');
        } else {
            $this->line('  ✅ Configured (app id ' . $ig['app_id'] . ') — "Connect Instagram" uses Instagram Login');
            $this->line('     Scopes: ' . ($ig['scopes'] ?? ''));
            $this->newLine();
            $this->line('     These three URLs must be registered verbatim under');
            $this->line('     Instagram → API setup with Instagram login → Business login settings:');
            $this->line('       OAuth redirect      ' . url('/meta/instagram/callback'));
            $this->line('       Deauthorize         ' . url('/meta/instagram/deauthorize'));
            $this->line('       Data deletion       ' . url('/meta/data-deletion'));

            if (! str_starts_with(url('/'), 'https://')) {
                $this->warn('  ⚠ Instagram requires HTTPS redirect URIs — localhost is not exempt, unlike Facebook.');
            }
        }

        // ── Summary ──────────────────────────────────────────────────
        $this->newLine();
        if ($this->problems > 0) {
            $this->warn("{$this->problems} problem(s) above need attention.");
            return self::FAILURE;
        }

        $this->info('Configuration looks good.');
        $this->newLine();
        $this->line('While the app is in Development mode, onboarding works ONLY for Facebook accounts');
        $this->line('holding a role on the app (App → App roles → Roles). Add your own account as');
        $this->line('Administrator and test the full flow for real.');

        return self::SUCCESS;
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        if (! $ok) {
            $this->problems++;
        }
        $this->line(sprintf('  %s %-26s %s', $ok ? '✅' : '❌', $label, $detail));
    }

    /** @return array decoded body, or ['__error' => message] */
    private function graph(string $path, array $query): array
    {
        $base = rtrim((string) config('meta.app.graph_base'), '/') . '/' . config('meta.app.graph_version') . '/';

        try {
            $resp = (new Client(['timeout' => 15, 'connect_timeout' => 8, 'http_errors' => false]))
                ->get($base . ltrim($path, '/'), ['query' => $query]);
            $json = json_decode((string) $resp->getBody(), true);

            if ($resp->getStatusCode() >= 400) {
                return ['__error' => $json['error']['message'] ?? ('HTTP ' . $resp->getStatusCode())];
            }

            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            return ['__error' => 'could not reach Graph: ' . $e->getMessage()];
        }
    }
}
