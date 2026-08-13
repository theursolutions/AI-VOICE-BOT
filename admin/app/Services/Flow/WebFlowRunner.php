<?php

namespace App\Services\Flow;

use App\Models\Flow;
use App\Models\FlowAsset;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\SessionTokenService;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\GraphClient;

/**
 * Webchat counterpart to FlowRunner.
 *
 * Same graph as the phone runtime — different output protocol. Instead
 * of emitting TwiML XML, this returns a JSON envelope the widget can
 * render: text bubbles, menu cards with quick-reply buttons, and at
 * `transfer_ai` a `handoff` block telling the widget to open the
 * normal Python WS for free-form AI.
 *
 * Key economy of flows (the customer's actual reason for using them):
 *   - say nodes are AUTHORED text → zero LLM cost at runtime.
 *   - menu choices are deterministic edges → zero LLM cost.
 *   - LLM tokens only start burning when transfer_ai fires.
 * The runner emits a `cost_avoided` counter on each step so admins can
 * see savings in the logs.
 *
 * Protocol shape returned by start() and step():
 *
 *   {
 *     "messages": [
 *       { "kind": "text", "text": "Hello! …", "audio_url": null|"…" },
 *       { "kind": "menu", "prompt": "Pick one:",
 *         "options": [
 *           { "id": "1", "label": "Billing" },
 *           { "id": "2", "label": "Orders"  }
 *         ]
 *       }
 *     ],
 *     "expecting": "menu_choice" | "free_text" | "none",
 *     "current_node_id": "n_menu",
 *     "handoff": null | { "ws_url": "wss://…", "token": "JWT", "session_id": 42 },
 *     "ended": false,
 *     "cost_avoided": 1                                // LLM turns we just skipped
 *   }
 */
class WebFlowRunner
{
    public const META_KEY = 'flow';
    public const MAX_HOPS = 20;   // safety guard against runaway loops in malformed graphs

    public function __construct(
        private TenantManager $tenants,
        private SessionTokenService $tokens,
    ) {}

    /**
     * Open a flow for a fresh session. Walks from the Start node and
     * collects every node up to the first one that needs user input
     * (capture_dtmf, capture_speech) OR a terminal node (end,
     * transfer_ai). All Say nodes in between contribute messages.
     */
    public function start(Project $project, Session $session, Flow $flow): array
    {
        $this->tenants->useFor($project);
        $def = $this->definition($flow);

        $startId = $this->findStartNodeId($def);
        if (!$startId) {
            return $this->failed('Flow has no start node.', $session);
        }

        // From Start, the first real node is whatever is wired to its
        // single "out" handle. Walk forward from there.
        $firstId = $this->advanceFrom($def, $startId, 'out');
        return $this->walk($project, $session, $flow, $def, $firstId, /*hops*/0, /*avoided*/0);
    }

    /**
     * User responded — either a menu_choice (clicked a quick-reply
     * button) or free_text (typed into the chat). Resume from the
     * stored current_node_id, branch on the input, walk forward.
     */
    public function step(Project $project, Session $session, Flow $flow, array $input): array
    {
        $this->tenants->useFor($project);
        $def = $this->definition($flow);

        $current = (string) data_get($session->metadata, self::META_KEY . '.current_node_id', '');
        if ($current === '') {
            // Lost the thread — restart from the top rather than 500.
            return $this->start($project, $session, $flow);
        }

        $node = $this->findNode($def, $current);
        if (!$node) {
            return $this->failed('Current node missing from flow.', $session);
        }

        // Persist the user's choice/text as a Message row so the admin
        // sees the full transcript later. For menu choices we record
        // the human-readable button label (not the bare "1") so the
        // log reads naturally.
        $this->recordUserChoice($project, $session, $node, $input);

        // collect_input may gather several fields in one node — it has its
        // own handler that asks the next field or branches when all are done.
        if (($node['type'] ?? '') === 'collect_input') {
            return $this->stepCollectInput($project, $session, $flow, $def, $node, $input);
        }

        $branch = $this->pickBranch($node, $input);
        $nextId = $this->advanceFrom($def, $node['id'], $branch);
        return $this->walk($project, $session, $flow, $def, $nextId, 0, 0);
    }

    // ── Core walker ──────────────────────────────────────────────────

