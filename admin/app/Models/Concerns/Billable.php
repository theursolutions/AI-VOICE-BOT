<?php

namespace App\Models\Concerns;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Billing behaviour for the billable entity.
 *
 * THE BILLABLE IS THE CLIENT (workspace) — not the User, not the Project.
 * A User can belong to several workspaces and a workspace can hold several
 * projects; the workspace is the only boundary that matches how access,
 * roles and the URL scheme (/c/{client:slug}) already work. See
 * SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §1.3.
 *
 * This is a hand-rolled equivalent of a Cashier `Billable` trait. Cashier
 * was not used because it assumes a User-shaped model with datetime
 * timestamps, while `clients` has `public $timestamps = false` and integer
 * unix timestamps (ANALYSIS §5 C2/C3).
 */
trait Billable
{
    // ── Relations ────────────────────────────────────────────────────

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'client_id');
    }

    /** The one live subscription. `type` is reserved for future add-ons. */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'client_id')
                    ->where('type', 'default')
                    ->latestOfMany();
    }

    public function currentPlan(): ?Plan
    {
        $sub = $this->currentSubscription();

        return $sub?->plan;
    }

    /**
     * Memoised per request. The access gate, the sidebar composer and the
     * billing banner all ask for this on the same request; without memoising
     * that's three identical queries on every page load.
     */
    public function currentSubscription(): ?Subscription
    {
        if (! array_key_exists('__subscription', $this->relations)) {
            $this->setRelation(
                '__subscription',
                Subscription::query()
                    ->where('client_id', $this->getKey())
                    ->where('type', 'default')
                    ->latest('id')
                    ->first()
            );
        }

        return $this->getRelation('__subscription');
    }

    /** Drop the memo after a state change so the next read is fresh. */
    public function forgetSubscription(): void
    {
        unset($this->relations['__subscription']);
    }

    // ── State questions the app actually asks ────────────────────────

    /**
     * Full access to the product? A workspace with no subscription row at all
     * is treated as having access: existing workspaces predate billing, and
     * silently locking them out on deploy would be the worst possible
     * migration behaviour. The backfill gives everyone a row explicitly.
     */
    public function hasBillingAccess(): bool
    {
        $sub = $this->currentSubscription();

        return $sub === null ? true : $sub->grantsAccess();
    }

    public function onFreeWindow(): bool
    {
        return (bool) $this->currentSubscription()?->isFree();
    }

    public function onTrial(): bool
    {
        return (bool) $this->currentSubscription()?->onTrial();
    }

    public function subscribed(): bool
    {
        $sub = $this->currentSubscription();

        return $sub !== null && in_array($sub->status, [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_TRIALING,
        ], true);
    }

    public function isReadOnly(): bool
    {
        return $this->access_state === 'read_only'
            || $this->access_state === 'locked';
    }

    /**
     * Can the public widget still answer end customers? This is the check the
     * high-volume inbound path uses, and it reads the denormalised column on
     * `clients` rather than joining `subscriptions` on every message.
     */
    public function widgetIsLive(): bool
    {
        return $this->access_state === 'active';
    }

    // ── Stripe customer ──────────────────────────────────────────────

    public function hasStripeCustomer(): bool
    {
        return ! empty($this->stripe_customer_ref);
    }

    /**
     * Email Stripe should send receipts to. Falls back to the workspace owner.
     *
     * Goes through billingOwner() rather than plucking a bare column: the
     * `users()` relation carries ->distinct(), and MySQL under
     * ONLY_FULL_GROUP_BY rejects `SELECT DISTINCT users.email … ORDER BY
     * users.id` because the ordering column isn't in the select list. That
     * threw a 500 on the very first checkout. Selecting whole models keeps the
     * ordering column present — and the owner is the right recipient anyway.
     */
    public function stripeEmail(): ?string
    {
        if ($this->billing_email) {
            return $this->billing_email;
        }

        return $this->billingOwner()?->email;
    }

    public function stripeName(): string
    {
        return (string) ($this->billing_name ?: $this->name);
    }

    /** The workspace owner — who receives dunning and lifecycle email. */
    public function billingOwner()
    {
        return $this->users()
                    ->join('roles', 'roles.id', '=', 'project_users.role_id')
                    ->where('roles.is_owner', true)
                    ->select('users.*')
                    ->first()
            ?? $this->users()->orderBy('users.id')->first();
    }
}
