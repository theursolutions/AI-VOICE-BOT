<?php

namespace App\Services\Billing;

use App\Models\Billing\Feature;
use App\Models\Billing\UsageCounter;
use App\Models\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Metered usage: recording it, and deciding whether the next unit is allowed.
 *
 * TWO SEPARATE VOICE METERS, deliberately:
 *
 *   telephony_minutes — a phone call. Twilio number rental plus carrier
 *                       per-minute. Real money, so it is ZERO on the free
 *                       plan and metered on every paid tier.
 *   voice_messages    — a mic message in the web widget, served by local
 *                       Whisper + XTTS. Near-zero marginal cost, so the free
 *                       plan can include it.
 *
 * Collapsing these into one "voice" number would have forced the free plan to
 * choose between having no microphone at all and giving away phone calls.
 *
 * OVERAGE vs HARD STOP: paid plans record overage and keep working (an
 * AI receptionist that stops answering mid-month is worse for the customer
 * than a slightly larger invoice). The free plan hard-stops, because there
 * is nobody to bill.
 */
class UsageLimitService
{
    public function __construct(private readonly PlanFeatureService $features)
    {
    }

    // ── Recording ────────────────────────────────────────────────────

    /**
     * Record usage. Atomic: an upsert followed by an in-database increment,
     * so concurrent inbound messages can't lose counts to a read-modify-write
     * race.
     */
    public function record(Client $client, string $metric, int $amount = 1, ?int $projectId = null): UsageCounter
    {
        if ($amount <= 0) {
            return $this->counter($client, $metric);
        }

        [$start, $end] = $this->currentPeriod($client, $metric);

        $key = [
            'client_id'    => $client->getKey(),
            'metric'       => $metric,
            'period_start' => $start,
        ];

        // Create-if-absent, then increment. The unique index on
        // (client_id, metric, period_start) makes the insert safe under
        // concurrency; the ignore-on-duplicate keeps it from throwing.
        DB::connection('mysql')->table('usage_counters')->insertOrIgnore([
            'client_id'        => $client->getKey(),
            'project_id'       => $projectId,
            'metric'           => $metric,
            'period_start'     => $start,
            'period_end'       => $end,
            'used'             => 0,
            'overage'          => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $allowance = $this->allowanceFor($client, $metric);
        $counter   = $this->counter($client, $metric);
        $before    = (int) $counter->used;

        // Split the increment into within-allowance and over-allowance parts
        // so the overage figure is correct even when a single call straddles
        // the boundary (e.g. a 5-minute call with 2 minutes left).
        $overageDelta = 0;
        if ($allowance !== null) {
            $remaining    = max(0, $allowance - $before);
            $overageDelta = max(0, $amount - $remaining);
        }

        DB::connection('mysql')->table('usage_counters')
            ->where($key)
            ->update([
                'used'             => DB::raw('used + ' . (int) $amount),
                'overage'          => DB::raw('overage + ' . (int) $overageDelta),
                'project_id'       => $projectId ?? DB::raw('project_id'),
                'last_recorded_at' => now(),
                'updated_at'       => now(),
            ]);

        return $this->counter($client, $metric, fresh: true);
    }

    /**
     * SET an absolute metric to a measured value, rather than adding to it.
     *
     * Absolute metrics (storage, indexed pages) are a standing total, not a
     * count of events: 40 MB stored is 40 MB however many uploads produced it.
     * Pushing them through record() would add 40 every time we measured, so
     * reconciling twice would report 80 MB of the same files.
     *
     * This is what makes reconciliation safe to re-run — the defining property
     * of measuring state instead of counting events.
     */
    public function setAbsolute(Client $client, string $metric, int $value, ?int $projectId = null): void
    {
        [$start, $end] = $this->currentPeriod($client, $metric);

        $allowance = $this->allowanceFor($client, $metric);
        $overage   = $allowance === null ? 0 : max(0, $value - $allowance);

        UsageCounter::query()->updateOrCreate(
            [
                'client_id'    => $client->getKey(),
                'metric'       => $metric,
                'period_start' => $start,
            ],
            [
                'period_end'       => $end,
                'project_id'       => $projectId,
                'used'             => max(0, $value),
                'overage'          => $overage,
                'last_recorded_at' => now(),
            ]
        );
    }

    // ── Asking permission ────────────────────────────────────────────

    /**
     * May this workspace consume `$amount` more of `$metric`?
     *
     * Unlimited (null allowance) → always yes.
     * Paid plan over allowance   → yes, and the excess is recorded as overage.
     * Free plan over allowance   → no.
     */
    public function allows(Client $client, string $metric, int $amount = 1): bool
    {
        $allowance = $this->allowanceFor($client, $metric);

        // NULL = unlimited. Note this must be checked before any truthiness
        // test: `if (!$allowance)` would treat unlimited exactly like zero.
        if ($allowance === null) {
            return true;
        }

        if ($allowance === 0) {
            return false;
        }

        $used = (int) $this->counter($client, $metric)->used;

        if (($used + $amount) <= $allowance) {
            return true;
        }

        return $this->overageAllowed($client);
    }

    /** Would this push the workspace into paid overage? */
    public function wouldExceed(Client $client, string $metric, int $amount = 1): bool
    {
        $allowance = $this->allowanceFor($client, $metric);

        if ($allowance === null) {
            return false;
        }

        return ((int) $this->counter($client, $metric)->used + $amount) > $allowance;
    }

    /**
     * Overage is billable only when there is a paid subscription to bill it
     * to. A workspace on the free window (or an expired one) hard-stops.
     */
    private function overageAllowed(Client $client): bool
    {
        $sub = $client->currentSubscription();

        if (! $sub || $sub->isFree() || $sub->isExpired()) {
            return false;
        }

        return $sub->grantsAccess();
    }

    // ── Allowances ───────────────────────────────────────────────────

    /**
     * The plan's ceiling for a metric. NULL = unlimited, 0 = none.
     *
     * Resolved by finding the feature that declares this `metric_key`, so a
     * super-admin renaming a feature or changing its number takes effect with
     * no code change.
     */
    public function allowanceFor(Client $client, string $metric): ?int
    {
        $plan = $client->currentPlan();

        if (! $plan) {
            return null;   // pre-billing workspace: don't throttle
        }

        $featureKey = Feature::query()
            ->where('metric_key', $metric)
            ->value('key');

        if (! $featureKey) {
            return null;   // metric isn't capped by any feature
        }

        return $this->features->planLimit($plan, $featureKey);
    }

    public function usedFor(Client $client, string $metric): int
    {
        return (int) $this->counter($client, $metric)->used;
    }

    public function remainingFor(Client $client, string $metric): ?int
    {
        $allowance = $this->allowanceFor($client, $metric);

        if ($allowance === null) {
            return null;
        }

        return max(0, $allowance - $this->usedFor($client, $metric));
    }

    /** Everything the billing page needs to draw usage bars. */
    public function summaryFor(Client $client): array
    {
        $out = [];

        foreach ((array) config('billing.metrics', []) as $metric => $meta) {
            $allowance = $this->allowanceFor($client, $metric);
            $counter   = $this->counter($client, $metric);

            // Hide meters this plan doesn't participate in at all (e.g.
            // telephony_minutes on the free plan) rather than showing 0/0.
            if ($allowance === 0) {
                continue;
            }

            $out[$metric] = [
                'label'     => $meta['label'] ?? $metric,
                'unit'      => $meta['unit'] ?? '',
                'used'      => (int) $counter->used,
                'overage'   => (int) $counter->overage,
                'allowance' => $allowance,
                'unlimited' => $allowance === null,
                'percent'   => $counter->percentOf($allowance),
                'resets_at' => $counter->period_end,
            ];
        }

        return $out;
    }

    // ── Periods ──────────────────────────────────────────────────────

    /**
     * The window a metric is counted over.
     *
     * Period metrics follow the SUBSCRIPTION's period, not the calendar
     * month, so a quota resets exactly when the customer is re-billed. A
     * customer who subscribes on the 20th would otherwise get a full quota
     * reset ten days later, for free.
     *
     * Absolute metrics (storage, indexed pages) use a NULL period — they are
     * a standing total, not a per-period count.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public function currentPeriod(Client $client, string $metric): array
    {
        if (config("billing.metric_resets.{$metric}") === 'absolute') {
            return [null, null];
        }

        $sub = $client->currentSubscription();

        if ($sub?->current_period_start && $sub?->current_period_end) {
            return [$sub->current_period_start, $sub->current_period_end];
        }

        // Free window: count across the whole window, so the quota is a
        // total for the 7 days rather than something that resets mid-way.
        if ($sub?->isFree() && $sub->free_started_at) {
            return [$sub->free_started_at, $sub->free_ends_at];
        }

        return [now()->startOfMonth(), now()->endOfMonth()];
    }

    private function counter(Client $client, string $metric, bool $fresh = false): UsageCounter
    {
        [$start, $end] = $this->currentPeriod($client, $metric);

        $query = UsageCounter::query()
            ->where('client_id', $client->getKey())
            ->where('metric', $metric);

        $start === null
            ? $query->whereNull('period_start')
            : $query->where('period_start', $start);

        $row = $query->first();

        return $row ?: new UsageCounter([
            'client_id'    => $client->getKey(),
            'metric'       => $metric,
            'period_start' => $start,
            'period_end'   => $end,
            'used'         => 0,
            'overage'      => 0,
        ]);
    }

    /**
     * Zero the period counters at renewal. Absolute metrics are untouched —
     * storage doesn't go back to zero because the customer paid again.
     */
    public function resetPeriod(Client $client, Carbon $start, Carbon $end): void
    {
        foreach ((array) config('billing.metric_resets', []) as $metric => $mode) {
            if ($mode !== 'period') {
                continue;
            }

            UsageCounter::query()->updateOrCreate(
                [
                    'client_id'    => $client->getKey(),
                    'metric'       => $metric,
                    'period_start' => $start,
                ],
                [
                    'period_end'   => $end,
                    'used'         => 0,
                    'overage'      => 0,
                ]
            );
        }
    }
}
