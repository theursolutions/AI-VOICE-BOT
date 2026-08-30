<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A subscription plan. Carries no price and no limits of its own — prices
 * live in {@see PlanPrice} (one row per interval) and limits/entitlements in
 * {@see PlanFeature}. That split is what lets a super-admin change pricing,
 * add an interval, or re-gate a feature without a developer.
 *
 * Pinned to the master `mysql` connection: TenantManager swaps the `tenant`
 * and `client` connections at request time, and billing must never follow it.
 */
class Plan extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';
    protected $table = 'plans';

    protected $fillable = [
        'name', 'slug', 'tagline', 'description', 'type',
        'is_active', 'is_public', 'is_featured', 'sort_order',
        'badge', 'cta_label', 'cta_url',
        'trial_days', 'trial_requires_payment_method',
        'free_window_days', 'metadata',
    ];

    protected $casts = [
        'is_active'                     => 'boolean',
        'is_public'                     => 'boolean',
        'is_featured'                   => 'boolean',
        'sort_order'                    => 'integer',
        'trial_days'                    => 'integer',
        'trial_requires_payment_method' => 'boolean',
        'free_window_days'              => 'integer',
        'metadata'                      => 'array',
    ];

    // ── Relations ────────────────────────────────────────────────────

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class, 'plan_id');
    }

    /** Only prices currently sellable to NEW customers. */
    public function activePrices(): HasMany
    {
        return $this->hasMany(PlanPrice::class, 'plan_id')->where('is_active', true);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_features', 'plan_id', 'feature_id')
                    ->withPivot(['value', 'is_highlighted', 'sort_order'])
                    ->withTimestamps();
    }

    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Visible on the public pricing page. */
    public function scopePublic($q)
    {
        return $q->where('is_public', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    public function scopeSellable($q)
    {
        return $q->where('is_active', true)->whereIn('type', ['free', 'standard', 'custom']);
    }

    // ── Type helpers ─────────────────────────────────────────────────

    public function isFree(): bool
    {
        return $this->type === 'free';
    }

    public function isEnterprise(): bool
    {
        return $this->type === 'enterprise';
    }

    /**
     * An add-on bought on top of a plan (extra seats, extra AI agents).
     *
     * scopePublic() already filters to free/standard/custom, so add-ons never
     * appear on the pricing page as a plan — they're sold from the billing
     * screen against an existing subscription.
     */
    public function isAddon(): bool
    {
        return $this->type === 'addon';
    }

    public function scopeAddons($q)
    {
        return $q->where('type', 'addon')->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * The feature one unit of this add-on tops up, e.g. `seats`.
     * Derived from its own plan_features rather than a second column, so the
     * matrix stays the single source of truth.
     */
    public function addonFeatureKey(): ?string
    {
        $row = $this->planFeatures()->with('feature')->get()
            ->first(fn ($pf) => $pf->feature && (int) $pf->value > 0);

        return $row?->feature?->key;
    }

    /** Enterprise plans are a CTA, not something you can check out. */
    public function isPurchasable(): bool
    {
        return $this->is_active && in_array($this->type, ['standard', 'custom'], true);
    }

    /**
     * May this workspace buy this plan?
     *
     * Only custom plans are restricted, and they are restricted absolutely: a
     * custom plan is priced for one workspace's configuration and nobody else's.
     *
     * This is a REVENUE control, not a data one. Plan slugs are guessable
     * (`custom-1-1`), findBySlug() does not filter on is_public, and
     * isPurchasable() has always allowed `custom` — so without this check any
     * workspace could subscribe to another's negotiated plan and inherit both
     * its price and, through the inherited template, its entitlements. A
     * hand-agreed rate is exactly the kind of plan someone would go looking for.
     *
     * Refuses when the plan claims no owner at all rather than treating that as
     * public: a custom plan with no client_id is a bug, and the safe reading of
     * a bug in a pricing record is "nobody".
     */
    public function isAvailableTo(?\App\Models\Client $client): bool
    {
        if ($this->type !== 'custom') {
            return true;
        }

        $owner = (int) data_get($this->metadata, 'client_id');

        return $owner > 0 && $client !== null && $owner === (int) $client->id;
    }

    public function hasTrial(): bool
    {
        return (int) $this->trial_days > 0;
    }

    /**
     * Days of no-card access this plan grants. NULL on a free plan means
     * permanent; 0/NULL on a paid plan means none.
     */
    public function freeWindowDays(): ?int
    {
        if (! $this->isFree()) {
            return null;
        }

        return $this->free_window_days === null
            ? null                                   // permanent free tier
            : (int) $this->free_window_days;
    }

    // ── Price lookup ─────────────────────────────────────────────────

    /**
     * The active price for an interval, or null. This is the ONLY sanctioned
     * way to resolve what a new customer pays — never trust an amount or a
     * price reference supplied by the client.
     */
    /**
     * The active price for an interval, in a given currency.
     *
     * A plan can carry more than one currency: a dollar price everyone pays
     * through Stripe, and a rupee price minted for the local gateway, which
     * settles nothing else. So an interval alone no longer identifies a price.
     *
     * $currency null means "the platform's own", and falls back to any active
     * row for the interval when no row matches — without that fallback, a plan
     * priced only in a local currency would look unpriced, and every existing
     * single-argument caller would change behaviour on the day a second
     * currency appeared.
     *
     * A currency asked for EXPLICITLY does not fall back: a caller that needs
     * rupees cannot be handed dollars, because the number would be charged as
     * though it were rupees.
     */
    public function priceFor(string $interval, ?string $currency = null): ?PlanPrice
    {
        $prices = $this->relationLoaded('prices')
            ? $this->prices->where('is_active', true)
            : $this->prices()->where('interval', $interval)->where('is_active', true)->get();

        $prices = $prices->filter(fn (PlanPrice $p) => $p->interval === $interval);

        $explicit = $currency !== null;
        $wanted   = strtolower($currency ?? (string) config('billing.currency', 'usd'));

        $match = $prices->first(fn (PlanPrice $p) => strtolower((string) $p->currency) === $wanted);

        if ($match || $explicit) {
            return $match;
        }

        return $prices->first();
    }

    /** Intervals this plan can actually be bought on right now. */
    public function availableIntervals(): array
    {
        $prices = $this->relationLoaded('prices') ? $this->prices : $this->prices()->get();

        return $prices->where('is_active', true)
                      ->pluck('interval')
                      ->unique()
                      ->values()
                      ->all();
    }
}
