<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\Message;
use App\Models\Session;
use App\Services\Conversation\BrainResolver;
use App\Services\Conversation\PythonClient;
use App\Services\Tenant\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Extract / refresh the Lead row for a session after each assistant turn.
 *
 * Pipeline:
 *   1. Take the messages that have arrived since the last extraction.
 *   2. Decide whether any of them could carry a lead field at all.
 *   3. Call Python /extract — which sanitises and scores confidence.
 *   4. Decide whether to create a new lead, update the existing one,
 *      or skip entirely (confidence below threshold and no contact info).
 *   5. Merge fields so we never blank out values the previous extraction
 *      already captured.
 *
 * COST. This job runs after every single assistant turn, which made it the
 * second most expensive call in the product: a fixed ~2.6k-char instruction
 * plus twenty messages of history, most of it re-read on the next turn to
 * re-derive fields already stored. Two things fixed that without changing
 * what gets captured:
 *
 *   INCREMENTAL   only messages past `lead_cursor` are sent, plus a short
 *                 overlap so a one-word answer still arrives with its
 *                 question. `existing_fields` already carried everything
 *                 previously extracted, so resending its evidence bought
 *                 nothing.
 *   GATED         turns whose user messages cannot contain a name, contact,
 *                 amount, date or intent skip the call entirely. "ok",
 *                 "thanks" and "salam" are not lead data.
 *
 * The cursor advances ONLY when the span has been fully accounted for. If
 * extraction found something we chose not to persist yet, the span stays in
 * the window so a later turn can combine it with fresh evidence — the
 * optimisation degrades to the old cost, never to a lost lead.
 */
class ExtractLeadFromTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    /** Drop extractions weaker than this UNLESS they include email or phone. */
    private const MIN_CONFIDENCE = 0.30;

    /** Ceiling on messages sent to Python in one call. */
    private const HISTORY_LIMIT = 20;

    /**
     * Already-seen messages resent ahead of the new ones.
     *
     * Two is the working figure: it carries the assistant's question and the
     * turn before it, which is what makes a bare "December", "Ali" or "yes"
     * mean anything. Default for services.llm.extract_overlap.
     */
    private const OVERLAP = 2;

    /**
     * Where a user message stops being an acknowledgement and starts being
     * prose worth reading. The measured average user message is 25 characters,
     * so this passes roughly the longer half through untested.
     */
    private const PROSE_CHARS = 25;

    public function __construct(
        public int $projectId,
        public int $sessionId,
        public int $assistantMessageId,
    ) {}

    public function handle(TenantManager $tenants, PythonClient $python): void
    {
        $tenants->useForProjectId($this->projectId);

        $session = Session::find($this->sessionId);
        if (!$session) {
            return;
        }

        $cursor = (int) data_get($session->metadata, 'lead_cursor', 0);

        // What has arrived since we last read this conversation. Newest-first
        // then reversed, so a backlog sends the RECENT end of it rather than
        // dredging the oldest twenty messages and leaving this turn unread.
        $span = Message::where('session_id', $this->sessionId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('id', '>', $cursor)
            ->where('id', '<=', $this->assistantMessageId)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'role', 'content'])
            ->reverse()
            ->values();

        // Nothing new. Either a duplicate delivery or a retry of a turn already
        // folded in — both would otherwise pay for an identical extraction.
        if ($span->isEmpty()) {
            return;
        }

        // The tail of what we have already read. Sent as context but never
        // gated on: it is here so a one-word answer keeps its question.
        $prior = $cursor > 0
            ? Message::where('session_id', $this->sessionId)
                ->whereIn('role', ['user', 'assistant'])
                ->where('id', '<=', $cursor)
                ->orderByDesc('id')
                ->limit($this->overlap())
                ->get(['id', 'role', 'content'])
                ->reverse()
                ->values()
            : collect();

        if ($this->gateEnabled() && ! $this->couldCarryLeadFields($span, $prior)) {
            // Read and dismissed. Advancing here is what makes the saving
            // permanent — leaving the cursor would re-test these messages on
            // every following turn for as long as the conversation runs.
            $this->advanceCursor($session, (int) $span->last()->id);

            Log::info('ExtractLeadFromTurn: no lead signal in span, skipped', [
                'session_id' => $session->id,
                'messages'   => $span->count(),
            ]);

            return;
        }

        $history = $prior->concat($span)
            ->map(fn ($m) => ['role' => $m->role, 'content' => (string) ($m->content ?? '')])
            ->all();

        $existing = Lead::where('session_id', $session->id)->first();

        // Routed through the brain that serves machinery, and metered against it.
        // The brain's keys go on the LEFT so they win the duplicate-key merge —
        // written the other way round the resolution would be dead code that
        // still recorded a brain_id, attributing spend to a brain that never saw
        // the call.
        $result = $python->extract(
            app(BrainResolver::class)->optionsFor($this->projectId, BrainResolver::CALL_CAPTURE) + [
                'session_id'      => $session->id,
                'project_id'      => $session->project_id,
                'history'         => $history,
                'existing_fields' => $existing?->fields ?? new \stdClass(),
                'call_type'       => BrainResolver::CALL_CAPTURE,
            ]
        );

        $fields     = $result['fields']     ?? [];
        $confidence = (float) ($result['confidence'] ?? 0.0);

        if (!is_array($fields)) {
            $fields = [];
        }

        // Decide whether this extraction is worth persisting.
        $hasContact  = !empty($fields['email']) || !empty($fields['phone']);
        $hasAnything = !empty($fields);

        if (!$hasAnything) {
            // Read, and there was genuinely nothing in it. Safe to advance.
            $this->advanceCursor($session, (int) $span->last()->id);
            return;
        }
        if (!$hasContact && $confidence < self::MIN_CONFIDENCE) {
            // Vague hits with no way to follow up — skip the noise.
            //
            // The cursor deliberately does NOT advance here. The model found
            // something and we declined to store it, so the only record of that
            // evidence is the messages themselves. Advancing past them would
            // mean a hint dropped at turn 3 can never combine with the phone
            // number given at turn 5, and the lead is lost for good. The span
            // stays in the window instead — at worst that costs what this job
            // cost before, which is the right thing to trade for a lead.
            Log::info('ExtractLeadFromTurn: skipping low-confidence non-contact lead', [
                'session_id' => $session->id,
                'confidence' => $confidence,
                'fields_keys' => array_keys($fields),
            ]);
            return;
        }

        $now = time();

        if ($existing) {
            $existing->fields = $this->mergeFields($existing->fields ?? [], $fields);
            // Only raise confidence — never overwrite a stronger past
            // extraction with a weaker one.
            $existing->confidence = max((float) ($existing->confidence ?? 0), $confidence);
            $existing->contact_id = $existing->contact_id ?: $session->contact_id;
            $existing->update_at = $now;
            $existing->save();
        } else {
            Lead::create([
                'session_id' => $session->id,
                'project_id' => $session->project_id,
                'contact_id' => $session->contact_id,
                'fields'     => $fields,
                'confidence' => $confidence,
                'status'     => 'new',
                'created_at' => $now,
                'update_at'  => $now,
            ]);
        }

        // This is the moment cross-channel identity actually resolves.
        //
        // An email or phone extracted here is usually the FIRST strong
        // identifier we have for someone who arrived on Instagram or
        // Messenger — where the handle is an opaque page-scoped id that
        // matches nothing. Feeding it back is what links them to the
        // WhatsApp conversation they had last month.
        $this->reconcileContact($session, $fields);

        // Persisted. Everything in this span is now represented in the lead's
        // own fields, which the next call receives as `existing_fields`, so the
        // messages themselves need not be sent again.
        $this->advanceCursor($session, (int) $span->last()->id);
    }

    /**
     * Remember how far we have read.
     *
     * Written as a JSON path update rather than a model save, so it cannot
     * clobber a concurrent writer of session.metadata — the language pick and
     * the contact link live in the same column, and a read-modify-write of the
     * whole document would silently drop whichever landed second.
     *
     * Never moves backwards: jobs for two turns can finish out of order, and a
     * cursor that regresses re-sends history we have already paid for.
     */
    private function advanceCursor(Session $session, int $messageId): void
    {
        $current = (int) data_get($session->metadata, 'lead_cursor', 0);

        if ($messageId <= $current) {
            return;
        }

        Session::where('id', $session->id)->update(['metadata->lead_cursor' => $messageId]);
    }

    /** Overlap size, guarded the way every other LLM knob is. */
    private function overlap(): int
    {
        $value = config('services.llm.extract_overlap');

        return max(0, min(10, is_numeric($value) ? (int) $value : self::OVERLAP));
    }

    /**
     * Is the gate switched on?
     *
     * Blank and null resolve to the DEFAULT, not to false. filter_var() reads
     * an empty string as a valid `false`, so `LLM_EXTRACT_GATE=` left blank in
     * .env would quietly turn extraction back on for every turn and put the
     * cost straight back — the same trap the numeric knobs guard against with
     * is_numeric(). Failing that way is cheap to do and invisible to spot: the
     * bill simply never falls, and nothing anywhere says why.
     */
    private function gateEnabled(): bool
    {
        $value = config('services.llm.extract_gate');

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Could anything the customer said in this span be a lead field?
     *
     * Only the USER messages in the span are tested. The assistant is not a
     * data source — the extractor prompt says so explicitly — so a bot turn
     * full of prices and dates is not evidence of anything.
     *
     * Deliberately generous. A false positive costs a fraction of a cent; a
     * false negative loses a customer's phone number, so every test here is
     * written to let borderline turns through:
     *
     *   answering a question  whatever the bot just asked for, the reply is the
     *                         answer to it, however short. "December", "Ali"
     *                         and "yes" are all lead data in context.
     *   any digit             phone, budget, date, quantity, "2 bed"
     *   an @                  email, and most handles
     *   prose                 past PROSE_CHARS we stop guessing and just read it
     *   keywords              short but loaded: "price?", "naam Ali", "demo"
     *
     * What is left — "ok", "thanks", "hi", "salam", "hmm", a lone emoji — is
     * the set this exists to skip.
     *
     * @param  \Illuminate\Support\Collection  $span   messages not yet read
     * @param  \Illuminate\Support\Collection  $prior  already-read tail, context only
     */
    private function couldCarryLeadFields($span, $prior): bool
    {
        // Was the bot's last word before this span a question? If so, the first
        // user turn in the span is its answer, and that answer cannot be judged
        // on its own text alone.
        $awaitingAnswer = false;

        foreach ($prior as $m) {
            $awaitingAnswer = $m->role === 'assistant'
                ? $this->endsWithQuestion((string) ($m->content ?? ''))
                : false;
        }

        foreach ($span as $m) {
            $content = trim((string) ($m->content ?? ''));

            if ($m->role !== 'user') {
                $awaitingAnswer = $this->endsWithQuestion($content);
                continue;
            }

            if ($awaitingAnswer || $this->looksInformative($content)) {
                return true;
            }

            // Asked and answered. A follow-up acknowledgement is not a second
            // answer to the same question.
            $awaitingAnswer = false;
        }

        return false;
    }

    /**
     * Does this turn end by asking something?
     *
     * Looks in the tail rather than at the final character: a reply routinely
     * closes with an emoji, a period, or a second sentence after the question,
     * and "Would you like to book a viewing? 😊" is still a question.
     */
    private function endsWithQuestion(string $content): bool
    {
        $tail = mb_substr(rtrim($content), -80);

        return str_contains($tail, '?') || str_contains($tail, '؟');
    }

    /** Does this user turn plausibly contain a name, contact, amount or intent? */
    private function looksInformative(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        if (str_contains($content, '@') || preg_match('/\d/u', $content)) {
            return true;
        }

        // The customer is asking something, so there is an intent to classify
        // however few words they used. This is what separates "Packages?" and
        // "who is this?" from "hello" — and greetings phrased as questions
        // ("how are you?") cost a call rather than risk a real enquiry.
        if (str_contains($content, '?') || str_contains($content, '؟')) {
            return true;
        }

        if (mb_strlen($content) >= self::PROSE_CHARS) {
            return true;
        }

        // Short and loaded. English, Roman Urdu and Urdu script together,
        // because one Karachi thread routinely carries all three.
        //
        // Weighted towards INTENT, not just contact details. Measured against
        // real traffic, the turns this gate wrongly dismissed were never
        // missing phone numbers — they were three-word intents like "Packages",
        // "Talk to agents" and "sum is not correct", each of which qualifies a
        // lead. Bare question words are deliberately absent ("how" would match
        // "how are you"); only the loaded pairings are listed.
        // The trailing (s|es)? applies to the whole alternation, so every noun
        // here matches its plural too. Listing singulars only and relying on
        // \b silently dropped "Talk to agents" and "Packages" — a plural is how
        // customers actually write most of these.
        $keywords = '/\b(name|email|mail|phone|number|call|whatsapp|contact|address|'
            . 'price|pricing|cost|rate|quote|budget|cheap|expensive|discount|fee|charge|'
            . 'package|plan|offer|deal|promo|'
            . 'demo|book|booking|visit|appointment|meeting|schedule|trial|'
            . 'buy|purchase|order|rent|lease|sell|interested|enquiry|inquiry|'
            . 'service|product|available|availability|stock|'
            . 'delivery|shipping|location|branch|timing|hour|'
            . 'agent|human|representative|staff|manager|owner|'
            . 'balance|account|invoice|payment|bill|refund|'
            . 'urgent|asap|today|tomorrow|tonight|week|month|year|morning|evening|'
            . 'jan|feb|mar|apr|jun|jul|aug|sep|oct|nov|dec|'
            . 'january|february|march|april|june|july|august|september|october|november|december|'
            . 'monday|tuesday|wednesday|thursday|friday|saturday|sunday|'
            . 'support|help|problem|issue|complaint|cancel|broken|wrong|incorrect|mistake|error|'
            . 'naam|qeemat|keemat|kitna|kitne|kitni|chahiye|chahye|chahta|rabta|'
            . 'milna|milne|dekhna|kharidna|kiraya|paisa|rupay|lakh|crore|karor)(s|es)?\b/iu';

        if (preg_match($keywords, $content)) {
            return true;
        }

        // Multi-word negations that carry a complaint without any single keyword.
        if (preg_match('/\b(not|isn.?t|doesn.?t|didn.?t|won.?t|can.?t)\b/iu', $content)) {
            return true;
        }

        return (bool) preg_match('/(نام|قیمت|کتنا|کتنے|چاہیے|رابطہ|نمبر|پتہ|مسئلہ|مدد|شکایت)/u', $content);
    }

    /** Push newly-learned identifiers into the contact record. */
    private function reconcileContact(Session $session, array $fields): void
    {
        $details = array_filter([
            'name'  => $fields['name']  ?? null,
            'email' => $fields['email'] ?? null,
            'phone' => $fields['phone'] ?? null,
        ], fn ($v) => is_scalar($v) && trim((string) $v) !== '');

        if (! $details) {
            return;
        }

        try {
            $resolver = app(\App\Services\Crm\ContactResolver::class);

            if ($session->contact_id && ($contact = \App\Models\Contact::find($session->contact_id))) {
                $resolver->reconcile($contact, $details);
                return;
            }

            // No contact yet — webchat and telephony sessions do not create
            // one on arrival the way Meta channels do.
            $contact = $resolver->resolve(
                projectId:  (int) $session->project_id,
                channel:    (string) $session->channel,
                externalId: (string) ($session->external_id ?: $session->id),
                details:    $details,
            );

            if ($contact) {
                $session->contact_id = $contact->id;
                $session->save();
            }
        } catch (\Throwable $e) {
            // Identity resolution is an enrichment. It must never cost the
            // lead that has already been saved above.
            Log::warning('Contact reconcile failed: ' . $e->getMessage());
        }
    }

    /**
     * Merge new extracted fields into the existing lead.
     *
     * Rules:
     *   - Non-empty new value WINS over old value (LLM had fresher evidence).
     *   - Empty new value never overwrites a non-empty old value.
     *   - `custom` is deep-merged with the same rule.
     */
    private function mergeFields(array $old, array $new): array
    {
        $merged = $old;
        foreach ($new as $k => $v) {
            if ($k === 'custom' && is_array($v)) {
                $oldCustom = is_array($merged['custom'] ?? null) ? $merged['custom'] : [];
                foreach ($v as $ck => $cv) {
                    if ($cv === null || $cv === '' || $cv === []) continue;
                    $oldCustom[$ck] = $cv;
                }
                if (!empty($oldCustom)) {
                    $merged['custom'] = $oldCustom;
                }
                continue;
            }
            if ($v === null || $v === '' || $v === []) continue;
            $merged[$k] = $v;
        }
        return $merged;
    }
}
