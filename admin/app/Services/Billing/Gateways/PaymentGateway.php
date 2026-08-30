<?php

namespace App\Services\Billing\Gateways;

use App\Models\Billing\PlanPrice;
use App\Models\Client;

/**
 * What the product needs from a payment provider.
 *
 * DELIBERATELY SMALL. The obvious move when adding a second gateway is to make
 * it implement the interface the first one already fits — but Stripe's surface
 * is subscriptions, saved-card tokens, prorated swaps, hosted invoices and a
 * customer portal, and PayFast has none of those. An interface shaped like
 * Stripe would force PayFast to fake five things it cannot do, and the faking
 * would fail at the worst moment: when a customer's renewal is due.
 *
 * So this covers only what BOTH can genuinely do — take one payment, and tell
 * us afterwards whether it worked — and everything richer is asked for through
 * capabilities() rather than assumed. The subscription LIFECYCLE (when to
 * charge, what a period is, what happens when a renewal fails) belongs to our
 * own billing code, which already owns plans, entitlements, usage and grants.
 * That is the part that must not differ between a customer in Karachi and one
 * in London.
 */
interface PaymentGateway
{
    /** Charge a saved credential on our schedule, with no customer present. */
    public const CAP_RECURRING = 'recurring';

    /** Store a reusable payment credential (card token, mandate). */
    public const CAP_SAVED_METHODS = 'saved_methods';

    /** The provider keeps its own subscription object and bills it. */
    public const CAP_PROVIDER_SUBSCRIPTIONS = 'provider_subscriptions';

    /** Mid-period plan changes settled by the provider. */
    public const CAP_PRORATION = 'proration';

    /** The provider hosts invoices/receipts we can link to. */
    public const CAP_HOSTED_INVOICES = 'hosted_invoices';

    /** Stable identifier, stored on every charge so a payment can be traced. */
    public function key(): string;

    /** Human name, for the customer's benefit on a checkout page. */
    public function label(): string;

    /** Credentials present and usable. */
    public function isConfigured(): bool;

    /**
     * @return array<int, string> the CAP_* constants this provider actually honours
     */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    /**
     * Currencies this gateway can settle. Empty means "anything".
     *
     * @return array<int, string> upper-case ISO codes
     */
    public function currencies(): array;

    /**
     * Begin a payment and hand back whatever the browser needs to continue.
     *
     * Returns a handoff rather than a URL because providers differ in kind, not
     * just in address: Stripe wants a redirect or an in-page confirmation,
     * PayFast wants a form POSTed to its host with a signature. Flattening both
     * to "a URL" would have forced one of them into a shape it does not take.
     *
     * @param  array<string, mixed>  $context  success/failure/callback URLs and
     *                                         anything the caller wants echoed back
     */
    public function startCheckout(Client $client, PlanPrice $price, array $context = []): CheckoutHandoff;

    /**
     * Establish, from the provider, what actually happened to a payment.
     *
     * Called with whatever reference the callback carried. It must ASK the
     * provider rather than believe the callback: a browser redirect is attacker
     * controlled, and treating its parameters as proof of payment is how a free
     * subscription gets granted by editing a query string.
     */
    public function verifyPayment(string $reference, array $payload = []): PaymentResult;
}
