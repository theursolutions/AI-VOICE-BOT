<?php

namespace App\Jobs\LeadGen;

use App\Models\LeadGen\Event;
use App\Models\LeadGen\Search;
use App\Services\LeadGen\Providers\ImportSource;
use App\Services\LeadGen\Providers\ProviderException;
use App\Services\LeadGen\Providers\ProviderRegistry;
use App\Services\LeadGen\Providers\SearchCriteria;
use App\Services\LeadGen\ProspectWriter;
use App\Services\Tenant\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one discovery search, a few pages at a time.
 *
 * Each run processes at most PAGES_PER_RUN pages and then queues the next
 * run, so a large search never holds a worker for long and pause/cancel take
 * effect within one page. State lives on the search row (status, cursor,
 * progress), so any run — first, resumed, or retried after a crash — picks
 * up exactly where the last one stopped. Saving a page is idempotent
 * (ProspectWriter), so a run that dies mid-page and is retried double-counts
 * nothing.
 *
 * WithoutOverlapping keys on the search, so two runs of the same search can
 * never interleave (e.g. "resume" clicked while a retry is pending).
 */
class RunSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGES_PER_RUN = 5;
    public const PAGE_SIZE     = 100;

    public int $tries = 0;          // bounded by retryUntil()
    public int $maxExceptions = 3;  // genuine crashes, not "area still loading"
    public int $timeout = 300;

    public function __construct(public int $projectId, public int $searchId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("leadgen-search:{$this->projectId}:{$this->searchId}"))
            ->releaseAfter(20)->expireAfter(600)];
    }

    public function handle(TenantManager $tenants, ProviderRegistry $registry, ProspectWriter $writer): void
    {
        $tenants->useForProjectId($this->projectId);

        $search = Search::find($this->searchId);
        if (!$search || $search->isFinished() || $search->status === Search::PAUSED) {
            return;
        }
        if ($search->status === Search::CANCELLING) {
            $this->finish($search, Search::CANCELLED, 'search.cancelled');
            return;
        }
        if ($search->status === Search::QUEUED) {
            // Conditional so two runs can't both "start" it.
            $claimed = Search::whereKey($search->id)->where('status', Search::QUEUED)
                ->update(['status' => Search::RUNNING, 'started_at' => $search->started_at ?: time(), 'updated_at' => time()]);
            if ($claimed) {
                Event::log('search', $search->id, 'search.started', ['provider' => $search->provider], 'info', $search->created_by);
            }
            $search->refresh();
        }

        try {
            // An import pages through the uploaded file; a search through its source.
            if ($search->provider === ImportSource::KEY) {
                $import     = app(ImportSource::class);
                $maxResults = ImportSource::MAX_ROWS;
                $fetch      = fn (?array $cursor, int $want) => $import->page((array) $search->criteria, $cursor, $want);
            } else {
                $source   = $registry->get($search->provider);
                $criteria = SearchCriteria::fromArray($search->criteria);

                if (!$source->isConfigured()) {
                    throw ProviderException::notConfigured((string) $source->configurationHint());
                }
                if (!$source->prepare($criteria)) {
                    $this->progress($search, ['phase' => 'loading_area']);
                    $this->release(15);
                    return;
                }
                $maxResults = $criteria->maxResults;
                $fetch      = fn (?array $cursor, int $want) => $source->search($criteria, $cursor, $want);
            }

            $progress = (array) $search->progress;
            for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
                $stored = (int) ($progress['new'] ?? 0) + (int) ($progress['duplicates'] ?? 0) + (int) ($progress['in_crm'] ?? 0);
                $want   = min(self::PAGE_SIZE, $maxResults - $stored);
                if ($want <= 0) {
                    $this->finish($search, Search::COMPLETED, 'search.completed', ['reason' => 'max_results']);
                    return;
                }

                $result = $fetch($search->cursor ?: null, $want);
                $counts = $writer->write($search, $result->items, $search->created_by);

                $progress = [
                    'phase'      => 'fetching',
                    'total'      => $result->total ?? ($progress['total'] ?? null),
                    'fetched'    => (int) ($progress['fetched'] ?? 0) + count($result->items),
                    'new'        => (int) ($progress['new'] ?? 0) + $counts['new'],
                    'duplicates' => (int) ($progress['duplicates'] ?? 0) + $counts['duplicate'],
                    'in_crm'     => (int) ($progress['in_crm'] ?? 0) + $counts['existing_crm'],
                    'skipped'    => (int) ($progress['skipped'] ?? 0) + $counts['skipped'],
                ] + $progress;
                $search->forceFill(['cursor' => $result->next, 'progress' => $progress])->save();

                if ($result->next === null) {
                    $this->finish($search, Search::COMPLETED, 'search.completed', ['reason' => 'exhausted']);
                    return;
                }

                // Pause / cancel clicked while we were fetching.
                $status = Search::whereKey($search->id)->value('status');
                if ($status === Search::PAUSED) {
                    return;
                }
                if ($status === Search::CANCELLING) {
                    $this->finish($search, Search::CANCELLED, 'search.cancelled');
                    return;
                }
            }

            // More to fetch: hand over to a fresh run.
            static::dispatch($this->projectId, $this->searchId);
        } catch (ProviderException $e) {
            if ($e->retryable()) {
                Event::log('search', $search->id, 'search.retry', ['kind' => $e->kind, 'message' => $e->getMessage()], 'warning');
                $this->release($e->retryAfter ?? 30);
                return;
            }
            $search->forceFill(['error' => $e->getMessage()])->save();
            $this->finish($search, Search::FAILED, 'search.failed', ['kind' => $e->kind, 'message' => $e->getMessage()], 'error');
        }
    }

    /** The queue gave up (retryUntil passed or too many crashes). */
    public function failed(Throwable $e): void
    {
        try {
            app(TenantManager::class)->useForProjectId($this->projectId);
            $search = Search::find($this->searchId);
            if ($search && !$search->isFinished()) {
                $search->forceFill(['error' => 'The search stopped unexpectedly. You can re-run it.'])->save();
                $this->finish($search, Search::FAILED, 'search.failed', ['exception' => class_basename($e), 'message' => mb_substr($e->getMessage(), 0, 500)], 'error');
            }
        } catch (Throwable $inner) {
            Log::error('leadgen.search.failed_handler', ['search_id' => $this->searchId, 'err' => $inner->getMessage()]);
        }
    }

    private function progress(Search $search, array $patch): void
    {
        $search->forceFill(['progress' => $patch + (array) $search->progress])->save();
    }

    private function finish(Search $search, string $status, string $event, array $payload = [], string $level = 'info'): void
    {
        $search->forceFill([
            'status'      => $status,
            'finished_at' => time(),
            'progress'    => ['phase' => $status] + (array) $search->progress,
        ])->save();
        Event::log('search', $search->id, $event, $payload + ['progress' => $search->progress], $level);
    }
}
