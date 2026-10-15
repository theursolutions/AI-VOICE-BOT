<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * One account a SocialPost goes out on.
 *
 *   pending     → not attempted yet
 *   processing  → Instagram is still processing the media; the every-minute
 *                 sweep (social:publish-due) checks it again and publishes
 *   published   → live; remote_id / permalink set
 *   failed      → `error` holds Meta's reason
 */
class SocialPostTarget extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'social_post_id', 'channel_connection_id', 'provider', 'account_name',
        'status', 'state', 'remote_id', 'permalink', 'error', 'attempts',
        'started_at', 'published_at',
    ];

    protected $casts = [
        'state'        => 'array',
        'started_at'   => 'datetime',
        'published_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function fail(string $reason): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error  = mb_substr($reason, 0, 2000);
        $this->save();
    }
}
