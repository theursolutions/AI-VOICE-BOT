<?php

namespace App\Services\Billing;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Client;
use App\Services\Currency\ExchangeRateService;
use App\Services\Geo\GeoLocationService;
use App\Services\Geo\GeoResult;
use Illuminate\Http\Request;

/**
 * Turns plans + prices + geo + FX into a flat array the pricing Blade can
 * render without thinking.
 *
 * WHY THIS EXISTS: the brief requires that no exchange-rate or geolocation
 * logic lives in a controller or a template. Blade receives finished strings
 * ("$19", "≈ Rs 5,400", "Save 17%") and never sees a rate, a cent amount, or
 * a Stripe reference.
 *
 * The local-currency figure is DECORATION. Every card also carries the plan
 * slug and interval, which is all the checkout form submits — so even if the
 * displayed local amount were tampered with in the DOM, there is nothing for
 * it to influence.
 */
class PricingPresenter
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly PlanFeatureService $features,
        private readonly GeoLocationService $geo,
        private readonly ExchangeRateService $fx,
    ) {
    }

    /**
     * Everything /pricing needs.
     *
     * @return array{
     *   intervals: array, default_interval: string, plans: array,
     *   geo: ?array, disclaimer: string, comparison: array, has_local: bool
     * }
     */
    /**
     * @param  ?Client  $billFor  the workspace these prices will be CHARGED to,
     *         when one is known. Given, the cards quote the currency that
     *         workspace's gateway actually settles, and the approximate local
     *         line is dropped — an estimate beside the real number is noise at
     *         best, and at worst two different figures for one price.
     */
    public function build(Request $request, ?string $selectedInterval = null, ?Client $billFor = null): array
    {
        $intervals = $this->plans->offeredIntervals();

        $interval = in_array($selectedInterval, $intervals, true)
            ? $selectedInterval
            : ($intervals[0] ?? 'monthly');

        // THE COUNTRY DECIDES THE CURRENCY, whether or not a workspace exists.
        //
        // This used to read the currency off the CLIENT, so a visitor to the
        // public pricing page — who has no client — was always quoted the
        // platform currency. For a Pakistani visitor that is not a cosmetic
        // difference: they were shown $26 for a plan Safepay would charge them
        // Rs 8,000 for, and changing the country picker appeared to do nothing
        // because the only thing it moved was the approximate line underneath.
        //
        // A stored workspace country still wins — it is a decision, where a
        // visitor's is a guess — but a guess is far better than assuming
        // everyone is billed in dollars.
        $geo = $this->resolveGeo($request);

        $country = $billFor?->billing_country ?: $geo?->countryCode;
        $billing = $this->billingCurrencyForCountry($country);

        // The approximate line is for people charged in the platform currency.
        // Someone billed in rupees already has both real figures beside each
        // other, and neither is an estimate.
        $geo = $billing ? null : $geo;

        $plans = $this->plans->publicPlans();

        $cards = [];
        foreach ($plans as $plan) {
            $cards[] = $this->card($plan, $intervals, $geo, $billing);
        }

        return [
            'intervals'        => array_map(fn ($i) => [
                'key'   => $i,
                'label' => (string) config("billing.intervals.labels.{$i}", ucfirst($i)),
            ], $intervals),
            'default_interval' => $interval,
            'plans'            => $cards,
            'geo'              => $geo?->toArray(),
            'has_local'        => $this->localEnabled($geo),
            'disclaimer'       => (string) config('billing.fx.disclaimer'),
            'comparison'       => $this->features->comparisonMatrix($plans),
            'plan_models'      => $plans,
        ];
    }

    /**
     * Keep an unchosen country in step with where the customer actually is.
     *
     * WRITTEN, not merely displayed. `billing_country` is what GatewayRegistry
     * routes on and what resolvePrice picks a currency from, so a value that
     * only looked right on the page would leave the checkout charging in
     * something else entirely.
     *
     * NEVER OVERRIDES A CHOICE. Once someone has picked their country the
     * source is `manual` and this does nothing, for as long as the workspace
     * exists — a customer who corrected our guess and found it corrected back
     * on the next page load would rightly conclude the control does not work.
     *
     * Also declines to move a workspace that is mid-period on a paid plan in
     * another currency: their invoices, their charge history and their renewal
     * are all denominated already, and silently re-routing them because someone
     * opened the billing page from an airport is not a decision an IP address
     * gets to make.
     */
    private function followDetectedCountry(Client $client, string $detected): void
    {
        $detected = strtoupper($detected);

        if ($client->billing_country === $detected) {
            return;
        }

        if (data_get($client->json_data, 'billing.country_source') === 'manual') {
            return;
        }

        if (! isset(\App\Support\Payments::sellableCountries()[$detected])) {
            return;
        }

        $subscription = $client->currentSubscription();

        if ($subscription
            && $subscription->unit_amount > 0
            && $subscription->current_period_end?->isFuture()) {
            return;
        }

        $client->forceFill([
            'billing_country' => $detected,
            'json_data'       => array_replace_recursive(
                (array) $client->json_data,
                ['billing' => ['country_source' => 'ip']],
            ),
            'updated_at'      => time(),
        ])->save();
    }

    /**
     * The currency this country's gateway settles, or null when it is the
     * platform's own and the ordinary path applies.
     *
     * Keyed on COUNTRY, not on a workspace, so the public pricing page — where
     * there is no workspace at all — can still quote what a visitor would
     * actually be charged.
     */
    private function billingCurrencyForCountry(?string $country): ?string
    {
        $currency = app(\App\Services\Billing\Gateways\GatewayRegistry::class)
            ->forCountry($country)?->currencies()[0] ?? null;

        $base = strtoupper((string) config('billing.currency', 'usd'));

        // Null when it IS the platform currency, so callers can treat null as
        // "nothing special about this one" rather than comparing every time.
        return ($currency && strtoupper($currency) !== $base) ? $currency : null;
    }

    /**
     * Everything the country picker needs to render and to post back.
     *
     * WHOSE ANSWER WINS. A workspace that has stored a country has decided —
     * that value is what checkout routes on, and re-guessing it from an IP
     * every page load would let a founder on holiday silently change the
     * currency of their own invoices. Only when nothing is stored does the
     * guess apply, and the picker says so, because a guess presented as a fact
     * is one nobody thinks to correct.
     *
     * @return array{action: string, current: ?string, detected: ?string, currency: string}
     */
    public function countryContext(Request $request, ?Client $client = null): array
    {
        $detected = $this->geo->resolve($request)?->countryCode;

        // Follow the IP until somebody chooses. Not merely for display — the
        // stored country is what the gateway and the price actually route on,
        // so leaving it stale would show a German visitor euros while charging
        // them through Safepay in rupees.
        if ($client && $detected) {
            $this->followDetectedCountry($client, $detected);
        }

        $current = $client?->billing_country ?: $detected;

        // A country an operator has since switched off must not stay selected:
        // it would route a payment to a gateway we have stopped selling
        // through, and the picker would show a choice the form rejects.
        if ($current && ! isset(\App\Support\Payments::sellableCountries()[strtoupper($current)])) {
            $current = null;
        }

        return [
            'action'   => $client
                ? route('billing.country', ['client' => $client->slug])
                : route('pricing.country'),
            'current'  => $current ? strtoupper($current) : null,
            'detected' => $detected ? strtoupper($detected) : null,
            'currency' => app(\App\Services\Billing\Gateways\GatewayRegistry::class)
                ->currencyForCountry($current),
        ];
    }

    // ── One plan card ────────────────────────────────────────────────

    private function card(Plan $plan, array $intervals, ?GeoResult $geo, ?string $billing = null): array
    {
        $prices  = [];
        $monthly = $plan->priceFor('monthly', $billing);

        foreach ($intervals as $interval) {
            // A plan with no row in the billing currency simply shows no price
            // for that interval, exactly as one missing a dollar price would.
            // Quoting the dollar figure instead would show a number the
            // customer will not be charged.
            $price = $plan->priceFor($interval, $billing);

            if (! $price) {
                continue;
            }

            $prices[$interval] = $this->price($price, $monthly, $geo);
        }

        return [
            'id'          => $plan->id,
            'slug'        => $plan->slug,
            'name'        => $plan->name,
            'tagline'     => $plan->tagline,
            'description' => $plan->description,
            'type'        => $plan->type,
            'is_free'     => $plan->isFree(),
            'is_enterprise' => $plan->isEnterprise(),
            'is_featured' => (bool) $plan->is_featured,
            'badge'       => $plan->badge,
            'cta_label'   => $plan->cta_label ?: $this->defaultCta($plan),
            'cta_url'     => $plan->cta_url,
            'purchasable' => $plan->isPurchasable(),
            'trial_days'  => (int) $plan->trial_days,
            'free_days'   => $plan->freeWindowDays(),
            'prices'      => $prices,
            'highlights'  => $this->highlights($plan),
            'included'    => $this->included($plan),
        ];
    }

    /**
     * EVERYTHING this plan grants, grouped by the feature's group heading.
     *
     * Distinct from highlights(): that's the short bullet list on the card
     * (features flagged `is_headline`), this is the full "what you actually
     * get" list for a homepage section where there is no separate pricing page
     * to click through to.
     *
     * Only granted features appear — a card listing crossed-out features sells
     * nothing, and "not included" is already visible in the comparison table.
     *
     * @return array<string, array<int, array{label:string, note:?string}>>
     */
    private function included(Plan $plan): array
    {
        $resolved = $this->features->forPlan($plan);
        $groups   = [];

        $rows = $plan->relationLoaded('planFeatures')
            ? $plan->planFeatures->sortBy('sort_order')
            : $plan->planFeatures()->with('feature')->orderBy('sort_order')->get();

        foreach ($rows as $row) {
            $feature = $row->feature;

            if (! $feature || ! $feature->is_visible) {
                continue;
            }

            $entry = $resolved[$feature->key] ?? null;
            $label = $this->includedLabel($feature, $entry);

            if ($label === null) {
                continue;
            }

            $groups[$feature->group ?: 'Included'][] = [
                'label' => $label,
                'note'  => $feature->description,
            ];
        }

        return $groups;
    }

    /**
     * A human sentence for one granted feature. NULL = not granted, leave it off.
     *
     * Deliberately NOT displayValue(): that renders a table CELL, where the
     * column header already supplies the feature name. Here the line has to
     * stand alone, so the name and the unit both have to land in a readable
     * order.
     *
     * The unit placement rule, which covers every unit in the catalogue:
     *   • unit already inside the name  → drop it   ("5,000 Indexed pages")
     *   • unit starts with "per"        → after the name
     *                                     ("5,000 AI conversations per month")
     *   • otherwise                     → straight after the number
     *                                     ("30 days Conversation history")
     */
    private function includedLabel(Feature $feature, ?array $entry): ?string
    {
        if ($entry === null) {
            return null;
        }

        $name = $feature->name;
        $unit = trim((string) $feature->unit);

        // Unlimited reads as a prefix, and never with a unit — "Unlimited days
        // Conversation history" is nonsense.
        //
        // The name is NOT lowercased: lcfirst() turns "WhatsApp, Instagram &
        // Facebook" into "whatsApp…", and mangling a brand name to win a
        // capital letter mid-sentence is the wrong trade.
        if ($entry['unlimited']) {
            return 'Unlimited ' . $name;
        }

        if ($feature->value_type === Feature::TYPE_BOOLEAN) {
            return $entry['value'] ? $name : null;
        }

        if ($feature->value_type === Feature::TYPE_NUMERIC) {
            $number = (int) $entry['value'];

            if ($number <= 0) {
                return null;   // granted with a zero allowance = nothing to show
            }

            $formatted = number_format($number);

            if ($unit === '' || stripos($name, $unit) !== false) {
                // Catalogue names are plural ("Projects", "Team seats"), so an
                // allowance of 1 needs the singular — "1 Projects" looks broken.
                // Str::singular, not Str::plural($name, 1): the latter only ever
                // pluralises and returns an already-plural word untouched.
                return $formatted . ' ' . ($number === 1
                    ? \Illuminate\Support\Str::singular($name)
                    : $name);
            }

            return str_starts_with(strtolower($unit), 'per')
                ? "{$formatted} {$name} {$unit}"
                : "{$formatted} {$unit} {$name}";
        }

        // Free text: "Support: Priority email" beats "Priority email Support".
        $text = trim((string) ($entry['raw'] ?? ''));

        return $text === '' ? null : "{$name}: {$text}";
    }

    /**
     * The charged figure, plus a second line in the other currency.
     *
     * TWO CURRENCIES, AND WHICH IS WHICH MATTERS. The primary figure is always
     * what the customer is actually CHARGED — the amount on the row the gateway
     * will collect. The second line is the same money expressed the other way,
     * marked approximate, and is never what anybody is billed.
     *
     * Which way round depends on where they are, and it inverts:
     *
     *   Pakistan   charged Rs 22,500  ·  shown "≈ $75"
     *   elsewhere  charged $75        ·  shown "≈ Rs 20,850"
     *
     * Getting this backwards would put the reference figure in the big type and
     * the real one in the footnote — a page that quotes a price nobody pays.
     *
     * The Pakistani second line is EXACT, not converted: both rows are real
     * prices we set. The international one is a live conversion and says so.
     */
    private function price(PlanPrice $price, ?PlanPrice $monthly, ?GeoResult $geo): array
    {
        $savings = $price->savingsPercentAgainst($monthly);

        $out = [
            'interval'          => $price->interval,
            'interval_label'    => $price->intervalLabel(),
            'suffix'            => $price->intervalSuffix(),
            // `amount`, not `usd`: a card may now be quoting rupees, and a key
            // that names one currency while holding another is how a wrong
            // symbol gets printed next to a real price.
            'amount'            => $price->formatted(),
            'amount_minor'      => $price->unit_amount,
            'amount_currency'   => strtoupper((string) $price->currency),
            'effective_monthly' => $price->formattedEffectiveMonthly(),
            'savings_percent'   => $savings,
            'savings_label'     => $savings > 0 ? "Save {$savings}%" : null,
            'months'            => $price->months(),

            'local'             => null,
            'local_effective'   => null,
            'currency'          => null,
            // True when the second line is another real price rather than a
            // live conversion, so the view can drop the "≈".
            'local_is_exact'    => false,
        ];

        $base = strtoupper((string) config('billing.currency', 'usd'));

        // ── Charged locally: the second line is the platform price ──────
        //
        // Taken from the sibling row rather than converted, because both are
        // prices we set — so this figure is exact, and labelling it with a "≈"
        // would understate what we actually know.
        if (strtoupper((string) $price->currency) !== $base) {
            $platform = $price->plan?->priceFor($price->interval, $base);

            if ($platform) {
                $out['currency']        = $base;
                $out['local']           = $platform->formatted();
                $out['local_effective'] = $platform->formattedEffectiveMonthly();
                $out['local_is_exact']  = true;
            }

            return $out;
        }

        // ── Charged in the platform currency: convert for display ───────
        if (! $this->localEnabled($geo)) {
            return $out;
        }

        $currency = $geo->currency;

        // A failed conversion silently omits the local line. It must never
        // surface as an error or an empty "≈ " on a public page.
        $local = $this->fx->convertAndFormat($price->unit_amount, $currency);

        if ($local === null) {
            return $out;
        }

        $out['currency']        = $currency;
        $out['local']           = $local;
        $out['local_effective'] = $this->fx->convertAndFormat(
            $price->effectiveMonthlyCents(),
            $currency
        );

        return $out;
    }

    /**
     * Bullets on the card: features flagged `is_headline`, in order.
     *
     * Public because the receipt email lists the same entitlements. Keeping
     * one implementation means the "what you're paying for" summary can't
     * drift between the pricing page and the invoice a customer files.
     */
    public function highlights(Plan $plan): array
    {
        $resolved = $this->features->forPlan($plan);
        $out      = [];

        $rows = $plan->relationLoaded('planFeatures')
            ? $plan->planFeatures->sortBy('sort_order')
            : $plan->planFeatures()->with('feature')->orderBy('sort_order')->get();

        foreach ($rows as $row) {
            $feature = $row->feature;

            if (! $feature || ! $feature->is_headline) {
                continue;
            }

            $entry = $resolved[$feature->key] ?? null;
            $value = $this->features->displayValue($feature, $entry);

            // A boolean that resolves to "not included" is simply left off
            // the card rather than listed with a dash — a card of crossed-out
            // features sells nothing.
            if ($value === '—') {
                continue;
            }

            $out[] = [
                'label'       => $value === '✓' ? $feature->name : trim("{$value} {$feature->name}"),
                'highlighted' => (bool) $row->is_highlighted,
            ];
        }

        return $out;
    }

    private function defaultCta(Plan $plan): string
    {
        return match (true) {
            $plan->isFree()       => 'Start free',
            $plan->isEnterprise() => 'Talk to us',
            default               => 'Get started',
        };
    }

    // ── Geo / FX gating ──────────────────────────────────────────────

    private function resolveGeo(Request $request): ?GeoResult
    {
        if (! tva_setting('billing.show_local_currency', config('billing.settings.show_local_currency', true))) {
            return null;
        }

        return $this->geo->resolve($request);
    }

    /**
     * Show a local line at all? Not for USD visitors — "≈ $19" under "$19" is
     * noise — and not when FX is switched off or the currency is unknown.
     *
     * @phpstan-assert-if-true !null $geo
     */
    private function localEnabled(?GeoResult $geo): bool
    {
        return $geo !== null
            && $geo->hasCurrency()
            && ! $geo->isUsd()
            && (bool) config('billing.fx.enabled', true);
    }

    // ── Reusable single-price rendering (billing page, emails) ───────

    /**
     * Render one price for a known workspace/visitor. Used by the customer
     * billing page so it shows the same "$59 ≈ Rs 16,600" pairing as /pricing.
     *
     * $currency is the currency the AMOUNT is already in — pass it whenever the
     * figure comes from a price row, because a rupee amount printed with a
     * dollar sign misstates the charge by a factor of a few hundred. When it is
     * not the platform currency the approximate local line is dropped: there is
     * nothing left to approximate, and two figures for one price is worse than
     * one.
     */
    public function renderPrice(int $minorUnits, string $interval, Request $request, ?string $currency = null): array
    {
        $base    = strtoupper((string) config('billing.currency', 'usd'));
        $code    = strtoupper((string) ($currency ?: $base));
        $isBase  = $code === $base;

        $geo    = $isBase ? $this->resolveGeo($request) : null;
        $amount = $minorUnits / 100;
        $symbol = $this->fx->symbolFor($code);

        $out = [
            'amount'          => $symbol . ($amount == floor($amount) ? number_format($amount, 0) : number_format($amount, 2)),
            'amount_minor'    => $minorUnits,
            'amount_currency' => $code,
            'suffix'          => (string) config("billing.intervals.suffixes.{$interval}", ''),
            'local'           => null,
            'currency'        => null,
        ];

        if ($this->localEnabled($geo)) {
            $local = $this->fx->convertAndFormat($minorUnits, $geo->currency);

            if ($local !== null) {
                $out['local']    = $local;
                $out['currency'] = $geo->currency;
            }
        }

        return $out;
    }
}
