<?php

namespace App\Services\Billing\Gateways;

use App\Models\Billing\PlanPrice;
use App\Models\Client;
use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PayFast (Pakistan) — Avanza Premier Payment Services.
 *
 * Hosted checkout: we obtain a short-lived access token, sign a set of fields,
 * and POST the customer to PayFast's page. They choose their method there —
 * Visa, Mastercard, bank account, JazzCash, Easypaisa — which is the reason for
 * preferring hosted over their direct API. Every method they support is
 * available without us building a form per method, and no card number ever
 * reaches this server.
 *
 * WHAT IT CANNOT DO, and the design consequence:
 *
 * PayFast authorises ONE payment. There is no reusable card token, no
 * subscription object, no invoice API, no proration. So a monthly plan here is
 * not a mandate the gateway honours on our behalf — it is our own scheduled
 * charge, raised as a fresh checkout each period, which the customer completes.
 * That is a materially different lifecycle from Stripe's and it is owned by our
 * billing code, not faked in here. capabilities() says so out loud so no caller
 * can assume otherwise.
 *
 * TRUST BOUNDARY. Their flow returns the customer to a success URL. That
 * redirect is attacker-controlled — anyone can open it — so it is treated as a
 * hint that something happened and nothing more. verifyPayment() asks PayFast
 * directly, and only that answer may mark a payment as received.
 */
class PayFastGateway implements PaymentGateway
{
    public function __construct(private readonly Http $http = new Http())
    {
    }

    public function key(): string
    {
        return 'payfast';
    }

    public function label(): string
    {
        return 'Cards, bank account, JazzCash or Easypaisa';
    }

    public function isConfigured(): bool
    {
        return (string) $this->config('merchant_id') !== ''
            && (string) $this->config('secured_key') !== '';
    }

