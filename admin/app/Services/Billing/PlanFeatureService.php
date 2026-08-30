<?php

namespace App\Services\Billing;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanFeature;
use App\Models\Billing\PlanGrant;
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
        if ($plan === null) {
            return true;
        }

        // A grant can switch on something the plan does not include, which is
        // what makes "give them white-label for the pilot" possible without
        // moving them to a tier they are not paying for. Checked first so a
        // grant is never masked by the plan's own answer.
        if ($this->grantFor($client, $featureKey) !== 0) {
            return true;
        }

        return $this->planHas($plan, $featureKey);
    }

    /**
     * The workspace's EFFECTIVE limit: the plan's allowance, plus anything
     * bought as an add-on, plus anything a super admin has granted.
     *
     * This is the integration point that makes add-ons and grants real. Buying
     * five extra seats has to raise the ceiling from 10 to 15 everywhere the
     * ceiling is consulted — the sidebar, the member form, the usage meters —
     * not just on the invoice. Every caller already went through here, so they
     * all inherit it, including the ones written after this.
     *
     * A GRANT IS DELIBERATELY A THIRD TERM IN THE SAME SUM rather than a
     * separate notion of entitlement. Anything else means two answers to "what
     * is this workspace allowed", and the one the product enforces would not be
     * the one the operator granted.
     *
     * NULL (unlimited) stays unlimited: you cannot top up infinity.
     */
    public function clientLimit(Client $client, string $featureKey): ?int
    {
        $plan = $client->currentPlan();

        if ($plan === null) {
            return null;
        }

        $base = $this->planLimit($plan, $featureKey);

        // A grant can make something unlimited that the plan bounded — the
        // reason it is checked BEFORE the null short-circuit below, which would
        // otherwise return the plan's own infinity and never look.
        $grant = $this->grantFor($client, $featureKey);

        if ($grant === PlanGrant::UNLIMITED) {
            return null;
        }

        if ($base === null) {
            return null;
        }

        // Floored at zero: a negative grant is allowed (it can pull an allowance
        // down) but the result must never go below "none", or a negative
        // allowance would compare as smaller than any usage and read as
        // permanently exhausted rather than as zero.
        $base = max(0, $base + $grant);

        return $base + $this->addonContribution($client, $featureKey);
    }

    /**
     * What a super admin has granted this workspace for one feature.
     *
     * Returns 0 when there is no grant, and PlanGrant::UNLIMITED when the grant
     * removes the ceiling entirely.
     *
     * Read on every limit check, so the whole client's grants are fetched and
     * cached together rather than queried per feature — a page rendering a usage
     * panel asks about six or seven features and would otherwise make a query
     * for each. Expired grants are excluded by the scope, not here, so a lapsed
     * grant cannot go on applying at some call site that forgot to check.
     */
    public function grantFor(Client $client, string $featureKey): int
    {
        $grants = Cache::remember(
            self::CACHE_PREFIX . 'grants:' . $client->getKey(),
            self::CACHE_TTL,
            fn () => PlanGrant::query()
                ->where('client_id', $client->getKey())
                ->inForce()
                ->pluck('value', 'feature_key')
                ->all()
        );

        return (int) ($grants[$featureKey] ?? 0);
    }

    /** After granting, editing or revoking. */
    public function flushGrants(Client|int $client): void
    {
        $id = $client instanceof Client ? $client->getKey() : $client;

        Cache::forget(self::CACHE_PREFIX . 'grants:' . $id);
    }

    /**
     * How much the purchased add-ons add to one feature.
     *
     * An add-on plan's own `plan_features` say what a single unit grants, so
     * "Extra seat" carries `seats = 1` and five of them contribute 5. That
     * keeps add-ons inside the same feature system rather than inventing a
     * parallel one — and means an operator can change what an add-on grants
     * from the same matrix as everything else.
     */
    public function addonContribution(Client $client, string $featureKey): int
    {
        $subscription = $client->currentSubscription();

        if (! $subscription) {
            return 0;
        }

        $addons = $subscription->relationLoaded('addons')
            ? $subscription->addons->whereNull('cancelled_at')->where('quantity', '>', 0)
            : $subscription->addons()->active()->get();

        $total = 0;

        foreach ($addons as $addon) {
            if (! $addon->plan_id) {
                continue;
            }

            $perUnit = $this->planLimit($addon->plan_id, $featureKey);

            // An add-on granting "unlimited" of something is not a sane
            // product, and multiplying null by a quantity is meaningless.
            if ($perUnit === null || $perUnit <= 0) {
                continue;
            }

            $total += $perUnit * max(0, (int) $addon->quantity);
        }

        return $total;
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
            // clientHas(), not planHas(): a granted feature has to unlock its
            // module too, or the entitlement would be visible in the usage panel
            // and still 402 at the door.
            if ($this->clientHas($client, $featureKey)) {
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