    /**
     * Walk from $startId, accumulating messages until we land on a node
     * that needs user input or terminates the flow.
     */
    private function walk(Project $project, Session $session, Flow $flow, array $def, ?string $startId, int $hops, int $avoided): array
    {
        $messages = [];
        $execution_path = [];   // ordered ids of every node we land on
        $nodeId = $startId;
        $expecting = 'none';
        $handoff = null;
        $ended = false;
        $lastNodeId = $nodeId;

        while ($nodeId && $hops < self::MAX_HOPS) {
            $hops++;
            $node = $this->findNode($def, $nodeId);
            if (!$node) break;
            $lastNodeId = $nodeId;
            $execution_path[] = $nodeId;     // record visit order

            $type = $node['type'] ?? '';
            $data = $node['data'] ?? [];

            switch ($type) {

                case 'say': {
                    $messages[] = $this->renderSayMessage($flow, $def, $data, $session, $project);
                    $avoided++;
                    $nodeId = $this->advanceFrom($def, $node['id'], 'out');
                    break;
                }

                case 'capture_dtmf': {
                    $menu = $this->renderMenuMessage($flow, $def, $node, $data, $session, $project);
                    if ($menu === null) {
                        // No labeled outputs (all empty) — skip the node
                        // entirely so the flow falls through to its
                        // timeout edge. Avoids dead-ending in webchat
                        // when the customer only labeled the phone path.
                        $nodeId = $this->advanceFrom($def, $node['id'], 'timeout');
                        break;
                    }
                    $messages[] = $menu;
                    $expecting = 'menu_choice';
                    $nodeId = null;     // halt — wait for the user
                    $avoided++;
                    break;
                }

                case 'capture_speech': {
                    if (!empty($data['prompt'])) {
                        $messages[] = $this->renderSayMessage($flow, $def, [
                            'text'           => $data['prompt'],
                            'audio_asset_id' => $data['prompt_audio_asset_id'] ?? null,
                            'language'       => $data['language'] ?? '',
                        ], $session, $project);
                    }
                    $expecting = 'free_text';
                    $nodeId = null;
                    $avoided++;
                    break;
                }

                case 'transfer_ai': {
                    // Bind any per-node agent override, then mint a fresh
                    // JWT and emit a handoff block so the widget opens
                    // the existing Python WS for free-form mode.
                    if (!empty($data['agent_id'])) {
                        $session->agent_id = (int) $data['agent_id'];
                    }
                    if (!empty($data['persona_override'])) {
                        $meta = (array) ($session->metadata ?? []);
                        $meta['persona_override'] = $data['persona_override'];
                        $session->metadata = $meta;
                    }
                    // Wipe the current_node marker so future turns go to
                    // the free-form AI path, not back into the flow.
                    $this->clearFlowState($session);
                    $session->save();

                    $token = $this->tokens->mint($session);
                    $wsUrl = $this->wsTurnUrl();
                    $handoff = [
                        'ws_url'     => $wsUrl,
                        'token'      => $token,
                        'session_id' => $session->id,
                    ];
                    $nodeId = null;
                    break;
                }

                case 'datasource': {
                    // Pin the conversation to specific data source(s) so the
                    // AI references ONLY those for the rest of this branch
                    // (e.g. customer-support KB). Empty list = clear the scope
                    // (back to automatic routing across all sources). Silent
                    // pass-through node — emits no message.
                    $ids = array_values(array_filter(array_map(
                        'intval',
                        (array) ($data['source_ids'] ?? []),
                    )));
                    $meta = (array) ($session->metadata ?? []);
                    $meta['ds_scope'] = $ids;
                    $session->metadata = $meta;
                    $session->save();
                    $nodeId = $this->advanceFrom($def, $node['id'], 'out');
                    break;
                }

                case 'collect_input': {
                    // Multi-field: ask the CURRENT field (by stored cursor),
                    // halt for the reply. Capture + advance happens in
                    // stepCollectInput on the next turn.
                    $fields = $this->collectFields($node);
                    $idx = (int) data_get($session->metadata, 'collect_progress.' . $node['id'], 0);
                    if ($idx >= count($fields)) {
                        $nodeId = $this->advanceFrom($def, $node['id'], 'collected');
                        break;
                    }
                    $f = $fields[$idx];
                    // Persist the question to the transcript, then emit an
                    // 'input' message so the widget renders a proper typed
                    // field (text/tel/email/number) instead of a plain bubble.
                    if ($f['prompt'] !== '') {
                        $this->saveMessage($project, $session, 'assistant', $f['prompt'], null, [
                            'source' => 'flow', 'flow_id' => $flow->id,
                        ]);
                    }
                    $messages[] = [
                        'kind'       => 'input',
                        'prompt'     => $f['prompt'],
                        'input_type' => $f['input_type'],
                        'field_key'  => $f['key'],
                        'audio_url'  => null,
                    ];
                    $expecting = 'free_text';   // value is submitted as {text}
                    $nodeId = null;   // halt — capture happens on the next turn
                    $avoided++;
                    break;
                }

                case 'send_channel': {
                    // Send a message (text / media / catalogue / template) to a
                    // recipient via the project's onboarded WhatsApp/Messenger/
                    // IG account. Branches sent/error. Not a halting node.
                    $branch = 'error';
                    try {
                        $branch = $this->sendChannel($project, $session, $data) ? 'sent' : 'error';
                    } catch (\Throwable $e) {
                        Log::warning('WebFlowRunner: send_channel failed', [
                            'flow_id' => $flow->id, 'error' => $e->getMessage(),
                        ]);
                    }
                    $nodeId = $this->advanceFrom($def, $node['id'], $branch);
                    break;
                }

                case 'end': {
                    $messages[] = $this->renderSayMessage($flow, $def, [
                        'text'           => $data['message'] ?? 'Goodbye!',
                        'audio_asset_id' => null,
                        'language'       => $data['language'] ?? '',
                    ], $session, $project);
                    $ended = true;
                    $avoided++;
                    $nodeId = null;
                    break;
                }

                case 'wait':
                case 'webhook':
                case 'branch':
                case 'transfer_human':
                default: {
                    // MVP: unsupported node types act as no-ops. Log so we
                    // notice flows that need a runtime extension.
                    Log::info('WebFlowRunner: skipping unsupported node', [
                        'type' => $type, 'flow_id' => $flow->id,
                    ]);
                    $nodeId = $this->advanceFrom($def, $node['id'], 'out')
                            ?? $this->advanceFrom($def, $node['id'], 'ok');
                    break;
                }
            }
        }

        // Persist where we landed so step() can resume next turn.
        if (!$ended && !$handoff && $expecting !== 'none') {
            $this->recordCurrent($session, $lastNodeId);
        } elseif ($ended || $handoff) {
            $this->clearFlowState($session);
            if ($ended) {
                $session->status   = 'ended';
                $session->ended_at = time();
            }
            $session->save();
        }

        return [
            'messages'        => $messages,
            'expecting'       => $expecting,
            'current_node_id' => $lastNodeId,
            'handoff'         => $handoff,
            'ended'           => $ended,
            'cost_avoided'    => $avoided,
            // Ordered list of node ids the runner just touched. Used by
            // the editor's Test panel to animate "watch it run"
            // highlights through the graph (n8n-style).
            'execution_path'  => $execution_path,
        ];
    }

