<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Support\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Models\ChannelOnboardingLog;
use Msd\MetaChannels\Models\ChannelOnboardingPayload;
use Msd\MetaChannels\Services\OAuthService;
use Msd\MetaChannels\Services\OnboardingService;

/**
 * Channel onboarding — three ways in, one pipeline behind them.
 *
 *   redirect         classic Facebook Login in a popup (works everywhere)
 *   embedded_signup  Meta's own WhatsApp popup — no redirect, ~2 minutes
 *   qr_handoff       desktop shows a QR, the customer finishes on the phone
 *                    where their WhatsApp actually lives
 *
 * All three converge on OnboardingService, which persists what Meta returns
 * BEFORE trying to use it. That is what lets the retry button replay our
 * side of a failure without dragging the customer back through consent.
 */
class ChannelOnboardController extends Controller
{
    private const PROVIDERS = [
        'facebook'  => ChannelConnection::PROVIDER_FACEBOOK_PAGE,
        'instagram' => ChannelConnection::PROVIDER_INSTAGRAM,
        'whatsapp'  => ChannelConnection::PROVIDER_WHATSAPP,
    ];

    public function __construct(
        private OAuthService $oauth,
        private OnboardingService $onboarding,
    ) {}

    // ── 1. Redirect flow ─────────────────────────────────────────────

    /** Kick off OAuth for a provider (opened in a popup by the Channels page). */
    public function start(Request $request, Client $client, string $provider)
    {
        $resolved = self::PROVIDERS[$provider] ?? null;
        abort_unless($resolved, 404);

        $project = $this->resolveProject($client, (int) $request->query('project_id'));

        if (! $this->oauth->isConfigured()) {
            return $this->popupClose('error', 'Meta app not configured — set META_APP_ID and META_APP_SECRET first.', $client, $project);
        }

        // Reuse the log a QR handoff already opened, so the desktop poller
        // is watching the same attempt the phone is completing.
        $log = $this->resolveOrCreateLog($request, $project, $resolved, ChannelOnboardingPayload::METHOD_REDIRECT);
        $log->step('redirect_to_facebook', true);

        return $this->redirectToFacebook($client, $project, $resolved, $log);
    }

    /** OAuth redirect target (fixed URL; context travels in encrypted `state`). */
    public function callback(Request $request): Response
    {
        $state   = $this->decodeState((string) $request->query('state'));
        $client  = $state ? Client::where('slug', $state['client'])->first() : null;
        $project = $client ? Project::where('client_id', $client->id)->where('id', $state['project'])->first() : null;
        $log     = $state ? ChannelOnboardingLog::find($state['log']) : null;

        if (! $state || ! $project || ! $log) {
            return $this->popupClose('error', 'Onboarding failed: invalid or expired session.', $client, $project);
        }

        // User denied / cancelled on the Facebook consent screen.
        if ($request->query('error') || ! $request->query('code')) {
            $reason = $request->query('error_description') ?: ($request->query('error') ?: 'no authorization code');
            $log->error_code = OnboardingService::ERR_CONSENT_DENIED;
            $log->step('consent', false, $reason);
            $log->fail('Consent not granted: ' . $reason);
            return $this->popupClose('error', 'Onboarding cancelled: ' . $reason, $client, $project);
        }
        $log->step('consent', true);

        // Persist FIRST. Everything past this line is our own machinery and
        // can be replayed from the stored payload if it breaks.
        $payload = $this->onboarding->ingestCode(
            projectId:   $project->id,
            userId:      $log->user_id,
            provider:    $state['provider'],
            code:        (string) $request->query('code'),
            redirectUri: $this->callbackUrl(),
            log:         $log,
            method:      $log->method ?: ChannelOnboardingPayload::METHOD_REDIRECT,
        );

        try {
            $imported = $this->onboarding->process($payload, $log);
        } catch (\Throwable $e) {
            Log::warning('Meta onboarding failed', ['provider' => $state['provider'], 'payload' => $payload->id, 'error' => $e->getMessage()]);

            $suffix = $payload->fresh()->isRetryable()
                ? ' Your Meta authorisation was saved — press Retry on the Channels page, no need to sign in again.'
                : '';

            return $this->popupClose('error', 'Onboarding failed: ' . $e->getMessage() . $suffix, $client, $project);
        }

        return $this->popupClose('success', 'Connected ' . count($imported) . ' ' . $state['provider'] . ' channel(s): ' . implode(', ', $imported), $client, $project);
    }

    // ── 2. Embedded Signup (WhatsApp) ────────────────────────────────

