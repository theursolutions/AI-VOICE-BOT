<?php

namespace App\Services\Billing\Gateways;

use App\Models\Billing\PlanPrice;
use App\Models\Client;
use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\Log;

/**
 * Safepay (Pakistan) — State Bank regulated, Y Combinator backed.
 *
 * Hosted checkout in three steps: ask Safepay for a payment session (a
 * "tracker"), build a checkout URL around it, send the customer there. They pay
 * by card, bank account, JazzCash or Easypaisa on Safepay's page, so every
 * method works without a form per method and no card number touches this
 * server.
 *
 * IMPLEMENTED AGAINST THE HTTP API RATHER THAN THEIR SDK, only because Composer
 * cannot reach GitHub from this environment. The endpoints are configuration
 * for the same reason the amount unit is: they are the part most likely to have
 * moved since the docs were read, and `safepay:doctor` exercises them against
 * the sandbox rather than trusting them.
 *
 * THE SIGNATURE IS REAL, unlike PayFast's. Safepay returns the customer with a
 * `tracker` and a `sig`, where sig is HMAC-SHA256 of the tracker under the v1
 * secret — a value only the two of us can compute. So unlike a gateway whose
 * "signature" contains no secret, verifying the redirect here genuinely proves
 * Safepay produced it. The webhook is signed separately, under a different
 * secret, in the X-SFPY-SIGNATURE header.
 *
 * RECURRING IS NOT CLAIMED. Safepay markets subscription support, but this
 * class will not assert a capability it has not demonstrated: claiming it makes
 * the renewal notices stop, and the failure would surface as customers
 * quietly lapsing. Once a sandbox run proves a saved instrument can be charged
 * without the customer present, add CAP_RECURRING here — one line, and the
 * notices stand down on their own.
 */
class SafepayGateway implements PaymentGateway
{
    public function __construct(private readonly Http $http = new Http())
    {
    }

    public function key(): string
    {
        return 'safepay';
    }

    public function label(): string
    {
        return 'Card, bank account, JazzCash or Easypaisa';
    }

    public function isConfigured(): bool
    {
        return (string) $this->config('api_key') !== ''
            && (string) $this->config('v1_secret') !== '';
    }

