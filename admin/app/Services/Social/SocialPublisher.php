<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\PublishingClient;

/**
 * Publishes a SocialPost's targets.
 *
 * Facebook is synchronous: one call (three for a Reel) and the post is live,
 * or it failed. Instagram is a container pipeline — create, wait for Meta to
 * finish processing, publish — and a video can take a minute or two. So an
 * Instagram target is created here, left `processing` with its container ids
 * in `state`, and advance() is called again by the every-minute
 * `social:publish-due` sweep until it settles. Nothing ever sleeps waiting
 * for Meta, which matters because the queue worker kills jobs at 60s.
 *
 * Failures are recorded on the target, never retried automatically: a retry
 * after a timeout that actually succeeded would post twice, and a duplicate
 * on a brand's page is worse than a visible "failed — retry".
 */
class SocialPublisher
{
    /** How long Instagram may process a container before we give up. */
    public const PROCESSING_TIMEOUT_MINUTES = 30;

    /** Publish every pending target of a post. */
    public function publish(SocialPost $post): void
    {
        foreach ($post->targets()->where('status', SocialPostTarget::STATUS_PENDING)->get() as $target) {
            $this->start($target, $post);
        }

        $post->syncStatus();
    }

    /** First attempt at one target. */
    public function start(SocialPostTarget $target, SocialPost $post): void
    {
        // Claim it atomically, so a job and the sweep (or two workers) can
        // never both publish the same target.
        $claimed = SocialPostTarget::where('id', $target->id)
            ->where('status', SocialPostTarget::STATUS_PENDING)
            ->update([
                'status'     => SocialPostTarget::STATUS_PROCESSING,
                'started_at' => now(),
                'attempts'   => $target->attempts + 1,
                'error'      => null,
            ]);
        if (! $claimed) {
            return;
        }
        $target->refresh();

        $conn = $target->connection;
        if (! $conn || ! $conn->isEnabled()) {
            $target->fail('This account is disconnected or disabled. Reconnect it on the Meta Onboarding page.');
            return;
        }

        try {
            $client = PublishingClient::forConnection($conn);

            if ($conn->provider === ChannelConnection::PROVIDER_FACEBOOK_PAGE) {
                $this->publishFacebook($client, $target, $post, (string) $conn->external_id);
            } else {
                $this->startInstagram($client, $target, $post, (string) $conn->external_id);
                $this->advance($target, $client);
            }
        } catch (\Throwable $e) {
            Log::info('Social publish failed', ['target' => $target->id, 'error' => $e->getMessage()]);
            $target->fail($e->getMessage());
        }
    }

