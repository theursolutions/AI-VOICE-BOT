<?php

namespace App\Services\Billing;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
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
    public function build(Request $request, ?string $selectedInterval = null): array
    {
        $intervals = $this->plans->offeredIntervals();

        $interval = in_array($selectedInterval, $intervals, true)
            ? $selectedInterval
            : ($intervals[0] ?? 'monthly');

        $geo = $this->resolveGeo($request);

        $plans = $this->plans->publicPlans();

        $cards = [];
        foreach ($plans as $plan) {
            $cards[] = $this->card($plan, $intervals, $geo);
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

    // ── One plan card ────────────────────────────────────────────────

    private function card(Plan $plan, array $intervals, ?GeoResult $geo): array
    {
        $prices  = [];
        $monthly = $plan->priceFor('monthly');

        foreach ($intervals as $interval) {
            $price = $plan->priceFor($interval);

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

    /** USD figure plus the optional approximate local line. */
    private function price(PlanPrice $price, ?PlanPrice $monthly, ?GeoResult $geo): array
    {
        $savings = $price->savingsPercentAgainst($monthly);

        $out = [
            'interval'          => $price->interval,
            'interval_label'    => $price->intervalLabel(),
            'suffix'            => $price->intervalSuffix(),
            'usd'               => $price->formatted(),
            'usd_cents'         => $price->unit_amount,
            'effective_monthly' => $price->formattedEffectiveMonthly(),
            'savings_percent'   => $savings,
            'savings_label'     => $savings > 0 ? "Save {$savings}%" : null,
            'months'            => $price->months(),

            'local'             => null,
            'local_effective'   => null,
            'currency'          => null,
        ];

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

    /** Bullets on the card: features flagged `is_headline`, in order. */
    private function highlights(Plan $plan): array
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
     */
    public function renderPrice(int $usdCents, string $interval, Request $request): array
    {
        $geo   = $this->resolveGeo($request);
        $usd   = $usdCents / 100;

        $out = [
            'usd'      => '$' . ($usd == floor($usd) ? number_format($usd, 0) : number_format($usd, 2)),
            'suffix'   => (string) config("billing.intervals.suffixes.{$interval}", ''),
            'local'    => null,
            'currency' => null,
        ];

        if ($this->localEnabled($geo)) {
            $local = $this->fx->convertAndFormat($usdCents, $geo->currency);

            if ($local !== null) {
                $out['local']    = $local;
                $out['currency'] = $geo->currency;
            }
        }

        return $out;
    }
}