    // ── Per-node message renderers ───────────────────────────────────

    private function renderSayMessage(Flow $flow, array $def, array $data, ?Session $session = null, ?Project $project = null): array
    {
        // Web-friendly text: prefer explicit web_text override, fall
        // back to the phone TTS text. Both flow types share `text`.
        $text = (string) ($data['web_text']
            ?? $data['text']
            ?? $data['message']
            ?? '');

        // Audio URL only if the customer uploaded one. Webchat will
        // autoplay it; otherwise the bubble is text-only and we burn
        // zero TTS budget.
        $audioUrl = null;
        $assetId = $data['audio_asset_id'] ?? null;
        if ($assetId) {
            $asset = FlowAsset::find((int) $assetId);
            if ($asset && $asset->storage_path) {
                $audioUrl = $this->publicAssetUrl($asset->storage_path);
            }
        }

        // Persist as an assistant message so the admin's transcript
        // shows the bot's authored Say nodes alongside any later AI
        // turns. Tagged source=flow so reports can distinguish.
        if ($session && $project && $text !== '') {
            $this->saveMessage($project, $session, 'assistant', $text, $audioUrl, [
                'source'  => 'flow',
                'flow_id' => $flow->id,
            ]);
        }

        return [
            'kind'      => 'text',
            'text'      => $text,
            'audio_url' => $audioUrl,
        ];
    }

