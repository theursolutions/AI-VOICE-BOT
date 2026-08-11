<?php

namespace App\Services\Billing;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanFeature;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves what a plan grants, and answers entitlement questions about a
 * workspace.
 *
 * A MISSING plan_features ROW MEANS NOT GRANTED. Adding a feature to the
 * catalogue therefore never silently hands it to every existing plan — the
 * safe direction for a billing system to fail in.
 *
 * Resolved entitlement sets are cached per plan (not per client), because
 * they change only when a super-admin edits the plan. `flush()` is called
 * from every mutation path so an admin edit takes effect immediately rather
 * than after a TTL.
 */
class PlanFeatureService
{
    private const CACHE_PREFIX = 'billing:plan-features:';
    private const CACHE_TTL    = 3600;

    /**
     * Resolved feature map for a plan:
     *   ['api_access' => ['type'=>'boolean','value'=>true,'raw'=>'1', ...], ...]
     */
    public function forPlan(Plan|int $plan): array
    {
        $planId = $plan instanceof Plan ? $plan->id : (int) $plan;

        return Cache::remember(
            self::CACHE_PREFIX . $planId,
            self::CACHE_TTL,
            fn () => $this->resolve($planId)
        );
    }

    private function resolve(int $planId): array
    {
        $rows = PlanFeature::query()
            ->with('feature')
            ->where('plan_id', $planId)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $feature = $row->feature;
            if (! $feature) {
                continue;
            }

            $out[$feature->key] = [
                'type'       => $feature->value_type,
                'value'      => $this->castValue($feature, $row),
                'raw'        => $row->value,
                'unit'       => $feature->unit,
                'name'       => $feature->name,
                'module_key' => $feature->module_key,
                'metric_key' => $feature->metric_key,
                'unlimited'  => $feature->value_type === Feature::TYPE_UNLIMITED || $row->isUnlimited(),
            ];
        }

