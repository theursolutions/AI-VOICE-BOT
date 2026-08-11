<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Billing\StripeEvent;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripeClientFactory;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe webhook endpoint. THE authority on paid subscription state.
 *
 * Three properties this class must guarantee, in order of importance:
 *
 * 1. AUTHENTICITY — every request is signature-verified against
 *    STRIPE_WEBHOOK_SECRET before a single byte of the body is trusted.
 *    Without that this endpoint is an unauthenticated "make me a subscriber"
 *    API. An unverifiable request gets 400 and is never recorded.
 *
 * 2. IDEMPOTENCY — Stripe guarantees AT LEAST ONCE delivery and retries every
 *    non-2xx response, so duplicate events are normal operation, not an edge
 *    case. StripeEvent::claim() inserts the event id against a UNIQUE index
 *    and treats the duplicate-key violation as "already handled". We insert
 *    rather than check-then-insert because two concurrent deliveries would
 *    both pass an existence check.
 *
 * 3. CORRECT ACKs — a handled event, a duplicate, and an event we don't care
 *    about all return 200. A 500 makes Stripe retry with backoff for days;
 *    only genuine, retryable server failures should do that.
 *
 * The route is registered WITHOUT the `web` middleware group (no session, no
 * CSRF) and outside `auth` — see routes/billing.php.
 */
