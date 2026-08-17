<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * "Why did social sign-in stop working?" — answered in one command.
 *
 * Google and Facebook sign-in fail for reasons that all look identical from
 * the login page: the visitor gets one plain sentence whatever went wrong.
 * SocialAuthController logs the real reason, but that only helps once you know
 * to go looking, and it cannot tell you about a misconfiguration that never
 * got as far as an exception.
 *
 * The three things this exists to make visible:
 *
 *  1. The redirect URI we ACTUALLY send. It has to match the provider's
 *     registration byte-for-byte, it is built from the request (or APP_URL in
 *     a console context), and nobody can read it off the config by eye.
 *  2. Which app each provider is really using. `services.facebook.client_id`
 *     falls back to META_APP_ID, so changing the channel-onboarding app
 *     silently repoints the "Log in with Facebook" button too — the single
 *     nastiest trap in this config, because the button was never touched.
 *  3. Whether the credentials are valid at all, as opposed to merely present.
 *
 *   php artisan auth:doctor
 */
class AuthDoctor extends Command
{
    protected $signature = 'auth:doctor {--lines=10 : How many recent sign-in failures to show}';

    protected $description = 'Check Google / Facebook sign-in credentials, redirect URIs and recent failures';

    private int $problems = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('<options=bold>Social sign-in — configuration check</>');

        // In a console context there is no request to derive the host from, so
        // url() falls back to APP_URL. Say so plainly: an operator comparing
        // this output against the provider dashboard needs to know whether
        // they are looking at the real thing or at the fallback.
        $root = rtrim(url('/'), '/');
        $this->newLine();
        $this->line('  URLs below are built from <options=bold>APP_URL</> (' . config('app.url') . ') because this is a');
        $this->line('  console run. In a browser request Laravel uses the request host and scheme');
        $this->line('  instead — which behind a proxy depends on X-Forwarded-Proto and TrustProxies.');
        $this->line('  If APP_URL disagrees with the real public URL, fix APP_URL before reading on:');
        $this->line('  queued mail, and every URL generated outside a request, uses this value.');

        $this->checkAppUrl($root);

        foreach (['google', 'facebook'] as $provider) {
            $this->provider($provider, $root);
        }

        $this->recentFailures();

        $this->newLine();
        if ($this->problems > 0) {
            $this->warn("{$this->problems} problem(s) above need attention.");
            return self::FAILURE;
        }

