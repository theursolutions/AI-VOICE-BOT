<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\AgentRouter;
use App\Services\Conversation\SessionTokenService;
use App\Services\Flow\FlowRunner;
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
        private FlowRunner $flowRunner,
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

        // Outbound demo call from the landing page. `To` is the visitor, so
        // the usual dialed-number lookup would match nothing (and fall
        // through to "first project"); the demo project is named in config
        // instead. Only trusted because this route sits behind Twilio's
        // request-signature check, which covers the query string.
        $resolved = $request->boolean('demo')
            ? $this->resolveDemoProject()
            : $this->resolveProjectForNumber($to);

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
                    'flow_id'      => $numberConfig['flow_id'] ?? null,
                ] : null,
            ],
            'created_at'       => $now,
            'update_at'        => $now,
        ]);

        // Pick the BotAgent for this call BEFORE any branch below can
        // return. Sets session.agent_id, session.skill_id and — the part
        // that matters here — session.voice_id, cloned from the agent.
        //
        // This used to run only on the Media-Stream path, *after* the Flow
        // branch had already returned. A number routed to a Conversation
        // Flow therefore kept voice_id = null for the whole call, so every
        // later step (including the transfer-to-AI hand-off) fell back to a
        // stock voice instead of the caller's cloned one.
        $this->router->assignToSession($project, $session);
        $session->refresh();

        // If this number is bound to a saved Flow, hand the call to the
        // FlowRunner instead of opening a Media Stream right away. The
        // flow walks the graph (IVR menus, Say nodes, etc.) and only
        // opens the WS stream when it hits a transfer_ai node.
        $flowId = (int) ($numberConfig['flow_id'] ?? 0);
        if ($flowId > 0) {
            $flow = Flow::where('id', $flowId)
                ->where('project_id', $project->id)
                ->where('status', Flow::STATUS_ACTIVE)
                ->first();
            if ($flow) {
                $meta = (array) ($session->metadata ?? []);
                $meta['flow'] = ['flow_id' => $flow->id, 'current_node_id' => null];
                $session->metadata = $meta;
                $session->save();

                $base = rtrim((string) config('services.twilio.webhook_base', ''), '/');
                $xml = $this->flowRunner->start($project, $session, $flow, $base);
                return $this->twimlResponse($xml);
            }
            // flow_id pointed at something that isn't active — fall
            // through to the legacy AI-stream path so the call still
            // works. Worth logging though.
            Log::warning('Telephony: flow_id set but flow not active', [
                'flow_id' => $flowId, 'project_id' => $project->id,
            ]);
        }

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

        // A demo call is OUTBOUND — the project's inbound greeting ("thanks
        // for calling") is simply untrue when we're the ones ringing. Use the
        // demo greeting instead, and tell them up front that the line cuts
        // off, so a hard hang-up mid-sentence doesn't read as a fault.
        if ($request->boolean('demo')) {
            $secs = max(5, (int) config('services.demo_call.max_seconds', 30));
            $welcomeText = (string) tva_setting(
                'content.demo_call_greeting',
                'Hi! This is the Serve AI demo agent calling you back from our website. '
                . 'Ask me anything about what we do — this test call lasts about ' . $secs . ' seconds.'
            );
        }

        // Try the cloned-voice welcome first — that's the "everything
        // in your voice" experience the user wants. The service lazily
        // synthesizes (and caches) the audio on first call after a
        // welcome-text or voice change. If anything goes wrong it
        // returns null and we fall back to Polly so the call still
        // works.
        // Pass the voice bound to THIS session (cloned from the routed agent)
        // so the greeting is in the same voice that answers afterwards. Without
        // it the service silently used the project default and the caller heard
        // two different voices in one call.
        $callVoice = $session->voice_id ? \App\Models\Voice::find($session->voice_id) : null;
        $welcomeAudioUrl = $this->welcome->urlFor($project, $welcomeText, $callVoice);

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

                // Meter the call. Twilio reports whole seconds in
                // `CallDuration` on the terminal status only, which is exactly
                // once per call — so this cannot double-count on a retry of an
                // earlier status. Rounded up to the minute in UsageRecorder,
                // because that is how the carrier bills us.
                //
                // Keyed off the SESSION's project, not the `Project::first()`
                // above — that lookup is a pre-existing single-tenant shortcut
                // (see the comment there) and would bill the wrong workspace
                // on a multi-tenant install.
                if ($session) {
                    app(\App\Services\Billing\UsageRecorder::class)->callCompleted(
                        (int) $session->project_id,
                        (int) $request->input('CallDuration', 0),
                    );
                }
            }
        }

        return response('', 204);
    }

    /**
     * POST /api/telephony/twilio/flow-step
     *
     * Twilio Gather (and our Redirect after a Say) callback. We look up
     * the in-flight session by CallSid, resume the flow at its last
     * known node, pick the branch matching the user input, and emit
     * TwiML for the next node.
     */
    public function flowStep(Request $request): Response
    {
        $callSid = $request->input('CallSid', '');
        if ($callSid === '') {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        // Find which project owns this in-flight call. Sessions are in
        // tenant DBs so we have to iterate until we find a hit.
        foreach (Project::all(['id', 'name', 'json_data']) as $p) {
            $this->tenants->useFor($p);
            $session = Session::where('external_id', $callSid)->first();
            if (!$session) continue;

            $flowId = (int) data_get($session->metadata, 'flow.flow_id', 0);
            if ($flowId <= 0) {
                Log::warning('flowStep: session has no flow bound', ['call_sid' => $callSid]);
                return $this->twimlResponse('<Response><Hangup/></Response>');
            }
            $flow = Flow::find($flowId);
            if (!$flow) {
                return $this->twimlResponse('<Response><Hangup/></Response>');
            }

            $input = [
                'Digits'       => (string) $request->input('Digits', ''),
                'SpeechResult' => (string) $request->input('SpeechResult', ''),
            ];

            $base = rtrim((string) config('services.twilio.webhook_base', ''), '/');
            $xml = $this->flowRunner->step($p, $session, $flow, $input, $base);
            return $this->twimlResponse($xml);
        }

        Log::warning('flowStep: no session found for CallSid', ['call_sid' => $callSid]);
        return $this->twimlResponse('<Response><Hangup/></Response>');
    }

    /**
     * POST /api/telephony/twilio/flow-handoff
     *
     * Called when FlowRunner reaches a transfer_ai node. We mint the
     * session JWT and return the <Connect><Stream> TwiML — same shape
     * voiceWebhook emits for non-flow calls. From this point on the
     * call is handled by the WebSocket AI pipeline.
     */
    public function flowHandoff(Request $request): Response
    {
        $sessionId = (int) $request->query('session_id', 0);
        if ($sessionId <= 0) {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        // Find the project that owns this session.
        $session = null;
        $project = null;
        foreach (Project::all(['id', 'name', 'json_data']) as $p) {
            $this->tenants->useFor($p);
            $s = Session::find($sessionId);
            if ($s) { $session = $s; $project = $p; break; }
        }
        if (!$session || !$project) {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        // FlowRunner may have set agent_id on the session before
        // redirecting here. If not, fall back to AgentRouter.
        if (empty($session->agent_id)) {
            $this->router->assignToSession($project, $session);
            $session->refresh();
        }

        $token = $this->tokens->mint($session);

        $wsBase = (string) config('services.python.ws_url');
        $phoneWs = preg_replace('#/ws/turn$#', '/ws/phone', $wsBase);
        $phoneWs = preg_replace('#^http(s?)://#', 'ws$1://', $phoneWs);

        $tokenXml = htmlspecialchars($token, ENT_XML1);
        $twiml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Connect>
        <Stream url="{$phoneWs}">
            <Parameter name="token" value="{$tokenXml}"/>
        </Stream>
    </Connect>
</Response>
XML;
        return $this->twimlResponse($twiml);
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
    /**
     * Project that answers landing-page demo calls. Named in config because
     * an outbound call has no dialed number of ours to route by.
     *
     * @return array{0:Project,1:null}|null
     */
    private function resolveDemoProject(): ?array
    {
        $id = (int) config('services.demo_call.project_id', 0);

        $project = $id > 0
            ? Project::find($id)
            : Project::orderBy('id')->first();

        if (! $project) {
            Log::warning('Telephony: demo call but no project available', ['configured_id' => $id]);
            return null;
        }

        if ($id > 0 && $project->id !== $id) {
            Log::warning('Telephony: DEMO_CALL_PROJECT_ID not found', ['configured_id' => $id]);
        }

        return [$project, null];
    }

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
