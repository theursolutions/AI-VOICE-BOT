<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads and mutations for the plan catalogue.
 *
 * The single most important method here is {@see resolvePrice()} — it is the
 * ONLY sanctioned way to turn a customer's selection into something we bill.
 * Everything the browser sends is a plan slug and an interval name; the
 * amount and the Stripe price reference are looked up server-side. No code
 * path anywhere may read a price, an amount or a currency from the request.
 */
class PlanService
{
    public function __construct(private readonly StripeSyncService $stripeSync)
    {
    }

    // ── Reads ────────────────────────────────────────────────────────

    /** Plans for the public pricing page, ordered, with prices + features. */
    public function publicPlans(): Collection
    {
        return Plan::query()
            ->active()
            ->public()
            ->ordered()
            ->with([
                'prices' => fn ($q) => $q->where('is_active', true),
                'planFeatures.feature',
            ])
            ->get();
    }

    /** Every plan, for the ops console. */
    public function allPlans(): Collection
    {
        return Plan::query()
            ->ordered()
            ->withCount('subscriptions')
            ->with(['prices' => fn ($q) => $q->orderBy('interval')])
            ->get();
    }

    public function findBySlug(string $slug): ?Plan
    {
        return Plan::query()->where('slug', $slug)->first();
    }

    public function freePlan(): ?Plan
    {
        return Plan::query()
            ->where('type', 'free')
            ->where('is_active', true)
            ->ordered()
            ->first();
    }

    /**
     * Intervals actually offered right now: the configured `offered` list,
     * narrowed to those that have at least one active price. Prevents the
     * pricing toggle from rendering a tab where every card says "unavailable".
     */
    public function offeredIntervals(): array
    {
        $configured = (array) config('billing.intervals.offered', ['monthly', 'annually']);

        $withPrices = PlanPrice::query()
            ->where('is_active', true)
            ->whereIn('interval', $configured)
            ->whereIn('plan_id', Plan::query()->active()->public()->select('id'))
            ->distinct()
            ->pluck('interval')
            ->all();

        $available = array_values(array_intersect($configured, $withPrices));

        return $available ?: ['monthly'];
    }

    // ── The trust boundary ───────────────────────────────────────────

    /**
     * Resolve a customer's selection to a real, sellable price row.
     *
     * SECURITY: this takes a plan SLUG and an INTERVAL NAME — both opaque
     * identifiers — and returns the server's own price row. It deliberately
     * accepts no amount, no currency and no Stripe reference from the caller,
     * which is what makes client-side price tampering structurally impossible
     * rather than merely validated against.
     *
     * @throws \RuntimeException when the selection isn't purchasable.
     */
    public function resolvePrice(string $planSlug, string $interval): PlanPrice
    {
        $plan = $this->findBySlug($planSlug);

        if (! $plan) {
            throw new \RuntimeException("Unknown plan [{$planSlug}].");
        }

        if (! $plan->isPurchasable()) {
            throw new \RuntimeException("Plan [{$planSlug}] is not purchasable.");
        }

        if (! in_array($interval, (array) config('billing.intervals.supported', []), true)) {
            throw new \RuntimeException("Unsupported billing interval [{$interval}].");
        }

        $price = $plan->priceFor($interval);

        if (! $price) {
            throw new \RuntimeException("Plan [{$planSlug}] has no active {$interval} price.");
        }

        if (! $price->isSyncedToStripe()) {
            throw new \RuntimeException(
                "Plan [{$planSlug}] {$interval} price is not synced to Stripe. " .
                'Sync it from Super Admin → Billing → Plans before selling it.'
            );
        }

        return $price;
    }

    // ── Mutations (Super Admin) ──────────────────────────────────────

