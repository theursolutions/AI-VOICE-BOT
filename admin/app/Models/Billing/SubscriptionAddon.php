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
        'gateway', 'paddle_item_price_id', 'period_end', 'cancelled_at', 'metadata',
    ];

    protected $casts = [
        'subscription_id' => 'integer',
        'client_id'       => 'integer',
        'plan_id'         => 'integer',
        'plan_price_id'   => 'integer',
        'quantity'        => 'integer',
        'unit_amount'     => 'integer',
        'cancelled_at'    => 'datetime',
        'period_end'      => 'datetime',
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

    /**
     * Add-ons currently granting their allowance.
     *
     * `period_end` is the difference between the two kinds of add-on. One
     * bought as a subscription line item runs for as long as the subscription
     * does and carries no end date. One bought as a single prorated payment was
     * only ever paid for up to a date — and once that passes it must stop
     * granting, or a customer would keep seats they last paid for in March.
     *
     * NULL means "no end", not "ended". Written as an explicit OR rather than a
     * comparison against a null column, which in SQL is never true and would
     * silently drop every Stripe add-on from the allowance.
     */
    public function scopeActive($q)
    {
        return $q->whereNull('cancelled_at')
                 ->where('quantity', '>', 0)
                 ->where(fn ($q) => $q->whereNull('period_end')->orWhere('period_end', '>', now()));
    }

    /**
     * Add-ons the customer still holds, expired or not.
     *
     * What a RENEWAL must re-charge. `active()` is the wrong question there:
     * a renewal is raised at or after the period end, by which point a one-off
     * add-on has just lapsed — using active() would drop exactly the seats the
     * renewal exists to preserve.
     */
    public function scopeHeld($q)
    {
        return $q->whereNull('cancelled_at')->where('quantity', '>', 0);
    }

    /** What this line costs per interval, in minor units of its own currency. */
    public function lineTotal(): int
    {
        return (int) $this->unit_amount * max(0, (int) $this->quantity);
    }

    /**
     * The line total as the customer reads it.
     *
     * The symbol comes from the ROW'S currency. An add-on bought in rupees
     * rendered with a dollar sign tells somebody they are paying $3,000 a month
     * for two seats.
     */
    public function formattedLineTotal(): string
    {
        $amount = $this->lineTotal() / 100;
        $symbol = app(\App\Services\Currency\ExchangeRateService::class)
            ->symbolFor((string) ($this->currency ?: config('billing.currency', 'usd')));

        return $symbol . ($amount == floor($amount)
            ? number_format($amount, 0)
            : number_format($amount, 2));
    }
}