    /** See the class note: nothing is claimed until a sandbox run proves it. */
    public function capabilities(): array
    {
        return [];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function currencies(): array
    {
        return ['PKR'];
    }

    /**
     * Create a session and hand back the URL to send the customer to.
     *
     * `$context` must carry basket_id (our correlation id), success_url and
     * cancel_url. The basket id comes back on the redirect and is the only way
     * to match Safepay's answer to our own charge record.
     */
    public function startCheckout(Client $client, PlanPrice $price, array $context = []): CheckoutHandoff
    {
        $orderId = (string) ($context['basket_id'] ?? '');

        if ($orderId === '') {
            throw new \InvalidArgumentException('Safepay needs a basket_id to correlate the redirect.');
        }

        $tracker = $this->createSession($price);

        $url = rtrim($this->baseUrl(), '/')
            . (string) $this->config('paths.checkout', '/embedded/')
            . '?' . http_build_query([
                'tracker'     => $tracker,
                'env'         => $this->config('sandbox') ? 'sandbox' : 'production',
                'source'      => 'custom',
                'order_id'    => $orderId,
                // Their flow POSTs back to this URL with tracker + sig.
                'redirect_url' => (string) ($context['success_url'] ?? ''),
                'cancel_url'   => (string) ($context['cancel_url'] ?? ''),
            ]);

        return CheckoutHandoff::redirect($url, $orderId);
    }

    /**
     * Ask Safepay for a payment session and return its tracker.
     *
     * @throws \RuntimeException when no tracker comes back — a checkout built
     *         around an empty tracker would send the customer to a broken page
     *         rather than fail here where the reason is visible.
     */
    private function createSession(PlanPrice $price): string
    {
        $response = $this->http->post(
            rtrim($this->baseUrl(), '/') . (string) $this->config('paths.session', '/order/v1/init'),
            [
                'json' => [
                    'client'      => (string) $this->config('api_key'),
                    'amount'      => $this->amountFor($price),
                    'currency'    => 'PKR',
                    'environment' => $this->config('sandbox') ? 'sandbox' : 'production',
                ],
                'timeout' => (int) $this->config('timeout', 20),
            ],
        );

        $body = json_decode((string) $response->getBody(), true) ?: [];

        // Their envelope has moved between versions; accept the shapes seen
        // rather than assuming one, and fail loudly if none matches.
        $tracker = (string) (
            data_get($body, 'data.token')
            ?? data_get($body, 'token')
            ?? data_get($body, 'data.tracker')
            ?? ''
        );

        if ($tracker === '') {
            throw new \RuntimeException(
                'Safepay returned no tracker for the payment session: '
                . mb_substr(json_encode($body) ?: '', 0, 300)
            );
        }

        return $tracker;
    }

    /**
     * The amount, in whatever unit Safepay is configured to expect.
     *
     * Prices are stored in minor units, so Rs 75 is 7500. Sending that where
     * rupees are expected charges a hundred times the price — which is why this
     * is one explicit conversion in one place rather than arithmetic scattered
     * through the caller.
     */
    private function amountFor(PlanPrice $price): int
    {
        return $this->config('amount_unit', 'rupees') === 'paisa'
            ? (int) $price->unit_amount
            : (int) round($price->unit_amount / 100);
    }

    /**
     * Confirm a payment.
     *
     * Safepay's redirect POSTs back `tracker` and `sig`, and unlike a gateway
     * whose signature carries no secret, that HMAC genuinely proves Safepay
     * produced it — so here the signature IS the verification, and a valid one
     * is accepted.
     *
     * A missing or wrong signature returns PENDING rather than FAILED. It may
     * be a forgery, but it may equally be a truncated POST or a customer who
     * refreshed, and cancelling a subscription on that basis would be guessing
     * with someone else's money. Pending is reconcilable; a cancelled
     * subscription is not.
     */
    public function verifyPayment(string $reference, array $payload = []): PaymentResult
    {
        $tracker   = (string) ($payload['tracker'] ?? $reference);
        $signature = (string) ($payload['sig'] ?? $payload['signature'] ?? '');

        if ($signature === '') {
            return PaymentResult::pending($reference, 'Safepay sent no signature to verify.', $payload);
        }

        if (! $this->signatureValid($tracker, $signature)) {
            Log::warning('safepay.signature_mismatch', ['tracker' => $tracker, 'order' => $reference]);

            return PaymentResult::pending($reference, 'Signature did not match.', $payload);
        }

        return PaymentResult::paid(
            $reference,
            (int) ($payload['amount_cents'] ?? 0),
            'PKR',
            $payload + ['tracker' => $tracker],
        );
    }

    /** HMAC-SHA256 of the tracker under the v1 secret. */
    public function signatureValid(string $tracker, string $signature): bool
    {
        $secret = (string) $this->config('v1_secret');

        if ($secret === '' || $tracker === '') {
            return false;
        }

        // hash_equals, not ===: a timing-safe comparison is the whole point of
        // checking a MAC, and a plain compare leaks the answer a byte at a time.
        return hash_equals(hash_hmac('sha256', $tracker, $secret), $signature);
    }

    /**
     * Verify a webhook, which is signed under a DIFFERENT secret and over the
     * whole raw body rather than the tracker alone.
     *
     * The raw body matters: re-encoding a decoded payload changes key order and
     * whitespace, and the MAC is over the bytes that arrived.
     */
    public function webhookValid(string $rawBody, string $signature): bool
    {
        $secret = (string) $this->config('webhook_secret');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function baseUrl(): string
    {
        return (string) $this->config(
            $this->config('sandbox') ? 'base_url.sandbox' : 'base_url.production'
        );
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("billing.safepay.{$key}", $default);
    }
}
