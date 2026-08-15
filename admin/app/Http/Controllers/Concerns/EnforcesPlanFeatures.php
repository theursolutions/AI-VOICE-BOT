<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use App\Services\Billing\PlanFeatureService;
use Illuminate\Support\Str;

/**
 * Feature checks for capabilities that are NOT a whole admin module.
 *
 * `EnsurePlanFeature` covers anything that maps to a route/module key. It can't
 * help with features that live INSIDE an allowed page — connecting a live
 * database is one action on the Data Sources page, and turning off the
 * "Powered by" badge is one checkbox on the Widget page. Those need a check at
 * the action, which is what this provides.
 *
 * Until this existed, five features were sold on the pricing page and enforced
 * nowhere: a Free workspace could connect a production database or remove our
 * branding just by submitting the form.
 */
trait EnforcesPlanFeatures
{
    /** Does this workspace's plan include the feature? */
    protected function planAllows(Client $client, string $featureKey): bool
    {
        return app(PlanFeatureService::class)->clientHas($client, $featureKey);
    }

    /**
     * Abort with the 402 upsell page unless the plan includes the feature.
     *
     * 402, not 403: this isn't a permissions failure, it's a plan boundary, and
     * the upsell page names the cheapest plan that unlocks it.
     */
    protected function requirePlanFeature(Client $client, string $featureKey, string $label): void
    {
        if ($this->planAllows($client, $featureKey)) {
            return;
        }

        abort(response()->view('errors.plan-upgrade', [
            'moduleLabel'  => $label,
            'moduleKey'    => $featureKey,
            'currentPlan'  => $client->currentPlan(),
            'client'       => $client,
            'requiredPlan' => $this->cheapestPlanWithFeature($featureKey),
        ], 402));
    }

    /**
     * Same check, but for a form submit where bouncing to a full-page upsell
     * would throw away everything the user typed. Returns a redirect-back with
     * an explanation, or null when the plan allows it.
     */
    protected function refuseUnlessPlanFeature(Client $client, string $featureKey, string $label): ?\Illuminate\Http\RedirectResponse
    {
        if ($this->planAllows($client, $featureKey)) {
            return null;
        }

        $required = $this->cheapestPlanWithFeature($featureKey);
        $plan     = $client->currentPlan();

        $message = $required
            ? "{$label} isn’t included in the " . ($plan?->name ?? 'current') . " plan — it’s available on {$required->name}."
            : "{$label} isn’t included in your current plan.";

        return back()->withInput()->with('error', $message);
    }

    /**
     * Refuse when a COUNT limit is already reached (seats, projects, agents,
     * phone numbers, data sources, flows, channels).
     *
     * Distinct from the metered allowances: those are consumption over a
     * period and are allowed to run into paid overage, because an agent that
     * stops answering mid-month is worse for the customer than a slightly
     * larger invoice. These are structural — an eleventh seat on a ten-seat
     * plan isn't overage, it's just not in the plan — so they're a hard stop.
     *
     * NULL from planLimit() means UNLIMITED and must be checked before any
     * truthiness test; `if (!$limit)` would block every unlimited plan.
     *
     * @param  int  $current  how many already exist
     */
    protected function refuseUnlessWithinQuota(
        Client $client,
        string $featureKey,
        int $current,
        string $noun,
    ): ?\Illuminate\Http\RedirectResponse {
        $limit = app(PlanFeatureService::class)->clientLimit($client, $featureKey);

        if ($limit === null || $current < $limit) {
            return null;
        }

        $plan     = $client->currentPlan();
        $required = $this->cheapestPlanWithMoreThan($featureKey, $limit);

        $message = $limit === 0
            ? ucfirst($noun) . ' isn’t included in the ' . ($plan?->name ?? 'current') . ' plan.'
            : "You've used all {$limit} " . Str::plural($noun, $limit)
              . ' on the ' . ($plan?->name ?? 'current') . ' plan.';

        if ($required) {
            $message .= " {$required->name} includes more.";
        }

        return back()->withInput()->with('error', $message);
    }

    /** The cheapest active public plan whose limit for this feature beats $limit. */
    protected function cheapestPlanWithMoreThan(string $featureKey, int $limit): ?\App\Models\Billing\Plan
    {
        $features = app(PlanFeatureService::class);

        return \App\Models\Billing\Plan::query()
            ->active()->public()->ordered()
            ->get()
            ->filter(function ($plan) use ($features, $featureKey, $limit) {
                $planLimit = $features->planLimit($plan, $featureKey);

                return $planLimit === null || $planLimit > $limit;   // null = unlimited
            })
            ->sortBy(fn ($plan) => $plan->priceFor('monthly')?->unit_amount ?? PHP_INT_MAX)
            ->first();
    }

    /** The cheapest active public plan that grants this feature. */
    protected function cheapestPlanWithFeature(string $featureKey): ?\App\Models\Billing\Plan
    {
        $features = app(PlanFeatureService::class);

        return \App\Models\Billing\Plan::query()
            ->active()->public()->ordered()
            ->get()
            ->filter(fn ($plan) => $features->planHas($plan, $featureKey))
            ->sortBy(fn ($plan) => $plan->priceFor('monthly')?->unit_amount ?? PHP_INT_MAX)
            ->first();
    }
}
