<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan's price for one billing interval, in integer USD cents.
 *
 * IMMUTABILITY CONTRACT — the reason this is a separate table:
 * Stripe Prices cannot be edited. Raising $19 -> $29 must create a NEW row
 * with a NEW stripe_price_ref and deactivate the old one. Subscribers keep
 * pointing at the old row through `subscriptions.plan_price_id`, so they are
 * grandfathered automatically. PlanService::changePrice() is the only code
 * that should ever perform that swap; nothing should UPDATE `unit_amount`
 * on a row that already has a stripe_price_ref.
 */
class PlanPrice extends Model
{
    protected $connection = 'mysql';
    protected $table = 'plan_prices';

    protected $fillable = [
        'plan_id', 'interval', 'currency', 'unit_amount', 'compare_at_amount',
        'stripe_price_ref', 'stripe_product_ref', 'stripe_livemode', 'stripe_synced_at',
        'is_active', 'effective_from', 'effective_to', 'archived_at', 'metadata',
    ];

    protected $casts = [
        'plan_id'           => 'integer',
        'unit_amount'       => 'integer',
        'compare_at_amount' => 'integer',
        'is_active'         => 'boolean',
        'stripe_livemode'   => 'boolean',
        'stripe_synced_at'  => 'datetime',
        'effective_from'    => 'datetime',
        'effective_to'      => 'datetime',
        'archived_at'       => 'datetime',
        'metadata'          => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    // ── Money ────────────────────────────────────────────────────────

    /** Whole-dollar float. For DISPLAY and conversion only — never for maths. */
    public function amount(): float
    {
        return $this->unit_amount / 100;
    }

    /** "$19" or "$19.50" — trailing ".00" dropped, which reads better on a card. */
    public function formatted(): string
    {
        $amount = $this->amount();

        return '$' . ($amount == floor($amount)
            ? number_format($amount, 0)
            : number_format($amount, 2));
    }

    public function months(): int
    {
        return (int) (config("billing.intervals.months.{$this->interval}") ?: 1);
    }

    /** Cents per month at this interval — the fair cross-interval comparison. */
    public function effectiveMonthlyCents(): int
    {
        return (int) round($this->unit_amount / max(1, $this->months()));
    }

    public function formattedEffectiveMonthly(): string
    {
        $amount = $this->effectiveMonthlyCents() / 100;

        return '$' . ($amount == floor($amount)
            ? number_format($amount, 0)
            : number_format($amount, 2));
    }

    /**
     * Percentage saved versus paying the monthly price for the same span.
     * Returns 0 when there is no monthly price to compare against, so the
     * pricing page can simply omit the badge.
     */
    public function savingsPercentAgainst(?PlanPrice $monthly): int
    {
        if (! $monthly || $monthly->unit_amount <= 0 || $this->interval === 'monthly') {
            return 0;
        }

        $full = $monthly->unit_amount * $this->months();
        if ($full <= 0 || $this->unit_amount >= $full) {
            return 0;
        }

        return (int) round((($full - $this->unit_amount) / $full) * 100);
    }

    /** Absolute cents saved versus the monthly price over the same span. */
    public function savingsCentsAgainst(?PlanPrice $monthly): int
    {
        if (! $monthly || $this->interval === 'monthly') {
            return 0;
        }

        return max(0, ($monthly->unit_amount * $this->months()) - $this->unit_amount);
    }

    // ── Stripe ───────────────────────────────────────────────────────

    public function isSyncedToStripe(): bool
    {
        return ! empty($this->stripe_price_ref);
    }

    /**
     * True when this row's Stripe ref was minted in a different mode than the
     * key currently configured. Checking out against a test price with a live
     * key fails at Stripe; this lets the admin UI warn first.
     */
    public function isStripeModeMismatched(): bool
    {
        if ($this->stripe_livemode === null || ! $this->isSyncedToStripe()) {
            return false;
        }

        $secret = (string) config('billing.stripe.secret');
        if ($secret === '') {
            return false;
        }

        return $this->stripe_livemode !== str_starts_with($secret, 'sk_live_');
    }

    public function intervalLabel(): string
    {
        return (string) (config("billing.intervals.labels.{$this->interval}") ?: ucfirst($this->interval));
    }

    public function intervalSuffix(): string
    {
        return (string) (config("billing.intervals.suffixes.{$this->interval}") ?: '');
    }
}
