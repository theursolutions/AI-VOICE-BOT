<?php

namespace App\Services\Billing;

use App\Models\Billing\SubscriptionAddon;
use App\Models\Client;
use App\Services\Billing\Gateways\GatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Buying extra capacity from a gateway that cannot amend a subscription.
 *
 * Stripe and Paddle both hold a subscription made of LINE ITEMS, so "one more
 * seat" is an amendment they price and collect themselves. Safepay holds one
 * plan per subscription and no items at all — there is nowhere to put a second
 * line — so the seat has to be sold as its own payment.
 *
 * THE SHAPE OF THAT SALE, and why it is this and not something tidier:
 *
 *   The customer pays ONCE, for the remainder of the period they are already
 *   in, prorated. The capacity arrives the moment the payment clears rather
 *   than at their next renewal, which is the whole point — somebody buying a
 *   seat is usually blocked on it right now.
 *
 *   The add-on row then PERSISTS, and the next renewal charge includes it at
 *   full price. Without that the seats would evaporate at renewal and the
 *   customer would have to buy them again every month, having been given no
 *   warning that they were temporary.
 *
 * AN ADD-ON CHARGE IS NOT A PLAN CHARGE. It must not move the plan and must not
 * move the billing period — paying for two seats on the 12th must not restart
 * the month on the 12th and quietly shorten what was already paid for. That is
 * what `gateway_charges.purpose` exists to distinguish, and why the applier
 * branches on it before touching anything.
 */
class AddonPurchaseService
{
    public function __construct(
        private readonly AddonProration $proration,
        private readonly GatewayRegistry $gateways,
        private readonly PlanFeatureService $features,
    ) {
    }

    /**
     * Does this workspace's gateway need a separate payment for an add-on?
     *
     * The question is whether the provider can amend a subscription, not who
     * the provider is — so a gateway added later answers it by declaring the
     * capability rather than by being named here.
     */
    public function needsCheckout(Client $client): bool
    {
        // A TOP-UP IS ITS OWN PURCHASE, paid for when it is bought.
        //
        // The alternative — folding it into the subscription as a line item and
        // letting the provider prorate it onto the next invoice — is tidier
        // technically and wrong as a product. Somebody who needs a seat today
        // does not want a promise about next month's bill; they want to pay for
        // it and use it. And a plan's payment should not silently change amount
        // because somebody added a seat three weeks ago.
        //
        // So plans and top-ups are separate transactions, always.
        //
        // Stripe is the exception, and only because its subscriptions are still
        // billed as line items elsewhere in this codebase; a customer there has
        // a live subscription-item path that works and is covered by tests.
        return $client->currentSubscription()?->provider() !== 'stripe';
    }