    public function createPlan(array $data): Plan
    {
        return DB::transaction(function () use ($data) {
            $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);

            return Plan::create($data);
        });
    }

    public function updatePlan(Plan $plan, array $data): Plan
    {
        // The slug is embedded in checkout links and Stripe metadata; changing
        // it silently would break in-flight sessions. Renaming is done by
        // creating a new plan.
        unset($data['slug']);

        $plan->fill($data)->save();

        return $plan->refresh();
    }

    /**
     * Only one plan may wear the "most popular" badge, so setting it clears
     * the others in the same statement rather than leaving the page with two.
     */
    public function setFeatured(Plan $plan): void
    {
        DB::transaction(function () use ($plan) {
            Plan::query()->where('id', '!=', $plan->id)->update(['is_featured' => false]);
            $plan->forceFill(['is_featured' => true])->save();
        });
    }

    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach (array_values($orderedIds) as $position => $id) {
                Plan::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });
    }

    // ── Prices ───────────────────────────────────────────────────────

    /**
     * Add a price for an interval that doesn't have one yet, and mint the
     * matching Stripe Price.
     */
    public function addPrice(Plan $plan, string $interval, int $unitAmountCents, array $extra = []): PlanPrice
    {
        if ($plan->priceFor($interval)) {
            throw new \RuntimeException(
                "Plan [{$plan->slug}] already has an active {$interval} price. " .
                'Use changePrice() so existing subscribers are grandfathered.'
            );
        }

        $price = DB::transaction(fn () => PlanPrice::create(array_merge([
            'plan_id'        => $plan->id,
            'interval'       => $interval,
            'currency'       => config('billing.currency', 'usd'),
            'unit_amount'    => $unitAmountCents,
            'is_active'      => true,
            'effective_from' => now(),
        ], $extra)));

        $this->stripeSync->syncPrice($price->fresh('plan'));

        return $price->refresh();
    }

    /**
     * Change what NEW customers pay, without touching existing subscribers.
     *
     * Stripe Prices are immutable, so this never edits the old row. It
     * creates a NEW plan_prices row with a NEW Stripe Price, archives the
     * previous one, and leaves every existing `subscriptions.plan_price_id`
     * pointing at the old row. Grandfathering is therefore automatic and not
     * something an operator can forget to do.
     *
     * This asymmetry is why launching at a low price is safe: raising it
     * later never touches anybody who already signed up.
     */
    public function changePrice(PlanPrice $current, int $newUnitAmountCents): PlanPrice
    {
        if ($newUnitAmountCents === $current->unit_amount) {
            return $current;
        }

        $new = DB::transaction(function () use ($current, $newUnitAmountCents) {
            $replacement = PlanPrice::create([
                'plan_id'           => $current->plan_id,
                'interval'          => $current->interval,
                'currency'          => $current->currency,
                'unit_amount'       => $newUnitAmountCents,
                'compare_at_amount' => $current->compare_at_amount,
                'is_active'         => true,
                'effective_from'    => now(),
                'metadata'          => array_merge((array) $current->metadata, [
                    'replaces_price_id'   => $current->id,
                    'previous_unit_amount'=> $current->unit_amount,
                ]),
            ]);

            $current->forceFill([
                'is_active'      => false,
                'effective_to'   => now(),
                'archived_at'    => now(),
            ])->save();

            return $replacement;
        });

        // New Stripe Price for the new amount; archive the old one so it can
        // no longer be checked out against but existing subs keep billing.
        $this->stripeSync->syncPrice($new->fresh('plan'));
        $this->stripeSync->archivePrice($current);

        return $new->refresh();
    }

    /**
     * Stop selling a price without archiving it in Stripe — existing
     * subscriptions on it keep renewing untouched.
     */
    public function deactivatePrice(PlanPrice $price): void
    {
        $price->forceFill([
            'is_active'    => false,
            'effective_to' => now(),
        ])->save();
    }

    public function activatePrice(PlanPrice $price): void
    {
        // Enforce the "one active price per plan+interval" invariant here,
        // since MySQL can't express it as a partial unique index.
        PlanPrice::query()
            ->where('plan_id', $price->plan_id)
            ->where('interval', $price->interval)
            ->where('id', '!=', $price->id)
            ->update(['is_active' => false, 'effective_to' => now()]);

        $price->forceFill([
            'is_active'    => true,
            'effective_to' => null,
        ])->save();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function uniqueSlug(string $seed): string
    {
        $base = \Illuminate\Support\Str::slug($seed) ?: 'plan';
        $slug = $base;
        $i    = 2;

        while (Plan::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
