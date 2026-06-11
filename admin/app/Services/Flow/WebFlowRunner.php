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
