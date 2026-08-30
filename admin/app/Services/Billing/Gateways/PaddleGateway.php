<?php

namespace App\Services\Billing\Gateways;

use App\Models\Billing\PlanPrice;
use App\Models\Client;
use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\Log;

/**
 * Paddle Billing — everywhere except Pakistan.
 *
 * MERCHANT OF RECORD, which is the reason it is here rather than a processor.
 * Paddle sells to the customer in its own name and pays us, so Paddle carries
 * the VAT, GST and sales-tax obligation in every country it sells into and
 * issues the invoice. A business with no foreign entity cannot register for tax
 * in forty jurisdictions; this removes the need to. It is also why "tax
 * included" on the pricing page is literally true here.
 *
 * THE OVERLAY IS THE POINT. Paddle draws its checkout on the page the customer
 * is already on — no redirect, no third-party page, no lost session. That is
 * what the product requires ("everything should be done in our application"),
 * and it is why this returns an OVERLAY handoff rather than a URL.
 *
 * THE AMOUNT IS NEVER SENT. A transaction is created against a Paddle PRICE ID,
 * and the price lives in Paddle. So there is no field in this integration that
 * carries money — not in the request, not in the response, and above all not in
 * the browser, which only ever receives a transaction id. Tampering has nothing
 * to act on. The cost is that every sellable price must exist in Paddle first;
 * `paddle:doctor` is what tells you which do not.
 *
 * TWO CREDENTIALS, NOT ONE. `api_key` is secret and server-side; `client_token`
 * is public and belongs in HTML. They are separate config keys precisely so the
 * secret one cannot be rendered by accident — an API key in a page source would
 * let anyone create transactions and read every customer.
 */
class PaddleGateway implements PaymentGateway
{
    public function __construct(private readonly ?Http $http = null)
    {
    }

    public function key(): string
    {
        return 'paddle';
    }

    public function label(): string
    {
        return 'Card, PayPal, Apple Pay or Google Pay';
    }

    /**
     * Both credentials, not either.
     *
     * A server key with no client token creates transactions that no browser
     * can open; a client token with no server key draws an overlay for a
     * transaction that was never created. Half-configured is not usable, and
     * reporting it as such is what keeps a customer from meeting it.
     */
    public function isConfigured(): bool
    {
        return (string) $this->config('api_key') !== ''
            && (string) $this->config('client_token') !== '';
    }

