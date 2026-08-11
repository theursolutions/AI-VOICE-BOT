<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as CheckoutSession;

/**
 * The Stripe boundary. Every outbound call to Stripe goes through here.
 *
 * Application code talks to SubscriptionService and PlanService and never
 * touches the Stripe SDK, so replacing the provider means rewriting this one
 * class. That is the swappability the brief asked for.
 *
 * THE CENTRAL SECURITY PROPERTY (see checkout()): the browser submits a plan
 * slug and an interval name — nothing else. Amounts, currencies and Stripe
 * price references are resolved server-side from `plan_prices`. There is no
 * code path that reads a price from the request, so client-side price
 * tampering is structurally impossible rather than merely validated against.
 */
class BillingService
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly PlanService $plans,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->factory->isConfigured();
    }

    // ── Customers ────────────────────────────────────────────────────

    /**
     * Get or create the workspace's Stripe Customer.
     *
     * Deliberately lazy: a workspace on the free window never gets a Stripe
     * Customer at all. Creating one at signup would litter the dashboard with
     * thousands of empty customers and blur the line between "free plan" and
     * "subscription", which the brief explicitly asked to keep separate.
     */
    public function ensureCustomer(Client $client): string
    {
        if ($client->stripe_customer_ref) {
            return $client->stripe_customer_ref;
        }

        $customer = $this->factory->make()->customers->create([
            'name'  => $client->stripeName(),
            'email' => $client->stripeEmail(),
            'metadata' => [
                'client_ref'  => (string) $client->getKey(),
                'client_slug' => (string) $client->slug,
                'app'         => config('app.name'),
            ],
        ]);

        $client->forceFill(['stripe_customer_ref' => $customer->id])->save();

        Log::info('billing.customer.created', [
            'client_id' => $client->getKey(),
            'customer'  => $customer->id,
        ]);

        return $customer->id;
    }

    // ── Checkout ─────────────────────────────────────────────────────

    /**
     * Create a Stripe Checkout Session for a plan selection.
     *
     * @param  string  $planSlug  Opaque plan identifier from the form.
     * @param  string  $interval  'monthly' | 'quarterly' | 'annually'.
     *
     * @throws \RuntimeException when the selection isn't purchasable.
     */
    public function checkout(
        Client $client,
        string $planSlug,
        string $interval,
        ?User $actor = null,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
    ): CheckoutSession {
        // Server-side resolution. Nothing from the request survives past here
        // except the two opaque identifiers.
        $price = $this->plans->resolvePrice($planSlug, $interval);
        $plan  = $price->plan;

        if ($price->isStripeModeMismatched()) {
            throw new \RuntimeException(
                'This price was created in a different Stripe mode (test vs live). ' .
                'Re-sync it from Super Admin → Billing → Plans.'
            );
        }

        $customerRef = $this->ensureCustomer($client);

        $payload = [
            'mode'        => 'subscription',
            'customer'    => $customerRef,
            'line_items'  => [[
                'price'    => $price->stripe_price_ref,
                'quantity' => 1,
            ]],
            'success_url' => $successUrl ?: route(config('billing.checkout.success_route')) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $cancelUrl  ?: route(config('billing.checkout.cancel_route')),

            'allow_promotion_codes'     => (bool) config('billing.checkout.allow_promotion_codes', true),
            'billing_address_collection'=> (string) config('billing.checkout.collect_billing_address', 'auto'),
            'client_reference_id'       => (string) $client->getKey(),

            // Stamped on BOTH the session and the subscription: the webhook
            // may see either object first, and it must be able to find the
            // workspace from whichever arrives.
            'metadata' => $this->metadata($client, $plan->slug, $interval, $actor),
            'subscription_data' => [
                'metadata' => $this->metadata($client, $plan->slug, $interval, $actor),
            ],
        ];

        // Trials are configured per plan and currently 0 everywhere — the
        // 7-day FREE window replaces them. Kept wired so a super-admin can
        // switch one back on with no deploy.
        if ($plan->hasTrial() && $this->subscriptions->isEligibleForFreeWindow($client)) {
            $payload['subscription_data']['trial_period_days'] = (int) $plan->trial_days;

            $payload['subscription_data']['trial_settings'] = [
                'end_behavior' => [
                    'missing_payment_method' => (string) config(
                        'billing.trial.missing_payment_method_behavior',
                        'cancel'
                    ),
                ],
            ];

            $payload['payment_method_collection'] = $plan->trial_requires_payment_method
                ? 'always'
                : 'if_required';
        }

        if (config('billing.checkout.automatic_tax')) {
            $payload['automatic_tax'] = ['enabled' => true];
            $payload['customer_update'] = ['address' => 'auto'];
        }

        $session = $this->factory->make()->checkout->sessions->create($payload);

        Log::info('billing.checkout.created', [
            'client_id' => $client->getKey(),
            'plan'      => $plan->slug,
            'interval'  => $interval,
            'session'   => $session->id,
        ]);

        return $session;
    }

    /** Re-read a completed Checkout Session, expanding what the webhook needs. */
    public function retrieveSession(string $sessionId): CheckoutSession
    {
        return $this->factory->make()->checkout->sessions->retrieve($sessionId, [
            'expand' => ['subscription', 'customer'],
        ]);
    }

    // ── Customer Portal ──────────────────────────────────────────────

    /**
     * Stripe's hosted billing portal.
     *
     * Used instead of rebuilding card management, invoice history and
     * cancellation flows ourselves: Stripe's version is PCI-compliant, handles
     * SCA, and is maintained for us. Our own billing page keeps the
     * plan-level actions (upgrade/downgrade) where the plan catalogue lives,
     * and hands off everything payment-instrument-shaped to the portal.
     */
    public function portalUrl(Client $client, string $returnUrl): string
    {
        $customerRef = $this->ensureCustomer($client);

        return $this->factory->make()->billingPortal->sessions->create([
            'customer'   => $customerRef,
            'return_url' => $returnUrl,
        ])->url;
    }

    // ── Lifecycle actions ────────────────────────────────────────────

    /**
     * Cancel. Defaults to at-period-end so the customer keeps the service
     * they already paid for — cancelling immediately would delete value they
     * bought, and Stripe does not refund the remainder automatically.
     */
    public function cancel(Client $client, bool $atPeriodEnd = true): ?Subscription
    {
        $sub = $client->currentSubscription();

        if (! $sub?->stripe_subscription_ref) {
            return $sub;
        }

        $stripe = $this->factory->make();

        $updated = $atPeriodEnd
            ? $stripe->subscriptions->update($sub->stripe_subscription_ref, ['cancel_at_period_end' => true])
            : $stripe->subscriptions->cancel($sub->stripe_subscription_ref, []);

        Log::info('billing.subscription.canceled', [
            'client_id'     => $client->getKey(),
            'at_period_end' => $atPeriodEnd,
        ]);

        // Sync immediately for a responsive UI; the webhook will confirm.
        return $this->subscriptions->syncFromStripe($updated->toArray(), $client);
    }

    /** Undo a pending cancellation, while still inside the paid period. */
    public function resume(Client $client): ?Subscription
    {
        $sub = $client->currentSubscription();

        if (! $sub?->stripe_subscription_ref) {
            return $sub;
        }

        if (! $sub->onGracePeriod() && ! $sub->cancel_at_period_end) {
            throw new \RuntimeException('This subscription is not scheduled to cancel.');
        }

        $updated = $this->factory->make()->subscriptions->update(
            $sub->stripe_subscription_ref,
            ['cancel_at_period_end' => false]
        );

        Log::info('billing.subscription.resumed', ['client_id' => $client->getKey()]);

        return $this->subscriptions->syncFromStripe($updated->toArray(), $client);
    }

    /**
     * Upgrade, downgrade, or switch billing interval.
     *
     * PRORATION: default `create_prorations` — Stripe credits the unused part
     * of the old price and charges the new one pro rata, applied to the NEXT
     * invoice rather than billing immediately. Chosen over `always_invoice`
     * because an unexpected mid-month charge is the single most common
     * complaint about self-serve upgrades. Configurable via BILLING_PRORATION.
     *
     * A workspace with no Stripe subscription (free window) can't swap — it
     * goes through checkout instead, which the caller handles.
     */
    public function swap(Client $client, string $planSlug, string $interval): Subscription
    {
        $sub = $client->currentSubscription();

        if (! $sub?->stripe_subscription_ref) {
            throw new \RuntimeException('No active Stripe subscription to change. Use checkout instead.');
        }

        $price = $this->plans->resolvePrice($planSlug, $interval);

        $stripe  = $this->factory->make();
        $current = $stripe->subscriptions->retrieve($sub->stripe_subscription_ref, []);
        $itemId  = $current->items->data[0]->id ?? null;

        if (! $itemId) {
            throw new \RuntimeException('Stripe subscription has no line item to update.');
        }

        $updated = $stripe->subscriptions->update($sub->stripe_subscription_ref, [
            'items' => [[
                'id'    => $itemId,
                'price' => $price->stripe_price_ref,
            ]],
            'proration_behavior' => (string) config('billing.checkout.proration_behavior', 'create_prorations'),
            'metadata'           => $this->metadata($client, $planSlug, $interval),
            // Switching interval mid-cycle without this leaves the anchor on
            // the old cadence, producing a confusing first invoice date.
            'billing_cycle_anchor' => $interval !== $sub->interval ? 'now' : 'unchanged',
        ]);

        Log::info('billing.subscription.swapped', [
            'client_id' => $client->getKey(),
            'from'      => $sub->interval,
            'to'        => $interval,
            'plan'      => $planSlug,
        ]);

        return $this->subscriptions->syncFromStripe($updated->toArray(), $client);
    }

    // ── Reads for the billing page ───────────────────────────────────

    /** Recent invoices. Returns [] rather than throwing — this is a page, not a transaction. */
    public function invoices(Client $client, int $limit = 12): array
    {
        if (! $client->stripe_customer_ref || ! $this->isConfigured()) {
            return [];
        }

        try {
            $invoices = $this->factory->make()->invoices->all([
                'customer' => $client->stripe_customer_ref,
                'limit'    => $limit,
            ]);

            return collect($invoices->data)->map(fn ($invoice) => [
                'id'          => $invoice->id,
                'number'      => $invoice->number,
                'status'      => $invoice->status,
                'total'       => $invoice->total,
                'currency'    => strtoupper((string) $invoice->currency),
                'created'     => $invoice->created ? \Illuminate\Support\Carbon::createFromTimestamp($invoice->created) : null,
                'pdf'         => $invoice->invoice_pdf,
                'hosted_url'  => $invoice->hosted_invoice_url,
            ])->all();
        } catch (\Throwable $e) {
            Log::warning('billing.invoices.fetch_failed', [
                'client_id' => $client->getKey(),
                'error'     => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** Default payment method summary, or null. Never throws. */
    public function paymentMethod(Client $client): ?array
    {
        if (! $client->stripe_customer_ref || ! $this->isConfigured()) {
            return null;
        }

        try {
            $customer = $this->factory->make()->customers->retrieve(
                $client->stripe_customer_ref,
                ['expand' => ['invoice_settings.default_payment_method']]
            );

            $pm = $customer->invoice_settings->default_payment_method ?? null;

            if (! $pm) {
                return null;
            }

            return [
                'brand'     => $pm->card->brand     ?? $pm->type,
                'last4'     => $pm->card->last4     ?? null,
                'exp_month' => $pm->card->exp_month ?? null,
                'exp_year'  => $pm->card->exp_year  ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('billing.payment_method.fetch_failed', [
                'client_id' => $client->getKey(),
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function metadata(Client $client, string $planSlug, string $interval, ?User $actor = null): array
    {
        return array_filter([
            'client_ref'  => (string) $client->getKey(),
            'client_slug' => (string) $client->slug,
            'plan_slug'   => $planSlug,
            'interval'    => $interval,
            'actor_ref'   => $actor ? (string) $actor->getKey() : null,
        ]);
    }
}
