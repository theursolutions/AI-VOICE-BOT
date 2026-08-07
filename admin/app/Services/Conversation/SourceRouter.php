<?php

namespace App\Services\Conversation;

use App\Models\DataSource;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Smart per-turn data-source router.
 *
 * Without this, {@see \App\Services\DataSource\DataSourceRouter} fans out
 * to EVERY active source on every question — so a "what's the price of
 * PRD-1002" query also hits the property database, the help-center docs and
 * the crawled website, wasting calls and polluting the LLM context with
 * irrelevant results (which is how hallucinations creep in).
 *
 * This routes the question to only the relevant source(s) using one LLM
 * call. Each source self-describes (structured tables → table + column
 * names; documents → file names; website → url) so the model can map the
 * question onto the right one(s): lookups/filters/sorts/counts → the
 * structured source, explanations/policies/how-to → docs or website.
 *
 * Requires a capable reasoning model (services.python.reasoning_provider).
 * With only a small local chat model configured we skip routing and let the
 * caller fall back to querying all sources, because a weak model picks badly.
 */
class SourceRouter
{
    public function __construct(private PythonClient $python) {}

    /**
     * @param  DataSource[] $sources  active candidate sources
     * @return int[]|null   selected source IDs (possibly empty = "none
     *                       relevant"); null = undecided, caller uses all.
     */
    public function select(string $userQuery, array $sources): ?array
    {
        // Routing needs a capable model; without one, don't second-guess.
        if ((string) config('services.python.reasoning_provider', '') === '') {
            return null;
        }
        if (count($sources) <= 1) {
            return null;   // nothing to narrow
        }

        $prompt = $this->buildPrompt($this->describe($sources), $userQuery);

        try {
            $resp = $this->python->llm(
                [['role' => 'system', 'content' => $prompt]],
                $this->llmOptions(),
            );
        } catch (Throwable $e) {
            Log::warning('SourceRouter: LLM call failed', ['error' => $e->getMessage()]);
            return null;
        }

        $text = trim((string) ($resp['text'] ?? ''));
        if ($text === '' || !preg_match('/\{.*\}/s', $text, $m)) {
            return null;
        }
        $decoded = json_decode($m[0], true);
        if (!is_array($decoded) || !array_key_exists('source_ids', $decoded)) {
            return null;
        }

        $allowed  = array_map(static fn ($s) => (int) $s->id, $sources);
        $selected = array_values(array_intersect(
            array_map('intval', (array) $decoded['source_ids']),
            $allowed,
        ));

        Log::info('SourceRouter: selected sources', [
            'query'    => substr($userQuery, 0, 160),
            'selected' => $selected,
            'from'     => $allowed,
        ]);

        return $selected;
    }

    /** Build the self-describing source catalog the model routes against. */
    private function describe(array $sources): string
    {
        $lines = [];
        foreach ($sources as $s) {
            $cfg  = $s->config ?? [];
            $desc = $this->describeOne($s, $cfg);
            if (!empty($cfg['description'])) {
                $desc = trim($cfg['description']) . ' — ' . $desc;
            }
            $lines[] = sprintf(
                '- source_id=%d  type=%s  name="%s"  %s',
                $s->id,
                $s->type,
                str_replace('"', "'", (string) $s->name),
                $desc,
            );
        }
        return implode("\n", $lines);
    }

    private function describeOne(DataSource $s, array $cfg): string
    {
        switch ($s->type) {
            case DataSource::TYPE_DATABASE:
            case DataSource::TYPE_DATA_SNAPSHOT:
                $schema = is_array($cfg['schema'] ?? null) ? $cfg['schema'] : [];
                $tables = [];
                foreach (array_slice($schema, 0, 8, true) as $t => $cols) {
                    $names = array_slice(array_map(
                        static fn ($c) => strtok((string) $c, ' '),
                        (array) $cols,
                    ), 0, 10);
                    $tables[] = $t . '(' . implode(', ', $names) . ')';
                }
                return 'structured SQL tables — ' . (empty($tables) ? 'no schema captured' : implode('; ', $tables));

            case DataSource::TYPE_WEBSITE:
                return 'crawled website text' . (!empty($cfg['url']) ? ' from ' . $cfg['url'] : '');

            case DataSource::TYPE_DOCUMENT:
                $files = array_filter(array_map(
                    static fn ($f) => is_array($f) ? ($f['original_name'] ?? '') : '',
                    (array) ($cfg['files'] ?? []),
                ));
                return 'uploaded documents (free text)' . (!empty($files) ? ': ' . implode(', ', $files) : '');

            case DataSource::TYPE_CRM_OAUTH:
                return 'connected CRM records (contacts, deals, tickets)';

            default:
                return (string) $s->type;
        }
    }

    private function buildPrompt(string $catalog, string $userQuery): string
    {
        return <<<PROMPT
You are a data-source router for a CRM assistant. Choose ONLY the source(s)
that can actually help answer the user's question.

Guidance:
- Structured SQL tables → exact lookups, filters, sorting, counts, prices,
  stock, IDs, "how many", "top N", "list X where ...".
- Documents / website → explanations, policies, how-to, descriptions,
  "what is", "how do I".
- Pick more than one only if the question genuinely spans them.
- Pick NONE (empty list) for greetings / small talk / anything unrelated to
  every source.
- Match meaning, not keywords: "product PRD-1002" or "cheapest items" maps to
  the table whose columns look like a product catalog.

# Available sources
{$catalog}

# Question
{$userQuery}

# Response format — JSON only, no prose, no markdown
{"source_ids": [<id>, ...]}
PROMPT;
    }

    private function llmOptions(): array
    {
        // Routing returns a tiny JSON object — deterministic + small cap = fast.
        $opts = ['respond_with' => 'text', 'temperature' => 0, 'max_tokens' => 150];
        $provider = (string) config('services.python.reasoning_provider', '');
        if ($provider !== '') {
            $opts['provider'] = $provider;
        }
        $model = (string) config('services.python.reasoning_model', '');
        if ($model !== '') {
            $opts['model'] = $model;
        }
        return $opts;
    }
}
