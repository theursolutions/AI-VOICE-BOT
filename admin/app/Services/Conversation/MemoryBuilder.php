<?php

namespace App\Services\Conversation;

use App\Models\BotAgent;
use App\Models\Message;
use App\Models\Session;
use App\Services\DataSource\ResolverResult;

class MemoryBuilder
{
    private const RECENT_TURNS = 6;

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
        $recent  = Message::where('session_id', $session->id)
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

        $context = $this->formatContext($contextResults);
        if ($context !== '') {
            $messages[] = ['role' => 'system', 'content' => $context];
        }

        foreach ($recent as $msg) {
            if (in_array($msg->role, ['user', 'assistant'], true) && $msg->content) {
                $messages[] = ['role' => $msg->role, 'content' => $msg->content];
            }
        }

        return $messages;
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
        $parts[] = 'Reply in a short, precise and natural way — usually 1-3 sentences and no more than ~60 words. Get straight to the point and skip filler. Always reply in the SAME language as the user\'s most recent message; if you cannot tell which language they used, reply in '.$langName.'; if you cannot write that language, use English. Use plain text only: no markdown, no HTML tags, and never write "<br>". Capture lead details (name, email, phone, intent) naturally, asking for one thing at a time.';

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
