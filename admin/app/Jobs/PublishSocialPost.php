<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Project;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Billing\PlanFeatureService;
use App\Services\Social\SocialPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Publish a SocialPost's pending targets.
 *
 * One try only. A failed target records Meta's reason and waits for a person
 * to press Retry — re-running a publish that may have half-succeeded is how a
 * brand page ends up with the same post twice.
 */
class PublishSocialPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $postId) {}

    public function handle(SocialPublisher $publisher): void
    {
        $post = SocialPost::find($this->postId);

        if (! $post || $post->status !== SocialPost::STATUS_PUBLISHING) {
            return;
        }

        // The page is plan-gated, but a post scheduled on Growth still fires
        // after a downgrade to Starter. Checked here, at publish time, so the
        // plan in force when the post goes out is the one that decides.
        if (! $this->planAllows($post)) {
            foreach ($post->targets()->where('status', SocialPostTarget::STATUS_PENDING)->get() as $target) {
                $target->fail('Social Posts is not included in your current plan. Upgrade to Growth or above to publish.');
            }
            $post->syncStatus();
            return;
        }

        $publisher->publish($post);
    }

    private function planAllows(SocialPost $post): bool
    {
        $clientId = Project::where('id', $post->project_id)->value('client_id');
        $client   = $clientId ? Client::find($clientId) : null;

        // No client resolved: don't block — same fail-open rule as
        // PlanFeatureService for workspaces that predate billing.
        return ! $client || app(PlanFeatureService::class)->clientHasModule($client, 'social');
    }
}
