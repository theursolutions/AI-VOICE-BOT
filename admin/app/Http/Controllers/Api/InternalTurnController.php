<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractLeadFromTurn;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\MemoryBuilder;
use App\Services\Conversation\ToolPicker;
use App\Services\DataSource\DataSourceRouter;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalTurnController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    /**
     * POST /api/internal/resolve-context
     *
     * Called by Python's /ws/turn after STT, before the LLM stream.
     * Runs the same resolver chain the HTTP path uses (ToolPicker +
     * DataSourceRouter) and returns a flat "Reference data" string
     * Python can prepend as a system message.
     *
     * Without this hop, the WS path bypasses Laravel entirely and the
     * bot never sees the customer's DB / RAG / webhook results — it
     * just answers from its base knowledge.
     */
    public function resolveContext(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'session_id' => 'required|integer',
            'user_text'  => 'required|string|max:4000',
        ]);

        $this->tenants->useForProjectId($data['project_id']);

        $session = Session::find($data['session_id']);
        if (!$session) {
            return response()->json(['context' => '', 'sources' => []]);
        }

        $router     = app(DataSourceRouter::class);
        $toolPicker = app(ToolPicker::class);
        $memory     = app(MemoryBuilder::class);

        $history = Message::where('session_id', $session->id)
            ->orderBy('created_at')
            ->limit(20)
            ->get(['role', 'content'])
            ->map(fn ($m) => ['role' => $m->role, 'content' => (string) ($m->content ?? '')])
            ->all();

        // Capability gate: restrict tool use to the actions granted by the
        // session agent's skills, so voice calls honor the same per-agent
        // capabilities as web chat (see Skill::toolGatingForAgent).
        $toolGate = \App\Models\Skill::toolGatingForAgent($session->agent_id);

        // Audience gate: voice calls are customer-facing, so only sources /
        // tools the owner opted in are consulted (see customer_visible).
        $customerFacing = $session->isCustomerFacing();

        $webhookDecision = $toolPicker->pick(
            $session->project_id,
            $history,
            $data['user_text'],
            $toolGate,
            $customerFacing,
        );

        $resolverContext = ['tool_gate' => $toolGate, 'customer_facing' => $customerFacing];
        if ($webhookDecision) {
            $resolverContext['webhook_decision'] = $webhookDecision;
        }

        // Flow "Data Source" node may have pinned this conversation to
        // specific source(s) (stored on the session). Honor that scope.
        $scope = (array) data_get($session->metadata, 'ds_scope', []);
        if (!empty($scope)) {
            $resolverContext['source_ids'] = array_values($scope);
        }

        $results = $router->onlyUsable(
            $router->resolve($session->project_id, $data['user_text'], $resolverContext)
        );

        // MemoryBuilder::build also adds the project system prompt +
        // history. For the WS path Python builds its own messages list
        // including history, so we only want the "Reference data" block.
        $context = $this->formatContextOnly($results);

        return response()->json([
            'context' => $context,
            'sources' => array_map(
                fn ($r) => ['source_id' => $r->sourceId, 'type' => $r->sourceType, 'kind' => $r->kind],
                $results,
            ),
        ]);
    }

    /**
     * Reuses MemoryBuilder's formatContext() logic but exposed as a
     * standalone callable (the original is private). Keeps the
     * "Reference data" formatting identical to the HTTP path.
     */
    private function formatContextOnly(array $results): string
    {
        if (empty($results)) return '';

        $lines = ['### Reference data (the ONLY facts you may use — copy values exactly, never invent)'];
        foreach ($results as $r) {
            if (!$r->isUsable()) continue;

            if ($r->kind === \App\Services\DataSource\ResolverResult::KIND_PASSAGES) {
                foreach ($r->items as $passage) {
                    $text = is_array($passage) ? ($passage['text'] ?? '') : (string) $passage;
                    if (trim($text) === '') continue;
                    $c = is_array($passage) ? ($passage['citation'] ?? []) : [];
                    $cite = $c['url'] ?? $c['original_name'] ?? $c['file_path'] ?? 'ref';
                    $lines[] = '- ('.$cite.') '.trim($text);
                }
            } elseif ($r->kind === \App\Services\DataSource\ResolverResult::KIND_RECORDS) {
                $lines[] = 'Results from the '.$r->sourceType.':';
                foreach (array_slice($r->items, 0, 20) as $row) {
                    $lines[] = '- '.\App\Services\Conversation\MemoryBuilder::renderRow(\App\Support\Sensitive::redactRow((array) $row));
                }
            }
        }
        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * POST /api/internal/session-ended
     *
     * Called by Python ws/turn when the WebSocket disconnects (caller
     * hung up, network dropped, etc). Flips the session to status=
     * 'ended' so the dashboard doesn't show it as still-active forever.
     * Idempotent — safe to call multiple times.
     */
    public function sessionEnded(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'session_id' => 'required|integer',
        ]);

        $this->tenants->useForProjectId($data['project_id']);
        $session = Session::find($data['session_id']);
        if (!$session) {
            return response()->json(['ok' => false, 'reason' => 'not_found'], 404);
        }

        if ($session->status === 'active') {
            $session->status   = 'ended';
            $session->ended_at = time();
            $session->update_at = time();
            $session->save();
        }

        return response()->json(['ok' => true, 'session_id' => $session->id, 'status' => $session->status]);
    }

    public function turnCompleted(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'session_id'   => 'required|integer',
            'role'         => 'required|in:assistant,tool',
            'content'      => 'nullable|string',
            'audio_url'    => 'nullable|string',
            'tokens_in'    => 'nullable|integer',
            'tokens_out'   => 'nullable|integer',
            'latency_ms'   => 'nullable|integer',
            'model_used'   => 'nullable|string',
            'metadata'     => 'nullable|array',
            // WS path persistence: the transcribed/typed user input that
            // produced this assistant reply. HTTP path persists user
            // messages in TurnController so omits this.
            'user_content' => 'nullable|string',
            'cancelled'    => 'nullable|boolean',
        ]);

        $this->tenants->useForProjectId($data['project_id']);

        $session = Session::findOrFail($data['session_id']);
        $now = time();

        // 1) Persist the user message first (WS path only — HTTP path
        // already wrote it in TurnController). Idempotent: only insert
        // if there's no recent user message with the same content.
        if (!empty($data['user_content'])) {
            $alreadyExists = Message::where('session_id', $session->id)
                ->where('role', 'user')
                ->where('content', $data['user_content'])
                ->where('created_at', '>=', $now - 60)
                ->exists();
            if (!$alreadyExists) {
                Message::create([
                    'session_id' => $session->id,
                    'project_id' => $session->project_id,
                    'role'       => 'user',
                    'content'    => $data['user_content'],
                    'metadata'   => ['source' => 'ws'],
                    'created_at' => $now,
                ]);
            }
        }

        // 2) Persist the assistant (or tool) message
        $assistantMetadata = $data['metadata'] ?? [];
        if (!empty($data['cancelled'])) {
            $assistantMetadata['cancelled'] = true;
        }

        $message = Message::create([
            'session_id' => $session->id,
            'project_id' => $session->project_id,
            'role'       => $data['role'],
            'content'    => $data['content']    ?? null,
            'audio_url'  => $data['audio_url']  ?? null,
            'tokens_in'  => $data['tokens_in']  ?? null,
            'tokens_out' => $data['tokens_out'] ?? null,
            'latency_ms' => $data['latency_ms'] ?? null,
            'model_used' => $data['model_used'] ?? null,
            'metadata'   => $assistantMetadata,
            'created_at' => $now,
        ]);

        $session->last_activity_at = $now;
        $session->update_at = $now;
        $session->save();

        if ($data['role'] === 'assistant') {
            ExtractLeadFromTurn::dispatch($session->project_id, $session->id, $message->id);
        }

        return response()->json(['message_id' => $message->id], 201);
    }
}
