<?php

namespace Msd\MetaChannels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One request to erase everything we hold about a person on a Meta platform.
 *
 * Meta requires this endpoint for any app holding messaging permissions, and
 * requires it to answer with a URL the person can check later — so a request
 * is a durable object with a public handle, not a webhook we acknowledge and
 * forget.
 */
class DataDeletionRequest extends Model
{
    protected $table = 'data_deletion_requests';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    public const SOURCE_META_CALLBACK = 'meta_callback';
    public const SOURCE_MANUAL        = 'manual_request';

    protected $fillable = [
        'provider', 'external_user_id', 'confirmation_code', 'status',
        'source', 'sessions_deleted', 'messages_deleted', 'error', 'completed_at',
    ];

    protected $casts = [
        'completed_at'     => 'datetime',
        'sessions_deleted' => 'integer',
        'messages_deleted' => 'integer',
    ];

    /**
     * Open a request with a fresh confirmation code.
     *
     * The code is 32 random characters rather than the row id: it appears in
     * a URL we hand to an unauthenticated stranger, and a sequential id there
     * would let anyone walk the table and read other people's deletion
     * status.
     */
    public static function open(string $provider, string $externalUserId, string $source = self::SOURCE_META_CALLBACK): self
    {
        return static::create([
            'provider'          => $provider,
            'external_user_id'  => $externalUserId,
            'confirmation_code' => Str::lower(Str::random(32)),
            'status'            => self::STATUS_PENDING,
            'source'            => $source,
        ]);
    }

    public function markCompleted(int $sessions, int $messages): void
    {
        $this->status           = self::STATUS_COMPLETED;
        $this->sessions_deleted = $sessions;
        $this->messages_deleted = $messages;
        $this->error            = null;
        $this->completed_at     = now();
        $this->save();
    }

    public function markFailed(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        // Truncated because this text is rendered on a public page — a raw
        // exception can carry table names and file paths.
        $this->error  = Str::limit($error, 500);
        $this->save();
    }

    /** Human-readable state for the public status page. */
    public function summary(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => $this->messages_deleted === 0 && $this->sessions_deleted === 0
                ? 'Completed — we held no data for this account.'
                : "Completed — {$this->sessions_deleted} conversation(s) and {$this->messages_deleted} message(s) were permanently deleted.",
            self::STATUS_FAILED    => 'Failed — our team has been notified and will complete this manually.',
            default                => 'In progress — this normally completes within a few minutes.',
        };
    }
}
