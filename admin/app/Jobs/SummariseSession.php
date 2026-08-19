<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\Session;
use App\Models\SessionSummary;
use App\Services\Conversation\PythonClient;
use App\Services\Tenant\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fold the turns that have scrolled out of the reply window into a rolling
 * summary.
 *
 * MemoryBuilder has always read `session.summary` into the system prompt, and
 * nothing ever wrote it. That was the gap this job closes, and it was more than
 * a missing feature: threads here are keyed per customer per channel and stay
 * active for months, so most real conversations run far past the reply window.
 * Everything older simply vanished from the model's view, with nothing carrying
 * it — the bot could be told something in March and have no access to it in
 * April, while appearing to hold a continuous relationship.
 *
 * Writing the summary is also what makes the smaller window affordable:
 * MemoryBuilder::RECENT_TURNS dropped from 20 to 8 in the same change. The
 * window supplies recent DETAIL, the summary supplies CONTINUITY, and the
 * structured facts (knownFacts) supply the values that must never be lost. The
 * three together cover more of the conversation than 20 raw turns did, for
 * roughly a third of the tokens.
 *
 * Incremental by design. Each run summarises only messages newer than
 * `last_message_id` and folds them into the previous summary, so cost is
 * proportional to what has been said since the last run — not to the length of
 * the thread. A year-old conversation is no more expensive to summarise than a
 * week-old one.
 */
class SummariseSession implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 10;

    /**
     * Unsummarised turns that must accumulate before this runs.
     *
     * Deliberately larger than the reply window (8 turns). Summarising on every
     * turn would add a third LLM call to every message to save tokens on the
     * same message — a loss. Waiting until a batch has scrolled out means the
     * cost is amortised over many turns, and a message is only summarised once
     * it is genuinely leaving the window.
     *
     * Default for services.llm.summarise_after (LLM_SUMMARISE_AFTER).
     */
    private const TRIGGER_AFTER = 12;

    /** Hard ceiling on the summary itself, so it cannot become the thing it replaced. */
    private const MAX_SUMMARY_CHARS = 1200;

    public function __construct(
        public int $projectId,
        public int $sessionId,
    ) {}

    public function handle(TenantManager $tenants, PythonClient $python): void
    {
        $tenants->useForProjectId($this->projectId);

        $session = Session::find($this->sessionId);
        if (! $session) {
            return;
        }

        $existing = SessionSummary::find($this->sessionId);
        $since    = (int) ($existing->last_message_id ?? 0);

        $fresh = Message::where('session_id', $this->sessionId)
            ->whereIn('role', ['user', 'assistant'])
            ->whereNotNull('content')
            ->where('id', '>', $since)
            ->orderBy('id')
            ->get(['id', 'role', 'content']);

        // Not enough has scrolled past yet. Cheaper to wait than to summarise a
        // handful of turns the window is still showing the model in full.
        //
        // is_numeric, not a cast: these keys always exist (declared in
        // config/services.php via env), so config()'s own default never fires
        // and a blank `LLM_SUMMARISE_AFTER=` would cast to 0 — turning this into
        // an LLM call on every single turn, the exact cost the job exists to
        // avoid. Floored at 2 as well, for the same reason.
        $configured = config('services.llm.summarise_after');
        $trigger = max(2, is_numeric($configured) ? (int) $configured : self::TRIGGER_AFTER);

        if ($fresh->count() < $trigger) {
            return;
        }

        $transcript = $fresh
            ->map(fn ($m) => ($m->role === 'user' ? 'Customer: ' : 'Assistant: ') . trim((string) $m->content))
            ->implode("\n");

        $prompt = $this->prompt($existing->summary ?? null, $transcript);

        try {
            // Routed to the cheap model explicitly. Summarising is compression,
            // not conversation — it never reaches a customer, so paying reply
            // rates for it is waste.
            $resp = $python->llm(
                [['role' => 'system', 'content' => $prompt]],
                [
                    'project_id'   => $this->projectId,
                    'respond_with' => 'text',
                    'provider'     => config('services.llm.cheap_provider', 'gemini'),
                    'model'        => config('services.llm.cheap_model'),
                    'max_tokens'   => 500,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('SummariseSession: LLM call failed', [
                'session' => $this->sessionId, 'error' => $e->getMessage(),
            ]);

            // Leave last_message_id untouched so the next run retries this span
            // rather than skipping it. A skipped span is history lost for good.
            return;
        }

        $summary = trim((string) ($resp['text'] ?? ''));

        if ($summary === '') {
            Log::warning('SummariseSession: empty summary returned', ['session' => $this->sessionId]);
            return;
        }

        if (mb_strlen($summary) > self::MAX_SUMMARY_CHARS) {
            $summary = mb_substr($summary, 0, self::MAX_SUMMARY_CHARS);
        }

        SessionSummary::updateOrCreate(
            ['session_id' => $this->sessionId],
            [
                'project_id'      => $this->projectId,
                'summary'         => $summary,
                'last_message_id' => (int) $fresh->last()->id,
                // Rough token estimate — 4 chars per token. Used for reporting
                // what the summary costs to carry, not for billing.
                'token_count'     => (int) ceil(mb_strlen($summary) / 4),
                'updated_at'      => time(),
            ],
        );

        Log::info('SummariseSession: summary updated', [
            'session'     => $this->sessionId,
            'folded_in'   => $fresh->count(),
            'summary_len' => mb_strlen($summary),
            'tokens_in'   => $resp['tokens_in'] ?? null,
            'tokens_out'  => $resp['tokens_out'] ?? null,
        ]);
    }

    /**
     * The folding prompt.
     *
     * Asks for a REPLACEMENT summary covering both the previous summary and the
     * new turns, rather than an appendix. Appending grows without bound and
     * re-creates the problem the summary exists to solve.
     */
    private function prompt(?string $previous, string $transcript): string
    {
        $head = $previous
            ? "You are updating a running summary of a long customer support conversation.\n\n"
              . "EXISTING SUMMARY:\n{$previous}\n\n"
              . "NEW MESSAGES since that summary was written:\n{$transcript}\n\n"
              . "Write a SINGLE replacement summary covering both the existing summary and the "
              . "new messages. Do not append; rewrite as one continuous account."
            : "Summarise this customer support conversation.\n\nCONVERSATION:\n{$transcript}";

        return $head . "\n\n"
            . "Rules:\n"
            . "- Under 150 words, plain prose, no headings or bullet points.\n"
            . "- Keep what a colleague taking over the conversation would need: what the customer "
            . "wants, what was promised or agreed, what is unresolved, and any constraint they stated.\n"
            . "- Keep specifics that are still relevant: order numbers, product names, dates, amounts.\n"
            . "- Drop greetings, pleasantries and anything already resolved and closed.\n"
            . "- Write in the third person about the customer. Do not address them.\n"
            . "- Output only the summary text.";
    }
}
