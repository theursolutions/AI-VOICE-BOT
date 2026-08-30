<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use Illuminate\Support\Facades\DB;

/**
 * Selling prices in a currency a local gateway can actually settle.
 *
 * A Pakistani customer pays through Safepay, and Safepay settles PKR only — so
 * somewhere a rupee figure has to exist. The obvious shortcut is to convert the
 * dollar price at checkout with the FX service, and it is the wrong answer:
 * ExchangeRateService exists for DISPLAY and says so in its own docblock. Its
 * rate moves daily and can be absent entirely, which would make the price on
 * the invoice depend on whether a third-party API answered this morning.
 *
 * So a rupee price is MINTED, once, into `plan_prices` — a real row, with a real
 * currency, that an operator can see and edit. After that the row is the price.
 * The rate in config is only the recipe that produced it, and re-running this
 * does not disturb a row whose amount has since been changed by hand.
 *
 * ROUNDED UP TO A CLEAN STEP, because a converted price reads like a mistake:
 * "Rs 21,372" invites the question of what the real number is, while
 * "Rs 21,500" reads as a decision. Up rather than nearest — rounding a selling
 * price down is a discount nobody chose to give.
 */
class LocalPriceService
{
    /**
     * Ensure every active price on this plan has a counterpart in $currency.
     *
     * @return array<int, PlanPrice>  the rows created (empty when nothing was missing)
     */
    public function mirror(Plan $plan, string $currency = 'PKR'): array
    {
        $currency = strtoupper($currency);
        $base     = strtoupper((string) config('billing.currency', 'usd'));

        if ($currency === $base || ! $this->isConfigured($currency)) {
            return [];
        }

        $existing = PlanPrice::where('plan_id', $plan->id)
            ->where('is_active', true)
            ->get();

        $created = [];

        foreach ($existing->where('currency', strtolower($base)) as $source) {
            // Already minted for this interval — leave it alone. An operator who
            // has adjusted the rupee price by hand must not have that overwritten
            // by a command someone runs months later.
            $already = $existing->first(
                fn (PlanPrice $p) => $p->interval === $source->interval
                    && strtoupper($p->currency) === $currency
            );

            if ($already) {
                continue;
            }

            $created[] = $this->mint($source, $currency);
        }

        return $created;
    }

    /**
     * The local row for one base-currency price.
     *
     * `metadata` records the rate and the source row, so "why is this plan
     * Rs 22,500" has an answer a year from now — the same reason a custom plan
     * stores the quote that produced it.
     */
    private function mint(PlanPrice $source, string $currency): PlanPrice
    {
        $minor = $this->convert((int) $source->unit_amount, $currency);

        return PlanPrice::create([
            'plan_id'           => $source->plan_id,
            'interval'          => $source->interval,
            'currency'          => strtolower($currency),
            'unit_amount'       => $minor,
            'compare_at_amount' => $source->compare_at_amount
                ? $this->convert((int) $source->compare_at_amount, $currency)
                : null,
            // Deliberately NO stripe_price_ref. This row is never sold through
            // Stripe, and resolvePrice() only demands a Stripe reference of the
            // customers who will actually pay through Stripe.
            'is_active'         => true,
            'effective_from'    => now(),
            'metadata'          => [
                'minted_from'    => $source->id,
                'source_amount'  => (int) $source->unit_amount,
                'source_currency'=> strtoupper($source->currency),
                'rate'           => $this->rate($currency),
                'minted_at'      => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Base-currency minor units → local minor units, rounded up to a clean step.
     *
     * ALWAYS HUNDREDTHS, in every currency. The rupee has no circulating minor
     * unit and Stripe would call it zero-decimal, but `unit_amount` is divided
     * by 100 by every reader in this codebase — `PlanPrice::amount()`, the
     * presenter, the gateway — so a rupee price stored as whole rupees would be
     * rendered and charged at a hundredth of itself. One invariant, held
     * everywhere, is worth more here than matching Stripe's convention for a
     * currency Stripe never sees.
     *
     * The step is expressed in MAJOR units (Rs 500, not 50,000 paisa) because
     * that is how the resulting price is read and quoted.
     */
    public function convert(int $baseMinor, string $currency = 'PKR'): int
    {
        $currency = strtoupper($currency);
        $rate     = $this->rate($currency);
        $step     = max(1, (int) config("billing.local_pricing.{$currency}.step", 1));

        $major = ($baseMinor / 100) * $rate;

        // ceil, not round: see the class note on rounding a selling price down.
        $major = (int) (ceil($major / $step) * $step);

        return $major * 100;
    }

    public function rate(string $currency = 'PKR'): float
    {
        return (float) config('billing.local_pricing.' . strtoupper($currency) . '.rate', 0);
    }

    public function isConfigured(string $currency): bool
    {
        return $this->rate($currency) > 0;
    }

    /** Every plan that can be bought, mirrored in one pass. */
    public function mirrorAll(string $currency = 'PKR'): int
    {
        $minted = 0;

        Plan::where('is_active', true)
            ->whereIn('type', ['standard', 'custom', 'addon'])
            ->orderBy('id')
            ->each(function (Plan $plan) use ($currency, &$minted) {
                $minted += count($this->mirror($plan, $currency));
            });

        return $minted;
    }
}