        return $out;
    }

    /**
     * The typed value. Numeric returns int|null where NULL MEANS UNLIMITED —
     * callers must distinguish that from 0, which means "none". Conflating
     * the two is the classic quota bug: `if (!$limit) block()` would lock out
     * unlimited plans.
     */
    private function castValue(Feature $feature, PlanFeature $row): bool|int|string|null
    {
        return match ($feature->value_type) {
            Feature::TYPE_BOOLEAN   => $row->booleanValue(),
            Feature::TYPE_NUMERIC   => $row->numericValue(),
            Feature::TYPE_UNLIMITED => null,
            default                 => $row->value,
        };
    }

    // ── Questions about a plan ───────────────────────────────────────

    /** Is a boolean feature switched on for this plan? */
    public function planHas(Plan|int $plan, string $featureKey): bool
    {
        $entry = $this->forPlan($plan)[$featureKey] ?? null;

        if ($entry === null) {
            return false;   // absent = not granted
        }

        return match ($entry['type']) {
            Feature::TYPE_BOOLEAN   => (bool) $entry['value'],
            Feature::TYPE_UNLIMITED => true,
            Feature::TYPE_NUMERIC   => $entry['unlimited'] || (int) $entry['value'] > 0,
            default                 => $entry['raw'] !== null && $entry['raw'] !== '',
        };
    }

    /** Numeric allowance; NULL means unlimited, 0 means none. */
    public function planLimit(Plan|int $plan, string $featureKey): ?int
    {
        $entry = $this->forPlan($plan)[$featureKey] ?? null;

        if ($entry === null) {
            return 0;
        }

        if ($entry['unlimited']) {
            return null;
        }

        return (int) $entry['value'];
    }

    /** Module keys (config/modules.php) this plan unlocks. */
    public function modulesForPlan(Plan|int $plan): array
    {
        $keys = [];

        foreach ($this->forPlan($plan) as $key => $entry) {
            if (! $entry['module_key']) {
                continue;
            }
            if ($this->planHas($plan, $key)) {
                $keys[] = $entry['module_key'];
            }
        }

        return array_values(array_unique($keys));
    }

    // ── Questions about a workspace ──────────────────────────────────

    public function clientHas(Client $client, string $featureKey): bool
    {
        $plan = $client->currentPlan();

        // No plan resolved (pre-billing workspace) — don't gate. The billing
        // backfill gives everyone a plan explicitly; failing open here stops
        // a deploy from locking out existing customers.
        return $plan === null ? true : $this->planHas($plan, $featureKey);
    }

    public function clientLimit(Client $client, string $featureKey): ?int
    {
        $plan = $client->currentPlan();

        return $plan === null ? null : $this->planLimit($plan, $featureKey);
    }

    /**
     * Is a module unlocked by the workspace's plan?
     *
     * Only features that actually declare a `module_key` gate anything. A
     * module nobody has mapped to a feature stays open — otherwise adding a
     * new admin module would instantly hide it from every paying customer
     * until someone remembered to add a feature row.
     */
    public function clientHasModule(Client $client, string $moduleKey): bool
    {
        $plan = $client->currentPlan();
        if ($plan === null) {
            return true;
        }

        $gated = Feature::query()
            ->where('module_key', $moduleKey)
            ->pluck('key');

        if ($gated->isEmpty()) {
            return true;
        }

        foreach ($gated as $featureKey) {
            if ($this->planHas($plan, $featureKey)) {
                return true;
            }
        }

        return false;
    }

    // ── Mutations ────────────────────────────────────────────────────

    public function setFeature(Plan $plan, Feature $feature, ?string $value, bool $highlighted = false): PlanFeature
    {
        $row = PlanFeature::updateOrCreate(
            ['plan_id' => $plan->id, 'feature_id' => $feature->id],
            ['value' => $value, 'is_highlighted' => $highlighted],
        );

        $this->flush($plan->id);

        return $row;
    }

    public function removeFeature(Plan $plan, Feature $feature): void
    {
        PlanFeature::query()
            ->where('plan_id', $plan->id)
            ->where('feature_id', $feature->id)
            ->delete();

        $this->flush($plan->id);
    }

    /** Bulk save from the admin matrix: [feature_id => value|null]. */
    public function syncFeatures(Plan $plan, array $values): void
    {
        foreach ($values as $featureId => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '' || $value === '0' && $this->isBooleanFeature((int) $featureId)) {
                // Empty, or an unchecked boolean → not granted at all.
                PlanFeature::query()
                    ->where('plan_id', $plan->id)
                    ->where('feature_id', (int) $featureId)
                    ->delete();
                continue;
            }

            PlanFeature::updateOrCreate(
                ['plan_id' => $plan->id, 'feature_id' => (int) $featureId],
                ['value' => (string) $value],
            );
        }

        $this->flush($plan->id);
    }

    private function isBooleanFeature(int $featureId): bool
    {
        return Feature::query()->whereKey($featureId)->value('value_type') === Feature::TYPE_BOOLEAN;
    }

    /** Drop cached entitlements so an admin edit is visible immediately. */
    public function flush(?int $planId = null): void
    {
        if ($planId !== null) {
            Cache::forget(self::CACHE_PREFIX . $planId);

            return;
        }

        foreach (Plan::query()->pluck('id') as $id) {
            Cache::forget(self::CACHE_PREFIX . $id);
        }
    }

    // ── Pricing-page helpers ─────────────────────────────────────────

    /** Visible features grouped for the comparison table. */
    public function comparisonMatrix(Collection $plans): array
    {
        $features = Feature::query()->visible()->ordered()->get();
        $grouped  = [];

        foreach ($features as $feature) {
            $row = ['feature' => $feature, 'values' => []];

            foreach ($plans as $plan) {
                $entry = $this->forPlan($plan)[$feature->key] ?? null;
                $row['values'][$plan->id] = $this->displayValue($feature, $entry);
            }

            $grouped[$feature->group ?: 'Features'][] = $row;
        }

        return $grouped;
    }

    /** What a cell in the comparison table shows. */
    public function displayValue(Feature $feature, ?array $entry): string
    {
        if ($entry === null) {
            return '—';
        }

        if ($entry['unlimited']) {
            return 'Unlimited';
        }

        return match ($feature->value_type) {
            Feature::TYPE_BOOLEAN => $entry['value'] ? '✓' : '—',
            Feature::TYPE_NUMERIC => number_format((int) $entry['value'])
                                     . ($feature->unit ? ' ' . $feature->unit : ''),
            default               => (string) ($entry['raw'] ?? '—'),
        };
    }
}