    /**
     * Meta's WhatsApp Embedded Signup posts here from the browser with the
     * code its popup produced, plus the WABA and phone number the customer
     * picked. No redirect_uri is involved — see exchangeEmbeddedSignupCode().
     */
    public function embeddedSignup(Request $request, Client $client): JsonResponse
    {
        // NOTE the field names: `waba` and `phone_number`, NOT `waba_id` /
        // `phone_number_id`. App\Http\Middleware\DecodeHashids rewrites every
        // request key matching `*_id` into an integer — Meta's identifiers are
        // numeric strings, so they would be silently coerced and then fail
        // `string` validation with an unexplainable 422.
        $data = $request->validate([
            'project_id'   => ['required'],
            'code'         => ['required', 'string', 'max:2000'],
            'waba'         => ['nullable', 'string', 'max:64'],
            'phone_number' => ['nullable', 'string', 'max:64'],
        ]);

        $project = $this->resolveProject($client, (int) $data['project_id']);

        if (! $this->oauth->isConfigured()) {
            return response()->json(['ok' => false, 'message' => 'Meta app not configured on this server.'], 422);
        }

        $log = ChannelOnboardingLog::create([
            'project_id' => $project->id,
            'user_id'    => auth()->id(),
            'provider'   => ChannelConnection::PROVIDER_WHATSAPP,
            'method'     => ChannelOnboardingPayload::METHOD_EMBEDDED_SIGNUP,
            'status'     => ChannelOnboardingLog::STATUS_STARTED,
        ]);
        $log->step('embedded_signup_returned', true, trim(($data['waba'] ?? '') . ' / ' . ($data['phone_number'] ?? ''), ' /'));

        // Trade the code for a token immediately, then persist — the code is
        // single-use, so it is worthless as something to store and retry.
        try {
            $token = $this->oauth->exchangeEmbeddedSignupCode($data['code']);
        } catch (\Throwable $e) {
            $log->error_code = OnboardingService::ERR_CODE_EXCHANGE;
            $log->step('code_exchange', false, $e->getMessage());
            $log->fail($e->getMessage());
            return response()->json(['ok' => false, 'message' => 'Could not complete the WhatsApp signup: ' . $e->getMessage()], 422);
        }
        $log->step('code_exchange', true);

        $payload = $this->onboarding->ingestToken(
            projectId: $project->id,
            userId:    auth()->id(),
            provider:  ChannelConnection::PROVIDER_WHATSAPP,
            token:     $token,
            log:       $log,
            method:    ChannelOnboardingPayload::METHOD_EMBEDDED_SIGNUP,
            extra:     ['waba_id' => $data['waba'] ?? null, 'phone_number_id' => $data['phone_number'] ?? null],
        );

        try {
            $imported = $this->onboarding->process($payload, $log);

            // Without this the number connects and then silently never
            // receives anything — the classic "it says connected but nothing
            // happens" ticket. Non-fatal: the connection is still usable for
            // sending, and it can be repaired by retrying.
            if ($payload->waba_id) {
                try {
                    $this->oauth->subscribeAppToWaba($payload->waba_id, $payload->long_lived_token ?: $token);
                    $log->step('subscribe_webhooks', true);
                } catch (\Throwable $e) {
                    $log->step('subscribe_webhooks', false, $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            return response()->json([
                'ok'        => false,
                'message'   => $e->getMessage(),
                'retryable' => $payload->fresh()->isRetryable(),
                'log_id'    => $log->id,
            ], 422);
        }

        return response()->json(['ok' => true, 'imported' => $imported, 'log_id' => $log->id]);
    }

    // ── 3. QR handoff ────────────────────────────────────────────────

    /**
     * Desktop asks for a QR; the customer scans it and finishes on their
     * phone, which is where WhatsApp actually is.
     *
     * The QR encodes a SIGNED, short-lived URL. That is what lets the phone
     * complete onboarding without a login — and why the window is 15
     * minutes and the link dies once the attempt finishes.
     */
    public function handoff(Request $request, Client $client, string $provider): JsonResponse
    {
        $resolved = self::PROVIDERS[$provider] ?? null;
        abort_unless($resolved, 404);

        $project = $this->resolveProject($client, (int) $request->query('project_id'));

        $log = ChannelOnboardingLog::create([
            'project_id' => $project->id,
            'user_id'    => auth()->id(),
            'provider'   => $resolved,
            'method'     => 'qr_handoff',
            'status'     => ChannelOnboardingLog::STATUS_STARTED,
        ]);
        $log->step('qr_issued', true, 'waiting for the phone to scan');

        $url = URL::temporarySignedRoute('channels.handoff.open', now()->addMinutes(15), [
            'log' => $log->id,
        ]);

        return response()->json([
            'ok'         => true,
            'log_id'     => $log->id,
            'url'        => $url,
            'qr'         => QrCode::dataUri($url, 260, '#0f172a'),
            'expires_in' => 15 * 60,
        ]);
    }

    /**
     * The page the phone lands on after scanning. Public but signed —
     * deliberately shows which workspace is about to be connected, so a
     * mis-scanned or forwarded link is obvious before anything happens.
     */
    public function handoffOpen(Request $request, int $log): Response
    {
        $onboarding = ChannelOnboardingLog::find($log);
        abort_unless($onboarding, 404);

        $project = Project::find($onboarding->project_id);
        $client  = $project ? Client::find($project->client_id) : null;
        abort_unless($project && $client, 404);

        // Already finished — don't let a shared link start a second attempt.
        if ($onboarding->status === ChannelOnboardingLog::STATUS_SUCCESS) {
            return response()->view('channels.handoff', [
                'done'     => true,
                'project'  => $project,
                'client'   => $client,
                'provider' => $onboarding->provider,
                'log'      => $onboarding,
                'goUrl'    => null,
            ]);
        }

        return response()->view('channels.handoff', [
            'done'     => false,
            'project'  => $project,
            'client'   => $client,
            'provider' => $onboarding->provider,
            'log'      => $onboarding,
            // Re-signed with the remaining window so the actual OAuth start
            // is authorised too, not just this landing page.
            'goUrl'    => URL::temporarySignedRoute('channels.handoff.go', now()->addMinutes(15), ['log' => $onboarding->id]),
        ]);
    }

    /** Phone tapped "Continue" — send them to Facebook. */
    public function handoffGo(Request $request, int $log)
    {
        $onboarding = ChannelOnboardingLog::find($log);
        abort_unless($onboarding, 404);

        $project = Project::find($onboarding->project_id);
        $client  = $project ? Client::find($project->client_id) : null;
        abort_unless($project && $client, 404);

        if (! $this->oauth->isConfigured()) {
            abort(503, 'Meta app not configured.');
        }

        $onboarding->step('phone_continue', true);

        return $this->redirectToFacebook($client, $project, $onboarding->provider, $onboarding);
    }

    /** Desktop polls this while the QR is on screen. */
    public function handoffStatus(Request $request, Client $client, int $log): JsonResponse
    {
        $onboarding = ChannelOnboardingLog::where('id', $log)->first();
        abort_unless($onboarding, 404);

        $this->resolveProject($client, (int) $onboarding->project_id);

        return response()->json([
            'status'   => $onboarding->status,
            'result'   => $onboarding->result,
            'error'    => $onboarding->error,
            'guidance' => $onboarding->guidance(),
            'steps'    => $onboarding->steps,
        ]);
    }

    // ── 4. Retry ─────────────────────────────────────────────────────

    /**
     * Replay our side of a failed attempt from what Meta already gave us.
     * No consent screen, no re-scan — this is the whole point of storing
     * the payload up front.
     */
    public function retry(Request $request, Client $client, int $log): RedirectResponse
    {
        $onboarding = ChannelOnboardingLog::find($log);
        abort_unless($onboarding, 404);

        $project = $this->resolveProject($client, (int) $onboarding->project_id);

        $payload = ChannelOnboardingPayload::where('id', $onboarding->payload_id)
            ->orWhere(fn ($q) => $q->where('log_id', $onboarding->id))
            ->replayable()
            ->latest('id')
            ->first();

        if (! $payload) {
            return back()->with('error', 'Nothing stored to retry from — this connection has to be started again from Meta.');
        }

        try {
            $result = $this->onboarding->retry($payload, auth()->id());
        } catch (\Throwable $e) {
            return back()->with('error', 'Retry failed: ' . $e->getMessage());
        }

        return back()->with('success', 'Reconnected ' . count($result['imported']) . ' channel(s): ' . implode(', ', $result['imported']));
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** Shared Socialite redirect used by both the popup and the phone. */
    private function redirectToFacebook(Client $client, Project $project, string $provider, ChannelOnboardingLog $log)
    {
        $state = Crypt::encryptString(json_encode([
            'client'   => $client->slug,
            'project'  => $project->id,
            'provider' => $provider,
            'log'      => $log->id,
            'ts'       => time(),
        ]));

        $scopes = array_filter(explode(',', (string) (config('meta.app.scopes')[$provider] ?? '')));

        return Socialite::driver('facebook')
            ->stateless()
            ->scopes($scopes)
            ->with(['state' => $state])
            ->redirectUrl($this->callbackUrl())
            ->redirect();
    }

    /** Project lookup scoped to the client — never trust the query string. */
    private function resolveProject(Client $client, int $projectId): Project
    {
        return Project::where('client_id', $client->id)->where('id', $projectId)->firstOrFail();
    }

    /** Continue an existing attempt (QR handoff) or open a fresh one. */
    private function resolveOrCreateLog(Request $request, Project $project, string $provider, string $method): ChannelOnboardingLog
    {
        $existing = $request->query('log')
            ? ChannelOnboardingLog::where('id', (int) $request->query('log'))
                ->where('project_id', $project->id)
                ->where('status', ChannelOnboardingLog::STATUS_STARTED)
                ->first()
            : null;

        return $existing ?: ChannelOnboardingLog::create([
            'project_id' => $project->id,
            'user_id'    => auth()->id(),
            'provider'   => $provider,
            'method'     => $method,
            'status'     => ChannelOnboardingLog::STATUS_STARTED,
        ]);
    }

    /**
     * Tiny HTML page that flashes the result, reloads the opener (Channels
     * page) and closes the popup — or full-page redirects if opened directly
     * (which is exactly what the phone does in the QR handoff).
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
        if (! is_array($data) || empty($data['log'])) {
            return null;
        }
        if ((time() - (int) ($data['ts'] ?? 0)) > 900) {   // 15-minute validity
            return null;
        }

        return $data;
    }
}
