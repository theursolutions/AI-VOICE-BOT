<?php

namespace Msd\MetaChannels\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Content publishing to Facebook Pages and Instagram professional accounts.
 *
 * Kept apart from GraphClient because the failure contract is the opposite.
 * GraphClient swallows errors and returns null — right for a message send that
 * gets retried. A publish is something a person scheduled and will look for,
 * so every call here THROWS with Meta's own reason ("The aspect ratio is not
 * supported", "Media ID is not available") and the caller stores it on the post.
 *
 * Media is always passed BY URL. Instagram has no upload endpoint at all — it
 * downloads the file itself — so the URL must be publicly reachable over
 * HTTPS. A localhost URL fails with "Media download has failed", which is the
 * single most common reason a first publish fails in development.
 *
 * Instagram flow (both Facebook Login and Instagram Login):
 *     POST /{ig-id}/media          → container id
 *     GET  /{container}?fields=status_code   until FINISHED (videos take a while)
 *     POST /{ig-id}/media_publish  → media id
 */
class PublishingClient
{
    /** Instagram container states, as returned in `status_code`. */
    public const IG_FINISHED    = 'FINISHED';
    public const IG_IN_PROGRESS = 'IN_PROGRESS';
    public const IG_ERROR       = 'ERROR';
    public const IG_EXPIRED     = 'EXPIRED';
    public const IG_PUBLISHED   = 'PUBLISHED';

    private string $base;
    private string $version;

    public function __construct(private ?string $token, ?string $base = null, ?string $version = null)
    {
        $this->base    = rtrim($base ?: (string) config('meta.app.graph_base', 'https://graph.facebook.com'), '/');
        $this->version = $version ?: (string) config('meta.app.graph_version', 'v21.0');
    }

    /** A client on the right Graph host for the connection — see GraphClient::forConnection(). */
    public static function forConnection(ChannelConnection $conn): self
    {
        return new self($conn->access_token ?: null, GraphClient::baseFor($conn->metadata));
    }

    // -- Facebook Page ------------------------------------------------------

    /** Text (and optional link preview) post. Returns the post id. */
    public function facebookText(string $pageId, string $message, ?string $link = null): string
    {
        $r = $this->post("{$pageId}/feed", array_filter(['message' => $message, 'link' => $link]));

        return $this->idOf($r, 'id');
    }

    /** Single photo post. Returns the post id (falls back to the photo id). */
    public function facebookPhoto(string $pageId, string $imageUrl, string $caption = ''): string
    {
        $r = $this->post("{$pageId}/photos", array_filter(['url' => $imageUrl, 'caption' => $caption]));

        return (string) ($r['post_id'] ?? $this->idOf($r, 'id'));
    }

    /**
     * Multi-photo post: each photo is uploaded unpublished, then attached to
     * one feed post. Posting them published would scatter N separate posts
     * across the Page.
     *
     * @param string[] $imageUrls
     */
    public function facebookPhotos(string $pageId, array $imageUrls, string $message = ''): string
    {
        $attached = [];
        foreach ($imageUrls as $url) {
            $r = $this->post("{$pageId}/photos", ['url' => $url, 'published' => false]);
            $attached[] = ['media_fbid' => $this->idOf($r, 'id')];
        }

        $r = $this->post("{$pageId}/feed", array_filter([
            'message'        => $message,
            'attached_media' => $attached,
        ]));

        return $this->idOf($r, 'id');
    }

    /** Regular (non-Reel) video post. Facebook fetches and processes it in the background. */
    public function facebookVideo(string $pageId, string $videoUrl, string $description = ''): string
    {
        $r = $this->post("{$pageId}/videos", array_filter(['file_url' => $videoUrl, 'description' => $description]));

        return $this->idOf($r, 'id');
    }

    /**
     * Facebook Reel — three phases: open an upload session, hand rupload the
     * file URL, then finish with PUBLISHED. Returns the video id.
     */
    public function facebookReel(string $pageId, string $videoUrl, string $description = ''): string
    {
        $start   = $this->post("{$pageId}/video_reels", ['upload_phase' => 'start']);
        $videoId = $this->idOf($start, 'video_id');

        // The upload host is rupload.facebook.com and authenticates with
        // "OAuth <token>", not "Bearer" — a Bearer header there is rejected.
        $uploadUrl = (string) ($start['upload_url'] ?? "https://rupload.facebook.com/video-upload/{$this->version}/{$videoId}");
        $resp = $this->send(fn () => Http::timeout(60)
            ->withHeaders(['Authorization' => 'OAuth ' . $this->token, 'file_url' => $videoUrl])
            ->post($uploadUrl), $uploadUrl);
        if (! ($resp['success'] ?? false)) {
            throw new \RuntimeException('Facebook did not accept the Reel video upload.');
        }

        $this->post("{$pageId}/video_reels", [
            'upload_phase' => 'finish',
            'video_id'     => $videoId,
            'video_state'  => 'PUBLISHED',
            'description'  => $description,
        ]);

        return $videoId;
    }

