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
     */
    private const RECENT_TURNS = 20;

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
            ->limit(self::RECENT_TURNS * 2)
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

        $context = $this->formatContext($contextResults);
        if ($context !== '') {
            $messages[] = ['role' => 'system', 'content' => $context];
        }

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
        $parts[] = 'Reply in a short, precise and natural way — usually 1-3 sentences and no more than ~60 words. Get straight to the point and skip filler. Always reply in the SAME language as the user\'s most recent message; if you cannot tell which language they used, reply in '.$langName.'; if you cannot write that language, use English. Use plain text only: no markdown, no HTML tags, and never write "<br>".';

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
                foreach ($r->items as $i => $passage) {
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
