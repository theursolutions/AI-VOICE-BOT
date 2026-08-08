<?php

namespace App\Services\Flow;

use App\Models\Flow;
use App\Models\FlowAsset;
use App\Models\Project;
use App\Models\Session;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\Log;

/**
 * Conversation Flow runtime.
 *
 *   FlowRunner walks the saved graph node-by-node and emits TwiML at
 *   each step. The flow's progress is tracked via `session.metadata.flow`
 *   (current_node_id + visited counters) so callbacks can resume.
 *
 *   For DTMF-style menus Twilio's <Gather> callback model is perfect —
 *   each user input is a fresh HTTP webhook to /api/telephony/twilio/flow-step.
 *   For free-form AI conversation we use a `transfer_ai` node which
 *   returns the existing <Connect><Stream> TwiML and hands the call
 *   over to the WebSocket pipeline.
 *
 *   Phase 1C-MVP supports:  start · say · capture_dtmf · end · transfer_ai
 *   Coming next:           capture_speech · webhook · branch · wait · transfer_human
 */
class FlowRunner
{
    public const META_KEY = 'flow';

    public function __construct(
        private TenantManager $tenants,
        private \App\Services\Telephony\WelcomeAudioService $tts,
    ) {}

    /**
     * TwiML for one spoken line, in the caller's cloned voice when we can.
     *
     * Every node used to emit `<Say voice="Polly.X">`, so a flow-routed number
     * answered in a stock Amazon voice no matter which cloned voice the agent
     * had — the whole point of cloning was lost the moment a flow was attached
     * to the number.
     *
     * Synthesis is cached by (text + speaker), so a given prompt is generated
     * once and every later call is a file read. The timeout is deliberately
     * short: Twilio abandons a webhook that takes too long, so a cold cache
     * must degrade to Polly for this one call rather than drop it.
     */
    private function speak(Project $project, Session $session, string $text, string $polly, string $lang): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $voice = $session->voice_id ? \App\Models\Voice::find($session->voice_id) : null;
        $url   = $this->tts->urlFor($project, $text, $voice, self::TTS_TIMEOUT_SECS);

        if ($url) {
            return "  <Play>" . $this->xml($url) . "</Play>\n";
        }

        Log::info('FlowRunner: cloned-voice TTS unavailable, using Polly', [
            'project_id' => $project->id,
            'voice_id'   => $session->voice_id,
        ]);

