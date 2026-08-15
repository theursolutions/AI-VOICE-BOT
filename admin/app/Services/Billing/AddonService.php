<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\SubscriptionAddon;
use App\Models\Client;
use Illuminate\Support\Facades\Log;

/**
 * Buying and adjusting add-ons — extra seats, extra AI agents.
 *
 * MODELLED AS STRIPE SUBSCRIPTION ITEMS on the customer's existing
 * subscription, not as a second subscription. That gives one invoice, one
 * renewal date and one payment method, and lets Stripe prorate a mid-cycle
 * change by itself. Two subscriptions would mean two charges on different days
 * for what the customer thinks of as one plan.
 *
 * The add-on's INTERVAL is forced to match the subscription's: Stripe refuses
 * to put a monthly and an annual price on the same subscription, and a
 * customer on annual billing should not get a separate monthly seat charge.
 */
class AddonService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly PlanService $plans,
        private readonly PlanFeatureService $features,
    ) {
    }

    /** Add-ons a workspace could buy, with the price for its billing interval. */
    public function available(Client $client): array
    {
        $subscription = $client->currentSubscription();
        $interval     = $subscription?->interval ?: 'monthly';

        $out = [];

        foreach (Plan::query()->addons()->with(['prices', 'planFeatures.feature'])->get() as $addon) {
            $price = $addon->priceFor($interval);

            if (! $price) {
                continue;   // not sold on this billing interval
            }

            $featureKey = $addon->addonFeatureKey();

            $held = $subscription
                ? SubscriptionAddon::where('subscription_id', $subscription->id)
                    ->where('plan_id', $addon->id)
                    ->active()
                    ->first()
                : null;

            $out[] = [
                'plan'        => $addon,
                'price'       => $price,
                'feature_key' => $featureKey,
                'per_unit'    => $featureKey ? $this->features->planLimit($addon, $featureKey) : null,
                'owned'       => (int) ($held->quantity ?? 0),
                'line_total'  => $held?->formattedLineTotal(),
            ];
        }

        return $out;
    }

    /**
     * Set the quantity of an add-on. Quantity 0 removes it.
     *
     * Idempotent on the quantity, not the action — asking for 5 when they
     * already hold 5 is a no-op rather than an error, so a double-submitted
     * form can't silently charge for ten.
     */
    public function setQuantity(Client $client, string $addonSlug, int $quantity): SubscriptionAddon|null
    {
        $subscription = $client->currentSubscription();

        if (! $subscription?->stripe_subscription_ref || ! $subscription->grantsAccess()) {
            throw new \RuntimeException('Add-ons need an active subscription. Choose a plan first.');
        }

        $addon = Plan::query()->addons()->where('slug', $addonSlug)->first();

        if (! $addon) {
            throw new \RuntimeException('That add-on isn’t available.');
        }

        $quantity = max(0, min($quantity, 999));

        // Match the subscription's cadence — Stripe rejects mixed intervals.
        $interval = $subscription->interval ?: 'monthly';
        $price    = $addon->priceFor($interval);

        if (! $price || ! $price->stripe_price_ref) {
            throw new \RuntimeException(
                'This add-on has no ' . $interval . ' price yet. Add one in Super Admin → Billing.'
            );
        }

        $existing = SubscriptionAddon::where('subscription_id', $subscription->id)
            ->where('plan_id', $addon->id)
            ->first();

        if ($existing && (int) $existing->quantity === $quantity && ! $existing->cancelled_at) {
            return $existing;
        }

        $stripe = $this->factory->make();

        // ── Remove ───────────────────────────────────────────────────
        if ($quantity === 0) {
            if ($existing?->stripe_item_ref) {
                $stripe->subscriptionItems->delete($existing->stripe_item_ref, [
                    // Credit the unused part rather than charging on for it.
                    'proration_behavior' => 'create_prorations',
                ]);
            }

            $existing?->forceFill(['quantity' => 0, 'cancelled_at' => now()])->save();

            $this->flush($client);

            Log::info('billing.addon.removed', ['client_id' => $client->getKey(), 'addon' => $addonSlug]);

            return null;
        }

        // ── Update an existing line ──────────────────────────────────
        if ($existing?->stripe_item_ref && ! $existing->cancelled_at) {
            $stripe->subscriptionItems->update($existing->stripe_item_ref, [
                'quantity'           => $quantity,
                'proration_behavior' => (string) config('billing.checkout.proration_behavior', 'create_prorations'),
            ]);

            $existing->forceFill([
                'quantity'      => $quantity,
                'plan_price_id' => $price->id,
                'unit_amount'   => $price->unit_amount,
                'interval'      => $interval,
                'cancelled_at'  => null,
            ])->save();

            $this->flush($client);

            return $existing;
        }

        // ── Create a new line ────────────────────────────────────────
        $item = $stripe->subscriptionItems->create([
            'subscription'       => $subscription->stripe_subscription_ref,
            'price'              => $price->stripe_price_ref,
            'quantity'           => $quantity,
            'proration_behavior' => (string) config('billing.checkout.proration_behavior', 'create_prorations'),
            'metadata'           => [
                'client_ref' => (string) $client->getKey(),
                'addon_slug' => $addon->slug,
            ],
        ]);

        $record = SubscriptionAddon::updateOrCreate(
            ['subscription_id' => $subscription->id, 'plan_id' => $addon->id],
            [
                'client_id'       => $client->getKey(),
                'plan_price_id'   => $price->id,
                'quantity'        => $quantity,
                'stripe_item_ref' => $item->id,
                'unit_amount'     => $price->unit_amount,
                'currency'        => 'usd',
                'interval'        => $interval,
                'cancelled_at'    => null,
            ]
        );

        $this->flush($client);

        Log::info('billing.addon.purchased', [
            'client_id' => $client->getKey(),
            'addon'     => $addonSlug,
            'quantity'  => $quantity,
        ]);

        return $record;
    }

    /**
     * What changing an add-on's quantity would cost, BEFORE committing to it.
     *
     * The prorated "due today" figure is asked of Stripe (`invoices
     * ->createPreview`) rather than computed here. Proration depends on the
     * exact second of the billing period, unused-time credits from earlier
     * changes, discounts and tax — arithmetic we would get subtly wrong, and
     * a receipt that disagrees with the card statement is worse than no
     * estimate at all.
     *
     * `due_today` is null when the preview can't be fetched. The page then
     * says "prorated on your next invoice" instead of showing a number it
     * can't stand behind — it never blocks the purchase.
     *
     * @return array{quantity:int,current:int,unit_amount:int,recurring:int,due_today:?int,next_total:?int,interval:string,currency:string}
     */
    public function preview(Client $client, string $addonSlug, int $quantity): array
    {
        $subscription = $client->currentSubscription();

        if (! $subscription?->stripe_subscription_ref) {
            throw new \RuntimeException('Add-ons need an active subscription. Choose a plan first.');
        }

        $addon = Plan::query()->addons()->where('slug', $addonSlug)->first();

        if (! $addon) {
            throw new \RuntimeException('That add-on isn’t available.');
        }

        $quantity = max(0, min($quantity, 999));
        $interval = $subscription->interval ?: 'monthly';
        $price    = $addon->priceFor($interval);

        if (! $price || ! $price->stripe_price_ref) {
            throw new \RuntimeException('This add-on has no ' . $interval . ' price yet.');
        }

        $existing = SubscriptionAddon::where('subscription_id', $subscription->id)
            ->where('plan_id', $addon->id)
            ->active()
            ->first();

        $out = [
            'quantity'    => $quantity,
            'current'     => (int) ($existing->quantity ?? 0),
            'unit_amount' => (int) $price->unit_amount,
            'recurring'   => (int) $price->unit_amount * $quantity,
            'due_today'   => null,
            'next_total'  => null,
            'interval'    => $interval,
            'currency'    => 'usd',
        ];

        try {
            $item = $existing?->stripe_item_ref
                ? ['id' => $existing->stripe_item_ref, 'quantity' => $quantity]
                : ['price' => $price->stripe_price_ref, 'quantity' => $quantity];

            // Removing a line is expressed as deleted, not quantity 0.
            if ($quantity === 0 && $existing?->stripe_item_ref) {
                $item = ['id' => $existing->stripe_item_ref, 'deleted' => true];
            }

            $invoice = $this->factory->make()->invoices->createPreview([
                'customer'     => $subscription->stripe_customer_ref ?: $client->stripe_customer_ref,
                'subscription' => $subscription->stripe_subscription_ref,
                'subscription_details' => [
                    'items'              => [$item],
                    'proration_behavior' => (string) config('billing.checkout.proration_behavior', 'create_prorations'),
                ],
            ]);

            $out['next_total'] = (int) ($invoice->total ?? 0);

            // Only the proration lines are charged now; the rest of the
            // preview is the next renewal.
            $now = 0;
            foreach ($invoice->lines->data ?? [] as $line) {
                if (! empty($line->proration)) {
                    $now += (int) ($line->amount ?? 0);
                }
            }

            $out['due_today'] = $now;
        } catch (\Throwable $e) {
            Log::warning('billing.addon.preview_failed', [
                'client_id' => $client->getKey(),
                'addon'     => $addonSlug,
                'error'     => $e->getMessage(),
            ]);
        }

        return $out;
    }

    /** Recurring cost of all add-ons, in USD cents per the plan's interval. */
    public function monthlyTotalCents(Client $client): int
    {
        $subscription = $client->currentSubscription();

        if (! $subscription) {
            return 0;
        }

        return (int) $subscription->addons()->active()->get()->sum(fn ($a) => $a->lineTotal());
    }

    /**
     * The entitlement cache is keyed per PLAN, but an add-on changes what a
     * CLIENT is entitled to. Flushing the add-on plan's entry isn't enough —
     * the base plan's resolved map is what callers read through, so drop the
     * lot and let it rebuild.
     */
    private function flush(Client $client): void
    {
        $client->forgetSubscription();
        $this->features->flush();
    }
}
