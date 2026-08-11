<?php

namespace App\Http\Controllers\SuperAdmin\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Services\Billing\PlanFeatureService;
use App\Services\Billing\PlanService;
use App\Services\Billing\StripeClientFactory;
use App\Services\Billing\StripeSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super Admin → Billing → Plans.
 *
 * THE POINT OF THIS CLASS: a super-admin must be able to change $19 to $29,
 * add a plan, re-gate a feature, change a limit, or alter a trial length
 * WITHOUT a developer, a migration, or a deploy. Everything here writes to
 * `plans` / `plan_prices` / `plan_features` and syncs Stripe.
 *
 * Follows the house pattern established by SuperAdmin\ModulesController:
 * validate → mutate through a service → AuditLog::record() → back() with a
 * flash message.
 */
class PlansController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly PlanFeatureService $features,
        private readonly StripeSyncService $stripeSync,
        private readonly StripeClientFactory $stripe,
    ) {
    }

    public function index(Request $request): View
    {
        return view('ops.billing.plans.index', [
            'title'          => 'Plans & Pricing',
            'plans'          => $this->plans->allPlans(),
            'intervals'      => (array) config('billing.intervals.supported', []),
            'offered'        => (array) config('billing.intervals.offered', []),
            'stripeReady'    => $this->stripe->isConfigured(),
            'stripeLiveMode' => $this->stripe->isLiveMode(),
            'mismatches'     => $this->stripe->isConfigured() ? $this->stripeSync->modeMismatches() : [],
            'unsyncedCount'  => \App\Models\Billing\PlanPrice::query()
                                    ->where('is_active', true)
                                    ->whereNull('stripe_price_ref')
                                    ->count(),
        ]);
    }

    public function create(): View
    {
        return view('ops.billing.plans.edit', [
            'title'     => 'New plan',
            'plan'      => new Plan(['is_active' => true, 'is_public' => true, 'trial_days' => 0]),
            'features'  => Feature::query()->ordered()->get(),
            'values'    => [],
            'intervals' => (array) config('billing.intervals.supported', []),
        ]);
    }

    public function edit(Request $request, int $id): View
    {
        $plan = Plan::query()->with(['prices', 'planFeatures'])->findOrFail($id);

        return view('ops.billing.plans.edit', [
            'title'     => 'Edit ' . $plan->name,
            'plan'      => $plan,
            'features'  => Feature::query()->ordered()->get(),
            'values'    => $plan->planFeatures->pluck('value', 'feature_id')->all(),
            'intervals' => (array) config('billing.intervals.supported', []),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePlan($request);

        $plan = $this->plans->createPlan($data);

        AuditLog::record('billing.plan.created', [
            'payload' => ['plan' => $plan->slug, 'name' => $plan->name],
        ]);

        return redirect()
            ->route('ops.billing.plans.edit', ['id' => $plan->id])
            ->with('success', "Plan “{$plan->name}” created. Add its prices next.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $plan = Plan::query()->findOrFail($id);
        $data = $this->validatePlan($request, $plan);

        $before = $plan->only(['name', 'is_active', 'is_public', 'trial_days', 'free_window_days']);

        $this->plans->updatePlan($plan, $data);

        // Feature values arrive as features[<feature_id>] = value. The KEY is
        // an id, but it's an array key rather than a top-level request key, so
        // DecodeHashids doesn't touch it (it only rewrites top-level keys).
        if ($request->has('features')) {
            $this->features->syncFeatures($plan, (array) $request->input('features', []));
        }

        AuditLog::record('billing.plan.updated', [
            'payload' => [
                'plan'   => $plan->slug,
                'before' => $before,
                'after'  => $plan->fresh()->only(array_keys($before)),
            ],
        ]);

        return back()->with('success', 'Plan saved.');
    }

    public function toggleActive(Request $request, int $id): RedirectResponse
    {
        $plan = Plan::query()->findOrFail($id);

        $plan->forceFill(['is_active' => ! $plan->is_active])->save();

        AuditLog::record('billing.plan.toggled', [
            'payload' => ['plan' => $plan->slug, 'is_active' => $plan->is_active],
        ]);

        // Deactivating only stops NEW signups. Existing subscribers keep their
        // subscription and keep being billed — pulling the plan out from under
        // paying customers would be indefensible.
        $note = $plan->is_active
            ? "“{$plan->name}” is now on sale."
            : "“{$plan->name}” is hidden from new signups. Existing subscribers are unaffected.";

        return back()->with('success', $note);
    }

    public function feature(Request $request, int $id): RedirectResponse
    {
        $plan = Plan::query()->findOrFail($id);

        $this->plans->setFeatured($plan);

        AuditLog::record('billing.plan.featured', ['payload' => ['plan' => $plan->slug]]);

        return back()->with('success', "“{$plan->name}” is now the recommended plan.");
    }

    public function reorder(Request $request): RedirectResponse
    {
        $request->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $this->plans->reorder((array) $request->input('order', []));

        AuditLog::record('billing.plan.reordered', [
            'payload' => ['order' => $request->input('order')],
        ]);

        return back()->with('success', 'Plan order updated.');
    }

    /** Push every unsynced active price into Stripe. */
    public function syncStripe(Request $request): RedirectResponse
    {
        if (! $this->stripe->isConfigured()) {
            return back()->with('error', 'Stripe keys are not configured. Set STRIPE_SECRET in .env first.');
        }

        $result = $this->stripeSync->syncAll();

        AuditLog::record('billing.stripe.synced', ['payload' => $result]);

        if ($result['failed'] > 0) {
            return back()
                ->with('error', "{$result['synced']} synced, {$result['failed']} failed: " .
                                implode(' · ', array_slice($result['errors'], 0, 3)));
        }

        return back()->with('success', $result['synced'] === 0
            ? 'Everything is already in sync with Stripe.'
            : "{$result['synced']} price(s) created in Stripe.");
    }

    // ── Validation ───────────────────────────────────────────────────

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'slug'        => ['nullable', 'string', 'max:100', 'alpha_dash'],
            'tagline'     => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type'        => ['required', 'in:free,standard,enterprise,custom'],

            'is_active'   => ['nullable', 'boolean'],
            'is_public'   => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],

            'badge'       => ['nullable', 'string', 'max:40'],
            'cta_label'   => ['nullable', 'string', 'max:60'],
            'cta_url'     => ['nullable', 'string', 'max:255'],

            'trial_days'  => ['nullable', 'integer', 'min:0', 'max:365'],
            'trial_requires_payment_method' => ['nullable', 'boolean'],

            // NULL on a free plan = permanent free tier. 7 = the approved
            // no-card free window.
            'free_window_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);
    }
}
