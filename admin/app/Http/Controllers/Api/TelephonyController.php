<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\AgentRouter;
use App\Services\Conversation\SessionTokenService;
use App\Services\Telephony\WelcomeAudioService;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Twilio voice-call webhook entrypoints.
 *
 * Twilio is configured with a single URL per project — when a caller
 * dials our Twilio number, Twilio POSTs to /api/telephony/twilio/voice
 * and we reply with TwiML that opens a Media Stream WebSocket to the
 * Python voice-engine. The WebSocket carries raw μ-law 8 kHz audio
 * both ways for the duration of the call.
 *
 *   Twilio caller → /voice (POST)
 *      ↓ returns TwiML <Connect><Stream url="wss://.../ws/phone?token=JWT" />
 *   Twilio opens WS → Python /ws/phone
 *      ↓ STT / LLM / TTS pipeline (reused from /ws/turn)
 *   Stream frames flow both ways until call ends
 */
class TelephonyController extends Controller
{
    public function __construct(
        private SessionTokenService $tokens,
        private TenantManager $tenants,
        private WelcomeAudioService $welcome,
        private AgentRouter $router,
    ) {}

    /**
     * POST /api/telephony/twilio/voice
     *
     * Twilio call-comes-in webhook. Twilio sends form-encoded params:
     *   CallSid, From, To, Direction, CallerCountry, etc.
     *
     * We:
     *   1. Find the project whose phone number matches the dialed To
     *      (so multiple projects can share one Twilio account later).
     *      For the trial single-number setup we just use the first
     *      project that has telephony enabled, or fall back to env.
     *   2. Mint a Session row (channel = 'phone') and a JWT.
     *   3. Return TwiML that opens a media stream WS to Python with
     *      the JWT in the URL.
     */
    public function voiceWebhook(Request $request): Response
    {
        $from    = $request->input('From', '');
        $to      = $request->input('To', '');
        $callSid = $request->input('CallSid', '');

        Log::info('Twilio voice webhook', [
            'from' => $from, 'to' => $to, 'call_sid' => $callSid,
        ]);

        $resolved = $this->resolveProjectForNumber($to);
        if (!$resolved) {
            Log::warning('Twilio: no project bound to number', ['to' => $to]);
            return $this->twimlResponse(
                '<Response><Say voice="alice">Sorry, this number is not configured.</Say><Hangup/></Response>'
            );
        }
        [$project, $numberConfig] = $resolved;

        // Refuse calls to numbers that exist but have been disabled in
        // the admin UI. Skip the check if no per-number config (legacy
        // single-number setup) is in play.
        if ($numberConfig !== null && !($numberConfig['enabled'] ?? true)) {
            Log::info('Twilio: dialed number is disabled', ['to' => $to, 'project_id' => $project->id]);
            return $this->twimlResponse(
                '<Response><Say voice="alice">This line is not currently in service. Goodbye.</Say><Hangup/></Response>'
            );
        }

        // Switch to the project's tenant DB — Session/Message/Lead all
        // live in the per-project tenant DB, not the master. Without
        // this the insert below fails with a connection / table error.
        $this->tenants->useFor($project);

        // Create the session up-front so transcripts + lead extraction
        // can hang off it. Reuses the same `sessions` table as the
        // widget; channel = "phone" distinguishes telephony.
        $now = time();
        $session = Session::create([
            'project_id'       => $project->id,
            'channel'          => 'phone',
            'external_id'      => $callSid,
            'customer_name'    => null,
            'customer_phone'   => $from,
            'voice_id'         => null,
            'status'           => 'active',
            'started_at'       => $now,
            'last_activity_at' => $now,
            'metadata'         => [
                'twilio' => [
                    'call_sid' => $callSid,
                    'from'     => $from,
                    'to'       => $to,
                ],
                // Carry the per-number routing config forward so the
                // session-token + memory-builder can pick the agent
                // (or skill pool) the admin assigned to this number.
                'routing' => $numberConfig ? [
                    'routing_type' => $numberConfig['routing_type'] ?? 'agents',
                    'agent_ids'    => (array) ($numberConfig['agent_ids'] ?? []),
                    'skill_id'     => $numberConfig['skill_id'] ?? null,
                ] : null,
            ],
            'created_at'       => $now,
            'update_at'        => $now,
        ]);

        // Pick the BotAgent that should handle this call based on the
        // per-number routing config (or fall back to project default).
        // Sets session.agent_id, session.skill_id (if skill-routed),
        // and session.voice_id (cloned from the agent), so the JWT
        // minter picks up the right cloned voice.
        $this->router->assignToSession($project, $session);
        $session->refresh();

        $token = $this->tokens->mint($session);

        // Twilio Media Streams require wss:// URLs. Build the WS URL
        // by swapping the scheme on the python ws_url config.
        $wsBase = (string) config('services.python.ws_url');
        $phoneWs = preg_replace('#/ws/turn$#', '/ws/phone', $wsBase);
        // Twilio requires wss://, not ws://. Translate if needed for
        // public deployments. For local ngrok dev the user supplies a
        // wss:// URL on the python WS already.
        $phoneWs = preg_replace('#^http(s?)://#', 'ws$1://', $phoneWs);

        // IMPORTANT: Twilio Media Streams STRIPS query-string params
        // from the <Stream url="...">. The only reliable way to pass
        // our JWT to Python is via <Parameter> children; they arrive
        // in the `start.customParameters` field of the start frame.
        $streamUrl = $phoneWs;

        $welcomeText = (string) ($project->json_data['widget']['welcome_message']
            ?? 'Hi! Thanks for calling. One moment please.');

        // Try the cloned-voice welcome first — that's the "everything
        // in your voice" experience the user wants. The service lazily
        // synthesizes (and caches) the audio on first call after a
        // welcome-text or voice change. If anything goes wrong it
        // returns null and we fall back to Polly so the call still
        // works.
        $welcomeAudioUrl = $this->welcome->urlForProject($project, $welcomeText);

        $tokenXml = htmlspecialchars($token, ENT_XML1);

        if ($welcomeAudioUrl) {
            $playUrl = htmlspecialchars($welcomeAudioUrl, ENT_XML1);
            $twiml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Play>{$playUrl}</Play>
    <Connect>
        <Stream url="{$streamUrl}">
            <Parameter name="token" value="{$tokenXml}"/>
        </Stream>
    </Connect>
</Response>
XML;
        } else {
            // Fallback — keeps phone calls working even if Coqui is
            // down or no voice is configured. Polly voice configurable
            // via widget settings (telephony_welcome_voice).
            $welcomeXml = htmlspecialchars($welcomeText, ENT_XML1);
            // Per-number Polly voice wins; fall back to widget-wide setting.
            $pollyVoice = (string) (
                ($numberConfig['welcome_voice'] ?? null)
                ?: ($project->json_data['widget']['telephony_welcome_voice'] ?? 'Polly.Matthew')
            );
            $twiml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say voice="{$pollyVoice}" language="en-US">{$welcomeXml}</Say>
    <Connect>
        <Stream url="{$streamUrl}">
            <Parameter name="token" value="{$tokenXml}"/>
        </Stream>
    </Connect>
</Response>
XML;
        }

        return $this->twimlResponse($twiml);
    }

