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
use Laravel\Socialite\Facades\Socialite;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Models\ChannelOnboardingLog;
use Msd\MetaChannels\Services\OAuthService;

/**
 * Facebook Login onboarding via Laravel Socialite (stateless): redirect the
 * user to Meta to pick their pages / IG accounts / WhatsApp numbers, then
 * import whatever they granted on the way back. Runs in a popup so the user
 * stays on the Channels page. Every attempt is logged for diagnosis + retry.
 */
class ChannelOnboardController extends Controller
{
    private const PROVIDERS = [
        'facebook'  => ChannelConnection::PROVIDER_FACEBOOK_PAGE,
        'instagram' => ChannelConnection::PROVIDER_INSTAGRAM,
        'whatsapp'  => ChannelConnection::PROVIDER_WHATSAPP,
    ];

    public function __construct(private OAuthService $oauth) {}

    /** Kick off OAuth for a provider (opened in a popup by the Channels page). */
    public function start(Request $request, Client $client, string $provider)
    {
        $resolved = self::PROVIDERS[$provider] ?? null;
        abort_unless($resolved, 404);

        $project = Project::where('client_id', $client->id)
            ->where('id', (int) $request->query('project_id'))
            ->firstOrFail();

        if (!$this->oauth->isConfigured()) {
            return $this->popupClose('error', 'Meta app not configured — set META_APP_ID and META_APP_SECRET first.', $client, $project);
        }

        $log = ChannelOnboardingLog::create([
            'project_id' => $project->id,
            'user_id'    => auth()->id(),
            'provider'   => $resolved,
            'status'     => ChannelOnboardingLog::STATUS_STARTED,
        ]);
        $log->step('redirect_to_facebook', true);

        $state = Crypt::encryptString(json_encode([
            'client'   => $client->slug,
            'project'  => $project->id,
            'provider' => $resolved,
            'log'      => $log->id,
            'ts'       => time(),
        ]));

        $scopes = array_filter(explode(',', (string) (config('meta.app.scopes')[$resolved] ?? '')));

        return Socialite::driver('facebook')
            ->stateless()
            ->scopes($scopes)
            ->with(['state' => $state])
            ->redirectUrl($this->callbackUrl())
            ->redirect();
    }

    /** OAuth redirect target (fixed URL; context travels in encrypted `state`). */
    public function callback(Request $request): Response
    {
        $state = $this->decodeState((string) $request->query('state'));
        $client  = $state ? Client::where('slug', $state['client'])->first() : null;
        $project = $client ? Project::where('client_id', $client->id)->where('id', $state['project'])->first() : null;
        $log     = $state ? ChannelOnboardingLog::find($state['log']) : null;

        if (!$state || !$project || !$log) {
            return $this->popupClose('error', 'Onboarding failed: invalid or expired session.', $client, $project);
        }

        // User denied / cancelled on the Facebook consent screen.
        if ($request->query('error') || !$request->query('code')) {
            $reason = $request->query('error_description') ?: ($request->query('error') ?: 'no authorization code');
            $log->step('consent', false, $reason);
            $log->fail('Consent not granted: ' . $reason);
            return $this->popupClose('error', 'Onboarding cancelled: ' . $reason, $client, $project);
        }

        try {
            $fbUser = Socialite::driver('facebook')->stateless()->redirectUrl($this->callbackUrl())->user();
            $token  = $fbUser->token;
            if (!$token) {
                throw new \RuntimeException('No access token returned by Facebook.');
            }
            $log->step('token_exchange', true);

            $channels = $this->oauth->discover($state['provider'], $token);
            $log->step('discover', true, count($channels) . ' channel(s)');

            $imported = [];
            foreach ($channels as $ch) {
                ChannelConnection::updateOrCreate(
                    ['project_id' => $project->id, 'provider' => $ch['provider'], 'external_id' => $ch['external_id']],
                    [
                        'name'         => $ch['name'],
                        'access_token' => $ch['access_token'],
                        'status'       => ChannelConnection::STATUS_ENABLED,
                        'metadata'     => $ch['metadata'] ?? [],
                    ],
                );
                $imported[] = $ch['name'];
            }

            $log->step('import', true, $imported);
            $log->status = ChannelOnboardingLog::STATUS_SUCCESS;
            $log->result = ['count' => count($imported), 'channels' => $imported];
            $log->save();

            return $this->popupClose('success', 'Onboarded ' . count($imported) . ' ' . $state['provider'] . ' channel(s): ' . implode(', ', $imported), $client, $project);
        } catch (\Throwable $e) {
            Log::warning('Meta onboarding failed', ['provider' => $state['provider'], 'error' => $e->getMessage()]);
            $log->step('error', false, $e->getMessage());
            $log->fail($e->getMessage());
            return $this->popupClose('error', 'Onboarding failed: ' . $e->getMessage(), $client, $project);
        }
    }

    /**
     * Tiny HTML page that flashes the result, reloads the opener (Channels
     * page) and closes the popup — or full-page redirects if opened directly.
     */
    private function popupClose(string $type, string $message, ?Client $client, ?Project $project): Response
    {
        if ($client && $project) {
            session()->flash($type, $message);   // shown when the opener reloads
            $back = route('channels.index', ['client' => $client->slug, 'project_id' => $project->id]);
        } else {
            $back = url('/');
        }
        $backJson = json_encode($back);
        $msg = e($message);
        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>Connecting…</title></head>
<body style="font-family:system-ui,sans-serif;padding:36px;text-align:center;color:#334155">
  <p style="font-size:14px">{$msg}</p>
  <p style="font-size:12px;color:#94a3b8">You can close this window.</p>
  <script>
    try {
      if (window.opener && !window.opener.closed) { window.opener.location.reload(); window.close(); }
      else { window.location.href = {$backJson}; }
    } catch (e) { window.location.href = {$backJson}; }
  </script>
</body></html>
HTML;
        return response($html);
    }

    private function callbackUrl(): string
    {
        return route('meta.oauth.callback');
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
        if (!is_array($data) || empty($data['log'])) {
            return null;
        }
        if ((time() - (int) ($data['ts'] ?? 0)) > 900) {   // 15-minute validity
            return null;
        }
        return $data;
    }
}
