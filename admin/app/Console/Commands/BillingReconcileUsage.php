<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\DataSource;
use App\Models\Project;
use App\Services\Billing\UsageLimitService;
use App\Services\Conversation\PythonClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile the ABSOLUTE usage metrics from measured state.
 *
 * WHY THIS EXISTS RATHER THAN AN EVENT HOOK.
 *
 * Conversations and call minutes are events: they happen once, at a known
 * moment, and are counted there. Indexed pages and stored bytes are not — they
 * are a standing total produced by an ASYNCHRONOUS pipeline. `rag/ingest`
 * returns a job id immediately and the crawler keeps working long after the
 * HTTP request is over, so there is no moment in PHP that knows the final page
 * count.
 *
 * Chasing that with events would mean polling a job registry the engine wipes
 * on restart, and every missed callback would silently under-bill for good.
 * Measuring the real state instead is simpler and self-healing: whatever went
 * wrong last hour, the next run reports the truth.
 *
 *   indexed_pages → COUNT(*) of the project's DuckDB index tables
 *   storage_mb    → bytes on disk under the project's data-source directory
 *
 * Safe to run as often as you like: it SETS absolute values rather than adding
 * to them (UsageLimitService::setAbsolute).
 */
class BillingReconcileUsage extends Command
{
    protected $signature = 'billing:reconcile-usage
                            {--client= : Only this client id}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Recompute indexed-page and storage usage from actual state';

    public function handle(UsageLimitService $usage, PythonClient $python): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        $clients = Client::query()
            ->when($this->option('client'), fn ($q, $id) => $q->whereKey((int) $id))
            ->whereNull('deleted_at')
            ->get();

        $touched = 0;

        foreach ($clients as $client) {
            $projects = Project::where('client_id', $client->id)
                ->where('is_active', 'Yes')
                ->get(['id']);

            if ($projects->isEmpty()) {
                continue;
            }

            $pages     = 0;
            $bytes     = 0;
            $pagesKnown = false;

            foreach ($projects as $project) {
                $bytes += $this->storageBytes((int) $project->id);

                [$projectPages, $ok] = $this->indexedRows($python, (int) $project->id);
                $pages += $projectPages;

                // Only one project needs to answer for the total to be worth
                // writing. See the guard below for why this matters.
                $pagesKnown = $pagesKnown || $ok;
            }

            $megabytes = (int) floor($bytes / 1048576);

            $this->line(sprintf(
                '  %-28s pages: %-8s storage: %s MB',
                \Illuminate\Support\Str::limit($client->name, 26),
                $pagesKnown ? number_format($pages) : 'unknown',
                number_format($megabytes)
            ));

            if ($dry) {
                continue;
            }

            // Storage is measured from our own disk, so it is always known.
            $usage->setAbsolute($client, 'storage_mb', $megabytes, (int) $projects->first()->id);

            // Pages are only written when the engine actually answered.
            // Otherwise an engine that is merely DOWN would look like a
            // customer who has indexed nothing, and we would wipe a real
            // figure — the one way a reconciler can be worse than no
            // reconciler at all.
            if ($pagesKnown) {
                $usage->setAbsolute($client, 'indexed_pages', $pages, (int) $projects->first()->id);
            }

            $touched++;
        }

        $this->newLine();
        $this->info($dry ? "{$clients->count()} client(s) inspected." : "{$touched} client(s) reconciled.");

        return self::SUCCESS;
    }

    /**
     * Indexed rows across a project's DuckDB tables.
     *
     * @return array{0:int,1:bool} [rows, engineAnswered]
     */
    private function indexedRows(PythonClient $python, int $projectId): array
    {
        $sources = DataSource::where('project_id', $projectId)
            ->whereIn('type', [
                DataSource::TYPE_DOCUMENT,
                DataSource::TYPE_WEBSITE,
                DataSource::TYPE_DATA_SNAPSHOT,
            ])
            ->get(['id', 'type']);

        if ($sources->isEmpty()) {
            return [0, true];   // genuinely nothing indexed
        }

        $rows     = 0;
        $answered = false;

        foreach ($sources as $source) {
            $table = $source->type === DataSource::TYPE_DATA_SNAPSHOT
                ? 'snap_' . (int) $source->id
                : 'docs_' . (int) $source->id;

            try {
                $resp = $python->duckQuery($projectId, "SELECT COUNT(*) AS n FROM {$table}");
                $rows += (int) ($resp['rows'][0]['n'] ?? 0);
                $answered = true;
            } catch (\Throwable $e) {
                // A missing table means that source never ingested — real, and
                // correctly counts as zero. An unreachable engine is different,
                // but we can't tell them apart here, so `answered` stays false
                // unless at least one query succeeded.
                Log::debug('billing.reconcile.duck_query_failed', [
                    'project_id' => $projectId,
                    'table'      => $table,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return [$rows, $answered];
    }

    /** Bytes stored under a project's uploaded-documents directory. */
    private function storageBytes(int $projectId): int
    {
        $dir = storage_path("app/data_sources/project_{$projectId}");

        if (! is_dir($dir)) {
            return 0;
        }

        $total = 0;

        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }
}