    /**
     * Render a DTMF node as a menu with quick-reply buttons. Returns
     * null if no outputs have a button_label set — caller should then
     * skip the node entirely (fall through the timeout edge).
     */
    private function renderMenuMessage(Flow $flow, array $def, array $node, array $data, ?Session $session = null, ?Project $project = null): ?array
    {
        $labels = (array) ($data['button_labels'] ?? []);
        $options = [];
        // Only surface outputs that (a) the customer labeled AND (b)
        // have an outgoing edge. Phone uses the full output set; web
        // only shows labeled paths so unlabeled "5"/"6" don't pollute.
        foreach (($def['edges'] ?? []) as $e) {
            if (($e['source'] ?? null) !== ($node['id'] ?? null)) continue;
            $h = (string) ($e['sourceHandle'] ?? 'out');
            if ($h === 'timeout' || $h === '') continue;
            $label = trim((string) ($labels[$h] ?? ''));
            if ($label === '') continue;
            $options[] = ['id' => $h, 'label' => $label];
        }
        if (empty($options)) return null;

        $prompt = (string) ($data['web_prompt'] ?? $data['prompt'] ?? '');

        $audioUrl = null;
        $assetId = $data['prompt_audio_asset_id'] ?? null;
        if ($assetId) {
            $asset = FlowAsset::find((int) $assetId);
            if ($asset && $asset->storage_path) {
                $audioUrl = $this->publicAssetUrl($asset->storage_path);
            }
        }

        // Persist the menu prompt as an assistant message so the
        // transcript shows what was asked. We include the available
        // options in metadata for completeness.
        if ($session && $project && $prompt !== '') {
            $this->saveMessage($project, $session, 'assistant', $prompt, $audioUrl, [
                'source'  => 'flow',
                'flow_id' => $flow->id,
                'kind'    => 'menu',
                'options' => $options,
            ]);
        }

        return [
            'kind'      => 'menu',
            'prompt'    => $prompt,
            'audio_url' => $audioUrl,
            'options'   => $options,
        ];
    }

    /**
     * Persist what the user just did — clicked a button or typed text —
     * so the admin's transcript reads top-to-bottom like a normal chat.
     */
    private function recordUserChoice(Project $project, Session $session, array $node, array $input): void
    {
        $type = $node['type'] ?? '';
        $data = $node['data'] ?? [];
        $content = null;
        $metaExtra = [];

        if ($type === 'capture_dtmf') {
            $choice = trim((string) ($input['choice_id'] ?? ''));
            if ($choice === '') return;   // timeout/no input — skip
            $labels = (array) ($data['button_labels'] ?? []);
            // Prefer the human-readable label for the transcript; fall
            // back to the bare handle id if no label was set (e.g. phone
            // flows that never get web buttons).
            $content = trim((string) ($labels[$choice] ?? '')) ?: $choice;
            $metaExtra = ['choice_id' => $choice];
        } elseif ($type === 'capture_speech') {
            $content = trim((string) ($input['text'] ?? ''));
            if ($content === '') return;
        } else {
            return;
        }

        $this->saveMessage($project, $session, 'user', $content, null, array_merge([
            'source'  => 'flow',
            'node_id' => $node['id'] ?? null,
        ], $metaExtra));
    }