    /**
     * Move a processing Instagram target forward one step: wait, build the
     * carousel once its items are ready, or publish the finished container.
     */
    public function advance(SocialPostTarget $target, ?PublishingClient $client = null): void
    {
        if ($target->status !== SocialPostTarget::STATUS_PROCESSING) {
            return;
        }

        $state = (array) $target->state;
        $conn  = $target->connection;

        // Processing with no container recorded means the attempt died
        // mid-flight (a worker killed between claim and create). It may or may
        // not have reached Meta, so it is failed with a warning rather than
        // re-run blind.
        if (! $state || ! $conn) {
            if ($target->started_at && $target->started_at->lt(now()->subMinutes(10))) {
                $target->fail('Publishing was interrupted. Check the account before retrying, in case it went out.');
            }
            return;
        }

        try {
            $client ??= PublishingClient::forConnection($conn);
            $igId = (string) $conn->external_id;

            if (($state['stage'] ?? null) === 'children') {
                foreach ((array) $state['children'] as $childId) {
                    $st = $client->instagramContainerStatus($childId);
                    if (in_array($st['status'], [PublishingClient::IG_ERROR, PublishingClient::IG_EXPIRED], true)) {
                        $target->fail('Instagram could not process one of the carousel items' . ($st['message'] ? ': ' . $st['message'] : '.'));
                        return;
                    }
                    if ($st['status'] !== PublishingClient::IG_FINISHED) {
                        $this->failIfStale($target);
                        return;
                    }
                }

                $state = ['stage' => 'container', 'container' => $client->instagramCarousel($igId, $state['children'], (string) $target->post->caption)];
                $target->state = $state;
                $target->save();
            }

            $st = $client->instagramContainerStatus($state['container']);

            if (in_array($st['status'], [PublishingClient::IG_ERROR, PublishingClient::IG_EXPIRED], true)) {
                $target->fail('Instagram could not process this media' . ($st['message'] ? ': ' . $st['message'] : '.'));
                return;
            }
            if ($st['status'] !== PublishingClient::IG_FINISHED) {
                $this->failIfStale($target);
                return;
            }

            $mediaId = $client->instagramPublish($igId, $state['container']);

            $target->forceFill([
                'status'       => SocialPostTarget::STATUS_PUBLISHED,
                'remote_id'    => $mediaId,
                'permalink'    => $client->instagramPermalink($mediaId),
                'published_at' => now(),
                'error'        => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::info('Social publish (Instagram) failed', ['target' => $target->id, 'error' => $e->getMessage()]);
            $target->fail($e->getMessage());
        }
    }

    // ── Facebook ─────────────────────────────────────────────────────

    private function publishFacebook(PublishingClient $client, SocialPostTarget $target, SocialPost $post, string $pageId): void
    {
        $caption = (string) $post->caption;
        $items   = $post->mediaItems();
        $images  = array_values(array_filter($items, fn ($m) => $m['type'] === 'image'));
        $videos  = array_values(array_filter($items, fn ($m) => $m['type'] === 'video'));

        if ($post->format === SocialPost::FORMAT_STORY) {
            throw new \RuntimeException('Stories are published to Instagram only.');
        }

        if ($post->format === SocialPost::FORMAT_REEL) {
            if (! $videos) {
                throw new \RuntimeException('A Reel needs a video.');
            }
            $id   = $client->facebookReel($pageId, SocialPost::mediaUrl($videos[0]), $caption);
            $link = "https://www.facebook.com/reel/{$id}";
        } elseif ($videos) {
            $id   = $client->facebookVideo($pageId, SocialPost::mediaUrl($videos[0]), $caption);
            $link = "https://www.facebook.com/{$id}";
        } elseif (count($images) > 1) {
            $id   = $client->facebookPhotos($pageId, array_map([SocialPost::class, 'mediaUrl'], $images), $caption);
            $link = $client->facebookPermalink($id);
        } elseif ($images) {
            $id   = $client->facebookPhoto($pageId, SocialPost::mediaUrl($images[0]), $caption);
            $link = $client->facebookPermalink($id);
        } else {
            if (trim($caption) === '' && ! $post->link) {
                throw new \RuntimeException('A post with no media needs some text or a link.');
            }
            $id   = $client->facebookText($pageId, $caption, $post->link ?: null);
            $link = $client->facebookPermalink($id);
        }

        $target->forceFill([
            'status'       => SocialPostTarget::STATUS_PUBLISHED,
            'remote_id'    => $id,
            'permalink'    => $link ?: "https://www.facebook.com/{$id}",
            'published_at' => now(),
        ])->save();
    }

    // ── Instagram ────────────────────────────────────────────────────

    /** Create the container(s) and record them; advance() does the rest. */
    private function startInstagram(PublishingClient $client, SocialPostTarget $target, SocialPost $post, string $igId): void
    {
        $caption = (string) $post->caption;
        $items   = $post->mediaItems();

        if (! $items) {
            throw new \RuntimeException('Instagram needs a photo or video — it has no text-only posts.');
        }

        $first = $items[0];
        $url   = SocialPost::mediaUrl($first);

        if ($post->format === SocialPost::FORMAT_STORY) {
            $container = $first['type'] === 'video'
                ? $client->instagramVideo($igId, $url, '', 'story')
                : $client->instagramImage($igId, $url, '', 'story');
            $state = ['stage' => 'container', 'container' => $container];
        } elseif ($post->format === SocialPost::FORMAT_REEL) {
            if ($first['type'] !== 'video') {
                throw new \RuntimeException('A Reel needs a video.');
            }
            $state = ['stage' => 'container', 'container' => $client->instagramVideo($igId, $url, $caption, 'reel')];
        } elseif (count($items) > 1) {
            $children = [];
            foreach (array_slice($items, 0, 10) as $m) {
                $children[] = $m['type'] === 'video'
                    ? $client->instagramVideo($igId, SocialPost::mediaUrl($m), '', 'carousel_item')
                    : $client->instagramImage($igId, SocialPost::mediaUrl($m), '', 'carousel_item');
            }
            $state = ['stage' => 'children', 'children' => $children];
        } else {
            $container = $first['type'] === 'video'
                ? $client->instagramVideo($igId, $url, $caption, 'reel')
                : $client->instagramImage($igId, $url, $caption, 'feed');
            $state = ['stage' => 'container', 'container' => $container];
        }

        $target->state = $state;
        $target->save();
    }

    private function failIfStale(SocialPostTarget $target): void
    {
        if ($target->started_at && $target->started_at->lt(now()->subMinutes(self::PROCESSING_TIMEOUT_MINUTES))) {
            $target->fail('Instagram was still processing the media after ' . self::PROCESSING_TIMEOUT_MINUTES . ' minutes.');
        }
    }
}
