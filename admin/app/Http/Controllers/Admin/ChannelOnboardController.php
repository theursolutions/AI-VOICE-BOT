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
use Msd\MetaChannels\Services\InstagramLoginService;
use Msd\MetaChannels\Services\OAuthService;
use Msd\MetaChannels\Services\OnboardingService;
use Msd\MetaChannels\Support\SignedRequest;

/**
 * Channel onboarding — four ways in, one pipeline behind them.
 *
 *   redirect         classic Facebook Login in a popup (works everywhere)
 *   instagram_login  Instagram's own OAuth, for business accounts with no
 *                    Facebook Page attached — which is most of them
 *   embedded_signup  Meta's own WhatsApp popup — no redirect, ~2 minutes
 *   qr_handoff       desktop shows a QR, the customer finishes on the phone
 *                    where their WhatsApp actually lives
 *
 * All four converge on OnboardingService, which persists what Meta returns
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
        private InstagramLoginService $instagram,
    ) {}

    // ── 1. Redirect flow ─────────────────────────────────────────────

    /** Kick off OAuth for a provider (opened in a popup by the Channels page). */
    public function start(Request $request, Client $client, string $provider)
    {
        $resolved = self::PROVIDERS[$provider] ?? null;
        abort_unless($resolved, 404);

        $project = $this->resolveProject($client, (int) $request->query('project_id'));

        $viaInstagram = $this->usesInstagramLogin($resolved);

        if (! $viaInstagram && ! $this->oauth->isConfigured()) {
            return $this->popupClose('error', 'Meta app not configured — set META_APP_ID and META_APP_SECRET first.', $client, $project);
        }

        // Reuse the log a QR handoff already opened, so the desktop poller
        // is watching the same attempt the phone is completing.
        $log = $this->resolveOrCreateLog($request, $project, $resolved, $viaInstagram
            ? ChannelOnboardingPayload::METHOD_INSTAGRAM_LOGIN
            : ChannelOnboardingPayload::METHOD_REDIRECT);

        return $this->redirectForProvider($client, $project, $resolved, $log);
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

    // ── 1b. Instagram Login callback ─────────────────────────────────

    /**
     * Where instagram.com sends the customer back to.
     *
     * A separate endpoint from the Facebook one on purpose: the redirect_uri
     * is part of the signature of the token exchange, and the exchange hosts
     * differ (api.instagram.com vs graph.facebook.com). Sharing one URL would
     * mean guessing which flow a given callback belongs to, and guessing
     * wrong yields "Invalid authorization code" with nothing to debug.
     *
     * Register this exact URL under App dashboard → Instagram → API setup
     * with Instagram login → "OAuth redirect URIs".
     */
    public function instagramCallback(Request $request): Response
    {
        $state   = $this->decodeState((string) $request->query('state'));
        $client  = $state ? Client::where('slug', $state['client'])->first() : null;
        $project = $client ? Project::where('client_id', $client->id)->where('id', $state['project'])->first() : null;
        $log     = $state ? ChannelOnboardingLog::find($state['log']) : null;

        if (! $state || ! $project || ! $log) {
            return $this->popupClose('error', 'Instagram onboarding failed: invalid or expired session.', $client, $project);
        }

        // Instagram reports a decline as error=access_denied with a
        // human-readable error_description.
        if ($request->query('error') || ! $request->query('code')) {
            $reason = $request->query('error_description') ?: ($request->query('error') ?: 'no authorization code');
            $log->error_code = OnboardingService::ERR_CONSENT_DENIED;
            $log->step('consent', false, $reason);
            $log->fail('Consent not granted: ' . $reason);
            return $this->popupClose('error', 'Instagram onboarding cancelled: ' . $reason, $client, $project);
        }
        $log->step('consent', true);

        // Persist before doing anything with it — same guarantee as the
        // Facebook path, so a failure downstream is replayable.
        $payload = $this->onboarding->ingestCode(
            projectId:   $project->id,
            userId:      $log->user_id,
            provider:    ChannelConnection::PROVIDER_INSTAGRAM,
            code:        (string) $request->query('code'),
            redirectUri: $this->instagramCallbackUrl(),
            log:         $log,
            method:      ChannelOnboardingPayload::METHOD_INSTAGRAM_LOGIN,
        );

        try {
            $imported = $this->onboarding->process($payload, $log);
        } catch (\Throwable $e) {
            Log::warning('Instagram onboarding failed', ['payload' => $payload->id, 'error' => $e->getMessage()]);

            $suffix = $payload->fresh()->isRetryable()
                ? ' Your Instagram authorisation was saved — press Retry on the Meta Onboarding page, no need to sign in again.'
                : '';

            return $this->popupClose('error', 'Instagram onboarding failed: ' . $e->getMessage() . $suffix, $client, $project);
        }

        return $this->popupClose('success', 'Connected ' . implode(', ', $imported) . ' on Instagram.', $client, $project);
    }

    /**
     * Instagram calls this when someone removes our app from their account
     * (Instagram → Settings → Apps and websites → Remove).
     *
     * Disabling rather than deleting is deliberate: the conversation history
     * belongs to the business, not to the authorisation, and a customer who
     * reconnects next week expects their inbox intact. Actual erasure is a
     * separate, explicit request — see DataDeletionController.
     */
    public function instagramDeauthorize(Request $request): JsonResponse
    {
        $data = $this->parseSignedRequest((string) $request->input('signed_request'));

        if ($data === null) {
            Log::warning('Instagram deauthorize: bad or unsigned request');
            return response()->json(['ok' => false], 400);
        }

        $igId = (string) ($data['user_id'] ?? '');
        if ($igId === '') {
            return response()->json(['ok' => true, 'disabled' => 0]);
        }

        $disabled = ChannelConnection::where('provider', ChannelConnection::PROVIDER_INSTAGRAM)
            ->where('external_id', $igId)
            ->update([
                'status'       => ChannelConnection::STATUS_DISABLED,
                'access_token' => null,
            ]);

        Log::info('Instagram deauthorize processed', ['ig_id' => $igId, 'disabled' => $disabled]);

        return response()->json(['ok' => true, 'disabled' => $disabled]);
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

    /** Phone tapped "Continue" — send them to the right consent screen. */
    public function handoffGo(Request $request, int $log)
    {
        $onboarding = ChannelOnboardingLog::find($log);
        abort_unless($onboarding, 404);

        $project = Project::find($onboarding->project_id);
        $client  = $project ? Client::find($project->client_id) : null;
        abort_unless($project && $client, 404);

        if (! $this->usesInstagramLogin($onboarding->provider) && ! $this->oauth->isConfigured()) {
            abort(503, 'Meta app not configured.');
        }

        $onboarding->step('phone_continue', true);

        return $this->redirectForProvider($client, $project, $onboarding->provider, $onboarding);
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

    /**
     * Send the customer to whichever consent screen this provider needs.
     *
     * Instagram is the interesting case: when Instagram Login credentials are
     * present we prefer that path, because it works for a business account
     * with no Facebook Page — the situation that made the Facebook-Login
     * route fail for most Instagram customers. Without those credentials it
     * falls back to the original behaviour, so nothing changes for anyone who
     * has not set the new env vars.
     */
    private function redirectForProvider(Client $client, Project $project, string $provider, ChannelOnboardingLog $log)
    {
        if ($this->usesInstagramLogin($provider)) {
            $log->step('redirect_to_instagram', true);
            return $this->redirectToInstagram($client, $project, $log);
        }

        $log->step('redirect_to_facebook', true);
        return $this->redirectToFacebook($client, $project, $provider, $log);
    }

    private function usesInstagramLogin(string $provider): bool
    {
        return $provider === ChannelConnection::PROVIDER_INSTAGRAM
            && $this->instagram->isConfigured();
    }

    /** Consent on instagram.com — no Facebook Page required. */
    private function redirectToInstagram(Client $client, Project $project, ChannelOnboardingLog $log): RedirectResponse
    {
        if ($log->method !== ChannelOnboardingPayload::METHOD_INSTAGRAM_LOGIN) {
            $log->method = ChannelOnboardingPayload::METHOD_INSTAGRAM_LOGIN;
            $log->save();
        }

        return redirect()->away($this->instagram->authUrl(
            $this->instagramCallbackUrl(),
            $this->encodeState($client, $project, ChannelConnection::PROVIDER_INSTAGRAM, $log),
        ));
    }

    /** Shared Socialite redirect used by both the popup and the phone. */
    private function redirectToFacebook(Client $client, Project $project, string $provider, ChannelOnboardingLog $log)
    {
        $state = $this->encodeState($client, $project, $provider, $log);

        $scopes = array_filter(explode(',', (string) (config('meta.app.scopes')[$provider] ?? '')));

        // setScopes(), NOT scopes(). Socialite's Facebook driver ships with
        // `protected $scopes = ['email']`, and scopes() MERGES into that
        // rather than replacing it — so every request also asked for `email`.
        // A Business-type app using Facebook Login for Business rejects that
        // with "Invalid Scopes: email" and refuses to show the consent screen
        // at all. setScopes() replaces the defaults with exactly what we ask
        // for.
        return Socialite::driver('facebook')
            ->stateless()
            ->setScopes($scopes)
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

    /**
     * The redirect_uri sent to Meta — and it has to match what is registered
     * in the app dashboard BYTE-FOR-BYTE.
     *
     * Prefer the explicitly configured value over route(), because route()
     * derives the URL from APP_URL: a server whose APP_URL is `http` while
     * the app registered `https`, or bare-domain against a registered `www.`,
     * sends a URI that does not match and Meta answers "This redirect failed
     * because the redirect URI is not whitelisted in the app's Client OAuth
     * Settings" — with nothing on our side to explain it. META_OAUTH_REDIRECT
     * is the lever for that, and it was documented in .env.example while being
     * ignored here.
     */
    private function callbackUrl(): string
    {
        return config('services.facebook.redirect') ?: route('meta.oauth.callback');
    }

    /** Same, for the separate Instagram Login redirect (HTTPS only). */
    private function instagramCallbackUrl(): string
    {
        return config('meta.instagram.redirect_uri') ?: route('meta.instagram.callback');
    }

    /**
     * Encrypted round-trip context. Both OAuth callbacks are fixed URLs with
     * no {client} segment, so everything they need to resume travels here.
     */
    private function encodeState(Client $client, Project $project, string $provider, ChannelOnboardingLog $log): string
    {
        return Crypt::encryptString(json_encode([
            'client'   => $client->slug,
            'project'  => $project->id,
            'provider' => $provider,
            'log'      => $log->id,
            'ts'       => time(),
        ]));
    }

    /** Verify a Meta `signed_request` against every app secret we hold. */
    private function parseSignedRequest(string $signed): ?array
    {
        return $signed === '' ? null : SignedRequest::parse($signed, SignedRequest::secrets());
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