    /**
     * Claimed because they are implemented, not because Paddle markets them.
     *
     * Paddle bills recurring prices itself and sends a transaction webhook for
     * every renewal, which PaddleWebhookController acts on — so CAP_RECURRING
     * is honest here in a way it deliberately is not on Safepay, and the
     * renewal notices correctly stand down for these customers.
     */
    public function capabilities(): array
    {
        return [
            self::CAP_RECURRING,
            self::CAP_PROVIDER_SUBSCRIPTIONS,
            self::CAP_SAVED_METHODS,
            self::CAP_HOSTED_INVOICES,
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * USD, because that is what the platform prices in and what Paddle is told
     * to charge.
     *
     * Paddle can present a local currency to the buyer, but the price object we
     * point at is the authority on what is collected — so quoting anything else
     * here would make the checkout page promise a currency the invoice does not
     * use.
     */
    public function currencies(): array
    {
        return [strtoupper((string) config('billing.currency', 'usd'))];
    }

    // ── Checkout ─────────────────────────────────────────────────────

    /**
     * Create a transaction and hand the browser its id.
     *
     * `custom_data` carries our own reference back on every webhook about this
     * transaction, which is what ties Paddle's answer to our charge row. It is
     * the same job the Safepay order id does, and it matters more here because
     * Paddle will send renewals we never initiated.
     */
    public function startCheckout(Client $client, PlanPrice $price, array $context = []): CheckoutHandoff
    {
        $reference = (string) ($context['basket_id'] ?? '');

        if ($reference === '') {
            throw new \InvalidArgumentException('Paddle needs a basket_id to correlate the webhook.');
        }

        $paddlePriceId = (string) $price->paddle_price_id;

        if ($paddlePriceId === '' && ($context['amount_override'] ?? null) === null) {
            // Deliberately loud and specific. The alternative — creating a
            // price on the fly — would mint a duplicate Paddle object on every
            // checkout and leave the catalogue unreconcilable.
            throw new \RuntimeException(sprintf(
                'The %s %s price has no Paddle price id. Create it in Paddle and set it on the plan, '
                . 'or run `php artisan paddle:doctor` to see which prices are missing one.',
                $price->plan?->name ?? 'plan',
                $price->interval,
            ));
        }

        // A ONE-OFF amount, when the caller has one — a prorated top-up, whose
        // figure exists only for this purchase and cannot be a catalogue price.
        // Paddle calls these non-catalog items: an inline price object with
        // `billing_cycle: null`, which bills once and never recurs. That is the
        // whole difference between a top-up and a subscription line.
        $override = $context['amount_override'] ?? null;

        $item = $override !== null
            ? [
                'quantity' => 1,
                'price'    => [
                    'description'   => (string) ($context['description'] ?? 'Additional capacity'),
                    'product_id'    => $this->productIdFor($price),
                    'unit_price'    => [
                        // A STRING in minor units, as everywhere in this API.
                        'amount'        => (string) (int) $override,
                        'currency_code' => strtoupper((string) ($price->currency ?: 'USD')),
                    ],
                    // Null is what makes it one-time. Omitting it would inherit
                    // a recurring cycle and bill the top-up every month.
                    'billing_cycle' => null,
                    'tax_mode'      => app(\App\Services\Billing\TaxService::class)->paddleTaxMode(),
                ],
            ]
            : ['price_id' => $paddlePriceId, 'quantity' => 1];

        $body = [
            'items' => [$item],
            'collection_mode' => 'automatic',
            'custom_data'     => [
                'reference'     => $reference,
                'client_id'     => (string) $client->id,
                'plan_price_id' => (string) $price->id,
            ],
        ];

        // An existing Paddle customer keeps their saved cards and address, so a
        // second purchase is one click. A first purchase has none, and Paddle
        // collects the email itself — sending an empty customer object would be
        // rejected, so it is omitted entirely rather than sent blank.
        if ($customerId = $this->customerIdFor($client)) {
            $body['customer_id'] = $customerId;
        }

        $response = $this->request('POST', '/transactions', $body);

        $transactionId = (string) data_get($response, 'data.id', '');

        if ($transactionId === '') {
            throw new \RuntimeException(
                'Paddle returned no transaction id: ' . mb_substr(json_encode($response) ?: '', 0, 300)
            );
        }

        return CheckoutHandoff::overlay($transactionId, [
            // Public token only. The API key must never reach this array — it
            // is rendered into the page.
            'token'       => (string) $this->config('client_token'),
            'environment' => $this->config('sandbox') ? 'sandbox' : 'production',
            'js'          => (string) $this->config('js_url'),
        ]);
    }

    /**
     * Ask Paddle what became of a transaction.
     *
     * ASKED, not believed. The overlay tells the browser it succeeded and the
     * browser tells us — but the browser is the customer's, and a checkout
     * "completed" event is a line of JavaScript anyone can fire. Only Paddle's
     * own answer decides.
     */
    public function verifyPayment(string $reference, array $payload = []): PaymentResult
    {
        $transactionId = (string) ($payload['transaction_id'] ?? $reference);

        if (! str_starts_with($transactionId, 'txn_')) {
            return PaymentResult::pending($reference, 'No Paddle transaction id to check.', $payload);
        }

        try {
            $response = $this->request('GET', '/transactions/' . $transactionId);
        } catch (\Throwable $e) {
            // Unreachable is not unpaid. Cancelling a subscription because an
            // API call timed out would be guessing with someone else's money.
            Log::warning('paddle.verify_failed', ['transaction' => $transactionId, 'error' => $e->getMessage()]);

            return PaymentResult::pending($reference, 'Could not reach Paddle to confirm.', $payload);
        }

        $status = (string) data_get($response, 'data.status', '');

        if (! in_array($status, ['completed', 'paid'], true)) {
            return PaymentResult::pending($reference, "Paddle reports the transaction as {$status}.", $payload);
        }

        return PaymentResult::paid(
            $reference,
            (int) data_get($response, 'data.details.totals.grand_total', 0),
            strtoupper((string) data_get($response, 'data.currency_code', 'USD')),
            $payload + ['transaction_id' => $transactionId],
        );
    }

    // ── Subscriptions ────────────────────────────────────────────────

    /** Read a subscription, including its current items. */
    public function subscription(string $subscriptionId): array
    {
        return (array) data_get($this->request('GET', '/subscriptions/' . $subscriptionId), 'data', []);
    }

    /**
     * Change what is on a subscription, prorated immediately.
     *
     * THE ITEMS ARRAY REPLACES EVERYTHING. Paddle removes any item not present
     * in the request, so sending just the add-on would cancel the customer's
     * plan and leave them paying for two seats and nothing to use them on. That
     * is the single most dangerous call in this integration, which is why the
     * complete list is assembled from what Paddle currently holds rather than
     * from what we think it holds.
     *
     * @param  array<int, array{price_id: string, quantity: int}>  $items  the COMPLETE list
     */
    public function updateSubscriptionItems(string $subscriptionId, array $items, string $proration = 'prorated_immediately'): array
    {
        if ($items === []) {
            // Paddle would read this as "remove everything". Refused here rather
            // than sent, because the API accepts it and the damage is a
            // cancelled subscription.
            throw new \InvalidArgumentException(
                'Refusing to send an empty item list to Paddle — it would strip the subscription bare.'
            );
        }

        foreach ($items as $item) {
            if (empty($item['price_id'])) {
                throw new \InvalidArgumentException('Every Paddle subscription item needs a price_id.');
            }
        }

        return (array) data_get($this->request('PATCH', '/subscriptions/' . $subscriptionId, [
            'items'                 => array_values($items),
            'proration_billing_mode'=> $proration,
        ]), 'data', []);
    }

    /**
     * The subscription's items as `[price_id => quantity]`.
     *
     * Read from Paddle rather than from our own tables: Paddle is the authority
     * on what it will bill, and a discrepancy — a change made in their portal,
     * a failed write of ours — must not be propagated into a replacement list
     * that then deletes whatever we did not know about.
     *
     * @return array<string, int>
     */
    public function subscriptionItems(string $subscriptionId): array
    {
        $out = [];

        foreach ((array) data_get($this->subscription($subscriptionId), 'items', []) as $item) {
            $priceId = (string) data_get($item, 'price.id', data_get($item, 'price_id', ''));

            if ($priceId === '') {
                continue;
            }

            // A scheduled-for-removal item is not something to carry forward.
            if (data_get($item, 'status') === 'inactive') {
                continue;
            }

            $out[$priceId] = (int) (data_get($item, 'quantity') ?? 1);
        }

        return $out;
    }

    // ── Webhooks ─────────────────────────────────────────────────────

    /**
     * Verify a `Paddle-Signature` header.
     *
     * Format is `ts=<unix>;h1=<hex>`, and the MAC is HMAC-SHA256 over
     * `"{ts}:{raw body}"` under the notification secret. The raw body matters:
     * decoding and re-encoding changes key order and whitespace, and the
     * signature covers the bytes that arrived.
     *
     * The timestamp is checked so a captured delivery cannot be replayed a week
     * later — but with a WIDE window. Paddle's documentation suggests five
     * seconds; copying that would reject every retry, because a retry is re-sent
     * carrying the ORIGINAL timestamp and signature. A five-second tolerance
     * therefore fails exactly when it is needed most.
     */
    public function webhookValid(string $rawBody, string $signatureHeader): bool
    {
        $secret = (string) $this->config('webhook_secret');

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        [$ts, $h1] = $this->parseSignature($signatureHeader);

        if ($ts === null || $h1 === '') {
            return false;
        }

        $tolerance = (int) $this->config('signature_tolerance', 86400);

        if ($tolerance > 0 && abs(time() - $ts) > $tolerance) {
            Log::warning('paddle.webhook.stale_signature', ['ts' => $ts, 'age' => time() - $ts]);

            return false;
        }

        $expected = hash_hmac('sha256', $ts . ':' . $rawBody, $secret);

        // hash_equals, not ===: comparing a MAC byte-by-byte leaks it.
        return hash_equals($expected, $h1);
    }

    /**
     * Pull `ts` and `h1` out of the header.
     *
     * @return array{0: ?int, 1: string}
     */
    private function parseSignature(string $header): array
    {
        $ts = null;
        $h1 = '';

        foreach (explode(';', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$name, $value] = $pair;

            if ($name === 'ts' && ctype_digit($value)) {
                $ts = (int) $value;
            } elseif ($name === 'h1') {
                $h1 = $value;
            }
        }

        return [$ts, $h1];
    }

    // ── HTTP ─────────────────────────────────────────────────────────

    /**
     * One request to Paddle, decoded.
     *
     * @throws \RuntimeException carrying Paddle's own error text, which names
     *         the offending field — far more use than "request failed".
     */
    public function request(string $method, string $path, array $body = []): array
    {
        $http = $this->http ?? new Http();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config('api_key'),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout'     => (int) $this->config('timeout', 20),
            'http_errors' => false,
        ];

        if ($body !== []) {
            $options['json'] = $body;
        }

        $response = $http->request($method, rtrim($this->baseUrl(), '/') . $path, $options);

        $decoded = json_decode((string) $response->getBody(), true) ?: [];
        $status  = $response->getStatusCode();

        if ($status >= 400) {
            // Paddle puts the USEFUL part in `error.errors[]` — the field and
            // why it was rejected. `error.detail` alone is often just "Invalid
            // request.", which names nothing and sends you reading docs instead
            // of reading the answer you were already given.
            $fields = collect((array) data_get($decoded, 'error.errors', []))
                ->map(fn ($e) => trim((data_get($e, 'field') ?? '') . ' ' . (data_get($e, 'message') ?? '')))
                ->filter()
                ->implode('; ');

            $detail = data_get($decoded, 'error.detail')
                ?? data_get($decoded, 'error.code')
                ?? mb_substr((string) $response->getBody(), 0, 300);

            throw new \RuntimeException(
                "Paddle {$method} {$path} failed ({$status}): {$detail}"
                . ($fields !== '' ? ' — ' . $fields : '')
            );
        }

        return $decoded;
    }

    public function baseUrl(): string
    {
        return (string) $this->config(
            $this->config('sandbox') ? 'base_url.sandbox' : 'base_url.production'
        );
    }

    /** The public token the overlay needs. Safe to render; the API key is not. */
    public function clientToken(): string
    {
        return (string) $this->config('client_token');
    }

    public function environment(): string
    {
        return $this->config('sandbox') ? 'sandbox' : 'production';
    }

    /**
     * The Paddle product a non-catalog price should belong to.
     *
     * Reusing the plan's own product keeps a top-up on the same line of the
     * customer's invoice history as the thing it tops up, rather than creating
     * a fresh product per purchase and littering the catalogue.
     */
    private function productIdFor(PlanPrice $price): ?string
    {
        $productId = data_get($price->plan?->metadata, 'paddle_product_id');

        return $productId ? (string) $productId : null;
    }

    /** Paddle's id for this workspace's customer, if we have ever seen one. */
    private function customerIdFor(Client $client): ?string
    {
        $id = $client->currentSubscription()?->paddle_customer_id;

        return $id ? (string) $id : null;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("billing.paddle.{$key}", $default);
    }
}
