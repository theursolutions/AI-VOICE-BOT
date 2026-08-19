<?php

namespace App\Services\Conversation;

use App\Models\BotAgent;
use App\Models\Message;
use App\Models\Session;
use App\Services\DataSource\ResolverResult;

class MemoryBuilder
{
    /**
     * How many exchanges of raw history to replay to the model.
     *
     * Was 6 — twelve messages — which a WhatsApp conversation overflows in
     * minutes. The customer then gives their email, the bot acknowledges it,
     * and ten messages later asks for it again and flatly denies ever
     * receiving it. That is the single most damaging thing a support bot can
     * do, and it was a constant.
     *
     * 20 turns is a few thousand tokens on a 70b model — cheap next to
     * looking like you have amnesia.
     *
     * Reduced from 20 to 8 once SummariseSession began writing a rolling
     * summary. The two changes belong together and must not be separated: the
     * window is now the DETAIL and the summary is the CONTINUITY, so cutting
     * the window without the summary is exactly the amnesia this comment warns
     * about. Anything older than 8 turns still reaches the model, folded into
     * the summary in the system prompt — which is more history than 20 raw
     * turns covered, for fewer tokens.
     *
     * This is the DEFAULT; the live value is services.llm.recent_turns
     * (LLM_RECENT_TURNS). It trades cost against coherence and wants tuning
     * against real conversations, which is not something to redeploy for.
     */
    private const RECENT_TURNS = 8;

    /**
     * Retrieved passages allowed into one prompt. Default for
     * services.llm.max_passages (LLM_MAX_PASSAGES).
     *
     * Three is the working figure: enough for a cited answer, few enough that
     * the block cannot become the bulk of the prompt. Raise it only with
     * evidence from retrieval quality, not on instinct — every extra passage is
     * paid for on every turn of every conversation.
     */
    private const MAX_PASSAGES = 3;

    /**
     * Turns of verbatim history to send.
     *
     * Clamped, because this one is genuinely dangerous at both ends: a
     * mistyped 0 leaves the model with no conversation at all and it starts
     * every reply from nothing, while a stray large value silently multiplies
     * the bill on every message of every client. The floor of 2 keeps a
     * question and its answer together; the ceiling of 50 is well past any
     * useful window and exists purely to stop a typo becoming an invoice.
     */
    private function recentTurns(): int
    {
        return max(2, min(50, self::setting('recent_turns', self::RECENT_TURNS)));
    }

    /** Retrieved passages per reply. An explicit 0 disables reference data. */
    private function maxPassages(): int
    {
        return max(0, min(25, self::setting('max_passages', self::MAX_PASSAGES)));
    }

