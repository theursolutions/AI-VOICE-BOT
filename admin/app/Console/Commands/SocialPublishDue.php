<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialPost;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Social\SocialPublisher;
use Illuminate\Console\Command;

/**
 * The Social Posts heartbeat, run every minute:
 *
 *   1. scheduled posts whose time has come → queued for publishing
 *   2. Instagram targets still processing → checked, and published once
 *      Meta has finished with the media
 */
class SocialPublishDue extends Command
{
    protected $signature = 'social:publish-due';

    protected $description = 'Publish scheduled social posts and finish Instagram media that was processing';

    public function handle(SocialPublisher $publisher): int
    {
        $queued = 0;
        $due = SocialPost::where('status', SocialPost::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->pluck('id');

        foreach ($due as $id) {
            // Conditional update = claim. Only the run that flips it queues it.
            $claimed = SocialPost::where('id', $id)
                ->where('status', SocialPost::STATUS_SCHEDULED)
                ->update(['status' => SocialPost::STATUS_PUBLISHING]);
            if ($claimed) {
                PublishSocialPost::dispatch($id);
                $queued++;
            }
        }

        // A job lost by the queue would leave its post "publishing" forever
        // with targets nobody started. Requeue those; start() claims each
        // target atomically, so a job that was merely slow cannot double-post.
        $stuck = SocialPost::where('status', SocialPost::STATUS_PUBLISHING)
            ->where('updated_at', '<', now()->subMinutes(5))
            ->whereHas('targets', fn ($q) => $q->where('status', SocialPostTarget::STATUS_PENDING))
            ->limit(50)
            ->pluck('id');
        foreach ($stuck as $id) {
            SocialPost::where('id', $id)->update(['updated_at' => now()]);
            PublishSocialPost::dispatch($id);
            $queued++;
        }

        $advanced = 0;
        $processing = SocialPostTarget::with(['post', 'connection'])
            ->where('status', SocialPostTarget::STATUS_PROCESSING)
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($processing as $target) {
            $publisher->advance($target);
            $advanced++;
        }
        foreach ($processing->pluck('post')->filter()->unique('id') as $post) {
            $post->syncStatus();
        }

        $this->info("Queued {$queued} post(s); checked {$advanced} processing target(s).");

        return self::SUCCESS;
    }
}
