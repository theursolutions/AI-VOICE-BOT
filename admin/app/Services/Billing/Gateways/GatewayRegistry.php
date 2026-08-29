<?php

namespace App\Services\Billing\Gateways;

use App\Models\Client;

/**
 * Which payment provider serves a given customer.
 *
 * The rule is the customer's country, not ours: a business in Pakistan pays in
 * rupees through a Pakistani gateway that offers the methods they actually have
 * — bank account, JazzCash, Easypaisa, a local card — and everyone else goes to
 * Stripe. Offering a Karachi SME a Visa-only checkout is how a sale is lost to
 * a payment method rather than to a price.
 *
 * BOTH COEXIST, deliberately and permanently. This is not a migration from one
 * to the other with a switch to flip: PayFast cannot settle USD and Stripe
 * cannot take Easypaisa, so each is the only option for its own customers. When
 * a foreign entity exists and Stripe becomes available for international sales,
 * nothing here changes — Stripe was never removed.
 *
 * CONFIGURED, NOT MERELY REGISTERED. A gateway with no credentials is skipped
 * rather than chosen and failed at, so a half-finished PayFast setup cannot
 * break checkout for customers Stripe was already serving.
 */
class GatewayRegistry
{
    /**
     * Countries served by PayFast. ISO-3166 alpha-2.
     *
     * A list rather than a single value because a Pakistani gateway is the
     * right answer for a Pakistani customer regardless of where the business
     * itself is registered, and because this is the line most likely to need
     * editing when a second local provider is added.
     */
    private const LOCAL_COUNTRIES = ['PK'];

    /** Local providers, in preference order. First one with credentials wins. */
    private const LOCAL_GATEWAYS = ['safepay', 'payfast'];

    /** @var array<int, PaymentGateway> */
    private array $gateways;

    public function __construct(SafepayGateway $safepay, PayFastGateway $payfast, StripeGateway $stripe)
    {
        // Order is preference among the local options. Safepay first — it is
        // the one with credentials — and PayFast stays registered so switching
        // is a matter of which has keys, not a code change. The country test
        // below decides who is eligible at all; this only breaks ties.
        $this->gateways = [$safepay, $payfast, $stripe];
    }

    /**
     * The gateway that should take this customer's money.
     *
     * Falls back to any configured gateway rather than failing, because a
     * customer with an unrecognised country is still a customer — better a
     * checkout in the wrong currency they can decline than no checkout at all.
     */
    public function forClient(Client $client): ?PaymentGateway
    {
        $country = strtoupper((string) $client->billing_country);

        if (in_array($country, self::LOCAL_COUNTRIES, true)) {
            // First local gateway that actually has credentials. Listing them
            // rather than naming one means adding or swapping a Pakistani
            // provider is a line here, and a half-configured one is skipped
            // instead of chosen and failed at.
            foreach (self::LOCAL_GATEWAYS as $key) {
                $local = $this->get($key);

                if ($local?->isConfigured()) {
                    return $local;
                }
            }
        }

        $stripe = $this->get('stripe');

        if ($stripe?->isConfigured()) {
            return $stripe;
        }

        // Nothing preferred is usable — take whatever is.
        return $this->configured()[0] ?? null;
    }

    /** Currency the customer should be quoted in, given their gateway. */
    public function currencyFor(Client $client): string
    {
        $gateway = $this->forClient($client);
        $supported = $gateway?->currencies() ?? [];

        return $supported[0] ?? strtoupper((string) config('billing.currency', 'usd'));
    }

    public function get(string $key): ?PaymentGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->key() === $key) {
                return $gateway;
            }
        }

        return null;
    }

    /** @return array<int, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }

    /** @return array<int, PaymentGateway> */
    public function configured(): array
    {
        return array_values(array_filter($this->gateways, fn (PaymentGateway $g) => $g->isConfigured()));
    }

    /**
     * Does this customer's gateway bill them on its own, or must we?
     *
     * The question every part of the subscription lifecycle has to ask before
     * assuming a renewal will simply happen. On Stripe it will; on PayFast a
     * period ending means WE have to raise a fresh charge and ask the customer
     * to complete it.
     */
    public function billsRecurringItself(Client $client): bool
    {
        return (bool) $this->forClient($client)?->supports(PaymentGateway::CAP_RECURRING);
    }
}