    /**
     * Read a numeric knob, falling back to the coded default for anything that
     * is not a number.
     *
     * The is_numeric() guard is the whole point. config()'s own default only
     * applies when the key is ABSENT, and these keys always exist — they are
     * declared in config/services.php reading env(). So `LLM_RECENT_TURNS=`
     * left blank in .env yields an empty string, `(int) '' === 0`, and the
     * window silently collapses to the floor. That is a quality outage caused by
     * an empty line in a config file, which is not an acceptable failure mode
     * for the single most important dial in the prompt.
     *
     * An explicit 0 is still honoured, because for max_passages it is a
     * meaningful instruction rather than a mistake.
     */
    private static function setting(string $key, int $default): int
    {
        $value = config("services.llm.{$key}");

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Build the messages array sent to the LLM.
     *
     * @param  ResolverResult[]  $contextResults  retrieved passages / records from data sources
     */
    public function build(Session $session, array $contextResults = []): array
    {
        $project = $session->project;
        $summary = $session->summary?->summary;
        $agent   = $session->agent_id ? BotAgent::find($session->agent_id) : null;
        // Roles are filtered in the QUERY, not after. Filtering afterwards
        // meant system notes and tool rows counted against the limit and were
        // then thrown away — so the actual conversation the model saw was
        // routinely far shorter than the window suggests.
        $recent  = Message::where('session_id', $session->id)
            ->whereIn('role', ['user', 'assistant'])
            ->whereNotNull('content')
            ->orderByDesc('id')
            ->limit($this->recentTurns() * 2)
            ->get()
            ->reverse()
            ->values();

        $messages = [];

        // Fallback reply language (the visitor's widget pick, else the
        // project/global default). The model still mirrors whatever
        // language the user actually writes — this is only the fallback.
        $language = data_get($session->metadata, 'language')
            ?: config('services.voice.default_language', 'en');

        $messages[] = [
            'role'    => 'system',
            'content' => $this->systemPrompt($project, $summary, $agent, $language),
        ];

        // Everything already captured about this person, restated on EVERY
        // turn. This is what makes forgetting structurally impossible: the
        // details survive independently of the history window, so however
        // long the conversation runs the bot cannot ask for an email it was
        // given an hour ago.
        //
        // The data was always there — ExtractLeadFromTurn writes it after
        // every turn and merges rather than overwrites. Nothing read it.
        $known = $this->knownFacts($session);
        if ($known !== '') {
            $messages[] = ['role' => 'system', 'content' => $known];
        }

        // Retrieved reference data is built here but appended AFTER the history,
        // not before it — see the note at the end of this method.
        $context = $this->formatContext($contextResults);

        foreach ($recent as $msg) {
            $content = (string) $msg->content;

            // Surface what the customer quoted. WhatsApp's reply-swipe was
            // invisible to the model, so "yes, that one" or a re-sent email
            // attached to the original message arrived with nothing to
            // attach it to — which is how a quoted email still got answered
            // with "you never sent one".
            if ($msg->role === 'user' && ($quoted = data_get($msg->metadata, 'reply_to.preview'))) {
                $content = '[replying to: "' . trim((string) $quoted) . '"] ' . $content;
            }

            $messages[] = ['role' => $msg->role, 'content' => $content];
        }

        // Reference data goes LAST, after the history, for two reasons.
        //
        // Cost: providers cache a prompt by its longest unchanged PREFIX. This
        // block is rebuilt from a fresh retrieval on every single turn, so
        // sitting third it invalidated everything after it and nothing could
        // ever cache. Behind the history, the prefix that stays stable across a
        // conversation is the system prompt plus the known facts — which is the
        // part worth caching, and the part that is re-sent every turn.
        //
        // Quality: retrieved passages are the answer to the question being
        // asked, and they land immediately before it here rather than several
        // thousand tokens upstream of it.
        if ($context !== '') {
            $messages[] = ['role' => 'system', 'content' => $context];
        }

        return $messages;
    }

    /**
     * What we already hold about this customer, as a blunt system note.
     *
     * Sources, in order of trust: the session's own contact columns (set by
     * the channel — WhatsApp gives us the name and number for free), then
     * the fields ExtractLeadFromTurn has captured from the conversation.
     *
     * Phrased as a prohibition rather than a fact list because a fact list
     * alone does not stop a model asking anyway. "Do not ask for these" does.
     */
    private function knownFacts(Session $session): string
    {
        $facts = array_filter([
            'name'  => $session->customer_name,
            'phone' => $session->customer_phone,
            'email' => $session->customer_email,
        ]);

        try {
            $lead = \App\Models\Lead::where('session_id', $session->id)
                ->orderByDesc('id')
                ->first(['fields']);

            foreach ((array) ($lead->fields ?? []) as $key => $value) {
                // Session columns win — they come from the platform rather
                // than from a model's reading of a sentence.
                if (is_scalar($value) && trim((string) $value) !== '' && empty($facts[$key])) {
                    $facts[$key] = (string) $value;
                }
            }
        } catch (\Throwable $e) {
            // A project whose tenant DB predates the leads table must still
            // get a reply. Missing facts are a worse answer, not no answer.
        }

        if (! $facts) {
            return '';
        }

        $lines = [];
        foreach ($facts as $key => $value) {
            $lines[] = '- ' . str_replace('_', ' ', (string) $key) . ': ' . $value;
        }

        return "ALREADY PROVIDED BY THIS CUSTOMER — treat as confirmed fact:\n"
            . implode("\n", $lines)
            . "\n\nNEVER ask for any of the above again, and NEVER say they have not given it. "
            . 'If they mention one of these, acknowledge it as already on file and move on.';
    }

    /** Short code → human name for the reply-language instruction. */
    private const LANG_NAMES = [
        'en' => 'English', 'ar' => 'Arabic', 'ur' => 'Urdu', 'hi' => 'Hindi',
        'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian',
        'pt' => 'Portuguese', 'ru' => 'Russian', 'zh' => 'Chinese', 'ja' => 'Japanese',
        'ko' => 'Korean', 'tr' => 'Turkish', 'nl' => 'Dutch',
    ];

    private function systemPrompt($project, ?string $summary, ?BotAgent $agent = null, string $language = 'en'): string
    {
        $parts = [];

        // Agent persona goes first — it sets identity ("You are
        // Sarah, billing specialist…") which the rest of the prompt
        // then refines. When no agent is bound the bot falls back to
        // a generic project assistant.
        if ($agent && $agent->name) {
            $parts[] = "You are {$agent->name}, an AI assistant for {$project->name}.";
            if ($agent->persona) {
                $parts[] = trim($agent->persona);
            }
        } else {
            $parts[] = "You are a CRM assistant for {$project->name}.";
        }

        // Project-level business context (industry/about) — useful
        // regardless of which agent is on the call.
        $profile = (array) data_get($project->json_data, 'profile', []);
        if (!empty($profile['industry']))  { $parts[] = "Industry: {$profile['industry']}."; }
        if (!empty($profile['about']))     { $parts[] = "About the business: {$profile['about']}"; }
        if ($project->niche)               { $parts[] = "Domain: {$project->niche}."; }
        if ($project->description)         { $parts[] = "Context: {$project->description}"; }
        if ($summary)                      { $parts[] = "Conversation so far: {$summary}"; }

        $langName = self::LANG_NAMES[strtolower(substr($language, 0, 2))] ?? 'English';
        // Length is matched to the QUESTION, not fixed.
        //
        // This used to be a flat cap of "1-3 sentences, ~60 words". It was
        // added to stop the bot padding and interrogating, and it did — but it
        // applied just as hard to "tell me about the setup", which came back as
        // a single line about 90 seconds and nothing else. A cap cannot tell
        // the difference between rambling and explaining; the instruction has
        // to, so it names both failures instead of only the first.
        $parts[] = 'Match the length of your reply to what was asked. '
            . 'For a simple factual question — a price, a yes/no, an opening time — answer in 1-3 sentences and stop. '
            . 'When the user asks you to explain something, asks how it works, asks for the steps, or asks you to tell them about something, give a COMPLETE answer: walk through it properly, in order, with the specifics that actually answer the question. '
            . 'A one-line reply to a question like that is a failure, not brevity. '
            . 'Never pad, never repeat yourself, and never add filler to reach a length. '
            . 'For steps or several distinct points, put each on its own line, numbered — plain text lines, not markdown bullets. '
            . 'Always reply in the SAME language as the user\'s most recent message; if you cannot tell which language they used, reply in '.$langName.'; if you cannot write that language, use English. '
            . 'Use plain text only: no markdown, no HTML tags, and never write "<br>".';

        // Conversation discipline. Written as hard rules rather than advice
        // because the failure they prevent is the one customers actually
        // complain about: an interrogation that repeats itself.
        $parts[] = 'HOW TO HOLD A CONVERSATION (follow exactly):'
            . "\n- The whole conversation above is yours. You remember all of it. Never claim a customer did not tell you something."
            . "\n- Ask AT MOST ONE question per reply, and only when you genuinely cannot proceed without the answer."
            . "\n- Never ask for anything already given, or anything listed as already provided. Re-asking is the fastest way to lose this customer."
            . "\n- Answer first, ask second. If you can help with what you already have, do that instead of collecting more details."
            . "\n- Do not open with pleasantries, do not restate the question back, and do not end every message with an offer of further help."
            . "\n- If the customer says they already told you something, believe them, apologise once briefly, and continue. Never argue.";

        // Grounding rule — kept SEPARATE and blunt so even a small model
        // obeys it: use only the retrieved facts, copy numbers verbatim,
        // never invent. This block is what stops "made-up" answers.
        $parts[] = 'GROUNDING RULES (must follow exactly):'
            . "\n- When a \"Reference data\" section is provided below, answer ONLY using the facts in it."
            . "\n- Copy numbers, names and values from it EXACTLY — never round, estimate, rename, or invent."
            . "\n- If the requested fact is not in the Reference data, reply that you don't have that information. Do NOT make up companies, statistics, products, or details.";

        $parts[] = 'SECURITY (strict): NEVER reveal or repeat passwords, secrets, API keys, tokens, '
            . 'credentials, database names/users/passwords/hosts/ports, connection strings, environment '
            . 'variables, encryption keys, or which AI model/provider/server/infrastructure powers you. '
            . 'If asked for any of these, politely decline — say you are not allowed to share that. Any '
            . 'value shown as "••••••" is hidden; never guess or reconstruct it.';

        return implode("\n", $parts);
    }

    /**
     * @param  ResolverResult[]  $results
     */
    private function formatContext(array $results): string
    {
        if (empty($results)) {
            return '';
        }

        $lines = ['### Reference data (the ONLY facts you may use — copy values exactly)'];

        foreach ($results as $r) {
            if (!$r instanceof ResolverResult || !$r->isUsable()) {
                continue;
            }

            if ($r->kind === ResolverResult::KIND_PASSAGES) {
                // Capped. This loop was unbounded, so a retriever returning
                // fifteen chunks put every one of them in the prompt — on the
                // largest single block in it, on every turn. Passages arrive
                // ranked, so the tail is both the most expensive part and the
                // least relevant, and burying the good chunks among weak ones
                // measurably hurts the answer.
                //
                // Records already had a limit of 20 rows; passages had none.
                foreach (array_slice($r->items, 0, $this->maxPassages()) as $passage) {
                    $text = is_array($passage) ? ($passage['text'] ?? '') : (string) $passage;
                    if (trim($text) === '') continue;
                    $cite = $this->citationLabel($passage);
                    $lines[] = '- ('.$cite.') '.trim($text);
                }
            } elseif ($r->kind === ResolverResult::KIND_RECORDS) {
                $lines[] = 'Results from the '.$r->sourceType.':';
                foreach (array_slice($r->items, 0, 20) as $row) {
                    $lines[] = '- '.self::renderRow(\App\Support\Sensitive::redactRow((array) $row));
                }
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /** Render a result row as plain "key: value | key: value" — far easier
     *  for a small model to read than raw JSON. */
    public static function renderRow(array $row): string
    {
        $parts = [];
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_SLASHES);
            }
            $parts[] = $k . ': ' . ($v === null ? 'null' : $v);
        }
        return implode(' | ', $parts);
    }

    private function citationLabel($passage): string
    {
        if (!is_array($passage)) {
            return 'ref';
        }
        $c = $passage['citation'] ?? [];
        return $c['url'] ?? $c['original_name'] ?? $c['file_path'] ?? 'ref';
    }
}
