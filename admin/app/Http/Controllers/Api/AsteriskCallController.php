<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SipExtension;
use App\Models\WebCallSession;
use App\Services\Conversation\SessionTokenService;
use App\Services\Telephony\CallGreeting;
use App\Services\Telephony\CallSessionService;
use App\Services\Telephony\TelephonyNumbers;
use App\Services\Telephony\WebCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Asterisk calls (telco SIP trunks, PBXs like a Grandstream UCM, WebRTC).
 *
 * Unlike Twilio there is no public webhook: Asterisk hands each call to
 * the voice engine's ARI client, which asks Laravel who answers it.
 *
 *   caller → trunk / PBX / browser → Asterisk → Stasis(ai-crm)
 *      ↓ voice engine (ARI) → POST /api/internal/asterisk/call-start
 *   ← session token + greeting; the engine answers and bridges the audio
 *     to the shared call core over AudioSocket.
 *
 * Numbers bound to a Conversation Flow are answered by the AI directly:
 * FlowRunner speaks TwiML, which only Twilio understands.
 */
class AsteriskCallController extends Controller
{
    public function __construct(
        private TelephonyNumbers $numbers,
        private CallSessionService $calls,
        private SessionTokenService $tokens,
        private WebCalls $webCalls,
    ) {}

    /**
     * POST /api/internal/asterisk/call-start
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => 'required|string|max:128',
            'caller'     => 'nullable|string|max:64',
            'dialed'     => 'required|string|max:64',
            // The SIP account that placed the call, when it is one of ours:
            // "w…" a website "Call us" button, "c<client>_<ext>" a client extension.
            'endpoint'   => 'nullable|string|max:64',
        ]);
        $endpoint = (string) ($data['endpoint'] ?? '');
        $caller   = (string) ($data['caller'] ?? '');
        $web      = null;
        $numberConfig = null;

        if (str_starts_with($endpoint, WebCallSession::PREFIX)) {
            // A website caller: the account says which project, and counts
            // this call against the visitor's daily limit.
            $web = $this->webCalls->start($endpoint);
            if (! $web || ! ($project = Project::find($web->project_id))) {
                Log::warning('Asterisk: unknown or used website call account', ['endpoint' => $endpoint]);
                return response()->json(['ok' => false, 'reason' => 'expired'], 403);
            }
            // No caller ID on the web: left empty, so the AI asks for a
            // number instead of treating one as known.
            $caller = '';
        } else {
            [$project, $numberConfig] = $this->numbers->owner($data['dialed'], TelephonyNumbers::ASTERISK)
                ?? [null, null];

            // A client's extension reaches only that client's own AI numbers.
            $fromClient = $this->extensionClient($endpoint);
            if ($fromClient !== null && $project && (int) $project->client_id !== $fromClient) {
                Log::warning('Asterisk: extension dialed another client\'s number', ['endpoint' => $endpoint, 'dialed' => $data['dialed']]);
                return response()->json(['ok' => false, 'reason' => 'not_configured'], 404);
            }

            // Lab / single-tenant: a number no project has claimed goes to the
            // configured default project instead of being rejected.
            $defaultId = (int) config('services.asterisk.default_project_id', 0);
            if (! $project && $defaultId > 0 && $fromClient === null) {
                $project = Project::find($defaultId);
            }
        }

        if (! $project) {
            Log::warning('Asterisk: no project bound to number', ['dialed' => $data['dialed'], 'endpoint' => $endpoint]);
            return response()->json(['ok' => false, 'reason' => 'not_configured'], 404);
        }

        if ($numberConfig !== null && ! ($numberConfig['enabled'] ?? true)) {
            Log::info('Asterisk: dialed number is disabled', ['dialed' => $data['dialed'], 'project_id' => $project->id]);
            return response()->json(['ok' => false, 'reason' => 'disabled'], 403);
        }

        $session = $this->calls->start($project, $numberConfig, TelephonyNumbers::ASTERISK, $data['channel_id'], $caller, array_filter([
            'channel_id' => $data['channel_id'],
            'from'       => $caller,
            'to'         => $data['dialed'],
            'endpoint'   => $endpoint ?: null,
            'web_call'   => $web ? ['ip' => $web->ip, 'page' => $web->page_url] : null,
        ]));

        if ($web) {
            $web->session_id = $session->id;
            $web->save();
        }

        Log::info('Asterisk call started', [
            'project_id' => $project->id, 'session_id' => $session->id, 'dialed' => $data['dialed'],
            'web' => (bool) $web,
        ]);

        return response()->json(array_filter([
            'ok'          => true,
            'session_id'  => $session->id,
            'project_id'  => $project->id,
            'token'       => $this->tokens->mint($session),
            // The AI speaks first, in the routed agent's voice and language.
            'greeting'    => CallGreeting::for($project, $session),
            'transfer_to' => $this->transferTarget($project, data_get($session->metadata, 'routing.transfer_to')),
            // Website calls end politely on time (the voice engine says goodbye).
            'max_seconds' => $web ? (int) $this->webCalls->settings($project)['max_seconds'] : null,
        ], fn ($v) => $v !== null));
    }

    /** "c12_2001" → 12; null for anything that isn't a client extension. */
    private function extensionClient(string $endpoint): ?int
    {
        return preg_match('/^c(\d+)_\d+$/', $endpoint, $m) ? (int) $m[1] : null;
    }

    /**
     * A transfer to one of the client's own extensions dials its account
     * ("2001" → "c12_2001"); anything else goes to the dialplan as typed.
     */
    private function transferTarget(Project $project, ?string $target): ?string
    {
        if (! $target) {
            return null;
        }
        $username = SipExtension::usernameFor((int) $project->client_id, $target);

        return SipExtension::where('sip_username', $username)->exists() ? $username : $target;
    }
}