    /**
     * Deliberately short.
     *
     * Nothing recurring, nothing saved, no provider-side subscriptions, no
     * proration, no hosted invoices. Listing a capability here that PayFast does
     * not have would not make it work — it would move the failure to a renewal
     * six weeks later, where it is far more expensive to discover.
     */
    public function capabilities(): array
    {
        return [];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /** Settles in Pakistani rupees only. */
    public function currencies(): array
    {
        return ['PKR'];
    }

    /**
     * Build the signed hand-off to PayFast's hosted page.
     *
     * `$context` must carry success_url, failure_url, callback_url and a
     * basket_id — our own reference for this charge, which comes back on the
     * callback and is the only way to match their answer to our record.
     */
    public function startCheckout(Client $client, PlanPrice $price, array $context = []): CheckoutHandoff
    {
        $basketId = (string) ($context['basket_id'] ?? '');

        if ($basketId === '') {
            throw new \InvalidArgumentException('PayFast needs a basket_id to correlate the callback.');
        }

        $merchantId   = (string) $this->config('merchant_id');
        $merchantName = (string) $this->config('merchant_name');

        // PayFast quotes amounts in rupees, not the minor unit our prices are
        // stored in. Sending 7500 where 75.00 was meant would charge a hundred
        // times the price, so the conversion is explicit and happens once.
        $amount = number_format($price->unit_amount / 100, 2, '.', '');

        $fields = [
            'MERCHANT_ID'            => $merchantId,
            'MERCHANT_NAME'          => $merchantName,
            'TOKEN'                  => $this->accessToken(),
            'PROCCODE'               => '00',
            'TXNAMT'                 => $amount,
            'CUSTOMER_MOBILE_NO'     => (string) ($context['mobile'] ?? ''),
            'CUSTOMER_EMAIL_ADDRESS' => (string) ($context['email'] ?? $client->billing_email ?? ''),
            'SIGNATURE'              => $this->signature($merchantId, $merchantName, $amount, $basketId),
            'TXNDESC'                => mb_substr((string) ($context['description'] ?? 'Subscription'), 0, 100),
            'SUCCESS_URL'            => (string) ($context['success_url'] ?? ''),
            'FAILURE_URL'            => (string) ($context['failure_url'] ?? ''),
            'BASKET_ID'              => $basketId,
            'ORDER_DATE'             => now()->format('Y-m-d H:i:s'),
            'CHECKOUT_URL'           => (string) ($context['callback_url'] ?? ''),
            'CURRENCY_CODE'          => 'PKR',
        ];

        return CheckoutHandoff::post($this->endpoint('checkout'), $fields, $basketId);
    }

    /**
     * Ask PayFast what became of a payment.
     *
     * Returns PENDING rather than FAILED whenever we cannot get a definite
     * answer — an unreachable provider must never cancel a payment the customer
     * may have completed. Pending is retryable; failed, acted on, is not.
     */
    public function verifyPayment(string $reference, array $payload = []): PaymentResult
    {
        // Their callback carries the outcome, but it arrives via the customer's
        // browser, so it decides nothing on its own. It is recorded for support
        // and used only to shortcut a lookup we still perform.
        $claimed = (string) ($payload['err_code'] ?? $payload['ERR_CODE'] ?? '');

        try {
            $response = $this->http->post($this->endpoint('checkout'), [
                'form_params' => [
                    'MERCHANT_ID'    => (string) $this->config('merchant_id'),
                    'TOKEN'          => $this->accessToken(),
                    // 'IR' — inquiry. Asks for the status of one basket rather
                    // than starting a payment.
                    'PROCCODE'       => 'IR',
                    'BASKET_ID'      => $reference,
                ],
                'timeout' => (int) $this->config('timeout', 20),
            ]);

            $body = json_decode((string) $response->getBody(), true) ?: [];
        } catch (\Throwable $e) {
            Log::warning('payfast.verify_failed', ['basket' => $reference, 'error' => $e->getMessage()]);

            return PaymentResult::pending($reference, 'Could not reach PayFast to confirm.', ['claimed' => $claimed]);
        }

        // "00" is their success code, both on the token call and here.
        $code = (string) ($body['err_code'] ?? $body['ERR_CODE'] ?? $body['code'] ?? '');

        if ($code === '00') {
            $amount = (float) ($body['transaction_amount'] ?? $body['TXNAMT'] ?? 0);

            return PaymentResult::paid($reference, (int) round($amount * 100), 'PKR', $body);
        }

        if ($code === '') {
            return PaymentResult::pending($reference, 'PayFast returned no status code.', $body);
        }

        return PaymentResult::failed(
            $reference,
            (string) ($body['err_msg'] ?? $body['ERR_MSG'] ?? "PayFast returned {$code}"),
            $body,
        );
    }

    /**
     * A short-lived access token, fetched with the merchant credentials.
     *
     * Cached for seconds rather than kept: their token expires quickly, and a
     * stale one fails the checkout with an error the customer cannot act on.
     * Caching it at all only serves the case where one request builds a checkout
     * and immediately verifies something.
     */
    private function accessToken(): string
    {
        return Cache::remember(
            'payfast:token:' . md5((string) $this->config('merchant_id')),
            (int) $this->config('token_ttl', 60),
            function (): string {
                $response = $this->http->post($this->endpoint('token'), [
                    'form_params' => [
                        'MERCHANT_ID' => (string) $this->config('merchant_id'),
                        'SECURED_KEY' => (string) $this->config('secured_key'),
                        'BASKET_ID'   => 'TOKEN-' . now()->timestamp,
                        'TXNAMT'      => '1',
                    ],
                    'timeout' => (int) $this->config('timeout', 20),
                ]);

                $body = json_decode((string) $response->getBody(), true) ?: [];
                $token = (string) ($body['ACCESS_TOKEN'] ?? $body['token'] ?? '');

                if ($token === '') {
                    throw new \RuntimeException(
                        'PayFast did not return an access token: '
                        . ($body['err_msg'] ?? $body['ERR_MSG'] ?? 'no message')
                    );
                }

                return $token;
            }
        );
    }

    /**
     * The signature PayFast expects over the checkout fields.
     *
     * Their scheme is an MD5 of merchant id, merchant name, amount and basket
     * id. Worth stating plainly: that is weak, and it contains no secret — so it
     * proves the fields were not garbled, NOT that they came from us. It is
     * reproduced here because it is what their gateway accepts, and it is
     * precisely why verifyPayment() refuses to trust anything the callback says
     * and asks PayFast directly instead.
     */
    private function signature(string $merchantId, string $merchantName, string $amount, string $basketId): string
    {
        return md5($merchantId . ':' . $merchantName . ':' . $amount . ':' . $basketId);
    }

    private function endpoint(string $which): string
    {
        $env = $this->config('sandbox') ? 'sandbox' : 'live';

        return (string) config("billing.payfast.endpoints.{$env}.{$which}");
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("billing.payfast.{$key}", $default);
    }
}
