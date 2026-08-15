<?php

namespace App\Services\Flow;

use App\Flow\NodeCatalog;
use App\Models\BotAgent;
use App\Models\DataSource;
use App\Models\Project;
use App\Services\Conversation\PythonClient;
use Illuminate\Support\Facades\Log;

/**
 * Turns "my flow should be like this…" into a real flow graph.
 *
 * The hard part is not generating JSON — it's making sure the JSON describes
 * something the runtime will actually execute, and telling the customer
 * plainly when part of what they asked for isn't buildable. Three mechanisms
 * do that, in order of how much they matter:
 *
 *   1. The prompt only ever lists node types that RUN on the target channel
 *      (App\Flow\NodeCatalog::promptSpec), together with the project's real
 *      agent and data-source ids. The model cannot pick a node the runtime
 *      would drop, because it is never shown one.
 *   2. Everything that comes back goes through FlowValidator before it can be
 *      saved. On failure the errors are handed back for exactly one repair
 *      round-trip — enough to fix a wrong branch name, not enough to spin.
 *   3. The model must return a `gaps` list: each thing it could not build,
 *      why, and the closest alternative. NodeCatalog::knownGaps seeds it with
 *      the cases we already know about, so the answer is specific rather than
 *      "not supported".
 *
 * Nothing here writes to the database. The caller previews the result and the
 * customer saves through the normal editor, so an AI-built flow goes live by
 * exactly the same path as a hand-built one.
 */
class FlowPlanner
{
    /** Cap on generated graph size — beyond this it stops being reviewable. */
    private const MAX_NODES = 40;

    public function __construct(
        private PythonClient $python,
        private FlowValidator $validator,
        private FlowAutoLayout $layout,
    ) {}

