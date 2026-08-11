<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanFeatureService;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;

/**
 * Plan entitlement gate: is this module included in the workspace's plan?
 *
 * Reuses the SAME route-name → module-key mapping as the RBAC gate
 * (config/modules.php via App\Support\Modules), so plan entitlements and
 * role permissions speak one vocabulary and can never drift apart. A plan's
 * entitlements are just a subset of the 17 module keys the roles matrix
 * already knows about.
 *
 * FAILS OPEN in three cases, all deliberate:
 *   • no client context                → other middleware owns that
 *   • route maps to no module          → utility/shared endpoints
 *   • no feature declares this module  → a newly added admin module isn't
 *     silently hidden from every paying customer until someone remembers to
 *     create a feature row for it
 *
 * Unlike the RBAC gate, an OWNER DOES NOT BYPASS this. Being the owner of a
 * workspace says nothing about what the workspace has paid for.
 */
class EnsurePlanFeature
{
    public function __construct(private readonly PlanFeatureService $features)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $client = $request->attributes->get('client');

        if (! $client) {
            return $next($request);
        }

        $module = Modules::moduleForRoute(optional($request->route())->getName() ?? '');

        if ($module === null) {
            return $next($request);
        }

        if ($this->features->clientHasModule($client, $module)) {
            return $next($request);
        }

        $label = Modules::label($module);
        $plan  = $client->currentPlan();

        $message = $plan
            ? "{$label} isn’t included in the {$plan->name} plan."
            : "{$label} isn’t included in your current plan.";

        if ($request->expectsJson()) {
            return response()->json([
                'error'       => 'plan_upgrade_required',
                'message'     => $message,
                'module'      => $module,
                'billing_url' => route('billing.index', ['client' => $client->slug]),
            ], 402);
        }

        // A dedicated upsell page rather than a 403: this is not an error, it
        // is a sales moment. The page names the feature, shows the cheapest
        // plan that includes it, and links straight to checkout.
        return response()->view('errors.plan-upgrade', [
            'moduleLabel'  => $label,
            'moduleKey'    => $module,
            'currentPlan'  => $plan,
            'client'       => $client,
            'requiredPlan' => $this->cheapestPlanWith($module),
        ], 402);
    }

    /**
     * The cheapest active plan that unlocks this module, so the upsell can
     * say "available on Growth" instead of "upgrade your plan".
     */
    private function cheapestPlanWith(string $moduleKey): ?\App\Models\Billing\Plan
    {
        $candidates = \App\Models\Billing\Plan::query()
            ->active()
            ->public()
            ->ordered()
            ->with(['prices' => fn ($q) => $q->where('is_active', true)->where('interval', 'monthly')])
            ->get()
            ->filter(fn ($plan) => $this->features->modulesForPlan($plan) &&
                                   in_array($moduleKey, $this->features->modulesForPlan($plan), true));

        return $candidates
            ->sortBy(fn ($plan) => $plan->priceFor('monthly')?->unit_amount ?? PHP_INT_MAX)
            ->first();
    }
}
