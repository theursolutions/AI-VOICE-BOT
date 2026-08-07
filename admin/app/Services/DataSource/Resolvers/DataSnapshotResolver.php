<?php

namespace App\Services\DataSource\Resolvers;

use App\Models\DataSource;
use App\Services\Conversation\PythonClient;
use App\Services\DataSource\ResolverResult;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Structured data-snapshot resolver (CSV / JSON / XLSX uploads — "Tier B").
 *
 * A snapshot is *tabular* data — a product catalog, a tour list, a bus
 * timetable. Users query it like a table: exact lookups ("details of
 * PRD-1002"), filters / sorts ("10 highest-priced products"), aggregates
 * ("how many in stock"). Semantic similarity can't answer those.
 *
 * Storage = DuckDB (one file per project, table `snap_<source>`):
 *   - sync()    → engine loads the sheet into the project's DuckDB file and
 *                 returns the inferred column schema.
 *   - resolve() → reuse DatabaseResolver's text-to-SQL to build a SELECT,
 *                 execute it against DuckDB via the engine, repair once on
 *                 error. Columnar + compressed; no MySQL table, no embeddings.
 *
 * Still extends DocumentResolver for the shared upload/validateConfig
 * plumbing, but fully overrides sync() + resolve().
 */
class DataSnapshotResolver extends DocumentResolver
{
    public function __construct(
        private PythonClient $python,
        private DatabaseResolver $database,
    ) {
        parent::__construct($python);
    }

    public function type(): string
    {
        return DataSource::TYPE_DATA_SNAPSHOT;
    }

    public function needsSync(): bool
    {
        return true;
    }

    /** Load the uploaded sheet into the project's DuckDB file. */
    public function sync(DataSource $source): void
    {
        $cfg = $source->config ?? [];

        $files = array_values(array_filter(array_map(static function ($f) {
            if (!is_array($f) || empty($f['path'])) {
                return null;
            }
            return [
                'path'          => $f['path'],
                'original_name' => $f['original_name'] ?? basename($f['path']),
            ];
        }, $cfg['files'] ?? [])));

        if (empty($files)) {
            $source->update([
                'status'     => DataSource::STATUS_FAILED,
                'last_error' => 'No files configured',
                'update_at'  => time(),
            ]);
            return;
        }

        $resp  = $this->python->duckLoadTable($source->project_id, $source->id, $files);
        $table = $resp['table'] ?? "snap_{$source->id}";

        $source->update([
            'status'         => DataSource::STATUS_ACTIVE,
            'last_synced_at' => time(),
            'update_at'      => time(),
            'last_error'     => null,
            'config'         => array_merge($cfg, [
                'store'            => 'duckdb',
                'sql_table'        => $table,
                // {table: ["col type", ...]} — the shape SourceRouter +
                // DatabaseResolver's prompt builder expect.
                'schema'           => [$table => ($resp['schema'] ?? [])],
                'snapshot_rows'    => $resp['row_count'] ?? null,
                'snapshot_columns' => $resp['columns'] ?? [],
            ]),
        ]);
    }

    /** Text-to-SQL over the snapshot's DuckDB table. */
    public function resolve(string $userQuery, DataSource $source, array $context = []): ResolverResult
    {
        $cfg    = $source->config ?? [];
        $table  = $cfg['sql_table'] ?? null;
        $schema = is_array($cfg['schema'] ?? null) ? $cfg['schema'] : [];

        if (!$table || empty($schema)) {
            // Not loaded yet (sync pending/failed) — contribute nothing rather
            // than fall back to similarity search.
            return ResolverResult::empty($source->id, $source->type);
        }

        $maxRows = (int) ($cfg['max_rows'] ?? 100);
        $projectId = (int) $source->project_id;

        $exec = fn (string $sql): array => $this->runDuck($projectId, $sql);

        try {
            $sql  = $this->database->buildSql($userQuery, $schema, $maxRows);
            $repaired = false;
            try {
                $rows = $exec($sql);
            } catch (Throwable $e) {
                $sql  = $this->database->repairAndValidate($userQuery, $schema, $sql, $e->getMessage(), $maxRows);
                $rows = $exec($sql);
                $repaired = true;
            }
        } catch (Throwable $e) {
            Log::warning('DataSnapshotResolver: query failed', [
                'source_id' => $source->id, 'error' => $e->getMessage(),
            ]);
            return ResolverResult::error($source->id, $source->type, $e->getMessage());
        }

        Log::info('DataSnapshotResolver: query ok', [
            'source_id' => $source->id, 'project_id' => $projectId,
            'sql' => $sql, 'rows' => count($rows), 'repaired' => $repaired,
        ]);

        return ResolverResult::records(
            $source->id,
            $source->type,
            $rows,
            ['sql' => $sql, 'repaired' => $repaired],
        );
    }

    /**
     * Execute a SELECT against the project's DuckDB file via the engine.
     * Re-throws DuckDB errors with their message so the repair loop can use
     * the actual DB error text.
     */
    private function runDuck(int $projectId, string $sql): array
    {
        try {
            $resp = $this->python->duckQuery($projectId, $sql);
        } catch (BadResponseException $e) {
            $body = (string) $e->getResponse()->getBody();
            $detail = json_decode($body, true)['detail'] ?? $e->getMessage();
            throw new \RuntimeException((string) $detail);
        }
        return $resp['rows'] ?? [];
    }
}
