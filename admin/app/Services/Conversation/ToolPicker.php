<?php

namespace App\Services\Conversation;

use App\Models\DataSource;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decide which (if any) registered webhook tool the bot should invoke
 * for the current turn, and extract the required arguments from the
 * conversation history.
 *
 * One LLM call per turn covers ALL the project's webhook tools. The
 * model returns either a chosen `tool_id` + extracted `args`, or null
 * if no tool matches.
 *
 * The decision is then handed to WebhookResolver via the resolver
 * context, so only the picked webhook actually fires (the rest are
 * silenced for the turn).
 */
class ToolPicker
{
    public function __construct(private PythonClient $python) {}

    /**
     * @param array<int, array{role:string, content:string}> $recentHistory
     * @return array{tool_id:int, args:array<string,mixed>}|null
     */
    public function pick(int $projectId, array $recentHistory, string $userQuery): ?array
    {
        $tools = $this->loadWebhookTools($projectId);
        if (empty($tools)) {
            return null;
        }

        $prompt = $this->buildPrompt($tools, $recentHistory, $userQuery);

        try {
            $resp = $this->python->llm(
                [['role' => 'system', 'content' => $prompt]],
                ['project_id' => $projectId, 'respond_with' => 'text']
            );
        } catch (Throwable $e) {
            Log::warning('ToolPicker: LLM call failed', ['error' => $e->getMessage()]);
            return null;
        }

        $text = trim((string) ($resp['text'] ?? ''));
        if ($text === '') return null;

        // The model is asked to reply with JSON; pull the first {...} block.
        if (!preg_match('/\{.*\}/s', $text, $m)) {
            return null;
        }
        $decoded = json_decode($m[0], true);
        if (!is_array($decoded)) return null;

        $toolId = (int) ($decoded['tool_id'] ?? 0);
        $args   = is_array($decoded['args'] ?? null) ? $decoded['args'] : [];

        if ($toolId <= 0) return null;

        // Sanity check: tool_id must belong to a webhook of this project.
        $allowedIds = array_column($tools, 'id');
        if (!in_array($toolId, $allowedIds, true)) {
            Log::info('ToolPicker: model picked a tool outside the allowlist', [
                'picked' => $toolId, 'allowed' => $allowedIds,
            ]);
            return null;
        }

        return ['tool_id' => $toolId, 'args' => $args];
    }

    /** @return array<int, array{id:int, name:string, when_to_use:string, args:array}> */
    private function loadWebhookTools(int $projectId): array
    {
        return DataSource::where('project_id', $projectId)
            ->where('type', DataSource::TYPE_WEBHOOK)
            ->where('status', DataSource::STATUS_ACTIVE)
            ->where('is_active', 'Yes')
            ->get()
            ->map(function (DataSource $s) {
                $cfg = $s->config ?? [];
                return [
                    'id'          => $s->id,
                    'name'        => $s->name,
                    'when_to_use' => (string) ($cfg['when_to_use'] ?? ''),
                    'args'        => is_array($cfg['args'] ?? null) ? $cfg['args'] : [],
                ];
            })
            ->all();
    }

    private function buildPrompt(array $tools, array $history, string $userQuery): string
    {
        $toolDesc = '';
        foreach ($tools as $t) {
            $argsLine = !empty($t['args'])
                ? 'args: ' . json_encode($t['args'], JSON_UNESCAPED_SLASHES)
                : 'args: {}';
            $toolDesc .= sprintf(
                "- tool_id=%d  name=\"%s\"\n  when_to_use: %s\n  %s\n",
                $t['id'],
                str_replace('"', "'", $t['name']),
                $t['when_to_use'],
                $argsLine,
            );
        }

        $historyText = '';
        foreach (array_slice($history, -8) as $m) {  // last 8 turns only
            $role = $m['role'] ?? 'user';
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') continue;
            $historyText .= strtoupper($role) . ': ' . substr($content, 0, 400) . "\n";
        }

        return <<<PROMPT
You are an intent router for a CRM chatbot. Decide whether the latest
user message should trigger one of the registered webhook tools below.

# Rules

1. Match the user message to a tool's `when_to_use` description. If
   nothing fits, return `{"tool_id": null}`.
2. If a tool matches, extract values for each arg from the conversation
   (latest message first, then earlier turns). Only include args you
   can populate confidently — never invent.
3. Output ONLY a single JSON object, no prose, no markdown fences.

# Tools

{$toolDesc}

# Recent conversation

{$historyText}USER: {$userQuery}

# Response format

{"tool_id": <id or null>, "args": {<key>: <value>, ...}}
PROMPT;
    }
}
