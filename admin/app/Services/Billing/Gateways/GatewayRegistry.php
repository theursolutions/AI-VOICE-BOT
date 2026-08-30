<?php

namespace App\Services\Billing\Gateways;

use App\Models\Client;
use App\Support\Payments;

/**
 * Which payment provider serves a given customer.
 *
 * The rule is the customer's country, not ours. A business in Pakistan pays in
 * rupees through a Pakistani gateway that offers the methods they actually have
 * — bank account, JazzCash, Easypaisa, a local card — and everyone else goes to
 * Paddle. Offering a Karachi SME a Visa-only checkout is how a sale is lost to
 * a payment method rather than to a price.
 *
 * THE COUNTRY IS THE CUSTOMER'S TO SET. It is seeded from their IP, but they
 * can change it, and their choice is what is stored on the workspace. A VPN, a
 * business registered somewhere other than where its founder sits, and a plain
 * wrong guess are all ordinary — and each one would otherwise route somebody to
 * a gateway that cannot take their money, with no way to correct it.
 *
 * THREE PROVIDERS COEXIST, and the set is an operator switch rather than a
 * deploy. Safepay settles PKR and takes Easypaisa; Paddle is Merchant of Record
 * everywhere else and carries the tax; Stripe is registered and waiting for a
 * foreign entity. When Stripe becomes available, turning the other two off in
 * Ops → Payments is the whole migration — no code changes, and nothing was
 * deleted to get there.
 *
 * A GATEWAY MUST BE BOTH ENABLED AND CONFIGURED. Enabled is permission and
 * configured is capability; a provider missing either is skipped rather than
 * chosen and failed at, so a half-finished Paddle setup cannot break checkout
 * for customers Safepay was already serving.
 */
class GatewayRegistry
{
    /** @var array<int, PaymentGateway> */
    private array $gateways;

    public function __construct(
        SafepayGateway $safepay,
        PayFastGateway $payfast,
        PaddleGateway $paddle,
        StripeGateway $stripe,
    ) {
        $this->gateways = [$safepay, $payfast, $paddle, $stripe];
    }

    /**
     * The gateway that should take this customer's money.
     *
     * Falls back to any usable gateway rather than failing, because a customer
     * with an unrecognised country is still a customer — better a checkout in
     * the wrong currency they can decline than no checkout at all.
     */
    public function forClient(Client $client): ?PaymentGateway
    {
        return $this->forCountry($client->billing_country);
    }

    /**
     * The gateway for a country, independent of any workspace.
     *
     * Separate from forClient() because the pricing page has to answer "what
     * would I pay from Germany" for a visitor with no workspace at all — and
     * because the country picker changes the answer before anything is saved.
     */
    public function forCountry(?string $country): ?PaymentGateway
    {
        return $this->availableFor($country)[0] ?? null;
    }

    /**
     * EVERY gateway a customer in this country may choose between.
     *
     * A country is not served by one provider. A Pakistani customer might want
     * Easypaisa and rupees, or might have an international card and prefer to
     * pay in dollars — and which of those suits them is not something an IP
     * address knows. So both are offered and the customer decides; the ORDER is
     * the recommendation, not the rule.
     *
     * Local providers come first where they exist, because they carry the
     * methods most people in that country actually hold. International ones
     * follow, and are the whole list everywhere else.
     *
     * @return array<int, PaymentGateway> usable only, in preference order
     */
    public function availableFor(?string $country): array
    {
        $country = strtoupper(trim((string) $country));

        $out = [];

        // EVERY usable local provider for this country. They are genuinely
        // different from one another — Safepay carries Easypaisa, PayFast
        // carries a different set — so a customer benefits from the choice.
        foreach (Payments::LOCAL_COUNTRIES[$country] ?? [] as $key) {
            $gateway = $this->get($key);

            if ($gateway && $this->isUsable($gateway)) {
                $out[$key] = $gateway;
            }
        }

        // But only ONE international provider. Paddle and Stripe are the same
        // experience to a customer — a card, in dollars — so offering both asks
        // them to pick between two things they cannot tell apart. Which one is
        // an operator's decision, made in Ops → Payments, not theirs.
        foreach (Payments::INTERNATIONAL as $key) {
            $gateway = $this->get($key);

            if ($gateway && $this->isUsable($gateway)) {
                $out[$key] = $gateway;
                break;
            }
        }

        if ($out !== []) {
            return array_values($out);
        }

        // Nothing preferred is usable. A country we do not normally serve still
        // gets SOMETHING, because a checkout with no way to pay is worse than
        // one in a currency they can decline.
        return $this->usable();
    }

    /** Is this specific gateway one the customer is allowed to choose? */
    public function isAvailableFor(?string $country, string $key): bool
    {
        foreach ($this->availableFor($country) as $gateway) {
            if ($gateway->key() === $key) {
                return true;
            }
        }

        return false;
    }

    /** Enabled by an operator AND holding credentials. Both, or it is skipped. */
    public function isUsable(PaymentGateway $gateway): bool
    {
        return Payments::gatewayEnabled($gateway->key()) && $gateway->isConfigured();
    }

    /** Currency the customer should be quoted in, given their gateway. */
    public function currencyFor(Client $client): string
    {
        return $this->currencyForCountry($client->billing_country);
    }

    public function currencyForCountry(?string $country): string
    {
        $supported = $this->forCountry($country)?->currencies() ?? [];

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

    /** Everything holding credentials, whether or not an operator enabled it. */
    public function configured(): array
    {
        return array_values(array_filter($this->gateways, fn (PaymentGateway $g) => $g->isConfigured()));
    }

    /** Everything that could actually take a payment right now. */
    public function usable(): array
    {
        return array_values(array_filter($this->gateways, fn (PaymentGateway $g) => $this->isUsable($g)));
    }

    /**
     * Does this customer's gateway bill them on its own, or must we?
     *
     * The question every part of the subscription lifecycle has to ask before
     * assuming a renewal will simply happen. On Paddle and Stripe it will; on
     * Safepay a period ending means WE have to raise a fresh charge and ask the
     * customer to complete it, which is what the renewal notices exist for.
     */
    public function billsRecurringItself(Client $client): bool
    {
        return (bool) $this->forClient($client)?->supports(PaymentGateway::CAP_RECURRING);
    }
}
