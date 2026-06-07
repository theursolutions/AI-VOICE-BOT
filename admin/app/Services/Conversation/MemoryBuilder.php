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

        $messages[] = [
            'role'    => 'system',
            'content' => $this->systemPrompt($project, $summary, $agent),
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

    private function systemPrompt($project, ?string $summary, ?BotAgent $agent = null): string
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

        $parts[] = 'Be concise. Answer from the Reference data below when present. If the user asks for something outside that data, say so. Capture lead details (name, email, phone, intent) naturally.';

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

        $lines = ['### Reference data'];

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
                $lines[] = 'Query results from '.$r->sourceType.':';
                foreach (array_slice($r->items, 0, 20) as $row) {
                    $lines[] = '- '.json_encode($row, JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
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
