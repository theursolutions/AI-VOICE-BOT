<?php

namespace App\Services\Conversation;

use App\Jobs\ExtractLeadFromTurn;
use App\Models\Message;
use App\Models\Session;
use Illuminate\Support\Facades\Log;
use App\Services\DataSource\DataSourceRouter;
use App\Services\Conversation\HumanRouter;

class ConversationManager
{
    public function __construct(
        private MemoryBuilder $memory,
        private PythonClient $python,
        private DataSourceRouter $sources,
        private ToolPicker $toolPicker,
        private HumanRouter $humans,
    ) {}

    public function handle(Session $session, Message $userMessage, string $respondWith = 'text'): Message
    {
        $start = microtime(true);

        // A human agent has taken this conversation over — the AI stays quiet.
        if (data_get($session->metadata, 'meta.bot_paused')) {
            return new Message([
                'session_id' => $session->id,
                'project_id' => $session->project_id,
                'role'       => 'assistant',
                'content'    => 'A member of our team is handling your request and will reply here shortly.',
                'metadata'   => ['handoff' => true, 'transient' => true],
            ]);
        }

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

            // Capability gate: restrict tool use to the actions granted by
            // the session agent's skills (see Skill::toolGatingForAgent).
            $toolGate = \App\Models\Skill::toolGatingForAgent($session->agent_id);

            // Audience gate: this path serves customers, so only sources /
            // tools the owner opted in are offered (see customer_visible).
            $customerFacing = $session->isCustomerFacing();

            $webhookDecision = $this->toolPicker->pick(
                $session->project_id,
                $history,
                $userMessage->content,
                $toolGate,
                $customerFacing,
            );

            // AI decided to escalate → hand off to a human and stop here.
            if ($webhookDecision && ($webhookDecision['tool_id'] ?? null) === ToolPicker::HANDOFF) {
                return $this->escalateToHuman($session);
            }

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

            $contextResults = $this->sources->onlyUsable(
                $this->sources->resolve($session->project_id, $userMessage->content, $resolverContext)
            );
        }

        $messages = $this->memory->build($session, $contextResults);

        // A provider failure must not become a 500.
        //
        // Every tier of the LLM chain exhausting is an outage, not a bug in the
        // request: the visitor asked a perfectly good question and the answer
        // is temporarily unavailable. Left uncaught it surfaced as an HTTP 500,
        // which the widget could only render as a generic error — and on Meta
        // channels sent nothing at all. A calm sentence is both truer and more
        // useful, and the reason still reaches the log.
        try {
            $reply = $this->python->llm($messages, [
                'project_id'   => $session->project_id,
                'session_id'   => $session->id,
                'voice_id'     => $session->voice_id,
                'respond_with' => $respondWith,
            ]);
        } catch (\Throwable $e) {
            Log::error('LLM generation failed for every provider', [
                'project_id' => $session->project_id,
                'session_id' => $session->id,
                'channel'    => $session->channel,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
            ]);

            // Empty text deliberately: the block below already treats an empty
            // reply as the busy case, logs it, and every channel already knows
            // how to render that. One failure mode, one path.
            $reply = ['text' => ''];
        }

        // An empty reply is saved as NULL content and, until now, said nothing
        // to anyone. Each channel then failed in its own way with no shared
        // cause visible: the widget printed "(no reply)", WhatsApp and
        // Instagram sent nothing at all and sat on a typing indicator, and the
        // log was silent. The generation itself is what failed — a provider
        // timeout, a missing key, an unreachable voice-engine — and that is
        // worth a line, because it is the same failure behind every one of
        // those symptoms.
        if (trim((string) ($reply['text'] ?? '')) === '') {
            \Illuminate\Support\Facades\Log::warning('Empty assistant reply', [
                'project_id'  => $session->project_id,
                'session_id'  => $session->id,
                'channel'     => $session->channel,
                'model'       => $reply['model'] ?? null,
                // Whatever the engine did send back, so a provider error that
                // arrived in another field is not thrown away.
                'reply_keys'  => array_keys((array) $reply),
                'error'       => $reply['error'] ?? null,
            ]);
        }

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

        // Meter the turn. This is the HTTP/sync path for every text channel —
        // web widget, WhatsApp, Instagram, Facebook. It counts the session once
        // (on its first AI reply) and counts a widget voice message when the
        // customer spoke. Cannot throw: see UsageRecorder.
        app(\App\Services\Billing\UsageRecorder::class)->assistantReplied($session, $userMessage);

        ExtractLeadFromTurn::dispatch($session->project_id, $session->id, $assistant->id);

        return $assistant;
    }

    /** Route the chat to a human agent and reply with a connect message. */
    private function escalateToHuman(Session $session): Message
    {
        $human = $this->humans->handoff($session);   // assigns or queues + pauses the bot
        $now = time();

        $text = $human
            ? "I'm connecting you with {$human->name} from our team — one moment."
            : 'Thanks for your patience — a member of our team will be with you shortly.';

        $assistant = Message::create([
            'session_id' => $session->id,
            'project_id' => $session->project_id,
            'role'       => 'assistant',
            'content'    => $text,
            'metadata'   => array_filter([
                'handoff'           => true,
                'assigned_agent_id' => $human?->id,
                'queued'            => $human ? null : true,
            ]),
            'created_at' => $now,
        ]);

        $session->last_activity_at = $now;
        $session->update_at = $now;
        $session->save();

        return $assistant;
    }
}
