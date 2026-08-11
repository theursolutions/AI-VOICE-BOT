<?php

namespace App\Http\Controllers\SuperAdmin\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Services\Billing\PlanFeatureService;
use App\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super Admin → Billing → Features.
 *
 * Two jobs:
 *   1. Maintain the feature catalogue (what a plan CAN grant).
 *   2. Render the plan × feature matrix and save it in one submit, which is
 *      how limits and gates are actually edited day to day.
 *
 * `module_key` binds a feature to an admin module from config/modules.php, so
 * plan entitlements and role permissions share one vocabulary and can't drift.
 * `metric_key` binds it to a usage meter from config/billing.php, turning a
 * numeric feature into an enforced quota — no code change either way.
 */
class FeaturesController extends Controller
{
    public function __construct(private readonly PlanFeatureService $features)
    {
    }

    public function index(Request $request): View
    {
        $plans    = Plan::query()->ordered()->get();
        $features = Feature::query()->ordered()->get();

        // [feature_id][plan_id] => raw value, for the editable matrix.
        $matrix = [];
        foreach ($plans as $plan) {
            foreach ($plan->planFeatures()->get() as $row) {
                $matrix[$row->feature_id][$plan->id] = $row->value;
            }
        }

        return view('ops.billing.features.index', [
            'title'      => 'Features & Limits',
            'plans'      => $plans,
            'features'   => $features,
            'matrix'     => $matrix,
            'moduleKeys' => Modules::all(),
            'metricKeys' => (array) config('billing.metrics', []),
            'valueTypes' => [
                Feature::TYPE_BOOLEAN   => 'Yes / No',
                Feature::TYPE_NUMERIC   => 'Number (a limit)',
                Feature::TYPE_UNLIMITED => 'Always unlimited',
                Feature::TYPE_TEXT      => 'Free text',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateFeature($request);

        $data['key'] = $this->uniqueKey($data['key'] ?? $data['name']);

        $feature = Feature::create($data);

        AuditLog::record('billing.feature.created', [
            'payload' => ['feature' => $feature->key, 'type' => $feature->value_type],
        ]);

        return back()->with('success', "Feature “{$feature->name}” added. Set its value per plan in the matrix.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $feature = Feature::query()->findOrFail($id);

        $data = $this->validateFeature($request, $feature);

        // The key is referenced by code (UsageLimitService, entitlement checks)
        // and cached under it, so renaming would silently detach the feature
        // from whatever depends on it. Display name is editable instead.
        unset($data['key']);

        $feature->fill($data)->save();

        $this->features->flush();

        AuditLog::record('billing.feature.updated', ['payload' => ['feature' => $feature->key]]);

        return back()->with('success', 'Feature updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $feature = Feature::query()->findOrFail($id);

        $inUse = $feature->planFeatures()->count();

        $feature->planFeatures()->delete();
        $feature->delete();

        $this->features->flush();

        AuditLog::record('billing.feature.deleted', [
            'payload' => ['feature' => $feature->key, 'plans_affected' => $inUse],
        ]);

        return back()->with('success', "Feature removed from {$inUse} plan(s).");
    }

    /**
     * Save the whole matrix.
     *
     * Payload shape: values[<plan_id>][<feature_id>] = value.
     * Ids are nested array KEYS, not top-level request keys, so DecodeHashids
     * leaves them alone — it only rewrites top-level `*_id` keys
     * (ANALYSIS §5 C1).
     */
    public function updateMatrix(Request $request): RedirectResponse
    {
        $request->validate([
            'values'   => ['array'],
            'values.*' => ['array'],
        ]);

        $payload = (array) $request->input('values', []);
        $touched = 0;

        foreach ($payload as $planId => $featureValues) {
            $plan = Plan::query()->find((int) $planId);

            if (! $plan) {
                continue;
            }

            $this->features->syncFeatures($plan, (array) $featureValues);
            $touched++;
        }

        AuditLog::record('billing.features.matrix_updated', [
            'payload' => ['plans_touched' => $touched],
        ]);

        return back()->with('success', "Limits and features saved for {$touched} plan(s).");
    }

    // ── Validation ───────────────────────────────────────────────────

    private function validateFeature(Request $request, ?Feature $feature = null): array
    {
        return $request->validate([
            'key'         => ['nullable', 'string', 'max:80', 'alpha_dash'],
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'value_type'  => ['required', 'in:boolean,numeric,unlimited,text'],
            'unit'        => ['nullable', 'string', 'max:40'],
            // Must be a real module key or blank, otherwise the gate would
            // reference something that can never match a route.
            'module_key'  => ['nullable', 'string', 'max:60', 'in:' . implode(',', Modules::keys())],
            'metric_key'  => ['nullable', 'string', 'max:60', 'in:' . implode(',', array_keys((array) config('billing.metrics', [])))],
            'group'       => ['nullable', 'string', 'max:80'],
            'sort_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_visible'  => ['nullable', 'boolean'],
            'is_headline' => ['nullable', 'boolean'],
        ]);
    }

    private function uniqueKey(string $seed): string
    {
        $base = \Illuminate\Support\Str::slug($seed, '_') ?: 'feature';
        $key  = $base;
        $i    = 2;

        while (Feature::query()->where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }

        return $key;
    }
}