    /**
     * Start a one-off purchase of extra capacity.
     *
     * The charge row is written BEFORE the customer leaves, for the same reason
     * every other charge is: a record that only exists on success cannot explain
     * the case anyone actually needs it for — somebody who was charged and never
     * came back.
     *
     * @return array{handoff: \App\Services\Billing\Gateways\CheckoutHandoff, reference: string, quote: array}
     *
     * @throws \RuntimeException when there is nothing chargeable, with a reason
     *         the customer can act on
     */
    public function begin(Client $client, string $addonSlug, int $quantity, array $context = []): array
    {
        $quote = $this->proration->quote($client, $addonSlug, $quantity);

        if (! $quote['chargeable']) {
            throw new \RuntimeException($quote['reason'] ?? 'There is nothing to pay for that change.');
        }

        $gateway = $this->gateways->forClient($client);

        if (! $gateway || ! $gateway->isConfigured()) {
            throw new \RuntimeException('No payment provider is available for this workspace.');
        }

        $subscription = $client->currentSubscription();
        $reference    = strtoupper(Str::random(4)) . '-' . strtoupper(bin2hex(random_bytes(5)));

        $chargeId = DB::table('gateway_charges')->insertGetId([
            'client_id'       => $client->id,
            'subscription_id' => $subscription?->id,
            // The ADD-ON's price row, not the plan's — this is what was bought.
            'plan_price_id'   => $quote['price']->id,
            'gateway'         => $gateway->key(),
            'purpose'         => 'addon',
            'reference'       => $reference,
            'amount_cents'    => $quote['amount'],
            'currency'        => $quote['currency'],
            'status'          => 'pending',
            'interval'        => $quote['price']->interval,
            // The period is recorded but NOT applied: an add-on charge must
            // never move the subscription's dates. It is here so the capacity
            // can be bounded to the period it was bought for.
            'period_start'    => now(),
            'period_end'      => $quote['period_end'],
            // Enough to explain the amount a year later without recomputing it.
            'line_items'      => json_encode([
                'kind'         => 'addon',
                'addon_slug'   => $addonSlug,
                'addon_plan_id'=> $quote['addon']->id,
                'from'         => $quote['from'],
                'to'           => $quote['to'],
                'added'        => $quote['added'],
                'unit_amount'  => $quote['unit_amount'],
                'full_amount'  => $quote['full_amount'],
                'days_left'    => $quote['days_left'],
                'days_total'   => $quote['days_total'],
            ]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        try {
            $handoff = $gateway->startCheckout($client, $quote['price'], $context + [
                'basket_id'   => $reference,
                'email'       => $client->billing_email,
                'description' => sprintf('%d × %s — %s', $quote['added'], $quote['addon']->name, $client->name),
                // The gateway must collect the PRORATED figure, not the price
                // row's full amount. A provider that reads the row directly
                // would charge a whole month for eleven days.
                'amount_override' => $quote['amount'],
            ]);
        } catch (\Throwable $e) {
            DB::table('gateway_charges')->where('id', $chargeId)->update([
                'status'         => 'failed',
                'failure_reason' => mb_substr($e->getMessage(), 0, 500),
                'updated_at'     => now(),
            ]);

            throw $e;
        }

        return ['handoff' => $handoff, 'reference' => $reference, 'quote' => $quote];
    }

    /**
     * Grant the capacity a paid add-on charge bought.
     *
     * Idempotent by writing a state rather than applying a delta: the quantity
     * lands at the `to` figure recorded when the purchase started, so a webhook
     * and a redirect arriving together produce the same row. Adding `added` to
     * whatever is currently there would double the seats on a retry.
     */
    public function applyPaidCharge(object $charge): ?SubscriptionAddon
    {
        $items = is_string($charge->line_items ?? null)
            ? json_decode($charge->line_items, true)
            : (array) ($charge->line_items ?? []);

        $client = Client::find($charge->client_id);
        $planId = (int) ($items['addon_plan_id'] ?? 0);

        if (! $client || ! $planId) {
            Log::error('billing.addon_charge_unmapped', [
                'charge' => $charge->id ?? null,
                'items'  => $items,
            ]);

            return null;
        }

        $subscription = $client->currentSubscription();

        if (! $subscription) {
            // Paid for capacity on a plan that has since gone. Loud, because
            // somebody is owed either the capacity or their money.
            Log::error('billing.addon_without_subscription', [
                'charge' => $charge->id ?? null,
                'client' => $client->id,
            ]);

            return null;
        }

        $price = \App\Models\Billing\PlanPrice::find($charge->plan_price_id);

        $addon = DB::transaction(function () use ($subscription, $client, $charge, $items, $price) {
            $row = SubscriptionAddon::firstOrNew([
                'subscription_id' => $subscription->id,
                'plan_id'         => (int) $items['addon_plan_id'],
            ]);

            $row->forceFill([
                'client_id'     => $client->id,
                'plan_price_id' => $charge->plan_price_id,
                // The TARGET quantity, not an increment. See the method note.
                'quantity'      => (int) ($items['to'] ?? 0),
                'unit_amount'   => (int) ($items['unit_amount'] ?? $price?->unit_amount ?? 0),
                'currency'      => strtolower((string) $charge->currency),
                'interval'      => $charge->interval,
                'gateway'       => $charge->gateway,
                // Bounded to the period it was bought for. The renewal charge
                // re-buys it at full price; until then this is what stops a
                // one-off add-on granting capacity forever.
                'period_end'    => $charge->period_end,
                'cancelled_at'  => null,
                'metadata'      => array_merge((array) $row->metadata, [
                    'last_charge'    => $charge->reference,
                    'last_paid_at'   => now()->toIso8601String(),
                    'last_prorated'  => (int) $charge->amount_cents,
                ]),
            ])->save();

            return $row;
        });

        // Without this the customer pays for a seat and cannot use it until a
        // cache expires — the one failure they would report as "it didn't work".
        $this->features->flush();
        $this->features->flushGrants($client);

        Log::info('billing.addon.applied', [
            'client'       => $client->id,
            'addon'        => $items['addon_slug'] ?? null,
            'quantity'     => $addon->quantity,
            'charge'       => $charge->reference,
            'until'        => (string) $charge->period_end,
        ]);

        return $addon;
    }

    /**
     * What the next renewal must collect on top of the plan.
     *
     * ZERO, BY DESIGN, and this method survives only so the intent is written
     * down rather than inferred from an absence.
     *
     * A top-up is bought and paid for on its own. Adding it to the plan's
     * renewal would make that renewal a different amount every month depending
     * on what was bought weeks earlier — a bill the customer cannot predict and
     * did not agree to. It would also blur the one distinction the product
     * makes: a plan is a subscription, a top-up is a purchase.
     *
     * The consequence is deliberate and is stated on the add-ons page: capacity
     * covers the period it was bought for, and buying again extends it.
     */
    public function renewalTopUp(Client $client): int
    {
        return 0;
    }

    /** What add-ons currently in force are worth, for display and reporting. */
    public function heldValue(Client $client): int
    {
        $subscription = $client->currentSubscription();

        if (! $subscription) {
            return 0;
        }

        $currency = strtolower((string) $subscription->currency);

        $held = $subscription->addons()->held()->get();

        // ONLY add-ons in the subscription's own currency. Minor units are just
        // integers — 500 could be $5 or Rs 5 — so summing across currencies
        // produces a number that is wrong by a factor of the exchange rate and
        // looks perfectly reasonable. AddonProration now prices in the
        // subscription's currency precisely so this cannot arise; if one ever
        // does, it is dropped from the total and said out loud rather than
        // quietly corrupting the amount charged.
        $mismatched = $held->filter(
            fn (SubscriptionAddon $a) => $currency !== '' && strtolower((string) $a->currency) !== $currency
        );

        if ($mismatched->isNotEmpty()) {
            Log::error('billing.addon.currency_mismatch', [
                'subscription' => $subscription->id,
                'expected'     => $currency,
                'found'        => $mismatched->pluck('currency')->unique()->values()->all(),
                'addons'       => $mismatched->pluck('id')->all(),
            ]);
        }

        return (int) $held
            ->diff($mismatched)
            ->sum(fn (SubscriptionAddon $a) => (int) $a->unit_amount * max(0, (int) $a->quantity));
    }
}
