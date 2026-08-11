<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use Illuminate\Support\Facades\Log;

/**
 * Pushes the local plan catalogue into Stripe: Products for plans, Prices for
 * plan_prices.
 *
 * THE IMMUTABILITY RULE this class exists to honour:
 * a Stripe Price can never have its amount changed. So this class only ever
 * CREATES prices and ARCHIVES old ones — it has no update path for an amount,
 * by design. PlanService::changePrice() creates the replacement row locally;
 * this turns it into a Stripe object. Existing subscriptions keep billing
 * against the archived Price, which is exactly the grandfathering behaviour
 * we want and the reason raising prices later is safe.
 */
class StripeSyncService
{
    public function __construct(private readonly StripeClientFactory $factory)
    {
    }

    // ── Products ─────────────────────────────────────────────────────

    /**
     * Ensure the plan has a Stripe Product, returning its id. Products ARE
     * mutable, so name/description changes are pushed on every sync.
     */
    public function syncProduct(Plan $plan): ?string
    {
        if (! $this->factory->isConfigured()) {
            return null;
        }

        $stripe = $this->factory->make();

        // Reuse whichever product any of this plan's prices already points at,
        // so a plan never ends up with two Products in the dashboard.
        $existing = $plan->prices()
            ->whereNotNull('stripe_product_ref')
            ->value('stripe_product_ref');

        $payload = [
            'name'        => $plan->name,
            'description' => $plan->tagline ?: $plan->description ?: null,
            'metadata'    => [
                'plan_slug' => $plan->slug,
                'plan_ref'  => (string) $plan->id,
                'app'       => config('app.name'),
            ],
        ];

        // Stripe rejects an explicit null description on update.
        if ($payload['description'] === null) {
            unset($payload['description']);
        }

        try {
            if ($existing) {
                $stripe->products->update($existing, $payload);

                return $existing;
            }

            return $stripe->products->create($payload)->id;
        } catch (\Throwable $e) {
            Log::error('stripe.sync.product_failed', [
                'plan'  => $plan->slug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // ── Prices ───────────────────────────────────────────────────────

    /**
     * Create the Stripe Price for a local price row, if it doesn't have one.
     *
     * Idempotent: a row that already carries a stripe_price_ref is returned
     * untouched, so re-running a sync never mints duplicates.
     */
    public function syncPrice(PlanPrice $price): PlanPrice
    {
        if (! $this->factory->isConfigured()) {
            return $price;
        }

        if ($price->isSyncedToStripe()) {
            return $price;
        }

        $plan = $price->plan ?: $price->plan()->first();

        if (! $plan) {
            throw new \RuntimeException("Price #{$price->id} has no plan.");
        }

        if ($plan->isFree()) {
            // A free plan never has a Stripe object — there is nothing to
            // charge, and creating a $0 Price would let it reach Checkout.
            return $price;
        }

        $productRef = $price->stripe_product_ref ?: $this->syncProduct($plan);

        [$interval, $intervalCount] = $this->stripeInterval($price->interval);

        try {
            $created = $this->factory->make()->prices->create([
                'product'     => $productRef,
                'currency'    => strtolower($price->currency ?: 'usd'),
                'unit_amount' => $price->unit_amount,
                'recurring'   => [
                    'interval'       => $interval,
                    'interval_count' => $intervalCount,
                ],
                'nickname'    => "{$plan->name} — {$price->intervalLabel()}",
                'metadata'    => [
                    'plan_slug'      => $plan->slug,
                    'plan_price_ref' => (string) $price->id,
                    'interval'       => $price->interval,
                ],
            ]);

            $price->forceFill([
                'stripe_price_ref'   => $created->id,
                'stripe_product_ref' => $productRef,
                'stripe_livemode'    => (bool) $created->livemode,
                'stripe_synced_at'   => now(),
            ])->save();

            Log::info('stripe.sync.price_created', [
                'plan'     => $plan->slug,
                'interval' => $price->interval,
                'amount'   => $price->unit_amount,
                'price'    => $created->id,
            ]);

            return $price->refresh();
        } catch (\Throwable $e) {
            Log::error('stripe.sync.price_failed', [
                'plan'  => $plan->slug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Archive a Stripe Price: it can no longer be used for NEW checkouts,
     * but existing subscriptions on it keep renewing at that amount. This is
     * precisely what grandfathers current subscribers through a price rise.
     */
    public function archivePrice(PlanPrice $price): void
    {
        if (! $this->factory->isConfigured() || ! $price->isSyncedToStripe()) {
            return;
        }

        try {
            $this->factory->make()->prices->update($price->stripe_price_ref, ['active' => false]);

            $price->forceFill(['archived_at' => now()])->save();

            Log::info('stripe.sync.price_archived', ['price' => $price->stripe_price_ref]);
        } catch (\Throwable $e) {
            // Never fatal: the local row is already deactivated, so nobody can
            // buy it. A stale active Price in the dashboard is cosmetic.
            Log::warning('stripe.sync.archive_failed', [
                'price' => $price->stripe_price_ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Sync every unsynced active price. Returns counts for the admin flash. */
    public function syncAll(): array
    {
        $synced = 0;
        $failed = 0;
        $errors = [];

        $pending = PlanPrice::query()
            ->with('plan')
            ->where('is_active', true)
            ->whereNull('stripe_price_ref')
            ->get()
            ->reject(fn (PlanPrice $p) => $p->plan?->isFree());

        foreach ($pending as $price) {
            try {
                $this->syncPrice($price);
                $synced++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ($price->plan?->slug ?? '?') . ' ' . $price->interval . ': ' . $e->getMessage();
            }
        }

        return ['synced' => $synced, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Prices whose Stripe ref was minted in the other mode. Checking out
     * against a test Price with a live key fails at Stripe, so the admin UI
     * surfaces this as a warning rather than letting a customer hit it.
     */
    public function modeMismatches(): array
    {
        return PlanPrice::query()
            ->with('plan')
            ->whereNotNull('stripe_price_ref')
            ->whereNotNull('stripe_livemode')
            ->where('stripe_livemode', '!=', $this->factory->isLiveMode())
            ->get()
            ->all();
    }

    /** @return array{0:string,1:int} */
    private function stripeInterval(string $interval): array
    {
        $map = (array) config("billing.intervals.stripe_map.{$interval}");

        if (count($map) !== 2) {
            throw new \RuntimeException("No Stripe mapping for interval [{$interval}].");
        }

        return [(string) $map[0], (int) $map[1]];
    }
}