class StripeWebhookController extends Controller
{
    /** Events we act on. Anything else is recorded and skipped. */
    private const HANDLED = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.trial_will_end',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'invoice.paid',
        'invoice.payment_failed',
        'payment_method.attached',
    ];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly BillingService $billing,
        private readonly StripeClientFactory $factory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('billing.stripe.webhook_secret');

        if ($secret === '') {
            // Misconfiguration, not an attack. 500 so Stripe retries once the
            // secret is set, instead of silently dropping real events.
            Log::error('stripe.webhook.no_secret_configured');

            return response('Webhook secret not configured', 500);
        }

        // ── 1. Verify ────────────────────────────────────────────────
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
                (int) config('billing.stripe.webhook_tolerance', 300),
            );
        } catch (SignatureVerificationException $e) {
            Log::warning('stripe.webhook.bad_signature', ['error' => $e->getMessage()]);

            return response('Invalid signature', 400);
        } catch (\UnexpectedValueException $e) {
            Log::warning('stripe.webhook.bad_payload', ['error' => $e->getMessage()]);

            return response('Invalid payload', 400);
        }

        // ── 2. Claim (idempotency) ───────────────────────────────────
        $record = StripeEvent::claim($event->id, [
            'type'        => $event->type,
            'api_version' => $event->api_version ?? null,
            'livemode'    => (bool) ($event->livemode ?? false),
            'payload'     => json_encode($event->toArray()),
        ]);

        if ($record === null) {
            // Already seen. ACK so Stripe stops retrying — this is the
            // duplicate-delivery path, and it is expected.
            Log::info('stripe.webhook.duplicate_ignored', ['event' => $event->id]);

            return response('Already processed', 200);
        }

        if (! in_array($event->type, self::HANDLED, true)) {
            $record->markSkipped('Event type not handled');

            return response('Ignored', 200);
        }

        // ── 3. Handle ────────────────────────────────────────────────
        try {
            $this->dispatch($event->type, $event->data->object);

            $record->markProcessed();

            return response('OK', 200);
        } catch (\Throwable $e) {
            $record->markFailed($e);

            Log::error('stripe.webhook.handler_failed', [
                'event' => $event->id,
                'type'  => $event->type,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);

            // 500 so Stripe retries with backoff — the event is real and we
            // failed to apply it, which is exactly what retries are for. The
            // ledger row stays `failed` and can be replayed manually.
            return response('Handler error', 500);
        }
    }

    // ── Dispatch ─────────────────────────────────────────────────────

    private function dispatch(string $type, $object): void
    {
        $data = $object instanceof \Stripe\StripeObject ? $object->toArray() : (array) $object;

        match ($type) {
            'checkout.session.completed'            => $this->onCheckoutCompleted($data),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.paused',
            'customer.subscription.resumed'         => $this->onSubscriptionChanged($data),
            'customer.subscription.deleted'         => $this->onSubscriptionDeleted($data),
            'customer.subscription.trial_will_end'  => $this->onTrialWillEnd($data),
            'invoice.paid'                          => $this->onInvoicePaid($data),
            'invoice.payment_failed'                => $this->onInvoicePaymentFailed($data),
            'payment_method.attached'               => $this->onPaymentMethodAttached($data),
            default                                 => null,
        };
    }

    /**
     * Checkout finished. This is where a free-window workspace becomes a
     * paying one.
     *
     * The session's `subscription` may be just an id, so we re-fetch the full
     * Subscription rather than guessing at its shape — and we do NOT rely on
     * the browser ever reaching the success URL.
     */
    private function onCheckoutCompleted(array $session): void
    {
        $client = $this->clientFrom($session);

        if (! $client) {
            Log::warning('stripe.webhook.checkout_no_client', ['session' => $session['id'] ?? null]);

            return;
        }

        // Persist the customer reference if this was their first purchase.
        if (! empty($session['customer']) && ! $client->stripe_customer_ref) {
            $client->forceFill(['stripe_customer_ref' => $session['customer']])->save();
        }

        if (! empty($session['customer_details']['email']) && ! $client->billing_email) {
            $client->forceFill([
                'billing_email'   => $session['customer_details']['email'],
                'billing_country' => $session['customer_details']['address']['country'] ?? null,
            ])->save();
        }

        $subscriptionRef = is_array($session['subscription'] ?? null)
            ? ($session['subscription']['id'] ?? null)
            : ($session['subscription'] ?? null);

        if (! $subscriptionRef) {
            return;
        }

        $stripeSubscription = $this->factory->make()->subscriptions->retrieve($subscriptionRef, []);

        $this->subscriptions->syncFromStripe($stripeSubscription->toArray(), $client);

        Log::info('stripe.webhook.checkout_completed', [
            'client_id'    => $client->getKey(),
            'subscription' => $subscriptionRef,
        ]);
    }

    private function onSubscriptionChanged(array $subscription): void
    {
        $this->subscriptions->syncFromStripe($subscription);
    }

    private function onSubscriptionDeleted(array $subscription): void
    {
        $local = Subscription::query()
            ->where('stripe_subscription_ref', $subscription['id'] ?? '')
            ->first();

        if ($local) {
            $this->subscriptions->markCanceled($local);
        }
    }

    /**
     * Stripe fires this 3 days before a trial ends. Only relevant if a
     * super-admin has switched a paid-plan trial on (trial_days > 0) — the
     * approved model uses the 7-day free window instead.
     */
    private function onTrialWillEnd(array $subscription): void
    {
        $local = $this->subscriptions->syncFromStripe($subscription);

        if ($local?->client) {
            Log::info('stripe.webhook.trial_will_end', ['client_id' => $local->client_id]);
            // Notification is dispatched by the lifecycle command, which owns
            // all customer-facing billing email so the cadence stays in one place.
        }
    }

    /**
     * A payment succeeded. Re-syncing the subscription (rather than patching
     * the period from the invoice) keeps one code path responsible for period
     * dates, and it also clears past_due and the read-only flags — recovery
     * is never half-applied.
     */
    private function onInvoicePaid(array $invoice): void
    {
        $subscriptionRef = is_array($invoice['subscription'] ?? null)
            ? ($invoice['subscription']['id'] ?? null)
            : ($invoice['subscription'] ?? null);

        if (! $subscriptionRef) {
            return;   // one-off invoice, not a subscription renewal
        }

        $stripeSubscription = $this->factory->make()->subscriptions->retrieve($subscriptionRef, []);

        $this->subscriptions->syncFromStripe($stripeSubscription->toArray());
    }

    private function onInvoicePaymentFailed(array $invoice): void
    {
        $subscriptionRef = is_array($invoice['subscription'] ?? null)
            ? ($invoice['subscription']['id'] ?? null)
            : ($invoice['subscription'] ?? null);

        if (! $subscriptionRef) {
            return;
        }

        $local = Subscription::query()
            ->where('stripe_subscription_ref', $subscriptionRef)
            ->first();

        if (! $local) {
            return;
        }

        $this->subscriptions->markPaymentFailed($local);

        Log::info('stripe.webhook.payment_failed', [
            'client_id' => $local->client_id,
            'invoice'   => $invoice['id'] ?? null,
            'attempt'   => $invoice['attempt_count'] ?? null,
        ]);
    }

    /**
     * Store the card label for the billing page, and burn the card
     * FINGERPRINT against the free window. Stripe's fingerprint is stable for
     * the same physical card across different Customers, so this is what stops
     * one card seeding a free week in workspace after workspace — and the
     * main reason collecting a card is worth doing at all.
     */
    private function onPaymentMethodAttached(array $paymentMethod): void
    {
        $customerRef = $paymentMethod['customer'] ?? null;

        if (! $customerRef) {
            return;
        }

        $client = Client::query()->where('stripe_customer_ref', $customerRef)->first();

        if (! $client) {
            return;
        }

        $client->forceFill([
            'pm_type'      => $paymentMethod['card']['brand'] ?? ($paymentMethod['type'] ?? null),
            'pm_last_four' => $paymentMethod['card']['last4'] ?? null,
        ])->save();

        if ($fingerprint = ($paymentMethod['card']['fingerprint'] ?? null)) {
            $this->subscriptions->recordCardFingerprint($client, $fingerprint);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** Locate the workspace from whichever hint the payload carries. */
    private function clientFrom(array $session): ?Client
    {
        foreach ([
            $session['metadata']['client_ref'] ?? null,
            $session['client_reference_id'] ?? null,
        ] as $ref) {
            if ($ref && $client = Client::query()->whereKey((int) $ref)->first()) {
                return $client;
            }
        }

        if ($customer = ($session['customer'] ?? null)) {
            return Client::query()->where('stripe_customer_ref', $customer)->first();
        }

        return null;
    }
}
