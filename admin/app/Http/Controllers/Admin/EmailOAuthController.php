<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Msd\MailChannel\Models\EmailAccount;
use Msd\MailChannel\Services\OAuth\GoogleMailOAuthService;
use Msd\MailChannel\Services\OAuth\MicrosoftMailOAuthService;

/**
 * "Connect Google" / "Connect Microsoft" for the email channel.
 *
 * A SEPARATE OAuth app from any dashboard "log in with Google/Facebook"
 * (SocialAuthController) — deliberately not built on Socialite, for the same
 * reason ChannelOnboardController isn't: Socialite's driver is configured
 * from the dashboard's own client id/secret, and driving a DIFFERENT app's
 * consent through it silently sends the wrong app's credentials to the
 * wrong half of the exchange. See ChannelOnboardController's docblock for
 * the incident that taught this codebase that lesson.
 *
 * Same fixed-callback-URL / encrypted-`state` technique as
 * ChannelOnboardController::start()/callback(), for the same reason: Google
 * and Microsoft both require an exact, pre-registered redirect_uri, which
 * cannot carry a per-workspace {client} slug.
 */
class EmailOAuthController extends Controller
{
    private const PROVIDERS = ['google', 'microsoft'];

    public function __construct(
        private GoogleMailOAuthService $google,
        private MicrosoftMailOAuthService $microsoft,
    ) {}

    /** Client-scoped: the popup opens here. */
    public function start(Request $request, Client $client, string $provider): RedirectResponse|Response
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $project = $this->guard($client, (int) $request->query('project_id'));
        $service = $this->serviceFor($provider);

        if (! $service->isConfigured()) {
            return $this->popupClose('error', ucfirst($provider) . ' mail is not configured — set the '
                . strtoupper($provider) . '_MAIL_CLIENT_ID / _SECRET env vars first.', $client, $project);
        }

        $state = Crypt::encryptString(json_encode([
            'client'   => $client->slug,
            'project'  => $project->id,
            'provider' => $provider,
            'ts'       => time(),
        ]));

        return redirect()->away($service->authUrl($this->callbackUrl($provider), $state));
    }

    /** Fixed redirect target (no {client} segment — context travels in `state`). */
    public function callback(Request $request, string $provider): Response
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $state   = $this->decodeState((string) $request->query('state'));
        $client  = $state ? Client::where('slug', $state['client'])->first() : null;
        $project = $client ? Project::where('client_id', $client->id)->where('id', $state['project'])->first() : null;

        if (! $state || ! $project) {
            return $this->popupClose('error', 'Mailbox connection failed: invalid or expired session.', $client, $project);
        }

        if ($request->query('error') || ! $request->query('code')) {
            $reason = $request->query('error_description') ?: ($request->query('error') ?: 'no authorization code');
            return $this->popupClose('error', 'Connection cancelled: ' . $reason, $client, $project);
        }

        $service = $this->serviceFor($provider);

        try {
            $tokens = $service->exchangeCode((string) $request->query('code'), $this->callbackUrl($provider));
            $email  = $service->discoverEmail($tokens['access_token']);
        } catch (\Throwable $e) {
            Log::warning('mail-channel: OAuth connect failed', ['provider' => $provider, 'error' => $e->getMessage()]);
            return $this->popupClose('error', 'Connection failed: ' . $e->getMessage(), $client, $project);
        }

        $cfg = config("mail_channel.{$provider}");

        // Matching on (project_id, from_email) means reconnecting the same
        // mailbox updates it in place rather than creating a duplicate —
        // "Reconnect" on an existing account is just re-running this flow.
        EmailAccount::updateOrCreate(
            ['project_id' => $project->id, 'from_email' => $email],
            [
                'label'           => null,
                'from_name'       => null,
                'imap_host'       => $cfg['imap_host'], 'imap_port' => $cfg['imap_port'], 'imap_encryption' => $cfg['imap_encryption'],
                'imap_username'   => $email, 'imap_password' => null,
                'smtp_host'       => $cfg['smtp_host'], 'smtp_port' => $cfg['smtp_port'], 'smtp_encryption' => $cfg['smtp_encryption'],
                'smtp_username'   => $email, 'smtp_password' => null,
                'oauth_provider'      => $provider,
                'oauth_token'         => $tokens['access_token'],
                'oauth_refresh_token' => $tokens['refresh_token'],
                'oauth_expires_at'    => isset($tokens['expires_in']) ? now()->addSeconds($tokens['expires_in']) : null,
                'status'          => EmailAccount::STATUS_ENABLED,
                'last_error'      => null,
            ],
        );

        return $this->popupClose('success', 'Connected ' . $email . '.', $client, $project);
    }

    private function serviceFor(string $provider): GoogleMailOAuthService|MicrosoftMailOAuthService
    {
        return match ($provider) {
            'google'    => $this->google,
            'microsoft' => $this->microsoft,
        };
    }

    private function callbackUrl(string $provider): string
    {
        return route('mail.oauth.callback', ['provider' => $provider]);
    }

    private function decodeState(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($data) && !empty($data['client']) && !empty($data['project']) ? $data : null;
    }

    /**
     * Tiny HTML page that flashes the result, reloads the opener (Channels
     * page) and closes the popup — same shape as
     * ChannelOnboardController::popupClose(), kept as its own small copy
     * rather than shared: the two OAuth flows don't share app credentials or
     * session state either, and a shared helper would be the only coupling
     * between two otherwise-independent integrations.
     */
    private function popupClose(string $type, string $message, ?Client $client, ?Project $project): Response
    {
        if ($client && $project) {
            session()->flash($type, $message);
            $back = route('channels.index', ['client' => $client->slug, 'project_id' => $project->id]);
        } else {
            $back = url('/');
        }
        $backJson = json_encode($back);
        $msg  = e($message);
        $tone = $type === 'success' ? '#059669' : '#dc2626';
        $html = <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Connecting…</title></head>
<body style="font-family:system-ui,sans-serif;padding:36px;text-align:center;color:#334155">
  <p style="font-size:15px;color:{$tone};max-width:32rem;margin:0 auto 10px">{$msg}</p>
  <p style="font-size:12px;color:#94a3b8">You can close this window.</p>
  <script>
    try {
      if (window.opener && !window.opener.closed) { window.opener.location.reload(); window.close(); }
      else { setTimeout(function(){ window.location.href = {$backJson}; }, 2500); }
    } catch (e) { window.location.href = {$backJson}; }
  </script>
</body></html>
HTML;
        return response($html);
    }

    private function guard(Client $client, int $projectId): Project
    {
        return Project::where('client_id', $client->id)->where('id', $projectId)->firstOrFail();
    }
}
