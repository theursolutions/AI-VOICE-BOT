<?php

namespace App\Services\Conversation;

use App\Jobs\ExtractLeadFromTurn;
use App\Models\Message;
use App\Models\Session;
use App\Services\DataSource\DataSourceRouter;

class ConversationManager
{
    public function __construct(
        private MemoryBuilder $memory,
        private PythonClient $python,
        private DataSourceRouter $sources,
        private ToolPicker $toolPicker,
    ) {}

    public function handle(Session $session, Message $userMessage, string $respondWith = 'text'): Message
    {
        $start = microtime(true);

        $contextResults = [];
        if ($userMessage->content) {
            // Step 1 — Webhook tool routing. One LLM call asks "given
            // this user message + these registered tools, which (if
            // any) should fire, and with what args?" The decision is
            // handed to WebhookResolver via context so only the picked
            // tool runs (the rest are silenced for this turn).
            $history = Message::where('session_id', $session->id)
                ->orderBy('created_at')
                ->limit(20)
                ->get(['role', 'content'])
                ->map(fn ($m) => ['role' => $m->role, 'content' => (string) ($m->content ?? '')])
                ->all();

            $webhookDecision = $this->toolPicker->pick(
                $session->project_id,
                $history,
                $userMessage->content,
            );

            $resolverContext = [];
            if ($webhookDecision) {
                $resolverContext['webhook_decision'] = $webhookDecision;
            }

            $contextResults = $this->sources->onlyUsable(
                $this->sources->resolve($session->project_id, $userMessage->content, $resolverContext)
            );
        }

        $messages = $this->memory->build($session, $contextResults);

        $reply = $this->python->llm($messages, [
            'project_id'   => $session->project_id,
            'session_id'   => $session->id,
            'voice_id'     => $session->voice_id,
            'respond_with' => $respondWith,
        ]);

        $latencyMs = (int) ((microtime(true) - $start) * 1000);
        $now = time();

        $assistant = Message::create([
            'session_id' => $session->id,
            'project_id' => $session->project_id,
            'role'       => 'assistant',
            'content'    => $reply['text']      ?? null,
            'audio_url'  => $reply['audio_url'] ?? null,
            'tokens_in'  => $reply['tokens_in']  ?? null,
            'tokens_out' => $reply['tokens_out'] ?? null,
            'latency_ms' => $latencyMs,
            'model_used' => $reply['model']      ?? null,
            'metadata'   => array_merge($reply['metadata'] ?? [], [
                'sources_consulted' => array_map(
                    fn ($r) => ['source_id' => $r->sourceId, 'type' => $r->sourceType, 'kind' => $r->kind],
                    $contextResults,
                ),
            ]),
            'created_at' => $now,
        ]);

        $session->last_activity_at = $now;
        $session->update_at = $now;
        $session->save();

        ExtractLeadFromTurn::dispatch($session->project_id, $session->id, $assistant->id);

        return $assistant;
    }
}
