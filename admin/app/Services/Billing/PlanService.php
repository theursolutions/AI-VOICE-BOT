<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Client;
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
    public function resolvePrice(
        string $planSlug,
        string $interval,
        ?Client $for = null,
        ?string $currency = null,
    ): PlanPrice {
        $plan = $this->findBySlug($planSlug);

        if (! $plan) {
            throw new \RuntimeException("Unknown plan [{$planSlug}].");
        }

        if (! $plan->isPurchasable()) {
            throw new \RuntimeException("Plan [{$planSlug}] is not purchasable.");
        }

        // Custom plans belong to one workspace. Enforced HERE rather than in each
        // controller because this method is the single point every purchase path
        // funnels through — checkout, subscribe and swap all arrive at it — and a
        // rule spread across three call sites is a rule that will be missing from
        // the fourth. See Plan::isAvailableTo().
        //
        // $for is nullable so internal callers with no workspace in hand (a
        // console command, a webhook replay) still work; it only ever tightens
        // the check when a workspace IS known.
        if ($for !== null && ! $plan->isAvailableTo($for)) {
            throw new \RuntimeException("Plan [{$planSlug}] is not available to this workspace.");
        }

        if (! in_array($interval, (array) config('billing.intervals.supported', []), true)) {
            throw new \RuntimeException("Unsupported billing interval [{$interval}].");
        }

        // The currency the settling gateway can actually take. Safepay settles
        // rupees and nothing else, so a rupee customer must be given the rupee
        // row — handing it the dollar row would send `7500` to a gateway that
        // reads it as Rs 7,500 and charge a $75 plan at a fiftieth of its price.
        //
        // Passed in when the customer has CHOSEN a provider: a Pakistani buyer
        // may pick the international card option, and then the rupee row is the
        // wrong one even though their country would normally select it.
        $currency ??= $for ? $this->currencyFor($for) : null;

        $price = $plan->priceFor($interval, $currency);

        if (! $price) {
            throw new \RuntimeException(
                $currency
                    ? "Plan [{$planSlug}] has no active {$interval} price in {$currency}. "
                        . 'Run `php artisan billing:local-prices` to mint one.'
                    : "Plan [{$planSlug}] has no active {$interval} price."
            );
        }

        // Only demanded of the customers who will actually pay through Stripe.
        // A rupee price has no Stripe Price behind it and never will, and
        // requiring one would make a business with no Stripe account — which is
        // exactly why the local gateway exists — unable to sell anything at all.
        if ($currency === null && $this->paysThroughStripe($for) && ! $price->isSyncedToStripe()) {
            throw new \RuntimeException(
                "Plan [{$planSlug}] {$interval} price is not synced to Stripe. " .
                'Sync it from Super Admin → Billing → Plans before selling it.'
            );
        }

        return $price;
    }

    /**
     * The currency a workspace is billed in, or null when it cannot be decided.
     *
     * Null — not a guess — when no gateway is configured at all, so the caller
     * falls back to the platform currency rather than being told a workspace
     * pays in a currency nothing can charge.
     */
    private function currencyFor(?Client $client): ?string
    {
        if (! $client) {
            return null;
        }

        return app(\App\Services\Billing\Gateways\GatewayRegistry::class)->forClient($client)?->currencies()[0] ?? null;
    }

    /** Will this workspace's money arrive through Stripe? */
    private function paysThroughStripe(?Client $client): bool
    {
        if (! $client) {
            // No workspace in hand — a console command, a webhook replay. Keep
            // the stricter historical behaviour rather than quietly relaxing a
            // check for every internal caller.
            return true;
        }

        return app(\App\Services\Billing\Gateways\GatewayRegistry::class)
            ->forClient($client)?->key() === 'stripe';
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
        $currency = strtolower((string) ($extra['currency'] ?? config('billing.currency', 'usd')));

        // Per CURRENCY, not per interval: a plan legitimately carries a dollar
        // monthly price and a rupee one, and the guard would otherwise refuse
        // the second.
        if ($plan->priceFor($interval, $currency)) {
            throw new \RuntimeException(
                "Plan [{$plan->slug}] already has an active {$interval} price in " . strtoupper($currency) . '. ' .
                'Use changePrice() so existing subscribers are grandfathered.'
            );
        }

        $price = DB::transaction(fn () => PlanPrice::create(array_merge([
            'plan_id'        => $plan->id,
            'interval'       => $interval,
            'currency'       => $currency,
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