        $this->info('Social sign-in configuration looks good.');
        return self::SUCCESS;
    }

    private function checkAppUrl(string $root): void
    {
        $this->newLine();

        if (str_starts_with($root, 'http://localhost') || str_starts_with($root, 'http://127.0.0.1')) {
            $this->check('APP_URL', false, $root . ' — a deployed server should not be on localhost');
            return;
        }

        if (! str_starts_with($root, 'https://')) {
            $this->check('APP_URL', false, $root . ' — Google refuses plain-http redirect URIs outside localhost');
            return;
        }

        $this->check('APP_URL', true, $root);
    }

    private function provider(string $provider, string $root): void
    {
        $cfg      = (array) config("services.{$provider}", []);
        $id       = (string) ($cfg['client_id'] ?? '');
        $secret   = (string) ($cfg['client_secret'] ?? '');
        $redirect = $root . '/auth/' . $provider . '/callback';

        $this->newLine();
        $this->line('<options=bold>' . ucfirst($provider) . '</>');

        if ($id === '' || $secret === '') {
            $this->check('credentials', false, 'not set — the button returns "'
                . ucfirst($provider) . ' sign-in isn\'t configured yet."');
            $this->line(sprintf('     set %s_CLIENT_ID and %s_CLIENT_SECRET', strtoupper($provider), strtoupper($provider)));
            return;
        }

        $this->check('client id', true, $id);
        $this->check('client secret', true, str_repeat('•', 12) . substr($secret, -4));

        // The redirect URI is the single most common cause of failure and the
        // hardest to eyeball, so print it as something copy-pasteable rather
        // than describing it.
        $this->line(sprintf('  %s %-26s %s', '→', 'sends redirect_uri', $redirect));
        $this->line('     register this VERBATIM under ' . $this->whereToRegister($provider));

        match ($provider) {
            'facebook' => $this->facebookExtras($id, $secret),
            'google'   => $this->googleExtras($id),
            default    => null,
        };
    }

    /**
     * The shared-credential trap.
     *
     * services.facebook.client_id is `env('FACEBOOK_CLIENT_ID', env('META_APP_ID'))`.
     * That fallback is convenient when one app does everything and actively
     * misleading when it does not: swapping META_APP_ID for channel onboarding
     * moves the login button to the new app without anyone editing anything
     * related to login. The new app has neither the /auth/facebook/callback URI
     * whitelisted nor, if it is a Business-type app, the ability to grant the
     * `email` scope Socialite's login flow needs — so login breaks in a way
     * that looks completely unrelated to the change that caused it.
     */
    private function facebookExtras(string $id, string $secret): void
    {
        $explicit = (string) env('FACEBOOK_CLIENT_ID', '');
        $metaId   = (string) env('META_APP_ID', '');

        if ($explicit === '' && $metaId !== '') {
            $this->check('credential source', false,
                'FACEBOOK_CLIENT_ID is not set, so login is using META_APP_ID (' . $metaId . ')');
            $this->line('     Login with Facebook and channel onboarding are therefore the SAME app.');
            $this->line('     If they are meant to be different apps, set FACEBOOK_CLIENT_ID and');
            $this->line('     FACEBOOK_CLIENT_SECRET explicitly — otherwise changing META_APP_ID');
            $this->line('     silently repoints the login button too.');
        } else {
            $this->check('credential source', true, $explicit !== ''
                ? 'FACEBOOK_CLIENT_ID (set explicitly)'
                : 'FACEBOOK_CLIENT_ID');
        }

        // id|secret is an app access token: no user, no App Review, so this is
        // the cheapest possible proof the pair belongs together and is live.
        $app = $this->graph('app', ['fields' => 'id,name', 'access_token' => "{$id}|{$secret}"]);

        if (isset($app['__error'])) {
            $this->check('credentials valid', false, $app['__error']);
            return;
        }

        $this->check('credentials valid', true, 'app "' . ($app['name'] ?? '?') . '" (id ' . ($app['id'] ?? '?') . ')');
    }

    private function googleExtras(string $id): void
    {
        // Google client ids always carry this suffix. A pasted client SECRET,
        // a project number or a truncated copy are all common and all produce
        // an opaque invalid_client at the far end of the flow.
        if (! str_ends_with($id, '.apps.googleusercontent.com')) {
            $this->check('client id shape', false,
                'does not end in .apps.googleusercontent.com — this looks like the wrong value');
            return;
        }

        $this->check('client id shape', true, 'well-formed OAuth client id');
    }

    private function whereToRegister(string $provider): string
    {
        return match ($provider) {
            'google'   => 'Google Cloud Console → APIs & Services → Credentials'
                          . PHP_EOL . '     → your OAuth 2.0 Client → Authorised redirect URIs',
            'facebook' => 'Meta app → Facebook Login → Settings'
                          . PHP_EOL . '     → Valid OAuth Redirect URIs (and turn on Client + Web OAuth Login)',
            default    => 'the provider dashboard',
        };
    }

    /**
     * The reasons behind the login page's one generic sentence.
     *
     * SocialAuthController logs these; surfacing them here means an operator
     * diagnosing a broken button never has to know that, or go grepping.
     */
    private function recentFailures(): void
    {
        $this->newLine();
        $this->line('<options=bold>Recent sign-in failures</> (from the log)');

        // In production LOG_CHANNEL is `stderr` so the app tier's 2+ replicas
        // stream to the Docker log driver rather than each writing a file only
        // one container can see. There is therefore no file to tail here, and
        // saying "no log file" invites the reader to conclude nothing failed —
        // when in fact the records exist, just somewhere else.
        $channel = (string) config('logging.default');

        if (in_array($channel, ['stderr', 'stdout', 'errorlog', 'syslog'], true)) {
            $this->line("  ○  LOG_CHANNEL is '{$channel}', so failures are not written to a file.");
            $this->line('     Read them from the container log instead — across all replicas:');
            $this->line('       dc logs app --tail=500 | grep "Social sign-in failed"');
            return;
        }

        $path = storage_path('logs/laravel.log');

        if (! File::exists($path)) {
            $this->line('  ○  no log file at ' . $path);
            return;
        }

        $wanted = max(1, (int) $this->option('lines'));
        $hits   = [];

        // Read backwards in chunks: the log is routinely hundreds of MB on a
        // busy server and file() would load all of it to show ten lines.
        $handle = fopen($path, 'rb');
        $size   = filesize($path);
        $chunk  = 64 * 1024;
        $pos    = $size;
        $tail   = '';

        while ($pos > 0 && count($hits) < $wanted) {
            $read = (int) min($chunk, $pos);
            $pos -= $read;
            fseek($handle, $pos);
            $tail = fread($handle, $read) . $tail;

            $lines = explode("\n", $tail);
            // The first element may be a partial line; keep it for the next pass.
            $tail  = $pos > 0 ? array_shift($lines) : '';

            foreach (array_reverse($lines) as $line) {
                if (str_contains($line, 'Social sign-in failed') && count($hits) < $wanted) {
                    $hits[] = trim($line);
                }
            }
        }

        fclose($handle);

        if (! $hits) {
            $this->line('  ○  none logged — either nobody has tried since the last deploy,');
            $this->line('     or the failure happens BEFORE the callback (a rejected redirect URI');
            $this->line('     never reaches us, so it cannot be logged here — check the provider).');
            return;
        }

        foreach (array_reverse($hits) as $line) {
            $this->line('  ' . $line);
        }
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
        $base = rtrim((string) config('meta.app.graph_base', 'https://graph.facebook.com'), '/')
            . '/' . config('meta.app.graph_version', 'v21.0') . '/';

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