    /** A permalink for a Page post id; null when Graph will not say. */
    public function facebookPermalink(string $postId): ?string
    {
        try {
            return $this->get($postId, ['fields' => 'permalink_url'])['permalink_url'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -- Instagram ----------------------------------------------------------

    /**
     * Create an image container. Instagram accepts JPEG only.
     *
     * @param string $kind 'feed' | 'story' | 'carousel_item'
     */
    public function instagramImage(string $igId, string $imageUrl, string $caption = '', string $kind = 'feed'): string
    {
        $body = ['image_url' => $imageUrl];
        if ($kind === 'story') {
            $body['media_type'] = 'STORIES';
        } elseif ($kind === 'carousel_item') {
            $body['is_carousel_item'] = true;
        } elseif ($caption !== '') {
            $body['caption'] = $caption;
        }

        return $this->idOf($this->post("{$igId}/media", $body), 'id');
    }

    /**
     * Create a video container. Every standalone Instagram video is a Reel
     * now — media_type VIDEO survives only inside a carousel.
     *
     * @param string $kind 'reel' | 'story' | 'carousel_item'
     */
    public function instagramVideo(string $igId, string $videoUrl, string $caption = '', string $kind = 'reel'): string
    {
        $body = ['video_url' => $videoUrl];
        if ($kind === 'story') {
            $body['media_type'] = 'STORIES';
        } elseif ($kind === 'carousel_item') {
            $body['media_type']       = 'VIDEO';
            $body['is_carousel_item'] = true;
        } else {
            $body['media_type']    = 'REELS';
            $body['share_to_feed'] = true;
            if ($caption !== '') {
                $body['caption'] = $caption;
            }
        }

        return $this->idOf($this->post("{$igId}/media", $body), 'id');
    }

    /** @param string[] $childIds  2–10 finished item containers */
    public function instagramCarousel(string $igId, array $childIds, string $caption = ''): string
    {
        return $this->idOf($this->post("{$igId}/media", array_filter([
            'media_type' => 'CAROUSEL',
            'children'   => implode(',', $childIds),
            'caption'    => $caption,
        ])), 'id');
    }

    /**
     * A container's processing state.
     *
     * @return array{status:string, message:?string}
     */
    public function instagramContainerStatus(string $containerId): array
    {
        $r = $this->get($containerId, ['fields' => 'status_code,status']);

        return [
            'status'  => (string) ($r['status_code'] ?? self::IG_IN_PROGRESS),
            // `status` carries the human reason on ERROR, e.g. a bad aspect ratio.
            'message' => isset($r['status']) ? (string) $r['status'] : null,
        ];
    }

    /** Publish a FINISHED container. Returns the media id. */
    public function instagramPublish(string $igId, string $containerId): string
    {
        return $this->idOf($this->post("{$igId}/media_publish", ['creation_id' => $containerId]), 'id');
    }

    public function instagramPermalink(string $mediaId): ?string
    {
        try {
            return $this->get($mediaId, ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -- internals ----------------------------------------------------------

    private function post(string $path, array $body): array
    {
        $url = "{$this->base}/{$this->version}/{$path}";

        return $this->send(fn () => Http::timeout(60)->connectTimeout(8)
            ->withToken((string) $this->token)
            ->asJson()
            ->post($url, $body), $path);
    }

    private function get(string $path, array $query): array
    {
        $url = "{$this->base}/{$this->version}/{$path}";

        return $this->send(fn () => Http::timeout(15)->connectTimeout(8)
            ->withToken((string) $this->token)
            ->get($url, $query + ['access_token' => $this->token]), $path);
    }

    /** Run a request and turn any failure into Meta's own words. */
    private function send(callable $request, string $label): array
    {
        if (! $this->token) {
            throw new \RuntimeException('This account has no access token — reconnect it on the Meta Onboarding page.');
        }

        try {
            $resp = $request();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach Meta: ' . $e->getMessage(), 0, $e);
        }

        $data = $resp->json();

        if ($resp->failed()) {
            $err = is_array($data) ? ($data['error'] ?? []) : [];
            // error_user_msg is the human sentence; `message` is often just
            // "Invalid parameter".
            $reason = $err['error_user_msg'] ?? $err['message'] ?? ('HTTP ' . $resp->status());

            Log::warning('MetaChannels PublishingClient: request rejected', [
                'path' => $label, 'code' => $resp->status(), 'body' => substr($resp->body(), 0, 1000),
            ]);

            throw new \RuntimeException((string) $reason);
        }

        return is_array($data) ? $data : [];
    }

    private function idOf(array $r, string $key): string
    {
        $id = (string) ($r[$key] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Meta returned no ' . $key . ' for this request.');
        }

        return $id;
    }
}
