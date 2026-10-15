<?php

namespace App\Jobs\LeadGen;

use App\Models\LeadGen\Event;
use App\Models\LeadGen\OfferProfile;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Qualification;
use App\Services\LeadGen\Ai\Qualifier;
use App\Services\Tenant\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scores a batch of prospects (≤ CHUNK) against one offer profile.
 *
 * Retried runs skip prospects already scored for this profile since the
 * batch was queued, so a retry never pays for the same scoring twice. One
 * AI failure stops the batch — a provider that's down would fail every
 * remaining prospect the same way — and the rest wait for the retry.
 */
class QualifyProspects implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK = 25;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $timeout = 600;

    /** @param int[] $prospectIds */
    public function __construct(public int $projectId, public int $profileId, public array $prospectIds, public int $queuedAt) {}

    public function handle(TenantManager $tenants, Qualifier $qualifier): void
    {
        $tenants->useForProjectId($this->projectId);
        $profile = OfferProfile::find($this->profileId);
        if (!$profile) {
            return;
        }

        $done = Qualification::where('profile_id', $profile->id)->whereIn('prospect_id', $this->prospectIds)
            ->where('created_at', '>=', $this->queuedAt)->pluck('prospect_id')->all();

        foreach (Prospect::whereIn('id', array_diff($this->prospectIds, $done))->get() as $p) {
            try {
                $qualifier->qualify($p, $profile);
            } catch (Throwable $e) {
                Event::log('prospect', $p->id, 'qualify.failed', ['message' => mb_substr($e->getMessage(), 0, 300)], 'warning');
                Log::warning('leadgen.qualify.failed', ['project_id' => $this->projectId, 'prospect_id' => $p->id, 'err' => $e->getMessage()]);
                throw $e;
            }
        }
    }
}
