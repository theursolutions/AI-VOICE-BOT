<?php

namespace App\Services\Billing\Gateways;

use App\Models\Billing\PlanPrice;
use App\Models\Client;
use App\Services\Billing\BillingService;
use App\Services\Billing\StripeClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Stripe, behind the same contract as PayFast.
 *
 * A thin adapter over BillingService rather than a reimplementation. Everything
 * Stripe-shaped — subscriptions, saved cards, prorated swaps, hosted invoices —
 * already works and is used directly by the billing pages; this exists so code
 * that must serve BOTH providers can ask one question and get an answer that is
 * true for whichever is in play.
 *
 * So there are deliberately two ways into Stripe in this codebase. The rich one
 * (BillingService) is used where Stripe is known to be the provider, and this
 * one where it might not be. Collapsing them would mean either losing Stripe's
 * capabilities or pretending PayFast has them.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(
        private readonly StripeClientFactory $factory,
        private readonly BillingService $billing,
    ) {
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Card';
    }

    public function isConfigured(): bool
    {
        return $this->factory->isConfigured();
    }

    /** Everything the contract knows how to ask for. */
    public function capabilities(): array
    {
        return [
            self::CAP_RECURRING,
            self::CAP_SAVED_METHODS,
            self::CAP_PROVIDER_SUBSCRIPTIONS,
            self::CAP_PRORATION,
            self::CAP_HOSTED_INVOICES,
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /** Empty means no restriction — Stripe settles what the account is set up for. */
    public function currencies(): array
    {
        return [];
    }

    /**
     * Stripe's own checkout is a redirect, but this install completes payments
     * in-page through Elements (see billing.checkout.in_app_only), so the
     * handoff points at our own form rather than at Stripe.
     */
    public function startCheckout(Client $client, PlanPrice $price, array $context = []): CheckoutHandoff
    {
        $plan = $price->plan;

        return CheckoutHandoff::redirect(
            route('billing.checkout', [
                'client'   => $client->slug,
                'plan'     => $plan?->slug,
                'interval' => $price->interval,
            ]),
            $context['basket_id'] ?? null,
        );
    }

    /**
     * Confirm a payment from Stripe itself.
     *
     * Accepts either a PaymentIntent or a Checkout Session id, because both
     * appear depending on which path the customer took, and a caller holding a
     * reference should not have to know which kind it is.
     */
    public function verifyPayment(string $reference, array $payload = []): PaymentResult
    {
        if (! $this->isConfigured()) {
            return PaymentResult::pending($reference, 'Stripe is not configured.');
        }

        try {
            $stripe = $this->factory->make();

            if (str_starts_with($reference, 'cs_')) {
                $session = $stripe->checkout->sessions->retrieve($reference, []);

                return ($session->payment_status ?? '') === 'paid'
                    ? PaymentResult::paid(
                        $reference,
                        (int) ($session->amount_total ?? 0),
                        strtoupper((string) ($session->currency ?? 'usd')),
                        $session->toArray(),
                    )
                    : PaymentResult::pending($reference, (string) ($session->payment_status ?? ''), $session->toArray());
            }

            $intent = $stripe->paymentIntents->retrieve($reference, []);

            return ($intent->status ?? '') === 'succeeded'
                ? PaymentResult::paid(
                    $reference,
                    (int) ($intent->amount_received ?? $intent->amount ?? 0),
                    strtoupper((string) ($intent->currency ?? 'usd')),
                    $intent->toArray(),
                )
                : PaymentResult::pending($reference, (string) ($intent->status ?? ''), $intent->toArray());
        } catch (\Throwable $e) {
            // Pending, not failed: an API error tells us nothing about whether
            // the customer's money moved, and cancelling on that basis would be
            // guessing with someone else's subscription.
            Log::warning('stripe.verify_failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            return PaymentResult::pending($reference, 'Could not reach Stripe to confirm.');
        }
    }
}
