<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\Subscription;
use App\Models\Billing\TrialFingerprint;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The subscription state machine. The one place that decides what state a
 * workspace is in and writes it down.
 *
 * DIVISION OF AUTHORITY:
 *   • Stripe is authoritative for everything paid. Paid state arrives here
 *     ONLY through syncFromStripe(), called by the webhook handler. We never
 *     infer "they paid" from a browser redirect — the customer can close the
 *     tab before Stripe finishes, and the success URL is a page anyone can
 *     simply navigate to.
 *   • This service is authoritative for the free window, which Stripe knows
 *     nothing about (no customer, no subscription object, no money).
 */
class SubscriptionService
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly UsageLimitService $usage,
    ) {
    }

    // ── Free window ──────────────────────────────────────────────────

    /**
     * Start the 7-day, no-card free window for a new workspace.
     *
     * Idempotent: a workspace that already has any subscription is returned
     * as-is, so a double-submitted signup can't grant two free windows.
     *
     * Returns NULL when no free plan is configured (a fresh install where
     * BillingSeeder hasn't run). That is deliberately not an exception: this is
     * called from inside the registration transaction, and nobody should be
     * unable to sign up because the billing catalogue is empty. With no
     * subscription row, EnsureSubscribed fails open — the same path that keeps
     * pre-billing workspaces working.
     */
    public function startFreeWindow(Client $client, ?User $owner = null): ?Subscription
    {
        if ($existing = $client->currentSubscription()) {
            return $existing;
        }

        $plan = $this->plans->freePlan();

        if (! $plan) {
            Log::warning('billing.free_window.no_free_plan_configured', [
                'client_id' => $client->getKey(),
                'hint'      => 'Run: php artisan db:seed --class=BillingSeeder',
            ]);

            return null;
        }

        $days = $plan->freeWindowDays() ?? (int) config('billing.free.window_days', 7);

        // Someone who already burned a free window elsewhere gets the plan
        // with NO window — they can still use the workspace, they just land
        // straight on "choose a plan" instead of getting another free week.
        $eligible = $this->isEligibleForFreeWindow($client, $owner);

        $subscription = DB::transaction(function () use ($client, $plan, $days, $eligible) {
            $now = now();

            $sub = Subscription::create([
                'client_id'       => $client->getKey(),
                'plan_id'         => $plan->id,
                'type'            => 'default',
                'status'          => $eligible ? Subscription::STATUS_FREE : Subscription::STATUS_EXPIRED,
                'free_started_at' => $now,
                'free_ends_at'    => $eligible ? $now->copy()->addDays($days) : $now,
                'read_only_since' => $eligible ? null : $now,
            ]);

            return $sub;
        });

        if ($eligible) {
            $this->consumeFreeWindow($client, $owner);
        }

        $client->forgetSubscription();
        $this->syncClientCache($client);

        Log::info('billing.free_window.started', [
            'client_id' => $client->getKey(),
            'eligible'  => $eligible,
            'ends_at'   => $subscription->free_ends_at?->toDateTimeString(),
        ]);

        return $subscription;
    }

    /**
     * Has any identity behind this workspace already used a free window?
     *
     * Checked against the owner's user id, their normalised email, and the
     * business domain. The card fingerprint is checked later, at checkout,
     * because we don't have one yet at signup.
     */
    public function isEligibleForFreeWindow(Client $client, ?User $owner = null): bool
    {
        $owner ??= $client->billingOwner();

        if (! $owner) {
            return true;
        }

        $checks = [];

        $enabled = (array) config('billing.trial.fingerprint_on', []);

        if (in_array('user', $enabled, true)) {
            $checks[] = [TrialFingerprint::KIND_USER, (string) $owner->getKey()];
        }

        if (in_array('email', $enabled, true) && $owner->email) {
            $checks[] = [
                TrialFingerprint::KIND_EMAIL,
                TrialFingerprint::normaliseEmail($owner->email),
            ];
        }

        if (in_array('domain', $enabled, true)) {
            $url = $client->projects()->value('url');
            if ($url && ($domain = TrialFingerprint::normaliseDomain($url))) {
                $checks[] = [TrialFingerprint::KIND_DOMAIN, $domain];
            }
        }

        foreach ($checks as [$kind, $value]) {
            $hit = TrialFingerprint::query()
                ->where('kind', $kind)
                ->where('value_hash', TrialFingerprint::hash($value))
                ->where('consumed_for', TrialFingerprint::FOR_FREE_WINDOW)
                ->where('is_waived', false)
                ->exists();

            if ($hit) {
                return false;
            }
        }

        return true;
    }

    /** Burn the fingerprints so this identity can't get a second window. */
    public function consumeFreeWindow(Client $client, ?User $owner = null): void
    {
        $owner ??= $client->billingOwner();

        if (! $owner) {
            return;
        }

        $records = [
            [TrialFingerprint::KIND_USER, (string) $owner->getKey()],
        ];

        if ($owner->email) {
            $records[] = [TrialFingerprint::KIND_EMAIL, TrialFingerprint::normaliseEmail($owner->email)];
        }

        if ($url = $client->projects()->value('url')) {
            if ($domain = TrialFingerprint::normaliseDomain($url)) {
                $records[] = [TrialFingerprint::KIND_DOMAIN, $domain];
            }
        }

        foreach ($records as [$kind, $value]) {
            TrialFingerprint::query()->firstOrCreate(
                [
                    'kind'         => $kind,
                    'value_hash'   => TrialFingerprint::hash($value),
                    'consumed_for' => TrialFingerprint::FOR_FREE_WINDOW,
                ],
                [
                    'client_id'   => $client->getKey(),
                    'user_id'     => $owner->getKey(),
                    'consumed_at' => now(),
                ]
            );
        }
    }

    /**
     * Record a card fingerprint against the free window. Called after
     * checkout, so the same physical card can't seed a second workspace's
     * free week — the strongest signal we have, and the main reason to
     * collect a card at all.
     */
    public function recordCardFingerprint(Client $client, string $fingerprint): void
    {
        TrialFingerprint::query()->firstOrCreate(
            [
                'kind'         => TrialFingerprint::KIND_CARD,
                'value_hash'   => TrialFingerprint::hash($fingerprint),
                'consumed_for' => TrialFingerprint::FOR_FREE_WINDOW,
            ],
            [
                'client_id'   => $client->getKey(),
                'consumed_at' => now(),
            ]
        );
    }

    /**
     * Free window elapsed with no payment: degrade access, don't delete
     * anything. Locking someone out of a product that is answering their
     * customers' calls turns a lapsed signup into a support ticket and a bad
     * review; read-only keeps their data and their export available.
     */
    public function expireFreeWindow(Subscription $subscription): void
    {
        if (! $subscription->isFree()) {
            return;
        }

        $purgeDays = (int) config('billing.free.purge_after_days', 30);

        $subscription->forceFill([
            'status'          => Subscription::STATUS_EXPIRED,
            'read_only_since' => now(),
            'purge_after'     => $purgeDays > 0 ? now()->addDays($purgeDays) : null,
        ])->save();

        if ($client = $subscription->client) {
            $client->forgetSubscription();
            $this->syncClientCache($client);
        }

        Log::info('billing.free_window.expired', [
            'client_id'   => $subscription->client_id,
            'purge_after' => $subscription->purge_after?->toDateTimeString(),
        ]);
    }

    // ── Stripe-driven transitions (webhook only) ─────────────────────

    /**
     * Mirror a Stripe Subscription object into our table.
     *
     * Written to be safe to call repeatedly with the same payload — webhooks
     * are delivered at least once and can arrive out of order.
     *
     * @param  array  $data  A \Stripe\Subscription as an array.
     */
    public function syncFromStripe(array $data, ?Client $client = null): ?Subscription
    {
        $stripeId = $data['id'] ?? null;

        if (! $stripeId) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('stripe_subscription_ref', $stripeId)
            ->first();

        // First sight of this subscription: locate the workspace from the
        // metadata we stamped at checkout, or from the Stripe customer.
        if (! $subscription) {
            $client ??= $this->resolveClient($data);

            if (! $client) {
                Log::warning('billing.sync.unmatched_subscription', ['stripe_id' => $stripeId]);

                return null;
            }

            // Reuse the workspace's existing row (the free-window one) rather
            // than stacking a second subscription on the same workspace.
            $subscription = Subscription::query()
                ->where('client_id', $client->getKey())
                ->where('type', 'default')
                ->latest('id')
                ->first()
                ?? new Subscription([
                    'client_id' => $client->getKey(),
                    'type'      => 'default',
                ]);
        }

        $client ??= $subscription->client;

        $priceData = $data['items']['data'][0]['price'] ?? [];
        $priceRef  = $priceData['id'] ?? ($data['plan']['id'] ?? null);
        $planPrice = $priceRef
            ? PlanPrice::query()->where('stripe_price_ref', $priceRef)->first()
            : null;

        $stripeStatus = (string) ($data['status'] ?? 'incomplete');

        $subscription->fill([
            'client_id'               => $client?->getKey() ?? $subscription->client_id,
            'plan_id'                 => $planPrice?->plan_id ?? $subscription->plan_id,
            'plan_price_id'           => $planPrice?->id ?? $subscription->plan_price_id,
            'stripe_subscription_ref' => $stripeId,
            'stripe_customer_ref'     => $data['customer'] ?? $subscription->stripe_customer_ref,
            'stripe_price_ref'        => $priceRef,
            'stripe_status'           => $stripeStatus,
            'status'                  => $this->normaliseStatus($stripeStatus),
            'quantity'                => $data['items']['data'][0]['quantity'] ?? 1,
            'interval'                => $planPrice?->interval ?? $subscription->interval,
            'unit_amount'             => $planPrice?->unit_amount ?? ($priceData['unit_amount'] ?? null),
            'currency'                => $priceData['currency'] ?? 'usd',
            'trial_ends_at'           => $this->ts($data['trial_end'] ?? null),
            'current_period_start'    => $this->ts($data['current_period_start'] ?? null),
            'current_period_end'      => $this->ts($data['current_period_end'] ?? null),
            'cancel_at_period_end'    => (bool) ($data['cancel_at_period_end'] ?? false),
            'canceled_at'             => $this->ts($data['canceled_at'] ?? null),
            'ends_at'                 => $this->resolveEndsAt($data),
        ]);

        // A successful payment clears the dunning clock and the degraded
        // access flags in one place, so recovery is never half-applied.
        if (in_array($stripeStatus, ['active', 'trialing'], true)) {
            $subscription->past_due_since  = null;
            $subscription->read_only_since = null;
            $subscription->purge_after     = null;
        }

        if ($stripeStatus === 'past_due' && ! $subscription->past_due_since) {
            $subscription->past_due_since = now();
        }

        $periodChanged = $subscription->isDirty('current_period_start');

        $subscription->save();

        if ($client) {
            $client->forgetSubscription();
            $this->syncClientCache($client);

            // Renewal → new quota window.
            if ($periodChanged && $subscription->current_period_start && $subscription->current_period_end) {
                $this->usage->resetPeriod(
                    $client,
                    $subscription->current_period_start,
                    $subscription->current_period_end,
                );
            }
        }

        return $subscription;
    }

    /** Stripe cancelled/deleted the subscription for good. */
    public function markCanceled(Subscription $subscription): void
    {
        $purgeDays = (int) config('billing.free.purge_after_days', 30);

        $subscription->forceFill([
            'status'          => Subscription::STATUS_CANCELED,
            'stripe_status'   => 'canceled',
            'ends_at'         => $subscription->ends_at ?: now(),
            'canceled_at'     => $subscription->canceled_at ?: now(),
            'read_only_since' => now(),
            'purge_after'     => $purgeDays > 0 ? now()->addDays($purgeDays) : null,
        ])->save();

        if ($client = $subscription->client) {
            $client->forgetSubscription();
            $this->syncClientCache($client);
        }
    }

    /** An invoice failed. Starts the grace clock if it isn't already running. */
    public function markPaymentFailed(Subscription $subscription): void
    {
        $subscription->forceFill([
            'status'         => Subscription::STATUS_PAST_DUE,
            'stripe_status'  => 'past_due',
            'past_due_since' => $subscription->past_due_since ?: now(),
        ])->save();

        if ($client = $subscription->client) {
            $client->forgetSubscription();
            $this->syncClientCache($client);
        }
    }

    // ── Derived cache on `clients` ───────────────────────────────────

    /**
     * Refresh the denormalised billing columns on `clients`.
     *
     * `subscriptions` stays authoritative; these columns exist so the
     * high-volume inbound widget/API path can gate on one already-loaded
     * column instead of joining on every customer message. This method is
     * the ONLY writer.
     */
    public function syncClientCache(Client $client): void
    {
        $sub = $client->currentSubscription();

        $state = match (true) {
            $sub === null            => 'active',   // pre-billing workspace
            $sub->grantsAccess()     => 'active',
            default                  => (string) config('billing.free.on_expiry', 'read_only'),
        };

        $client->forceFill([
            'current_plan_id'   => $sub?->plan_id,
            'billing_status'    => $sub?->status,
            'access_state'      => $state,
            'billing_synced_at' => now(),
        ])->save();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Stripe status → our status. Stripe has no notion of 'free' or
     * 'expired', which is why we keep our own column rather than reading
     * stripe_status everywhere.
     */
    private function normaliseStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'trialing'           => Subscription::STATUS_TRIALING,
            'active'             => Subscription::STATUS_ACTIVE,
            'past_due'           => Subscription::STATUS_PAST_DUE,
            'canceled'           => Subscription::STATUS_CANCELED,
            'unpaid'             => Subscription::STATUS_UNPAID,
            'incomplete'         => Subscription::STATUS_INCOMPLETE,
            'incomplete_expired' => Subscription::STATUS_INCOMPLETE_EXPIRED,
            'paused'             => Subscription::STATUS_PAUSED,
            default              => Subscription::STATUS_INCOMPLETE,
        };
    }

    private function resolveClient(array $data): ?Client
    {
        // Preferred: the metadata we stamped onto the Checkout Session.
        if ($ref = ($data['metadata']['client_ref'] ?? null)) {
            if ($client = Client::query()->whereKey((int) $ref)->first()) {
                return $client;
            }
        }

        if ($customer = ($data['customer'] ?? null)) {
            return Client::query()->where('stripe_customer_ref', $customer)->first();
        }

        return null;
    }

    private function resolveEndsAt(array $data): ?Carbon
    {
        if (! empty($data['ended_at'])) {
            return $this->ts($data['ended_at']);
        }

        if (! empty($data['cancel_at_period_end']) && ! empty($data['current_period_end'])) {
            return $this->ts($data['current_period_end']);
        }

        if (! empty($data['cancel_at'])) {
            return $this->ts($data['cancel_at']);
        }

        return null;
    }

    private function ts(?int $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
