<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanGrant;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Models\User;
use App\Services\Conversation\ConversationBudget;
use Illuminate\Support\Facades\DB;

/**
 * Super-admin control over what a workspace is on and what it is allowed.
 *
 * Two operations, both of which existed only as "edit the database" before:
 *
 *   assign   put a workspace on any plan, optionally at no charge
 *   grant    top up one allowance beyond what its plan includes
 *
 * ASSIGNING FREE DOES NOT TOUCH STRIPE, and that is the whole point. A local
 * subscription with status `active` and no stripe_subscription_ref grants access
 * — Subscription::grantsAccess() already reads `active` from
 * billing.lifecycle.active_statuses — while Stripe never learns of it and never
 * invoices. That is exactly what a pilot, a partner, an internal workspace or a
 * goodwill month needs, and it is why this is safer than the alternative people
 * reach for, which is creating a $0 plan that then sits in the catalogue.
 *
 * The reverse operation is deliberately NOT here. Cancelling or refunding a paid
 * Stripe subscription has consequences this class cannot reason about — proration,
 * invoices already issued, a card that may be disputed — so switching a paying
 * customer onto a free assignment is refused rather than half-done.
 */
class WorkspacePlanService
{
    public function __construct(
        private readonly PlanFeatureService $features,
        private readonly UsageLimitService $usage,
    ) {
    }

    /**
     * Put a workspace on a plan without charging for it.
     *
     * @param  ?string  $note   why, for the audit trail
     */
    public function assignFree(Client $client, Plan $plan, ?User $actor = null, ?string $note = null): Subscription
    {
        return DB::transaction(function () use ($client, $plan, $actor, $note) {
            $existing = $client->currentSubscription();

            // Refuse to overwrite a live Stripe subscription. Doing so would
            // leave Stripe billing a plan the product no longer thinks they are
            // on — the customer keeps paying for something invisible, which is
            // the worst of both outcomes and is not recoverable from here.
            if ($existing?->stripe_subscription_ref && $existing->grantsAccess()) {
                throw new \RuntimeException(
                    'This workspace has a live Stripe subscription. Cancel it in Stripe first — '
                    . 'assigning over it would leave them paying for a plan they are no longer on.'
                );
            }

            $price = $plan->priceFor('monthly');

            $attributes = [
                'client_id'     => $client->id,
                'plan_id'       => $plan->id,
                // The price is recorded for display only; nothing will be
                // charged, so unit_amount is zero rather than the list price. A
                // free assignment that reports $199 of MRR would overstate
                // revenue in every report that sums this column.
                'plan_price_id' => $price?->id,
                'interval'      => $price?->interval ?? 'monthly',
                'unit_amount'   => 0,
                'currency'      => 'usd',
                'status'        => Subscription::STATUS_ACTIVE,
                'stripe_status' => null,
                'quantity'      => 1,
                'metadata'      => [
                    'assigned_by_super_admin' => true,
                    'assigned_by'             => $actor?->id,
                    'assigned_at'             => now()->toIso8601String(),
                    'note'                    => $note,
                ],
                'updated_at'    => now(),
            ];

            if ($existing) {
                // Clear any Stripe linkage from a previous incomplete attempt,
                // so a later webhook for that object cannot reanimate it.
                $existing->forceFill($attributes + [
                    'stripe_subscription_ref' => null,
                    'stripe_price_ref'        => null,
                    'cancel_at_period_end'    => false,
                    'canceled_at'             => null,
                    'ends_at'                 => null,
                    'past_due_since'          => null,
                    'read_only_since'         => null,
                    'purge_after'             => null,
                ])->save();

                $subscription = $existing;
            } else {
                $subscription = Subscription::create($attributes + ['created_at' => now()]);
            }

            $client->forceFill([
                'current_plan_id' => $plan->id,
                'billing_status'  => Subscription::STATUS_ACTIVE,
                'updated_at'      => time(),
            ])->save();

            $this->afterEntitlementChange($client);

            return $subscription;
        });
    }

    /**
     * Grant or update one allowance.
     *
     * A value of PlanGrant::UNLIMITED removes the ceiling for that feature.
     * Passing 0 revokes, because a grant of nothing and no grant are the same
     * state and keeping a zero row would only make the audit trail lie about
     * what is in force.
     */
    public function grant(
        Client $client,
        string $featureKey,
        int $value,
        ?User $actor = null,
        ?string $note = null,
        ?\DateTimeInterface $expiresAt = null,
    ): ?PlanGrant {
        if ($value === 0) {
            $this->revoke($client, $featureKey);

            return null;
        }

        $grant = PlanGrant::updateOrCreate(
            ['client_id' => $client->id, 'feature_key' => $featureKey],
            [
                'value'      => $value,
                'note'       => $note,
                'granted_by' => $actor?->id,
                'expires_at' => $expiresAt,
            ],
        );

        $this->afterEntitlementChange($client);

        return $grant;
    }

    public function revoke(Client $client, string $featureKey): void
    {
        PlanGrant::where('client_id', $client->id)->where('feature_key', $featureKey)->delete();

        $this->afterEntitlementChange($client);
    }

    /** Grants in force, keyed by feature. */
    public function grantsFor(Client $client)
    {
        return PlanGrant::where('client_id', $client->id)->orderBy('feature_key')->get();
    }

    /**
     * Everything the page needs to describe this workspace's entitlement:
     * plan value, grant, and the effective total, per numeric feature.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entitlementRows(Client $client): array
    {
        $plan  = $client->currentPlan();
        $rows  = [];

        $features = \App\Models\Billing\Feature::query()
            ->where('value_type', \App\Models\Billing\Feature::TYPE_NUMERIC)
            ->orderBy('group')->orderBy('sort_order')
            ->get();

        foreach ($features as $feature) {
            $fromPlan  = $plan ? $this->features->planLimit($plan, $feature->key) : null;
            $granted   = $this->features->grantFor($client, $feature->key);
            $effective = $this->features->clientLimit($client, $feature->key);

            $rows[] = [
                'key'       => $feature->key,
                'name'      => $feature->name,
                'unit'      => $feature->unit,
                'group'     => $feature->group,
                'plan'      => $fromPlan,
                'granted'   => $granted,
                'effective' => $effective,
                // Only metered features have a usage figure to show against the
                // allowance; the rest are structural (seats, projects).
                'used'      => $feature->metric_key
                    ? $this->usage->usedFor($client, $feature->metric_key)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Drop every cache that could still answer with the old entitlement.
     *
     * Three of them, and missing any one leaves a stale answer somewhere
     * specific: the plan map decides what the tier includes, the grant map what
     * was added on top, and the conversation budget how long a single chat runs
     * — the last being per-project, so it has to be cleared for the whole
     * workspace rather than one project.
     */
    private function afterEntitlementChange(Client $client): void
    {
        $this->features->flush();
        $this->features->flushGrants($client);
        ConversationBudget::forgetClient((int) $client->id);
    }
}
