<?php

namespace App\Models\Billing;

use App\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metered usage for one workspace, one metric, one billing period.
 *
 * Written through UsageLimitService::record(), which uses an atomic upsert +
 * increment so concurrent inbound messages can't lose counts.
 */
class UsageCounter extends Model
{
    protected $connection = 'mysql';
    protected $table = 'usage_counters';

    protected $fillable = [
        'client_id', 'project_id', 'metric',
        'period_start', 'period_end', 'used', 'overage', 'last_recorded_at',
    ];

    protected $casts = [
        'client_id'        => 'integer',
        'project_id'       => 'integer',
        'used'             => 'integer',
        'overage'          => 'integer',
        'period_start'     => 'datetime',
        'period_end'       => 'datetime',
        'last_recorded_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function label(): string
    {
        return (string) (config("billing.metrics.{$this->metric}.label") ?: $this->metric);
    }

    public function unit(): string
    {
        return (string) (config("billing.metrics.{$this->metric}.unit") ?: '');
    }

    /**
     * Percentage of an allowance consumed, capped at 100 for the progress bar.
     * Null allowance (unlimited) returns 0 so the bar simply stays empty.
     */
    public function percentOf(?int $allowance): int
    {
        if ($allowance === null || $allowance <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->used / $allowance) * 100));
    }
}