        return "  <Say voice=\"{$polly}\" language=\"{$lang}\">" . $this->xml($text) . "</Say>\n";
    }

    /** Twilio drops a webhook that stalls; stay well inside that budget. */
    private const TTS_TIMEOUT_SECS = 10;

    /**
     * First entry — caller picks up. Find the start node, advance,
     * and emit TwiML for the first user-facing node.
     */
    public function start(Project $project, Session $session, Flow $flow, string $webhookBase): string
    {
        $this->tenants->useFor($project);

        $def = $this->definition($flow);
        $startId = $this->findStartNodeId($def);
        if (!$startId) {
            Log::warning('FlowRunner: flow has no start node', ['flow_id' => $flow->id]);
            return $this->endTwiML('Sorry, this flow is misconfigured. Goodbye.');
        }

        // Walk forward from the start node until we hit a node that
        // emits TwiML (say, capture_dtmf, end, transfer_ai). The start
        // node itself doesn't speak.
        $next = $this->advanceFrom($def, $startId, 'out');
        return $this->renderNode($project, $session, $flow, $def, $next, $webhookBase);
    }

    /**
     * A user just hit our Gather callback. Resume from session metadata,
     * pick the right branch for the input, and emit the next TwiML.
     *
     * @param  array  $input  ['Digits' => '1'] or ['SpeechResult' => '...']
     */
    public function step(Project $project, Session $session, Flow $flow, array $input, string $webhookBase): string
    {
        $this->tenants->useFor($project);
        $def = $this->definition($flow);

        $meta = (array) ($session->metadata ?? []);
        $current = (string) ($meta[self::META_KEY]['current_node_id'] ?? '');
        if ($current === '') {
            // Lost the thread — fall back to walking from start.
            return $this->start($project, $session, $flow, $webhookBase);
        }
        $node = $this->findNode($def, $current);
        if (!$node) {
            return $this->endTwiML('Lost the call flow. Goodbye.');
        }

        // Figure out which output handle the user's input takes.
        $branch = $this->pickBranch($node, $input);
        $nextId = $this->advanceFrom($def, $node['id'], $branch);
        return $this->renderNode($project, $session, $flow, $def, $nextId, $webhookBase);
    }

    // ── Per-node TwiML renderers ──────────────────────────────────────

    private function renderNode(Project $project, Session $session, Flow $flow, array $def, ?string $nodeId, string $webhookBase): string
    {
        if (!$nodeId) {
            return $this->endTwiML('Goodbye.');
        }
        $node = $this->findNode($def, $nodeId);
        if (!$node) {
            return $this->endTwiML('Goodbye.');
        }

        // Remember where we are so the next webhook callback can resume.
        $this->recordCurrent($session, $node['id']);

        $type = $node['type'] ?? 'end';
        $data = $node['data'] ?? [];
        $lang = $this->resolveLanguage($flow, $def, $data);
        $polly = $this->pollyVoiceForLang($lang);

        switch ($type) {
            case 'say':       return $this->renderSay($project, $session, $node, $def, $lang, $polly, $webhookBase);
            case 'capture_dtmf':
                              return $this->renderCaptureDtmf($project, $node, $def, $lang, $polly, $webhookBase, $session);
            case 'transfer_ai':
                              return $this->renderTransferAi($project, $session, $node);
            case 'end':       return $this->endTwiML($data['message'] ?? 'Goodbye.', $polly, $lang, $project, $session);
            default:
                // Unsupported node type (capture_speech, webhook, branch
                // etc. land here until we add their renderers). Fail
                // safely — hang up so the caller isn't stuck.
                Log::warning('FlowRunner: unsupported node type', ['type' => $type, 'flow_id' => $flow->id]);
                return $this->endTwiML('This flow step isn\'t available yet. Goodbye.', $polly, $lang);
        }
    }

    private function renderSay(Project $project, Session $session, array $node, array $def, string $lang, string $polly, string $webhookBase): string
    {
        $data = $node['data'] ?? [];
        // After speaking we need to advance — Twilio doesn't auto-call
        // back after a <Say>. So wrap in a redirect to /flow-step with
        // no input — the runner advances on its own to the next node.
        $afterUrl = $this->stepUrl($webhookBase, $node['id'], 'auto');

        if (($data['source'] ?? 'tts') === 'audio' && !empty($data['audio_asset_id'])) {
            $asset = FlowAsset::find((int) $data['audio_asset_id']);
            if ($asset && $asset->storage_path) {
                $publicUrl = $this->assetUrl($asset->storage_path);
                return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                    . "<Response>\n"
                    . "  <Play>" . $this->xml($publicUrl) . "</Play>\n"
                    . "  <Redirect method=\"POST\">" . $this->xml($afterUrl) . "</Redirect>\n"
                    . "</Response>";
            }
            // Fall through to TTS if asset is missing
        }

        // Cloned voice when we have one, Polly only as a fallback.
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<Response>\n"
            . $this->speak($project, $session, (string) ($data['text'] ?? ''), $polly, $lang)
            . "  <Redirect method=\"POST\">" . $this->xml($afterUrl) . "</Redirect>\n"
            . "</Response>";
    }

    private function renderCaptureDtmf(Project $project, array $node, array $def, string $lang, string $polly, string $webhookBase, Session $session): string
    {
        $data = $node['data'] ?? [];
        $timeout = (int) ($data['timeout_secs'] ?? 8);
        $maxDigits = (int) ($data['max_digits'] ?? 1);
        $action = $this->stepUrl($webhookBase, $node['id'], 'dtmf');

        $promptXml = '';
        if (($data['prompt_source'] ?? 'tts') === 'audio' && !empty($data['prompt_audio_asset_id'])) {
            $asset = FlowAsset::find((int) $data['prompt_audio_asset_id']);
            if ($asset) {
                $publicUrl = $this->assetUrl($asset->storage_path);
                $promptXml = "    <Play>" . $this->xml($publicUrl) . "</Play>\n";
            }
        }
        if ($promptXml === '') {
            // speak() indents by two; menu prompts sit inside <Gather>.
            $promptXml = '  ' . $this->speak($project, $session, (string) ($data['prompt'] ?? ''), $polly, $lang);
        }

        // On timeout (no Digits in callback) Twilio still POSTs back —
        // FlowRunner::step detects empty input and routes to the
        // "timeout" branch.
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<Response>\n"
            . "  <Gather input=\"dtmf\" numDigits=\"{$maxDigits}\" timeout=\"{$timeout}\" "
            .         "action=\"" . $this->xml($action) . "\" method=\"POST\">\n"
            . $promptXml
            . "  </Gather>\n"
            // If <Gather> times out and the user said nothing, Twilio
            // falls through to the next verb — Redirect back to step
            // with no input → routes to the timeout branch.
            . "  <Redirect method=\"POST\">" . $this->xml($action) . "</Redirect>\n"
            . "</Response>";
    }

    /**
     * Hands the call off to the existing AI agent system. Same TwiML
     * shape the regular voiceWebhook returns — <Connect><Stream>
     * pointing at Python's /ws/phone with a JWT.
     *
     * We don't mint the JWT here (FlowRunner doesn't have the token
     * service injected); instead we redirect to a small "handoff"
     * endpoint that handles minting + returns the connect TwiML.
     * Keeps the dependency tree simple.
     */
    private function renderTransferAi(Project $project, Session $session, array $node): string
    {
        $data = $node['data'] ?? [];
        // Stash override on the session so the existing AgentRouter +
        // SessionTokenService can pick it up on the handoff.
        if (!empty($data['agent_id'])) {
            $session->agent_id = (int) $data['agent_id'];
        }
        if (!empty($data['persona_override'])) {
            $meta = (array) ($session->metadata ?? []);
            $meta['persona_override'] = $data['persona_override'];
            $session->metadata = $meta;
        }
        $session->save();

        // Redirect to the normal voice webhook with a continue flag —
        // that re-builds the <Connect><Stream> TwiML. Avoids duplicating
        // the JWT minting logic here.
        $base = rtrim((string) config('services.twilio.webhook_base', ''), '/');
        $continueUrl = "{$base}/api/telephony/twilio/flow-handoff?session_id={$session->id}";
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<Response>\n"
            . "  <Redirect method=\"POST\">" . $this->xml($continueUrl) . "</Redirect>\n"
            . "</Response>";
    }

    /**
     * $project/$session are optional because several call sites are error
     * paths reached before a session is in hand (misconfigured flow, unknown
     * node). Those keep the Polly sign-off; the normal "end" node passes both
     * and hangs up in the caller's own voice like the rest of the flow.
     */
    private function endTwiML(
        string $msg,
        string $polly = 'Polly.Joanna',
        string $lang = 'en-US',
        ?Project $project = null,
        ?Session $session = null,
    ): string {
        $line = ($project && $session)
            ? $this->speak($project, $session, $msg, $polly, $lang)
            : "  <Say voice=\"{$polly}\" language=\"{$lang}\">" . $this->xml($msg) . "</Say>\n";

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<Response>\n"
            . $line
            . "  <Hangup/>\n"
            . "</Response>";
    }

    // ── Graph helpers ────────────────────────────────────────────────

    private function definition(Flow $flow): array
    {
        $def = $flow->definition ?? [];
        return is_array($def) ? $def : [];
    }

    private function findNode(array $def, string $id): ?array
    {
        foreach (($def['nodes'] ?? []) as $n) {
            if (($n['id'] ?? null) === $id) return $n;
        }
        return null;
    }

    private function findStartNodeId(array $def): ?string
    {
        foreach (($def['nodes'] ?? []) as $n) {
            if (($n['type'] ?? '') === 'start') return $n['id'] ?? null;
        }
        return null;
    }

    /**
     * Follow the edge from $fromNodeId on the given $handle. Returns
     * the target node id, or null if no edge exists for that branch.
     */
    private function advanceFrom(array $def, string $fromNodeId, string $handle): ?string
    {
        foreach (($def['edges'] ?? []) as $e) {
            if (($e['source'] ?? null) !== $fromNodeId) continue;
            $h = $e['sourceHandle'] ?? 'out';
            if ($h !== $handle) continue;
            return $e['target'] ?? null;
        }
        return null;
    }

    /**
     * For a node with multiple outputs, decide which handle the
     * user's input took. Maps Twilio's Digits/SpeechResult to a
     * sourceHandle id on the current node.
     */
    private function pickBranch(array $node, array $input): string
    {
        $type = $node['type'] ?? '';

        if ($type === 'capture_dtmf') {
            $digits = trim((string) ($input['Digits'] ?? ''));
            return $digits !== '' ? $digits : 'timeout';
        }
        // Say's redirect comes back through with no input → just take
        // its single "out" branch and walk forward.
        return 'out';
    }

    private function recordCurrent(Session $session, string $nodeId): void
    {
        $meta = (array) ($session->metadata ?? []);
        $meta[self::META_KEY] = array_merge(
            (array) ($meta[self::META_KEY] ?? []),
            ['current_node_id' => $nodeId]
        );
        $session->metadata = $meta;
        $session->save();
    }

    // ── Language / voice resolution ──────────────────────────────────

    private function resolveLanguage(Flow $flow, array $def, array $nodeData): string
    {
        $lang = trim((string) ($nodeData['language'] ?? ''));
        if ($lang === '') $lang = (string) data_get($def, 'settings.language', '');
        if ($lang === '') $lang = (string) ($flow->language ?: 'en');
        // Map our short code to Polly's BCP-47-ish form.
        return [
            'en' => 'en-US', 'es' => 'es-ES', 'fr' => 'fr-FR',
            'de' => 'de-DE', 'pt' => 'pt-BR', 'ar' => 'arb',
            'hi' => 'hi-IN', 'ur' => 'hi-IN',  // no native Polly Urdu — Hindi is closest
            'zh' => 'cmn-CN',
        ][$lang] ?? $lang;
    }

    private function pollyVoiceForLang(string $bcp): string
    {
        return [
            'en-US'  => 'Polly.Joanna',
            'es-ES'  => 'Polly.Lucia',
            'fr-FR'  => 'Polly.Lea',
            'de-DE'  => 'Polly.Vicki',
            'pt-BR'  => 'Polly.Camila',
            'arb'    => 'Polly.Zeina',
            'hi-IN'  => 'Polly.Aditi',
            'cmn-CN' => 'Polly.Zhiyu',
        ][$bcp] ?? 'Polly.Joanna';
    }

    // ── Misc helpers ─────────────────────────────────────────────────

    private function stepUrl(string $webhookBase, string $fromNodeId, string $reason): string
    {
        $base = rtrim($webhookBase, '/');
        return "{$base}/api/telephony/twilio/flow-step?from=" . urlencode($fromNodeId) . "&r=" . urlencode($reason);
    }

    private function assetUrl(string $storagePath): string
    {
        // Assets live under public/storage/flow-assets/... — served via
        // the Laravel storage symlink. Use the public ngrok URL so
        // Twilio can fetch them.
        $base = rtrim((string) config('services.twilio.python_public_url')
            ?: config('services.twilio.webhook_base', ''), '/');
        return $base . '/storage/' . ltrim($storagePath, '/');
    }

    private function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
