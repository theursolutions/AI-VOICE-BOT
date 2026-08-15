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

    /**
     * Subscribe using an on-site Stripe Elements form instead of hosted Checkout.
     *
     * THE FLOW, and why it has these moving parts:
     *
     *  1. The browser turns card details into a PaymentMethod id via Stripe.js.
     *     Card data never reaches us — the inputs are Stripe-hosted iframes.
     *  2. We attach that PM to the customer and create the subscription with
     *     `payment_behavior: default_incomplete`. Stripe then produces an
     *     invoice with a PaymentIntent but does NOT charge yet.
     *  3. We hand the PaymentIntent's client_secret back to the browser, which
     *     confirms it. If the bank demands 3-D Secure, the challenge happens
     *     there, with the customer present.
     *  4. The webhook — not this method, and not the browser's success
     *     callback — is what finally marks the subscription active.
     *
     * `default_incomplete` is essential: without it Stripe would attempt the
     * charge server-side, and any card requiring SCA would fail outright with
     * no way to present the challenge. That is the single most common reason a
     * hand-rolled Elements subscription flow breaks for European and Indian
     * cards.
     *
     * @return array{status:string, requires_action:bool, client_secret:?string, subscription_ref:?string}
     */
    public function subscribeWithPaymentMethod(
        Client $client,
        string $planSlug,
        string $interval,
        string $paymentMethodRef,
        ?User $actor = null,
    ): array {
        $price = $this->plans->resolvePrice($planSlug, $interval);
        $plan  = $price->plan;

        if ($price->isStripeModeMismatched()) {
            throw new \RuntimeException(
                'This price was created in a different Stripe mode (test vs live). Re-sync it from Super Admin → Billing → Plans.'
            );
        }

        $customerRef = $this->ensureCustomer($client);
        $stripe      = $this->factory->make();

        // A card tokenised by Elements is NOT yet attached to the customer —
        // it's a bare `pm_…` belonging to nobody. Stripe refuses it as a
        // subscription's default_payment_method with "The customer does not
        // have a payment method with the ID pm_…", which is the correct
        // behaviour and exactly what a first-time checkout hits. A saved card
        // is already attached, so this is a no-op on that path.
        $this->attachPaymentMethod($client, $paymentMethodRef);

        $payload = [
            'customer' => $customerRef,
            'items'    => [['price' => $price->stripe_price_ref]],

            'payment_behavior'        => 'default_incomplete',
            'default_payment_method'  => $paymentMethodRef,
            'payment_settings'        => [
                'save_default_payment_method' => 'on_subscription',
                'payment_method_types'        => ['card'],
            ],

            // Both objects are stamped so the webhook can find the workspace
            // whichever one it happens to see first.
            'metadata' => $this->metadata($client, $plan->slug, $interval, $actor),

            'expand' => ['latest_invoice.payment_intent'],
        ];

        if ($plan->hasTrial() && $this->subscriptions->isEligibleForFreeWindow($client)) {
            $payload['trial_period_days'] = (int) $plan->trial_days;
        }

        $subscription = $stripe->subscriptions->create($payload);

        $intent = $subscription->latest_invoice->payment_intent ?? null;

        // Mirror immediately so the UI is responsive; the webhook remains the
        // authority and will overwrite this with the settled truth.
        $this->subscriptions->syncFromStripe($subscription->toArray(), $client);

        Log::info('billing.subscribe.created', [
            'client_id'    => $client->getKey(),
            'plan'         => $plan->slug,
            'interval'     => $interval,
            'subscription' => $subscription->id,
            'intent'       => $intent->status ?? 'none',
        ]);

        $status = (string) ($intent->status ?? 'succeeded');

        return [
            'status'           => $status,
            // requires_confirmation is included deliberately: with a saved card
            // Stripe hands back a PI awaiting confirmation, and the browser has
            // to call confirmCardPayment for it exactly as it would for 3-D
            // Secure. Treating only requires_action as "needs the browser"
            // leaves those subscriptions stuck as incomplete.
            'requires_action'  => in_array($status, ['requires_action', 'requires_confirmation', 'requires_payment_method'], true),
            'client_secret'    => $intent->client_secret ?? null,
            'subscription_ref' => $subscription->id,
        ];
    }

    /**
     * Attach a browser-supplied PaymentMethod to this workspace's customer.
     *
     * Decides from the PM's CURRENT owner rather than from the text of a
     * Stripe error:
     *   - unowned  → freshly tokenised by Elements, attach it
     *   - ours     → already attached, nothing to do (so this is idempotent)
     *   - someone else's → refuse
     *
     * That last branch matters. The `pm_…` id arrives from the browser, and
     * Stripe's "already been attached" message covers BOTH "to you" and "to
     * another customer" — so matching on the message would quietly accept
     * another workspace's card here.
     */
    public function attachPaymentMethod(Client $client, string $paymentMethodRef): void
    {
        $customerRef = $this->ensureCustomer($client);
        $stripe      = $this->factory->make();

        $owner = $stripe->paymentMethods->retrieve($paymentMethodRef, [])->customer ?? null;

        if ($owner === null) {
            $stripe->paymentMethods->attach($paymentMethodRef, ['customer' => $customerRef]);

            return;
        }

        if ($owner !== $customerRef) {
            throw new \RuntimeException('That payment method does not belong to this workspace.');
        }
    }

    /** Re-read a subscription (after a browser-side 3-D Secure confirmation). */
    public function refreshSubscription(Client $client, string $subscriptionRef): void
    {
        $remote = $this->factory->make()->subscriptions->retrieve($subscriptionRef, []);

        $this->subscriptions->syncFromStripe($remote->toArray(), $client);
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

    /**
     * One invoice, with its line items, for our own branded invoice page.
     *
     * SECURITY: the id comes from a URL, so the fetched invoice is checked
     * against this workspace's Stripe customer before anything is returned.
     * Without that check an `in_…` id would read another tenant's invoice.
     * Returns null (→ 404) on any mismatch or failure.
     */
    public function invoice(Client $client, string $invoiceRef): ?array
    {
        if (! $client->stripe_customer_ref || ! $this->isConfigured()) {
            return null;
        }

        try {
            $invoice = $this->factory->make()->invoices->retrieve($invoiceRef, []);

            if (($invoice->customer ?? null) !== $client->stripe_customer_ref) {
                Log::warning('billing.invoice.cross_tenant_blocked', [
                    'client_id' => $client->getKey(),
                    'invoice'   => $invoiceRef,
                ]);

                return null;
            }

            $lines = [];
            foreach ($invoice->lines->data ?? [] as $line) {
                $lines[] = [
                    'description' => $line->description ?: ($line->price->nickname ?? 'Subscription'),
                    'quantity'    => (int) ($line->quantity ?? 1),
                    'amount'      => (int) ($line->amount ?? 0),
                    'period_start'=> ! empty($line->period->start) ? \Illuminate\Support\Carbon::createFromTimestamp($line->period->start) : null,
                    'period_end'  => ! empty($line->period->end) ? \Illuminate\Support\Carbon::createFromTimestamp($line->period->end) : null,
                ];
            }

            $tax = $this->taxBreakdown($invoice);

            return [
                'id'          => $invoice->id,
                'number'      => $invoice->number,
                'status'      => $invoice->status,
                'currency'    => strtoupper((string) $invoice->currency),
                'subtotal'    => (int) ($invoice->subtotal ?? 0),
                'tax'         => $tax['total'],
                'tax_lines'   => $tax['lines'],
                'tax_ids'     => $tax['ids'],
                'tax_note'    => $tax['note'],
                'total'       => (int) ($invoice->total ?? 0),
                'amount_paid' => (int) ($invoice->amount_paid ?? 0),
                'created'     => $invoice->created ? \Illuminate\Support\Carbon::createFromTimestamp($invoice->created) : null,
                'paid_at'     => ! empty($invoice->status_transitions->paid_at)
                                    ? \Illuminate\Support\Carbon::createFromTimestamp($invoice->status_transitions->paid_at)
                                    : null,
                'period_start'=> $invoice->period_start ? \Illuminate\Support\Carbon::createFromTimestamp($invoice->period_start) : null,
                'period_end'  => $invoice->period_end ? \Illuminate\Support\Carbon::createFromTimestamp($invoice->period_end) : null,
                'pdf'         => $invoice->invoice_pdf,
                'hosted_url'  => $invoice->hosted_invoice_url,
                'customer_name'  => $invoice->customer_name,
                'customer_email' => $invoice->customer_email,
                'lines'       => $lines,
            ];
        } catch (\Throwable $e) {
            Log::warning('billing.invoice.fetch_failed', [
                'client_id' => $client->getKey(),
                'invoice'   => $invoiceRef,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Tax on an invoice: the total, a per-rate breakdown, and the buyer's tax
     * ids — everything a receipt has to state rather than imply.
     *
     * `Invoice::$tax` WAS REMOVED from the Stripe API. Modern versions carry
     * `total_taxes[]`, each entry holding the amount, whether the rate was
     * inclusive or exclusive, and why it was (or wasn't) charged. Reading the
     * old field returns null, so `$invoice->tax ?? 0` reported zero tax on
     * every invoice forever — silently, because zero is a plausible answer.
     * Both shapes are read here so the receipt is right either way.
     *
     * Rate LABELS come from `default_tax_rates`, which Stripe returns as full
     * TaxRate objects; `total_taxes` only references a rate by id. When a rate
     * can't be resolved, the percentage is derived from amount ÷ taxable
     * amount rather than left blank.
     *
     * @return array{total:int, lines:array<int,array>, ids:array<int,string>, note:?string}
     */
    private function taxBreakdown(\Stripe\Invoice $invoice): array
    {
        // id => TaxRate, for turning a bare reference into "GST 17%".
        $rates = [];
        foreach ($invoice->default_tax_rates ?? [] as $rate) {
            if (! empty($rate->id)) {
                $rates[$rate->id] = $rate;
            }
        }

        $lines = [];
        $total = 0;

        // `total_taxes` (current) or `total_tax_amounts` (pre-2025).
        $entries = $invoice->total_taxes ?? $invoice->total_tax_amounts ?? [];

        foreach ($entries as $entry) {
            $amount = (int) ($entry->amount ?? 0);
            $total += $amount;

            $rateId = $entry->tax_rate_details->tax_rate       // current shape
                   ?? (is_string($entry->tax_rate ?? null) ? $entry->tax_rate : null)
                   ?? ($entry->tax_rate->id ?? null);          // expanded object

            $rate = $rateId ? ($rates[$rateId] ?? null) : null;

            // Percentage: from the rate when we have it, otherwise derived.
            $percentage = $rate->percentage ?? $rate->effective_percentage ?? null;
            $taxable    = (int) ($entry->taxable_amount ?? 0);

            if ($percentage === null && $taxable > 0) {
                $percentage = round($amount / $taxable * 100, 2);
            }

            $inclusive = $rate->inclusive
                ?? (($entry->tax_behavior ?? null) === 'inclusive');

            $label = $rate->display_name
                ?? ucwords(str_replace('_', ' ', (string) ($rate->tax_type ?? 'Tax')));

            $lines[] = [
                'label'        => $label,
                'percentage'   => $percentage !== null ? (float) $percentage : null,
                'jurisdiction' => $rate->jurisdiction ?? $rate->country ?? null,
                'inclusive'    => (bool) $inclusive,
                'amount'       => $amount,
                'reason'       => $entry->taxability_reason ?? null,
            ];
        }

        // Pre-2025 fallback: a flat total with no breakdown available.
        if ($lines === [] && isset($invoice->tax) && $invoice->tax !== null) {
            $total = (int) $invoice->tax;
        }

        // The buyer's own registration number (NTN / VAT / GST), which a
        // business customer needs on the document to reclaim anything.
        $ids = [];
        foreach ($invoice->customer_tax_ids ?? [] as $taxId) {
            if (! empty($taxId->value)) {
                $ids[] = trim(strtoupper(str_replace('_', ' ', (string) ($taxId->type ?? ''))) . ' ' . $taxId->value);
            }
        }

        // Say WHY there's no tax rather than showing a bare zero.
        $note = null;

        if ($total === 0) {
            $status = $invoice->automatic_tax->status ?? null;
            $exempt = $invoice->customer_tax_exempt ?? null;

            $note = match (true) {
                $exempt === 'reverse'        => 'Reverse charge — tax accounted for by the recipient.',
                $exempt === 'exempt'         => 'Tax exempt.',
                $status === 'failed'         => 'Tax could not be calculated for this invoice.',
                $status === 'requires_location_inputs' => 'No tax applied — billing address incomplete.',
                default                      => 'No tax applied.',
            };
        }

        return ['total' => $total, 'lines' => $lines, 'ids' => $ids, 'note' => $note];
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
