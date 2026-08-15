<?php

namespace App\Models\Billing;

use App\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on held by a subscription — e.g. "5 × Extra seat".
 *
 * The add-on itself is a Plan (`type = 'addon'`); this is the join carrying
 * how many. What one unit grants comes from that plan's `plan_features`, so
 * the effective allowance is base + (unit value × quantity). See
 * PlanFeatureService::effectiveLimit().
 */
class SubscriptionAddon extends Model
{
    protected $connection = 'mysql';
    protected $table = 'subscription_addons';

    protected $fillable = [
        'subscription_id', 'client_id', 'plan_id', 'plan_price_id',
        'quantity', 'stripe_item_ref', 'unit_amount', 'currency', 'interval',
        'cancelled_at', 'metadata',
    ];

    protected $casts = [
        'subscription_id' => 'integer',
        'client_id'       => 'integer',
        'plan_id'         => 'integer',
        'plan_price_id'   => 'integer',
        'quantity'        => 'integer',
        'unit_amount'     => 'integer',
        'cancelled_at'    => 'datetime',
        'metadata'        => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class, 'plan_price_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** Live add-ons only — a cancelled one must stop granting its allowance. */
    public function scopeActive($q)
    {
        return $q->whereNull('cancelled_at')->where('quantity', '>', 0);
    }

    /** What this line costs per interval, in USD cents. */
    public function lineTotal(): int
    {
        return (int) $this->unit_amount * max(0, (int) $this->quantity);
    }

    public function formattedLineTotal(): string
    {
        $dollars = $this->lineTotal() / 100;

        return '$' . ($dollars == floor($dollars)
            ? number_format($dollars, 0)
            : number_format($dollars, 2));
    }
}
