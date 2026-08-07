<?php

namespace App\Services\DataSource;

use App\Models\DataSource;
use App\Services\Conversation\PythonClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cleans up everything a data source owns, to be called BEFORE the row is
 * deleted. Type-aware so it never destroys data it doesn't own:
 *
 *   data_snapshot → DROP the auto-built `snap_*` table (ours) + uploaded file
 *   document      → delete RAG vectors + uploaded file
 *   website       → delete RAG vectors
 *   database      → NOTHING (it points at the CUSTOMER's external DB — we
 *                   only ever forget the connection, never touch their tables)
 *   webhook/crm   → nothing to clean
 *
 * Every step is best-effort: a failure to drop a table or reach the engine
 * must not block the user from removing the source.
 */
class SourceCleaner
{
    public function __construct(private PythonClient $python) {}

    public function purge(DataSource $source): void
    {
        // 1) Snapshot / document / website all live in the project's DuckDB
        //    file now — drop their table(s). Safe: we own that store and it
        //    only ever holds this project's data-source tables.
        if (in_array($source->type, [
            DataSource::TYPE_DATA_SNAPSHOT,
            DataSource::TYPE_DOCUMENT,
            DataSource::TYPE_WEBSITE,
        ], true)) {
            try {
                $this->python->duckDropSource((int) $source->project_id, (int) $source->id);
            } catch (Throwable $e) {
                Log::warning('SourceCleaner: duck drop failed', [
                    'source_id' => $source->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        // 2) Uploaded files we stored on disk (document / snapshot).
        $this->deleteUploadedFiles($source);
    }

    private function deleteUploadedFiles(DataSource $source): void
    {
        $files = (array) data_get($source->config, 'files', []);
        foreach ($files as $f) {
            $path = is_array($f) ? ($f['path'] ?? null) : null;
            if (!$path || !is_file($path)) {
                continue;
            }
            // Only ever unlink files inside our own upload tree.
            if (str_contains(str_replace('\\', '/', (string) $path), '/data_sources/')) {
                @unlink($path);
            }
        }
    }
}
