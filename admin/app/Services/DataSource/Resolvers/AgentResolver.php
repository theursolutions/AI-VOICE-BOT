<?php

namespace App\Services\DataSource\Resolvers;

use App\Models\Agent;
use App\Models\AgentQuery;
use App\Models\DataSource;
use App\Services\DataSource\ResolverInterface;
use App\Services\DataSource\ResolverResult;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tier 3b — customer-hosted query agent.
 *
 * Flow per resolve():
 *   1. Use OllamaService to translate the user's natural-language
 *      question into a SQL SELECT against the project's stored schema.
 *   2. Enqueue an agent_queries row addressed to the agent referenced
 *      in this DataSource's config (`config.agent_id`).
 *   3. Poll the row until status flips to done / failed, or timeout.
 *   4. Return rows as ResolverResult::records.
 *
 * config = { agent_id: int, schema?: string }
 *
 * The customer's DB credentials are never seen by this code — they
 * live in the customer's Docker container alongside the agent binary.
 */
class AgentResolver implements ResolverInterface
{
    private const WAIT_TIMEOUT_SECONDS = 30;
    private const POLL_INTERVAL_MS     = 250;
    private const MAX_ROWS             = 100;

    public function __construct(private OllamaService $ollama) {}

    public function type(): string
    {
        return DataSource::TYPE_AGENT;
    }

    public function resolve(string $userQuery, DataSource $source, array $context = []): ResolverResult
    {
        try {
            $cfg = $source->config ?? [];

            $agentId = (int) ($cfg['agent_id'] ?? 0);
            $agent = Agent::find($agentId);

            if (!$agent || !$agent->isActive()) {
                return ResolverResult::error($source->id, $source->type, 'Agent not active');
            }

            if (!$agent->last_seen_at || $agent->last_seen_at < time() - 120) {
                return ResolverResult::error($source->id, $source->type, 'Agent appears offline (no recent poll)');
            }

            $schema = $cfg['schema'] ?? $source->project?->db_schema;
            if (empty($schema)) {
                return ResolverResult::error($source->id, $source->type, 'No schema configured for SQL generation');
            }

            // Apply table/column ACL before the LLM sees the schema —
            // same privacy guarantee as DatabaseResolver: the agent
            // can't generate SQL for tables/columns the admin hid.
            if (is_array($schema)) {
                $schema = app(\App\Services\DataSource\SchemaAclFilter::class)->filter($schema, $cfg);
                if (empty($schema)) {
                    return ResolverResult::error($source->id, $source->type,
                        'No tables are allow-listed for AI access on this data source.');
                }
            }
            $schemaText = is_string($schema) ? $schema : json_encode($schema);

            $rawSql = $this->ollama->generateSqlQuery($userQuery, $schemaText);
            $sql    = $this->stripFences($rawSql);

            if (!preg_match('/^\s*select\b/i', $sql)) {
                return ResolverResult::error($source->id, $source->type, 'Generated SQL was not a SELECT');
            }
            if (str_contains($sql, ';')) {
                return ResolverResult::error($source->id, $source->type, 'Generated SQL contains a semicolon');
            }

            $requestId = (string) Str::uuid();

            AgentQuery::create([
                'agent_id'   => $agent->id,
                'request_id' => $requestId,
                'sql'        => $sql,
                'params'     => null,
                'max_rows'   => self::MAX_ROWS,
                'status'     => AgentQuery::STATUS_PENDING,
                'created_at' => time(),
            ]);

            $row = $this->waitForResult($requestId);

            if (!$row) {
                AgentQuery::where('request_id', $requestId)->update([
                    'status'       => AgentQuery::STATUS_TIMEOUT,
                    'completed_at' => time(),
                ]);
                return ResolverResult::error($source->id, $source->type, 'Agent did not respond in time');
            }

            if ($row->status === AgentQuery::STATUS_FAILED) {
                return ResolverResult::error($source->id, $source->type, $row->error ?: 'Agent reported failure');
            }

            $rows = $row->result ?? [];
            if (empty($rows)) {
                return ResolverResult::empty($source->id, $source->type);
            }

            return ResolverResult::records(
                $source->id,
                $source->type,
                $rows,
                ['sql' => $sql, 'request_id' => $requestId],
            );
        } catch (Throwable $e) {
            Log::error("AgentResolver crashed for source #{$source->id}: ".$e->getMessage());
            return ResolverResult::error($source->id, $source->type, $e->getMessage());
        }
    }

    private function waitForResult(string $requestId): ?AgentQuery
    {
        $deadline = microtime(true) + self::WAIT_TIMEOUT_SECONDS;

        do {
            $row = AgentQuery::where('request_id', $requestId)->first();
            if ($row && in_array($row->status, [AgentQuery::STATUS_DONE, AgentQuery::STATUS_FAILED], true)) {
                return $row;
            }
            usleep(self::POLL_INTERVAL_MS * 1000);
        } while (microtime(true) < $deadline);

        return null;
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (empty($config['agent_id']) || !is_numeric($config['agent_id'])) {
            $errors['agent_id'] = 'agent_id is required';
        } else {
            $agent = Agent::find((int) $config['agent_id']);
            if (!$agent) {
                $errors['agent_id'] = 'Agent not found';
            }
        }
        return $errors;
    }

    public function needsSync(): bool
    {
        return false;
    }

    public function sync(DataSource $source): void {}

    private function stripFences(string $text): string
    {
        return trim(preg_replace('/```sql|```/', '', $text) ?? $text);
    }
}
