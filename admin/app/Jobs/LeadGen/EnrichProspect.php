<?php

namespace App\Jobs\LeadGen;

use App\Models\LeadGen\Prospect;
use App\Services\LeadGen\Enrichment\WebsiteEnricher;
use App\Services\Tenant\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Read one prospect's website for contact details (WebsiteEnricher). */
class EnrichProspect implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 60;
    public int $timeout = 120;

    public function __construct(public int $projectId, public int $prospectId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("leadgen-enrich:{$this->projectId}:{$this->prospectId}"))->dontRelease()->expireAfter(300)];
    }

    public function handle(TenantManager $tenants, WebsiteEnricher $enricher): void
    {
        $tenants->useForProjectId($this->projectId);
        $p = Prospect::find($this->prospectId);
        if (!$p) {
            return;
        }
        $p->forceFill(['enrichment_status' => 'running'])->save();
        $enricher->enrich($p);
    }

    public function failed(Throwable $e): void
    {
        try {
            app(TenantManager::class)->useForProjectId($this->projectId);
            Prospect::whereKey($this->prospectId)->update(['enrichment_status' => 'failed', 'updated_at' => time()]);
        } catch (Throwable) {
            // Nothing more to record.
        }
    }
}