    /**
     * POST /api/telephony/twilio/status
     *
     * Twilio call-status webhook. Fires for ringing/answered/completed
     * etc. We use the completed event to mark the session ended.
     */
    public function statusWebhook(Request $request): Response
    {
        $callSid    = $request->input('CallSid', '');
        $callStatus = $request->input('CallStatus', '');

        Log::info('Twilio status webhook', [
            'call_sid' => $callSid, 'status' => $callStatus,
        ]);

        if (in_array($callStatus, ['completed', 'failed', 'busy', 'no-answer', 'canceled'], true)) {
            // Find the session by external_id and mark ended.
            // (Tenant connection is set in the voice webhook, but here
            // we don't know the project yet — iterate by external_id
            // across all tenants if we have multiple. Single-tenant
            // dev setup: just look in the first tenant.)
            $project = Project::first();
            if ($project) {
                app(\App\Services\Tenant\TenantManager::class)->useFor($project);
                $session = Session::where('external_id', $callSid)->first();
                if ($session && $session->status === 'active') {
                    $session->status   = 'ended';
                    $session->ended_at = time();
                    $session->save();
                }
            }
        }

        return response('', 204);
    }

    /**
     * Find which project + per-number config owns the dialed Twilio number.
     *
     * Storage (new): projects.json_data.telephony.numbers[] — each entry
     * has phone_number + routing_type + agent_ids/skill_id + enabled
     * + welcome_voice. Admins manage these via /c/{client}/telephony.
     *
     * Backward-compat: also matches the legacy single
     * projects.json_data.telephony.phone_number field so old projects
     * keep working until someone re-saves them through the new UI.
     *
     * Returns [Project, ?numberConfigArray] or null if nothing matches
     * and no fallback is appropriate. The numberConfig is null for
     * legacy single-field matches (no routing info available).
     */
    private function resolveProjectForNumber(string $number): ?array
    {
        $number = trim($number);

        foreach (Project::all(['id', 'name', 'json_data']) as $p) {
            // 1) New shape — walk the numbers[] array.
            $numbers = (array) data_get($p->json_data, 'telephony.numbers', []);
            foreach ($numbers as $n) {
                if (trim((string) ($n['phone_number'] ?? '')) === $number) {
                    return [$p, $n];
                }
            }

            // 2) Legacy shape — single phone_number string.
            $legacy = data_get($p->json_data, 'telephony.phone_number');
            if ($legacy && trim((string) $legacy) === $number) {
                return [$p, null];
            }
        }

        // 3) Env-default fallback (dev mode): if no project owns the
        //    dialed number but it matches TWILIO_PHONE_NUMBER, route to
        //    the first project. Keeps single-tenant local testing alive.
        $envDefault = trim((string) config('services.twilio.phone_number', ''));
        if ($envDefault && $envDefault === $number) {
            $p = Project::orderBy('id')->first();
            return $p ? [$p, null] : null;
        }

        // 4) Last resort — first project so trial callers don't hear
        //    "this number is not configured" right after sign-up. Real
        //    deploys should always hit step 1.
        $p = Project::orderBy('id')->first();
        return $p ? [$p, null] : null;
    }

