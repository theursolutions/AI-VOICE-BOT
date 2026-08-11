<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * A Stripe webhook event we have seen. The UNIQUE index on `stripe_event_id`
 * is the idempotency guarantee.
 *
 * Stripe delivers AT LEAST ONCE and retries any non-2xx response, so the same
 * event WILL arrive twice in normal operation. See claim() for why we insert
 * first rather than check-then-insert.
 */
class StripeEvent extends Model
{
    protected $connection = 'mysql';
    protected $table = 'stripe_events';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_SKIPPED   = 'skipped';

    protected $fillable = [
        'stripe_event_id', 'type', 'api_version', 'livemode',
        'status', 'attempts', 'error', 'payload', 'processed_at',
    ];

    protected $casts = [
        'livemode'     => 'boolean',
        'attempts'     => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * Attempt to take ownership of an event id.
     *
     * Returns the row on success, or NULL if this event was already claimed —
     * in which case the caller must ACK with 200 and do nothing else.
     *
     * We INSERT and catch the duplicate-key violation rather than SELECT-then-
     * INSERT: two concurrent deliveries of the same event would both pass a
     * prior existence check and both process. Letting the unique index reject
     * the loser is the only race-free option.
     */
    public static function claim(string $eventId, array $attributes = []): ?self
    {
        try {
            return static::create(array_merge([
                'stripe_event_id' => $eventId,
                'status'          => self::STATUS_PENDING,
                'attempts'        => 1,
            ], $attributes));
        } catch (\Illuminate\Database\QueryException $e) {
            // 23000 = integrity constraint violation (duplicate key).
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return null;
            }
            throw $e;
        }
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'status'       => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'error'        => null,
        ])->save();
    }

    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status'       => self::STATUS_SKIPPED,
            'processed_at' => now(),
            'error'        => $reason,
        ])->save();
    }

    public function markFailed(\Throwable $e): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error'  => substr($e->getMessage(), 0, 2000),
        ])->save();
    }

    public function decodedPayload(): array
    {
        return json_decode((string) $this->payload, true) ?: [];
    }

    public function scopeFailed($q)
    {
        return $q->where('status', self::STATUS_FAILED);
    }
}