    /**
     * @param  string  $brief    what the customer typed
     * @param  string  $channel  NodeCatalog::CHANNEL_VOICE | CHANNEL_CHAT
     * @param  array|null  $existing  current graph, when revising rather than creating
     *
     * @return array{
     *   ok:bool, definition:?array, summary:string, steps:array<int,string>,
     *   gaps:array<int,array{cannot:string,because:string,instead:string}>,
     *   assumptions:array<int,string>, warnings:array<int,string>,
     *   errors:array<int,string>, repaired:bool
     * }
     */
    public function plan(Project $project, string $brief, string $channel, ?array $existing = null): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            return $this->failure(['Describe the flow you want before generating.']);
        }

        $context = $this->projectContext($project);
        $context['channel'] = $channel;

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($channel, $context)],
            ['role' => 'user',   'content' => $this->userPrompt($brief, $existing)],
        ];

        $raw = $this->ask($messages);
        if ($raw === null) {
            return $this->failure([
                'The flow builder could not reach the AI service. Try again in a moment.',
            ]);
        }

        $parsed = $this->decode($raw);
        if ($parsed === null) {
            return $this->failure(['The AI returned something that was not a valid flow. Try rewording your description.']);
        }

        $definition = $this->extractDefinition($parsed);
        $check      = $this->validator->validate($definition, $context);
        $repaired   = false;

        // One repair attempt. A second is not worth the latency: if the model
        // cannot fix a graph when handed the exact errors, the brief itself is
        // usually the problem and the customer needs to see that.
        if (! $check['ok']) {
            Log::info('FlowPlanner: first attempt invalid, repairing', [
                'project_id' => $project->id, 'errors' => $check['errors'],
            ]);

            $messages[] = ['role' => 'assistant', 'content' => $raw];
            $messages[] = ['role' => 'user', 'content' =>
                "That flow is invalid. Fix exactly these problems and return the same JSON shape again:\n\n- "
                . implode("\n- ", $check['errors'])
                . "\n\nChange nothing else. Return JSON only.",
            ];

            $retry = $this->ask($messages);
            $retryParsed = $retry !== null ? $this->decode($retry) : null;

            if ($retryParsed !== null) {
                $retryCheck = $this->validator->validate($this->extractDefinition($retryParsed), $context);
                if ($retryCheck['ok']) {
                    $parsed   = $retryParsed;
                    $check    = $retryCheck;
                    $repaired = true;
                }
            }
        }

        if (! $check['ok']) {
            return [
                'ok'          => false,
                'definition'  => null,
                'summary'     => (string) ($parsed['summary'] ?? ''),
                'steps'       => $this->stringList($parsed['steps'] ?? []),
                'gaps'        => $this->gapList($parsed['gaps'] ?? []),
                'assumptions' => $this->stringList($parsed['assumptions'] ?? []),
                'warnings'    => $check['warnings'],
                'errors'      => $check['errors'],
                'repaired'    => false,
            ];
        }

        return [
            'ok'          => true,
            'definition'  => $this->layout->apply($check['definition']),
            'summary'     => (string) ($parsed['summary'] ?? ''),
            'steps'       => $this->stringList($parsed['steps'] ?? []),
            'gaps'        => $this->gapList($parsed['gaps'] ?? []),
            'assumptions' => $this->stringList($parsed['assumptions'] ?? []),
            'warnings'    => $check['warnings'],
            'errors'      => [],
            'repaired'    => $repaired,
        ];
    }

    // ── Prompting ────────────────────────────────────────────────────

    private function systemPrompt(string $channel, array $context): string
    {
        $where = $channel === NodeCatalog::CHANNEL_VOICE
            ? 'a PHONE CALL. The customer hears everything and cannot see or type anything — they can only speak or press keypad digits.'
            : 'a CHAT conversation (website widget, WhatsApp, Messenger or Instagram). The customer reads messages and can tap buttons or type replies.';

        $agents = $context['agents'] === []
            ? 'none — always use agent_id: null'
            : implode(', ', array_map(fn ($a) => "#{$a['id']} \"{$a['name']}\"", $context['agents']));

        // Listed only where the datasource node exists. On a voice flow it
        // does not, and naming it here was enough to make the model try.
        $sourceLine = '';
        if (NodeCatalog::supports('datasource', $channel)) {
            $sourceLine = "\nData sources (for datasource.source_ids): " . ($context['sources'] === []
                ? 'none — do not use the datasource node'
                : implode(', ', array_map(fn ($s) => "#{$s['id']} \"{$s['name']}\"", $context['sources'])));
        }

        $gaps = implode("\n", array_map(
            fn ($g) => "- {$g['cannot']} → {$g['because']} Offer instead: {$g['instead']}",
            NodeCatalog::knownGaps($channel)
        ));

        // Only the node types that genuinely run on this channel. The model
        // cannot choose one the runtime would drop, because it never sees it.
        $spec = NodeCatalog::promptSpec($channel);

        $maxNodes = self::MAX_NODES;

        // Rule 7 only makes sense where collect_input exists. Naming an
        // unavailable node anywhere in the prompt invites the model to reach
        // for it, which is the exact failure the capability list prevents.
        $variablesRule = NodeCatalog::supports('collect_input', $channel)
            ? "\n7. Use `{{ variable }}` to reuse an answer captured earlier by `collect_input`."
            : '';

        return <<<PROMPT
You design conversation flows for Serve AI. The customer describes what they want in plain language and you return ONE JSON object describing the flow.

This flow will run on {$where}

## Node types you may use — these are the ONLY ones that exist
{$spec}

## This project's real resources
Agents (for transfer_ai.agent_id): {$agents}{$sourceLine}

Never invent an id. If the customer asks for an agent or knowledge base that is not in those lists, use the default (null / empty) and record it in "gaps".

## Things this builder genuinely cannot do
If the customer's request needs any of these, DO NOT fake it with another node. Build everything else, leave that part out, and describe it in "gaps":
{$gaps}

## Rules
1. Exactly one `start` node. Every path must end at a terminal node (`end` or `transfer_ai`) — never leave a branch dangling.
2. Edges: {"id","source","target","sourceHandle"}. `sourceHandle` MUST be one of the branch ids listed for the source node's type. For `capture_dtmf` the handles are the option digits themselves plus "timeout".
3. Wire EVERY branch of every node you use, including "timeout", "no_match" and "error". A timeout usually re-prompts or goes to a fallback, not nowhere.
4. Do not set node `position` — coordinates are computed after you.
5. Write the wording the customer's business would actually use. On a phone call, read menu options aloud in the prompt text ("Press 1 for sales…") because the caller cannot see buttons.
6. Keep it under {$maxNodes} nodes. Prefer the simplest graph that does the job.{$variablesRule}

## Output — return ONLY this JSON object, no markdown fence, no commentary
{
  "summary": "One or two sentences describing the flow you built.",
  "steps": ["Short plain-language description of each step, in order."],
  "assumptions": ["Anything you had to decide because the brief did not say."],
  "gaps": [
    {"cannot": "what they asked for that you could not build",
     "because": "the concrete reason",
     "instead": "what you did instead, or what they should do"}
  ],
  "definition": {
    "nodes": [{"id": "start", "type": "start", "data": {"label": "Call connects"}}],
    "edges": [],
    "settings": {"language": "en", "timeout_secs": 8, "max_retries": 2}
  }
}

"gaps" must be [] when everything asked for was built. Never claim to have built something you did not.
PROMPT;
    }

    private function userPrompt(string $brief, ?array $existing): string
    {
        if ($existing !== null && ! empty($existing['nodes'])) {
            return "Here is the flow as it stands today:\n\n"
                . json_encode(['nodes' => $existing['nodes'], 'edges' => $existing['edges'] ?? []],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\n\nChange it as follows, keeping everything else intact:\n\n{$brief}";
        }

        return "Build a flow for this:\n\n{$brief}";
    }

    // ── LLM plumbing ─────────────────────────────────────────────────

    /**
     * Uses the capable reasoning model rather than the chat model — the chat
     * model on this platform can be a 1B local one, which cannot hold a graph
     * schema in its head. Same config the text-to-SQL and routing calls use.
     */
    private function ask(array $messages): ?string
    {
        $opts = [
            'respond_with' => 'text',
            'temperature'  => 0.2,       // structure, not creativity
            'max_tokens'   => 4000,
        ];

        if ($provider = (string) config('services.python.reasoning_provider', '')) {
            $opts['provider'] = $provider;
        }
        if ($model = (string) config('services.python.reasoning_model', '')) {
            $opts['model'] = $model;
        }

        try {
            $res = $this->python->llm($messages, $opts);
        } catch (\Throwable $e) {
            Log::warning('FlowPlanner: LLM call failed', ['err' => $e->getMessage()]);

            return null;
        }

        $text = (string) ($res['text'] ?? $res['content'] ?? $res['answer'] ?? '');

        return trim($text) === '' ? null : $text;
    }

    /**
     * Models wrap JSON in prose or a ```json fence often enough that stripping
     * it is part of the contract, not a workaround.
     */
    private function decode(string $raw): ?array
    {
        $text = trim($raw);

        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $m)) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Last resort: the outermost {...} in the response.
        $first = strpos($text, '{');
        $last  = strrpos($text, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $decoded = json_decode(substr($text, $first, $last - $first + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        Log::warning('FlowPlanner: could not parse model output', ['head' => mb_substr($raw, 0, 400)]);

        return null;
    }

    /** Accepts either {definition:{nodes,edges}} or a bare {nodes,edges}. */
    private function extractDefinition(array $parsed): array
    {
        $def = is_array($parsed['definition'] ?? null) ? $parsed['definition'] : $parsed;

        return [
            'nodes'    => array_values((array) ($def['nodes'] ?? [])),
            'edges'    => array_values((array) ($def['edges'] ?? [])),
            'settings' => (array) ($def['settings'] ?? []),
        ];
    }

    // ── Project context ──────────────────────────────────────────────

    /** Real ids the model is allowed to reference. */
    private function projectContext(Project $project): array
    {
        $agents = [];
        $sources = [];

        try {
            $agents = BotAgent::query()
                ->where('project_id', $project->id)
                ->get(['id', 'name'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'name' => (string) $a->name])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('FlowPlanner: could not list agents', ['err' => $e->getMessage()]);
        }

        try {
            $sources = DataSource::query()
                ->where('project_id', $project->id)
                ->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('FlowPlanner: could not list data sources', ['err' => $e->getMessage()]);
        }

        return [
            'agents'     => $agents,
            'sources'    => $sources,
            'agent_ids'  => array_column($agents, 'id'),
            'source_ids' => array_column($sources, 'id'),
        ];
    }

    // ── Shaping ──────────────────────────────────────────────────────

    /** @return array<int,string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(
            array_map(fn ($v) => trim((string) (is_scalar($v) ? $v : json_encode($v))), (array) $value),
            fn ($v) => $v !== ''
        ));
    }

    /** @return array<int,array{cannot:string,because:string,instead:string}> */
    private function gapList(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $g) {
            if (! is_array($g)) {
                continue;
            }
            $cannot = trim((string) ($g['cannot'] ?? ''));
            if ($cannot === '') {
                continue;
            }
            $out[] = [
                'cannot'  => $cannot,
                'because' => trim((string) ($g['because'] ?? '')),
                'instead' => trim((string) ($g['instead'] ?? '')),
            ];
        }

        return $out;
    }

    private function failure(array $errors): array
    {
        return [
            'ok' => false, 'definition' => null, 'summary' => '', 'steps' => [],
            'gaps' => [], 'assumptions' => [], 'warnings' => [],
            'errors' => $errors, 'repaired' => false,
        ];
    }
}