    /**
     * GET /api/telephony/twilio/diagnose
     *
     * Pre-flight check that never makes an actual call. Verifies:
     *   1. Env vars are populated
     *   2. Auth creds work — hits Twilio's REST API /Accounts/{SID}.json
     *   3. The configured phone number exists in this account
     *   4. The phone number's webhook URLs are set + pointing at us
     *
     * Run before testing a real call to catch misconfig without burning
     * trial credit. Auth-protected — only call from your own machine.
     */
    public function diagnose(Request $request)
    {
        $sid    = (string) config('services.twilio.account_sid');
        $token  = (string) config('services.twilio.auth_token');
        $number = (string) config('services.twilio.phone_number');
        $base   = (string) config('services.twilio.webhook_base');

        $checks = [];

        // 1) env populated
        $checks['env'] = [
            'account_sid'  => $sid ? '✓ set ('.substr($sid, 0, 8).'…)' : '✗ missing',
            'auth_token'   => $token ? '✓ set' : '✗ missing',
            'phone_number' => $number ?: '✗ missing',
            'webhook_base' => $base ?: '✗ missing (set TWILIO_WEBHOOK_BASE to your ngrok URL)',
        ];

        if (!$sid || !$token) {
            return response()->json([
                'ok'     => false,
                'reason' => 'Twilio creds not set',
                'checks' => $checks,
            ], 422);
        }

        // 2) Hit Twilio API /Accounts/{SID}.json
        $accountUrl = "https://api.twilio.com/2010-04-01/Accounts/{$sid}.json";
        $accountResp = $this->twilioApiGet($accountUrl, $sid, $token);
        $checks['auth'] = $accountResp['ok']
            ? '✓ Twilio auth ok — account: '.($accountResp['body']['friendly_name'] ?? 'unknown').' ('.($accountResp['body']['status'] ?? '').')'
            : '✗ Twilio auth failed: HTTP '.$accountResp['code'].' — '.($accountResp['body']['message'] ?? 'unknown');

        if (!$accountResp['ok']) {
            return response()->json([
                'ok'     => false,
                'reason' => 'Twilio auth rejected — check ACCOUNT_SID / AUTH_TOKEN',
                'checks' => $checks,
            ], 422);
        }

        // 3) Phone number exists on this account?
        $numbersUrl = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/IncomingPhoneNumbers.json";
        $numbersResp = $this->twilioApiGet($numbersUrl, $sid, $token);
        $foundNumber = null;
        if ($numbersResp['ok']) {
            foreach ($numbersResp['body']['incoming_phone_numbers'] ?? [] as $n) {
                if (($n['phone_number'] ?? '') === $number) {
                    $foundNumber = $n;
                    break;
                }
            }
        }
        $checks['phone_number'] = $foundNumber
            ? '✓ found in account · capabilities: '.implode(', ', array_keys(array_filter($foundNumber['capabilities'] ?? [])))
            : '✗ '.$number.' is NOT in this Twilio account';

        // 4) Webhook URLs configured to point at us?
        if ($foundNumber) {
            $expectedVoice  = rtrim($base, '/') . '/api/telephony/twilio/voice';
            $expectedStatus = rtrim($base, '/') . '/api/telephony/twilio/status';
            $actualVoice    = $foundNumber['voice_url']  ?? '';
            $actualStatus   = $foundNumber['status_callback'] ?? '';

            $checks['webhook_voice'] = $actualVoice === $expectedVoice
                ? '✓ voice webhook matches: '.$actualVoice
                : '⚠ voice webhook = "'.$actualVoice.'" (expected "'.$expectedVoice.'")';

            $checks['webhook_status'] = $actualStatus === $expectedStatus
                ? '✓ status callback matches: '.$actualStatus
                : '⚠ status callback = "'.$actualStatus.'" (expected "'.$expectedStatus.'")';
        }

        return response()->json([
            'ok'     => $foundNumber !== null,
            'checks' => $checks,
        ]);
    }

    private function twilioApiGet(string $url, string $sid, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $sid . ':' . $token,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [
            'ok'   => $err === '' && $code >= 200 && $code < 300,
            'code' => $code,
            'body' => $body ? (json_decode($body, true) ?: []) : ['error' => $err],
        ];
    }

    private function twimlResponse(string $xml): Response
    {
        return response($xml, 200, [
            'Content-Type' => 'text/xml; charset=utf-8',
        ]);
    }
}
