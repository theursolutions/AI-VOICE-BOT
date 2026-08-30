<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\Subscription;
use App\Models\Billing\SubscriptionAddon;
use App\Models\Client;

/**
 * What extra capacity costs for the rest of a period the customer already paid.
 *
 * Needed only where the gateway will not work it out for us. Stripe and Paddle
 * both prorate natively; Safepay takes one payment at a time and has no concept
 * of a mid-period adjustment, so somebody has to do the arithmetic and it had
 * better be one place rather than scattered through a controller.
 *
 * THE RULE, and it is deliberately the generous reading:
 *
 *   charge = unit price × ADDITIONAL units × (time left ÷ length of period)
 *
 * Three things follow from that, each of which is a decision rather than an
 * accident:
 *
 *   ADDITIONAL units, not total. Going from 2 seats to 5 charges for 3. Charging
 *   for 5 would bill twice for the two they already hold.
 *
 *   REDUCING COSTS NOTHING AND REFUNDS NOTHING. There is no line to shrink and
 *   no card to credit; the seats simply stop at renewal. Attempting a refund
 *   through a one-off gateway is a support ticket, not a feature.
 *
 *   TIME LEFT IS ROUNDED UP TO THE DAY. Somebody buying a seat at 11pm on the
 *   last day pays for that day rather than for four cents' worth of it — the
 *   alternative produces amounts a gateway will reject and a customer cannot
 *   read.
 */
class AddonProration
{
    /**
     * The smallest amount worth charging, in the currency being charged.
     *
     * PER CURRENCY, and that is not fussiness. A single constant of 5000 minor
     * units is Rs 50 and $50 — one is a sensible floor and the other refuses
     * almost every top-up anyone would buy. It did exactly that: two $5 seats
     * came to 1000 minor units and were turned away as "too little".
     */
    private function minimumCharge(string $currency): int
    {
        $minimums = (array) config('billing.minimum_charge', []);

        return (int) ($minimums[strtoupper($currency)] ?? $minimums['*'] ?? 50);
    }

    public function __construct(private readonly PlanService $plans)
    {
    }

    /**
     * Quote a change from the current quantity to $quantity.
     *
     * @return array{
     *   addon: Plan, price: PlanPrice, from: int, to: int, added: int,
     *   unit_amount: int, full_amount: int, amount: int, currency: string,
     *   days_left: int, days_total: int, period_end: ?\Illuminate\Support\Carbon,
     *   chargeable: bool, reason: ?string
     * }
     *
     * @throws \RuntimeException when the add-on cannot be sold to this workspace
     */
    public function quote(Client $client, string $addonSlug, int $quantity): array
    {
        $subscription = $client->currentSubscription();

        if (! $subscription || ! $subscription->grantsAccess()) {
            throw new \RuntimeException('Add-ons need an active plan. Choose a plan first.');
        }

        $addon = Plan::query()->addons()->where('slug', $addonSlug)->first();

        if (! $addon) {
            throw new \RuntimeException('That add-on isn’t available.');
        }

        $quantity = max(0, min($quantity, 999));

        // THE SUBSCRIPTION'S OWN CURRENCY, not the country's.
        //
        // An add-on is extra capacity on a plan that already exists, and it is
        // re-charged with that plan at every renewal — so the two must be
        // denominated the same. Pricing from the country instead lets a
        // customer who moves between buying the plan and buying the seat end up
        // with a $5 add-on sitting on a rupee subscription, whose renewal then
        // adds `500` to `2250000` and collects Rs 22,505 instead of Rs 24,000.
        //
        // The country still decides for a workspace with no subscription yet —
        // there is nothing to match.
        $currency = strtoupper((string) ($subscription->currency
            ?: app(\App\Services\Billing\Gateways\GatewayRegistry::class)->currencyFor($client)));

        $interval = $subscription->interval ?: 'monthly';
        $price    = $addon->priceFor($interval, $currency);

        if (! $price) {
            throw new \RuntimeException(sprintf(
                'This add-on has no %s price in %s yet. Run `php artisan billing:local-prices`, '
                . 'or add one in Super Admin → Billing.',
                $interval,
                $currency,
            ));
        }

        $existing = SubscriptionAddon::where('subscription_id', $subscription->id)
            ->where('plan_id', $addon->id)
            ->whereNull('cancelled_at')
            ->first();

        $from  = (int) ($existing?->quantity ?? 0);
        $added = max(0, $quantity - $from);

        [$daysLeft, $daysTotal, $periodEnd] = $this->periodShape($subscription);

        $full = $price->unit_amount * $added;

        // Rounded to the minor unit; a gateway cannot take a fraction of one.
        $amount = $daysTotal > 0
            ? (int) round($full * ($daysLeft / $daysTotal))
            : $full;

        return [
            'addon'       => $addon,
            'price'       => $price,
            'from'        => $from,
            'to'          => $quantity,
            'added'       => $added,
            'unit_amount' => $price->unit_amount,
            'full_amount' => $full,
            'amount'      => $amount,
            'currency'    => strtoupper($currency),
            'days_left'   => $daysLeft,
            'days_total'  => $daysTotal,
            'period_end'  => $periodEnd,
            'chargeable'  => $added > 0 && $amount >= $this->minimumCharge($currency),
            'reason'      => $this->reasonNotChargeable($quantity, $from, $added, $amount, $currency),
        ];
    }

    /**
     * Why there is nothing to charge, in words a customer can act on.
     *
     * Distinguished rather than lumped into one "cannot do that": reducing,
     * repeating and a too-small amount are three different situations and only
     * one of them is a problem.
     */
    private function reasonNotChargeable(int $to, int $from, int $added, int $amount, string $currency): ?string
    {
        if ($to === $from) {
            return 'You already have that many.';
        }

        if ($to < $from) {
            return 'Removing takes effect straight away for future purchases — there is nothing '
                 . 'to pay, and the capacity you have already paid for stays until the end of '
                 . 'this billing period.';
        }

        if ($added > 0 && $amount < $this->minimumCharge($currency)) {
            return 'There is too little left of this billing period to be worth charging for. '
                 . 'Buy this at the start of your next period instead.';
        }

        return null;
    }

    /**
     * How much of the period is left, in whole days.
     *
     * Rounded UP, so any part of today counts as a day the customer gets. The
     * total is taken from the actual period rather than from a nominal 30, so a
     * February month is not billed as though it were March.
     *
     * @return array{0: int, 1: int, 2: ?\Illuminate\Support\Carbon}
     */
    private function periodShape(Subscription $subscription): array
    {
        $end = $subscription->current_period_end;

        if (! $end || $end->isPast()) {
            // No period to prorate against — an expired or never-started
            // subscription. Charge the full unit price rather than nothing;
            // zero would hand out capacity for free.
            return [1, 1, $end];
        }

        $start = $subscription->current_period_start
            ?: $end->copy()->subMonths($subscription->interval === 'annually' ? 12 : 1);

        $daysTotal = max(1, (int) ceil($start->diffInHours($end) / 24));
        $daysLeft  = max(1, min($daysTotal, (int) ceil(now()->diffInHours($end) / 24)));

        return [$daysLeft, $daysTotal, $end];
    }
}