    private function saveMessage(Project $project, Session $session, string $role, string $content, ?string $audioUrl, array $metadata = []): void
    {
        try {
            Message::create([
                'session_id' => $session->id,
                'project_id' => $project->id,
                'role'       => $role,
                'content'    => $content,
                'audio_url'  => $audioUrl,
                'metadata'   => $metadata,
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            // Persistence is best-effort — the flow shouldn't 500 if a
            // metadata column shifts. Just log and keep walking.
            Log::warning('WebFlowRunner: message persist failed', [
                'session_id' => $session->id,
                'err'        => $e->getMessage(),
            ]);
        }
    }

    // ── Branch selection ─────────────────────────────────────────────

    private function pickBranch(array $node, array $input): string
    {
        $type = $node['type'] ?? '';
        if ($type === 'capture_dtmf') {
            $choice = trim((string) ($input['choice_id'] ?? ''));
            return $choice !== '' ? $choice : 'timeout';
        }
        if ($type === 'capture_speech') {
            // Phase 2 MVP: any non-empty user text routes through
            // sourceHandle='match'. Later we can grep `match_phrases`.
            $text = trim((string) ($input['text'] ?? ''));
            return $text !== '' ? 'match' : 'timeout';
        }
        return 'out';
    }

    // ── collect_input + send_channel helpers ─────────────────────────

    /**
     * Normalize a collect_input node to a list of {key, prompt, input_type}.
     * Supports the multi-field `fields` array and the legacy single field.
     *
     * @return array<int,array{key:string,prompt:string,input_type:string}>
     */
    private function collectFields(array $node): array
    {
        $data = $node['data'] ?? [];
        $out = [];
        if (!empty($data['fields']) && is_array($data['fields'])) {
            foreach ($data['fields'] as $f) {
                if (!is_array($f)) continue;
                $out[] = [
                    'key'        => trim((string) ($f['key'] ?? '')) ?: 'value',
                    'prompt'     => (string) ($f['prompt'] ?? 'Please provide a value.'),
                    'input_type' => (string) ($f['input_type'] ?? 'text'),
                ];
            }
        }
        if (empty($out)) {
            // Legacy single-field shape.
            $out[] = [
                'key'        => trim((string) ($data['field_key'] ?? '')) ?: 'value',
                'prompt'     => (string) ($data['prompt'] ?? 'Please provide a value.'),
                'input_type' => (string) ($data['input_type'] ?? 'text'),
            ];
        }
        return $out;
    }

    /**
     * Handle a reply to a collect_input node. Validates + stores the current
     * field; asks the next one if more remain (re-entering the same node);
     * branches 'collected' when all are gathered. Invalid input re-asks the
     * same field; empty input takes 'timeout'.
     */
    private function stepCollectInput(Project $project, Session $session, Flow $flow, array $def, array $node, array $input): array
    {
        $fields = $this->collectFields($node);
        $key    = 'collect_progress.' . $node['id'];
        $idx    = (int) data_get($session->metadata, $key, 0);
        $field  = $fields[$idx] ?? null;
        $text   = trim((string) ($input['text'] ?? ''));

        $clearAndGo = function (string $handle) use ($project, $session, $flow, $def, $node) {
            $meta = (array) ($session->metadata ?? []);
            unset($meta['collect_progress'][$node['id']]);
            $session->metadata = $meta;
            $session->save();
            return $this->walk($project, $session, $flow, $def, $this->advanceFrom($def, $node['id'], $handle), 0, 0);
        };

        // Misconfigured (no field) or empty reply → exit the node.
        if ($field === null) {
            return $clearAndGo('collected');
        }
        if ($text === '') {
            return $clearAndGo('timeout');
        }

        // Invalid → re-ask the SAME field (stay on this node).
        $value = $this->validateInput($field['input_type'], $text);
        if ($value === null) {
            return $this->walk($project, $session, $flow, $def, $node['id'], 0, 0);
        }

        // Store the value, advance the cursor.
        $meta = (array) ($session->metadata ?? []);
        $meta['collected'] = array_merge((array) ($meta['collected'] ?? []), [$field['key'] => $value]);
        $idx++;

        if ($idx < count($fields)) {
            $meta['collect_progress'][$node['id']] = $idx;
            $session->metadata = $meta;
            $session->save();
            // Ask the next field (re-enter this same node).
            return $this->walk($project, $session, $flow, $def, $node['id'], 0, 0);
        }

        // All fields gathered.
        unset($meta['collect_progress'][$node['id']]);
        $session->metadata = $meta;
        $session->save();
        return $this->walk($project, $session, $flow, $def, $this->advanceFrom($def, $node['id'], 'collected'), 0, 0);
    }

    /** Returns the normalized value, or null if it fails validation. */
    private function validateInput(string $type, string $text): ?string
    {
        switch ($type) {
            case 'email':
                return filter_var($text, FILTER_VALIDATE_EMAIL) ? strtolower($text) : null;
            case 'phone':
                $digits = preg_replace('/[^0-9]/', '', $text);
                if (strlen($digits) < 7 || strlen($digits) > 15) return null;
                return (str_starts_with(trim($text), '+') ? '+' : '') . $digits;
            case 'number':
                return is_numeric($text) ? $text : null;
            case 'text':
            default:
                return $text;
        }
    }

    /**
     * Send a message to a recipient via the project's onboarded channel.
     * Recipient = a collected field (e.g. whatsapp_number) or a literal.
     * NOTE: WhatsApp business-initiated sends to a number that hasn't
     * messaged in 24h require an APPROVED TEMPLATE (use payload_type=template).
     */
    private function sendChannel(Project $project, Session $session, array $data): bool
    {
        $provider = (string) ($data['provider'] ?? ChannelConnection::PROVIDER_WHATSAPP);
        $to = $this->resolveRecipient($session, $data);
        if ($to === '') {
            return false;
        }

        $conn = ChannelConnection::where('project_id', $project->id)
            ->where('provider', $provider)
            ->where('status', ChannelConnection::STATUS_ENABLED)
            ->first();
        if (!$conn) {
            Log::warning('WebFlowRunner: no enabled channel connection', [
                'provider' => $provider, 'project_id' => $project->id,
            ]);
            return false;
        }

        $graph = GraphClient::forConnection($conn);
        $from  = (string) $conn->external_id;
        $ptype = (string) ($data['payload_type'] ?? 'text');
        $text  = $this->interpolate((string) ($data['text'] ?? ''), $session);

        if ($provider === ChannelConnection::PROVIDER_WHATSAPP) {
            if ($ptype === 'media') {
                return $graph->sendWhatsAppMediaByLink(
                    $from, $to,
                    (string) ($data['media_type'] ?? 'document'),
                    (string) ($data['media_url'] ?? ''),
                    $data['caption'] ?? null,
                );
            }
            if ($ptype === 'template') {
                return $graph->sendTemplate(
                    $from, $to,
                    (string) ($data['template_name'] ?? ''),
                    (string) ($data['template_lang'] ?? 'en_US'),
                );
            }
            return $graph->sendText($from, $to, $text) !== null;
        }

        // Messenger / Instagram
        if ($ptype === 'media') {
            return $graph->sendMessengerAttachment(
                $from, $to,
                (string) ($data['media_type'] ?? 'file'),
                (string) ($data['media_url'] ?? ''),
            );
        }
        return $graph->sendMessengerText($from, $to, $text) !== null;
    }

    /** Recipient = a captured field value, or an interpolated literal. */
    private function resolveRecipient(Session $session, array $data): string
    {
        $field = trim((string) ($data['recipient_field'] ?? ''));
        if ($field !== '') {
            $collected = (array) data_get($session->metadata, 'collected', []);
            return (string) ($collected[$field] ?? '');
        }
        return $this->interpolate((string) ($data['recipient'] ?? ''), $session);
    }

    /** Replace {{ field }} placeholders with collected session values. */
    private function interpolate(string $tpl, Session $session): string
    {
        if ($tpl === '' || strpos($tpl, '{{') === false) {
            return $tpl;
        }
        $collected = (array) data_get($session->metadata, 'collected', []);
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function ($m) use ($collected) {
            return (string) ($collected[$m[1]] ?? '');
        }, $tpl) ?? $tpl;
    }

    // ── Graph helpers (mirror FlowRunner) ────────────────────────────

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

    // ── Session metadata helpers ─────────────────────────────────────

    private function recordCurrent(Session $session, ?string $nodeId): void
    {
        if (!$nodeId) return;
        $meta = (array) ($session->metadata ?? []);
        $meta[self::META_KEY] = array_merge(
            (array) ($meta[self::META_KEY] ?? []),
            ['current_node_id' => $nodeId]
        );
        $session->metadata = $meta;
        $session->save();
    }

    private function clearFlowState(Session $session): void
    {
        $meta = (array) ($session->metadata ?? []);
        if (isset($meta[self::META_KEY])) {
            // Keep the flow_id around so logs can see which flow ran,
            // but null out the cursor so future turns aren't routed.
            $meta[self::META_KEY]['current_node_id'] = null;
            $session->metadata = $meta;
        }
    }

    // ── URL helpers ──────────────────────────────────────────────────

    private function wsTurnUrl(): string
    {
        $ws = (string) config('services.python.ws_url', 'ws://127.0.0.1:8000/ws/turn');
        // Some envs configure ws_url with http(s) for HTTP polling — coerce.
        return preg_replace('#^http(s?)://#', 'ws$1://', $ws);
    }

    private function publicAssetUrl(string $storagePath): string
    {
        // Same shape FlowRunner uses — served via the storage symlink.
        $base = rtrim((string) (config('services.twilio.python_public_url')
            ?: config('services.twilio.webhook_base', '')
            ?: url('/')), '/');
        return $base . '/storage/' . ltrim($storagePath, '/');
    }

    private function failed(string $msg, Session $session): array
    {
        Log::warning("WebFlowRunner: {$msg}", ['session_id' => $session->id]);
        return [
            'messages'        => [['kind' => 'text', 'text' => 'Sorry — something is misconfigured in this flow.', 'audio_url' => null]],
            'expecting'       => 'none',
            'current_node_id' => null,
            'handoff'         => null,
            'ended'           => true,
            'cost_avoided'    => 0,
            'execution_path'  => [],
        ];
    }
}
