<?php

namespace App\Services\Billing;

use App\Models\Billing\PlanPrice;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Services\Billing\Gateways\CheckoutHandoff;
use App\Services\Billing\Gateways\GatewayRegistry;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\TaxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Start a payment on whichever gateway serves this customer.
 *
 * The charge row is written BEFORE the customer leaves, and that ordering is the
 * whole design. A record created only on success cannot explain the case anyone
 * actually needs it for: a customer who was charged and never came back. Written
 * first, the pending row is what the webhook matches against, what a support
 * question is answered from, and what proves a payment was ever attempted.
 *
 * The reference is OURS, not the gateway's, because the payment has to be named
 * before the gateway has seen it — and it is the only thing that ties their
 * eventual answer to our row.
 *
 * PERIOD DATES ARE FIXED HERE TOO, not on payment. A customer who pays two days
 * early keeps those two days; one who pays two days late is not silently granted
 * a longer month. Deciding it at payment time would make the length of a billing
 * period depend on when someone happened to click.
 */
class GatewayCheckoutService
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
    ) {
    }

    /**
     * Begin a plan purchase.
     *
     * @return array{handoff: CheckoutHandoff, reference: string, gateway: string}
     *
     * @throws \RuntimeException when no gateway can serve this customer
     */
    public function begin(Client $client, PlanPrice $price, array $context = [], ?string $via = null): array
    {
        // The customer's choice wins where they made one — but only from the
        // list their country actually offers, so a hand-edited form field
        // cannot route a payment through a provider we do not sell with there.
        $gateway = ($via && $this->gateways->isAvailableFor($client->billing_country, $via))
            ? $this->gateways->get($via)
            : $this->gateways->forClient($client);

        if (! $gateway || ! $gateway->isConfigured()) {
            throw new \RuntimeException('No payment provider is available for this workspace.');
        }

        $subscription = $client->currentSubscription();
        [$start, $end] = $this->periodFor($price, $subscription);

        // NOTHING FROM TOP-UPS. A plan's charge is the plan's price and nothing
        // else — a renewal that changed amount because somebody bought a seat
        // three weeks ago is a bill the customer cannot predict, and did not
        // agree to when they chose the plan.
        //
        // Top-ups are separate purchases, paid when bought. renewalTopUp()
        // returns zero and explains why; it is called rather than assumed so
        // that reversing the policy has exactly one place to change.
        $topUp = app(AddonPurchaseService::class)->renewalTopUp($client);

        // Tax, where no provider applies it for us. A Merchant of Record
        // computes and remits its own; adding ours on top would charge the
        // customer twice and leave us holding money we cannot remit.
        $tax = app(TaxService::class)->breakdown($client, (int) $price->unit_amount, $gateway);

        $amount = $tax['total'];

        // Short, unambiguous, and safe in a URL — it travels through a third
        // party and comes back as a query parameter.
        $reference = strtoupper(Str::random(4)) . '-' . strtoupper(bin2hex(random_bytes(5)));

        $chargeId = DB::table('gateway_charges')->insertGetId([
            'client_id'       => $client->id,
            'subscription_id' => $subscription?->id,
            'plan_price_id'   => $price->id,
            'gateway'         => $gateway->key(),
            'reference'       => $reference,
            'amount_cents'    => $amount,
            'currency'        => strtoupper($price->currency ?: 'PKR'),
            'status'          => 'pending',
            'interval'        => $price->interval,
            'period_start'    => $start,
            'period_end'      => $end,
            // Why the amount is not simply the plan price, when it isn't.
            'line_items'      => ($topUp > 0 || $tax['tax'] > 0)
                ? json_encode([
                    'kind'        => 'plan',
                    'plan_amount' => (int) $price->unit_amount,
                    'addons'      => $topUp,
                    'tax'         => $tax['tax'],
                    'tax_rate'    => $tax['rate'],
                    'tax_mode'    => $tax['mode'],
                ])
                : null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        try {
            $handoff = $gateway->startCheckout($client, $price, $context + [
                'basket_id'   => $reference,
                'email'       => $client->billing_email,
                'description' => trim(($price->plan?->name ?? 'Subscription') . ' — ' . $client->name),
                // Only when it differs from the row, so the ordinary case sends
                // nothing and cannot be got wrong.
                'amount_override' => $amount !== (int) $price->unit_amount ? $amount : null,
            ]);
        } catch (\Throwable $e) {
            // The gateway refused before the customer ever saw a page, so this
            // charge will never be paid. Marked rather than deleted: a failed
            // attempt is worth being able to see, and a row that vanishes makes
            // a recurring provider outage invisible.
            DB::table('gateway_charges')->where('id', $chargeId)->update([
                'status'         => 'failed',
                'failure_reason' => mb_substr($e->getMessage(), 0, 500),
                'updated_at'     => now(),
            ]);

            throw $e;
        }

        return [
            'handoff'   => $handoff,
            'reference' => $reference,
            'gateway'   => $gateway->key(),
        ];
    }

    /**
     * The period this payment buys.
     *
     * A RENEWAL — the same plan on the same interval — extends from the end of
     * the current period, so renewing early adds time rather than resetting the
     * clock and discarding days already paid for.
     *
     * A CHANGE of plan starts NOW, and that distinction is the whole point of
     * this method. Extending on a change would take a customer switching from
     * an annual plan and start their new one when the old year ended: they
     * would pay today and get nothing for eleven months. What they lose instead
     * is the remainder of the plan they left, which this gateway cannot prorate
     * — so the checkout page says so before they pay.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function periodFor(PlanPrice $price, ?Subscription $subscription): array
    {
        $months = $price->interval === 'annually' ? 12 : ($price->interval === 'quarterly' ? 3 : 1);

        $start = $this->isRenewal($price, $subscription)
            ? $subscription->current_period_end->copy()
            : now();

        return [$start, $start->copy()->addMonths($months)];
    }

    /**
     * Is this payment buying more of what the customer already has?
     *
     * Same plan, same interval, and a period that has not yet run out. Anything
     * else is a change, and a change starts today.
     */
    public function isRenewal(PlanPrice $price, ?Subscription $subscription): bool
    {
        if (! $subscription || ! $subscription->current_period_end?->isFuture()) {
            return false;
        }

        return (int) $subscription->plan_id === (int) $price->plan_id
            && $subscription->interval === $price->interval;
    }

    /**
     * What an unexpired plan loses when this purchase replaces it.
     *
     * Null when nothing is at stake — no subscription, an expired one, or a
     * straight renewal. Otherwise the date cover was paid up to, so the page
     * can tell the customer plainly what they are giving up rather than letting
     * them discover it on the invoice.
     */
    public function forfeits(Client $client, PlanPrice $price): ?\Illuminate\Support\Carbon
    {
        $subscription = $client->currentSubscription();

        if (! $subscription || $this->isRenewal($price, $subscription)) {
            return null;
        }

        $end = $subscription->current_period_end;

        // A free or complimentary period is not something a customer paid for,
        // so presenting its loss as a sacrifice would be misleading.
        $paid = $subscription->unit_amount > 0 || $subscription->stripe_subscription_ref;

        return ($end && $end->isFuture() && $paid) ? $end : null;
    }

    /**
     * Put the workspace on the plan it just paid for.
     *
     * The missing half of "the payment succeeded". Extending the dates on
     * whatever subscription already existed records that money arrived but
     * delivers nothing: entitlements come from `subscriptions.plan_id`, so a
     * customer who paid for Growth would sit on Starter, correctly billed and
     * wrongly limited — the one failure they cannot see and we would not notice.
     *
     * Idempotent by construction: it writes a state rather than applying a
     * delta, so the webhook and the redirect arriving together produce the same
     * row. The single-winner guard on the charge row still matters, but nothing
     * here doubles anything if it were bypassed.
     */
    public function applyPaidCharge(object $charge): ?Subscription
    {
        // An ADD-ON payment is not a plan payment. Falling through to the code
        // below would put the workspace "onto" the seat add-on as though it
        // were their plan, and reset the billing period to the day they bought
        // a seat — losing whatever they had already paid for. The purpose
        // column exists for exactly this branch.
        if (($charge->purpose ?? 'plan') === 'addon') {
            app(AddonPurchaseService::class)->applyPaidCharge($charge);

            // Deliberately null: nothing about the subscription changed, and
            // returning it would invite a caller to treat this as a renewal.
            return null;
        }

        $client = Client::find($charge->client_id);
        $price  = $charge->plan_price_id ? PlanPrice::with('plan')->find($charge->plan_price_id) : null;

        if (! $client || ! $price?->plan) {
            // Money arrived for something we cannot map to a plan. Loud, because
            // it means a customer has paid and is owed something.
            Log::error('billing.paid_charge_unmapped', [
                'charge'    => $charge->id ?? null,
                'client'    => $charge->client_id ?? null,
                'planPrice' => $charge->plan_price_id ?? null,
            ]);

            return null;
        }

        return DB::transaction(function () use ($client, $price, $charge) {
            $subscription = $client->currentSubscription();

            $attributes = [
                'client_id'            => $client->id,
                'plan_id'              => $price->plan_id,
                'plan_price_id'        => $price->id,
                'interval'             => $price->interval,
                'unit_amount'          => (int) $charge->amount_cents,
                'currency'             => strtolower((string) $charge->currency),
                'status'               => Subscription::STATUS_ACTIVE,
                'quantity'             => 1,
                'current_period_start' => $charge->period_start,
                'current_period_end'   => $charge->period_end,
                // Whatever dunning state they were in, they are out of it.
                'past_due_since'       => null,
                'read_only_since'      => null,
                'purge_after'          => null,
                'cancel_at_period_end' => false,
                'canceled_at'          => null,
                'ends_at'              => null,
                'updated_at'           => now(),
            ];

            if ($subscription) {
                // A Stripe reference left on a row now paid through a local
                // gateway would have the next Stripe webhook overwrite this
                // period from an object that is no longer the source of truth.
                $subscription->forceFill($attributes + [
                    'stripe_subscription_ref' => null,
                    'stripe_price_ref'        => null,
                    'stripe_status'           => null,
                ])->save();
            } else {
                $subscription = Subscription::create($attributes + ['created_at' => now()]);
            }

            $client->forceFill([
                'current_plan_id' => $price->plan_id,
                'billing_status'  => Subscription::STATUS_ACTIVE,
                'updated_at'      => time(),
            ])->save();

            // Without this the new plan's limits stay behind a cache and the
            // customer pays for an upgrade that does not arrive until the cache
            // happens to expire.
            app(PlanFeatureService::class)->flush();
            app(PlanFeatureService::class)->flushGrants($client);
            \App\Services\Conversation\ConversationBudget::forgetClient((int) $client->id);

            Log::info('billing.plan_applied', [
                'client'       => $client->id,
                'plan'         => $price->plan->slug,
                'interval'     => $price->interval,
                'until'        => (string) $charge->period_end,
                'subscription' => $subscription->id,
            ]);

            return $subscription;
        });
    }

    /**
     * Does this customer pay on the gateway's own page rather than ours?
     *
     * False when there is NO gateway at all, not true. Both answers lead
     * somewhere broken, but a missing provider is a configuration fault that
     * belongs on the Stripe path where the existing error handling explains it
     * — rather than sending the customer off to a redirect that cannot be
     * built.
     */
    public function isHosted(Client $client): bool
    {
        $gateway = $this->gateways->forClient($client);

        return $gateway !== null && $gateway->key() !== 'stripe';
    }

    public function gateway(Client $client): ?PaymentGateway
    {
        return $this->gateways->forClient($client);
    }

    public function gatewayFor(Client $client): ?string
    {
        return $this->gateways->forClient($client)?->key();
    }
}
