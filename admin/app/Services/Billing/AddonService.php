<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\SubscriptionAddon;
use App\Models\Client;
use Illuminate\Support\Facades\Log;

/**
 * Buying and adjusting add-ons — extra seats, extra AI agents.
 *
 * MODELLED AS A LINE ON THE EXISTING SUBSCRIPTION wherever the provider has
 * lines, not as a second subscription. That gives one invoice, one renewal date
 * and one payment method, and lets the provider prorate a mid-cycle change
 * itself. Two subscriptions would mean two charges on different days for what
 * the customer thinks of as one plan.
 *
 * THREE PATHS, because the providers genuinely differ in kind:
 *
 *   Stripe   subscription items — create, update, delete. Stripe prorates.
 *   Paddle   a PATCH replacing the whole item list. Paddle prorates.
 *   Safepay  no items at all: one plan per subscription, nowhere to put a
 *            second line. Sold instead as a separate prorated payment, which
 *            is AddonPurchaseService's job and does not come through here.
 *
 * The Paddle path is the one to be careful with: its items array REPLACES
 * everything, so an amendment that forgets the plan's own line cancels the
 * plan. The complete list is therefore read back from Paddle before it is sent.
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

    /**
     * Add-ons a workspace could buy, priced as they will be charged.
     *
     * IN THE WORKSPACE'S OWN CURRENCY. A plan carries a dollar price and a rupee
     * one, and taking whichever came first would show a Pakistani customer "$5"
     * for a seat they are about to be charged Rs 1,500 for — a figure that is
     * neither what they pay nor a conversion of it.
     */
    public function available(Client $client): array
    {
        $subscription = $client->currentSubscription();
        $interval     = $subscription?->interval ?: 'monthly';
        $currency     = app(\App\Services\Billing\Gateways\GatewayRegistry::class)->currencyFor($client);

        $out = [];

        foreach (Plan::query()->addons()->with(['prices', 'planFeatures.feature'])->get() as $addon) {
            $price = $addon->priceFor($interval, $currency);

            if (! $price) {
                continue;   // not sold on this billing interval, in this currency
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

                // Every interval this add-on is sold on, so the page can show a
                // monthly/annual comparison instead of the single figure that
                // happens to match the current subscription. Only the matching
                // one is purchasable — Stripe requires every line on a
                // subscription to share one billing interval — but showing both
                // is the difference between an informed choice and a number that
                // looks arbitrary.
                // Filtered to one currency BEFORE keying by interval: with both
                // a dollar and a rupee monthly row, keyBy would keep whichever
                // came last and the comparison would silently mix currencies.
                'prices'      => $addon->prices
                    ->where('is_active', true)
                    ->filter(fn ($p) => strtoupper((string) $p->currency) === strtoupper($currency))
                    ->keyBy('interval')
                    ->all(),
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

        if (! $subscription?->grantsAccess()) {
            throw new \RuntimeException('Add-ons need an active subscription. Choose a plan first.');
        }

        // WHICHEVER PROVIDER HOLDS THIS SUBSCRIPTION is the one to tell, and
        // that is not the same as whichever gateway serves this customer's
        // country today. Routing changes when an operator flips a switch; a
        // subscription created in Stripe still lives in Stripe. Dispatching on
        // the country would send an existing Stripe subscriber's seat change to
        // Paddle, which has never heard of them.
        // Paddle used to amend its own subscription here, adding the seat as a
        // recurring line prorated onto the next invoice. That is no longer how
        // top-ups work: they are separate purchases, paid when bought, and the
        // controller routes them to AddonPurchaseService before reaching this.
        // Only a REMOVAL still lands here for a Paddle customer.
        if ($subscription->provider() === 'paddle') {
            return $this->setQuantityViaPaddle($client, $subscription, $addonSlug, $quantity);
        }

        if ($subscription->provider() !== 'stripe') {
            // Safepay and anything else without line items. Not an error the
            // customer can fix by retrying — it needs a separate payment, which
            // AddonPurchaseService handles and the controller routes to.
            throw new \RuntimeException(
                'Extra capacity is bought as a separate payment with this payment method.'
            );
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
    /**
     * Amend the customer's Paddle subscription.
     *
     * THE ITEMS ARRAY REPLACES EVERYTHING Paddle holds. Sending only the add-on
     * would remove the plan's own line and leave the customer paying for two
     * seats attached to nothing — so the current list is read back from Paddle
     * and the add-on merged into it, rather than assembled from our own tables
     * which may not match what Paddle will actually bill.
     *
     * Paddle prorates immediately, so the customer is charged the difference for
     * the remainder of the period and the seats work at once. The resulting
     * `subscription.updated` webhook confirms it; this writes the row so the
     * capacity does not wait on a round trip.
     */
    private function setQuantityViaPaddle(
        Client $client,
        \App\Models\Billing\Subscription $subscription,
        string $addonSlug,
        int $quantity,
    ): ?SubscriptionAddon {
        $paddleId = (string) $subscription->paddle_subscription_id;

        if ($paddleId === '') {
            throw new \RuntimeException(
                'This plan has not finished setting up with Paddle yet. Try again in a moment.'
            );
        }

        $addon = Plan::query()->addons()->where('slug', $addonSlug)->first();

        if (! $addon) {
            throw new \RuntimeException('That add-on isn’t available.');
        }

        $quantity = max(0, min($quantity, 999));
        $interval = $subscription->interval ?: 'monthly';
        $currency = strtoupper((string) config('billing.currency', 'usd'));
        $price    = $addon->priceFor($interval, $currency);

        if (! $price?->paddle_price_id) {
            throw new \RuntimeException(
                'This add-on has no Paddle price yet. Run `php artisan paddle:sync` to create one.'
            );
        }

        $paddle = app(\App\Services\Billing\Gateways\PaddleGateway::class);

        // Read, don't assume. See the method note — this is the call that
        // decides whether the plan survives the amendment.
        $items = $paddle->subscriptionItems($paddleId);

        if ($quantity === 0) {
            unset($items[$price->paddle_price_id]);
        } else {
            $items[$price->paddle_price_id] = $quantity;
        }

        if ($items === []) {
            throw new \RuntimeException(
                'Removing that would leave the subscription empty. Cancel the plan instead.'
            );
        }

        $paddle->updateSubscriptionItems(
            $paddleId,
            array_map(
                fn ($priceId, $qty) => ['price_id' => $priceId, 'quantity' => $qty],
                array_keys($items),
                array_values($items),
            ),
        );

        $existing = SubscriptionAddon::where('subscription_id', $subscription->id)
            ->where('plan_id', $addon->id)
            ->first();

        if ($quantity === 0) {
            $existing?->forceFill(['cancelled_at' => now(), 'quantity' => 0])->save();
            $this->features->flush();

            Log::info('billing.addon.paddle_removed', [
                'client' => $client->id, 'addon' => $addonSlug,
            ]);

            return null;
        }

        $row = $existing ?: new SubscriptionAddon();

        $row->forceFill([
            'subscription_id'      => $subscription->id,
            'client_id'            => $client->id,
            'plan_id'              => $addon->id,
            'plan_price_id'        => $price->id,
            'quantity'             => $quantity,
            'unit_amount'          => $price->unit_amount,
            'currency'             => strtolower($currency),
            'interval'             => $interval,
            'gateway'              => 'paddle',
            'paddle_item_price_id' => $price->paddle_price_id,
            // No end date: Paddle bills this line every period for as long as
            // the subscription runs, unlike a one-off purchase.
            'period_end'           => null,
            'cancelled_at'         => null,
        ])->save();

        // The seat has to work now, not once a cache expires.
        $this->features->flush();
        $this->features->flushGrants($client);

        Log::info('billing.addon.paddle_set', [
            'client' => $client->id, 'addon' => $addonSlug, 'quantity' => $quantity,
        ]);

        return $row->refresh();
    }

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
