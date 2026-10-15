<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A piece of content a project publishes to its Facebook Pages and Instagram
 * accounts. Fans out to one SocialPostTarget per account; the post's own
 * status is derived from theirs (see syncStatus()).
 */
class SocialPost extends Model
{
    public const FORMAT_POST  = 'post';
    public const FORMAT_REEL  = 'reel';
    public const FORMAT_STORY = 'story';

    public const FORMATS = [
        self::FORMAT_POST  => 'Post',
        self::FORMAT_REEL  => 'Reel',
        self::FORMAT_STORY => 'Story',
    ];

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_SCHEDULED  = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_PARTIAL    = 'partial';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'project_id', 'user_id', 'format', 'caption', 'link', 'media',
        'status', 'scheduled_at', 'published_at', 'ai_generated',
    ];

    protected $casts = [
        'media'        => 'array',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'ai_generated' => 'boolean',
    ];

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    /** @return array<int, array{path:string, type:string, mime:?string, size:?int}> */
    public function mediaItems(): array
    {
        return array_values(array_filter((array) $this->media, fn ($m) => ! empty($m['path'])));
    }

    /** Public URL of a stored media item — the address Meta downloads from. */
    public static function mediaUrl(array $item): string
    {
        return Storage::disk('public')->url($item['path']);
    }

    public function hasVideo(): bool
    {
        return collect($this->mediaItems())->contains(fn ($m) => $m['type'] === 'video');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    /**
     * Derive the post's status from its targets.
     *
     * Anything still pending or processing keeps the post "publishing"; once
     * every target has settled it is published, failed, or partial (some of
     * each), which is the case the UI most needs to make visible.
     */
    public function syncStatus(): void
    {
        $statuses = $this->targets()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        if ($statuses->contains(fn ($s) => in_array($s, [SocialPostTarget::STATUS_PENDING, SocialPostTarget::STATUS_PROCESSING], true))) {
            $status = self::STATUS_PUBLISHING;
        } elseif ($statuses->every(fn ($s) => $s === SocialPostTarget::STATUS_PUBLISHED)) {
            $status = self::STATUS_PUBLISHED;
        } elseif ($statuses->every(fn ($s) => $s === SocialPostTarget::STATUS_FAILED)) {
            $status = self::STATUS_FAILED;
        } else {
            $status = self::STATUS_PARTIAL;
        }

        $this->status = $status;
        if (in_array($status, [self::STATUS_PUBLISHED, self::STATUS_PARTIAL], true) && ! $this->published_at) {
            $this->published_at = now();
        }
        $this->save();
    }
}
