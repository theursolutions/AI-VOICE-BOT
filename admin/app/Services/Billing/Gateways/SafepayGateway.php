<?php

namespace App\Services\Billing\Gateways;

use App\Models\Billing\PlanPrice;
use App\Models\Client;
use GuzzleHttp\Client as Http;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Safepay (Pakistan) — Payments 2.0 hosted checkout.
 *
 * Three calls and a redirect: open a payment session (a "tracker") for the
 * amount, attach our order reference to it, mint a time-based token (TBT) that
 * lets Safepay's page act on that session, then send the customer to
 * `/embedded` carrying both. They pay on Safepay's page, so no card number
 * touches this server. A fourth call, to the reporter API, confirms payment.
 *
 * FOLLOWS THE OFFICIAL SDK (getsafepay/sfpy-php) CALL FOR CALL — same paths,
 * same X-SFPY-MERCHANT-SECRET header, same bodies, same checkout URL — but over
 * our own Guzzle client rather than the package. The SDK's HTTP client is a
 * private static curl singleton with no way to substitute it, so every test
 * that opened a checkout would reach Safepay's servers; it is also a 0.0.x
 * release whose URL builder silently drops `user_id` and whose webhook sample
 * mis-verifies any payload containing an empty object. Every path and response
 * shape below was checked against the sandbox, not copied from prose.
 *
 * WHAT 2.0 CHANGED, each of which fails quietly if missed:
 *
 *   • AMOUNTS ARE PAISA. The v1 endpoint took whole rupees; 2.0 takes minor
 *     units. Proven on the sandbox: a session for 7500 renders "Rs.75.00". Our
 *     prices are already stored in minor units, so they now go out unchanged —
 *     and the old divide-by-100 would charge Rs 0.75 for a Rs 75 plan.
 *   • AUTH IS THE SECRET KEY IN A HEADER. v1 sent only the public key in the
 *     body, which let anyone open a session on our account for any amount.
 *   • THE REDIRECT IS NOT SIGNED. v1 returned `tracker` + `sig`; 2.0 documents
 *     no signature on the return at all. So the return is treated as a hint
 *     and the payment confirmed by asking Safepay (the reporter API) — which
 *     is what PaymentGateway::verifyPayment() demands anyway.
 *   • WEBHOOKS SIGN THE WHOLE EVENT (HMAC-SHA512), not just its `data`. The v1
 *     scheme left `type` unsigned; checking only that would reject every
 *     genuine 2.0 delivery as a forgery.
 *
 * RECURRING IS NOT CLAIMED. 2.0 does have saved instruments and native
 * subscriptions, but this class will not assert a capability it has not
 * demonstrated: claiming it stands the renewal notices down, and a failure
 * would surface as customers quietly lapsing. Once a sandbox run proves a saved
 * instrument can be charged without the customer present, add CAP_RECURRING.
 */
class SafepayGateway implements PaymentGateway
{
    // The SDK's paths (OrderService, PassportService, ReporterService). Fixed
    // rather than read from .env: a stale override left over from v1 would
    // otherwise send 2.0 traffic to a v1 endpoint, and fail as "bad tracker".
    private const PATH_SESSION  = '/order/payments/v3/';
    private const PATH_METADATA = '/order/payments/v3/%s/metadata';
    private const PATH_PASSPORT = '/client/passport/v1/token';
    private const PATH_PAYMENT  = '/reporter/api/v2/payments/%s';

    /** The tracker state once the money has been captured. */
    public const STATE_PAID = 'TRACKER_ENDED';

    /** A webhook signed over the whole event — 2.0. Its contents can be believed. */
    public const SIGNED_EVENT = 'event';

    /** A webhook signed over `data` alone — v1. Its `type` is unauthenticated. */
    public const SIGNED_DATA = 'data';

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
            && (string) $this->config('secret_key') !== '';
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
     * Open a session and hand back the URL to send the customer to.
     *
     * `$context` must carry basket_id (our reference), success_url and
     * cancel_url. The reference is attached to the session as metadata — where
     * the dashboard and every webhook show it — and is also put in the return
     * path, because 2.0 does not say what it appends to the redirect and the
     * return must be able to find its charge regardless.
     *
     * The tracker comes back on the handoff so the caller can store it with the
     * charge. It is the only tracker the return will ever check: one taken from
     * the returning browser could belong to a cheaper payment.
     */
    public function startCheckout(Client $client, PlanPrice $price, array $context = []): CheckoutHandoff
    {
        $orderId = (string) ($context['basket_id'] ?? '');

        if ($orderId === '') {
            throw new \InvalidArgumentException('Safepay needs a basket_id to correlate the payment.');
        }

        // Every session is opened in rupees. A dollar price would open a rupee
        // session for the dollar figure — and the customer could pay it before
        // confirmation refused it for the currency mismatch. Stop it here,
        // before any money can move.
        $currency = strtoupper((string) ($price->currency ?: 'PKR'));

        if ($currency !== 'PKR') {
            throw new \InvalidArgumentException("Safepay settles rupees only, not {$currency}.");
        }

        // An add-on is sold for the REMAINDER of a period, so the amount is
        // computed by the caller and is deliberately not the price row's. Passed
        // explicitly rather than mutating the row, which would reprice the plan
        // for everybody.
        $tracker = $this->createSession($this->amountFor($price, $context['amount_override'] ?? null));

        $this->attachOrder($tracker, $orderId);

        $url = $this->checkoutHost() . '/embedded?' . http_build_query([
            'environment'  => $this->environment(),
            'tracker'      => $tracker,
            'source'       => 'hosted',
            'tbt'          => $this->timeBasedToken(),
            'redirect_url' => $this->returnUrl((string) ($context['success_url'] ?? ''), $orderId),
            'cancel_url'   => (string) ($context['cancel_url'] ?? ''),
        ]);

        return CheckoutHandoff::redirect($url, $orderId, $tracker);
    }

    /**
     * Open a payment session and return its tracker.
     *
     * @throws \RuntimeException when no tracker comes back — a checkout built
     *         around an empty tracker sends the customer to a broken page rather
     *         than failing here, where the reason is visible.
     */
    private function createSession(int $amount): string
    {
        $body = $this->send('POST', self::PATH_SESSION, [
            'merchant_api_key' => (string) $this->config('api_key'),
            'intent'           => 'CYBERSOURCE',
            'mode'             => 'payment',
            'currency'         => 'PKR',
            'amount'           => $amount,
        ]);

        $tracker = (string) data_get($body, 'data.tracker.token', '');

        if ($tracker === '') {
            throw new \RuntimeException(
                'Safepay returned no tracker for the payment session: '
                . mb_substr(json_encode($body) ?: '', 0, 300)
            );
        }

        return $tracker;
    }

    /**
     * Label the session with our reference.
     *
     * Not fatal. The charge row keeps the tracker, which is what the return and
     * the webhook match on, so a failure here costs the dashboard its order
     * column — not the payment. Refusing a paying customer over a label would
     * be the worse trade.
     */
    private function attachOrder(string $tracker, string $orderId): void
    {
        try {
            $this->send('POST', sprintf(self::PATH_METADATA, rawurlencode($tracker)), [
                'data' => ['source' => 'billing', 'order_id' => $orderId],
            ]);
        } catch (\Throwable $e) {
            Log::warning('safepay.metadata_failed', [
                'tracker' => $tracker,
                'order'   => $orderId,
                'error'   => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * The time-based token Safepay's page authenticates with.
     *
     * It arrives as a bare string in `data`, not an object — the SDK wraps it
     * in {token: …} itself — so both shapes are read.
     */
    private function timeBasedToken(): string
    {
        $body  = $this->send('POST', self::PATH_PASSPORT);
        $token = data_get($body, 'data');
        $token = is_string($token) ? $token : (string) data_get($body, 'data.token', '');

        if ($token === '') {
            throw new \RuntimeException('Safepay returned no time-based token for the checkout.');
        }

        return $token;
    }

    /**
     * The amount Safepay should collect, in PAISA.
     *
     * Prices are stored in minor units and 2.0 takes minor units, so this is
     * the identity — kept as a named step because the unit is the single most
     * expensive thing to get wrong here, and it changed between API versions.
     * v1 wanted rupees and this used to divide by 100; doing that on 2.0
     * charges a hundredth of the price.
     */
    private function amountFor(PlanPrice $price, ?int $override = null): int
    {
        return $override ?? (int) $price->unit_amount;
    }

    /**
     * Where the customer comes back to, with our reference in the PATH.
     *
     * In the path rather than the query because 2.0 does not document what it
     * appends to the redirect, and a provider that naively adds "?tracker=…"
     * to a URL that already has a query string would mangle it.
     */
    private function returnUrl(string $successUrl, string $orderId): string
    {
        if ($successUrl === '') {
            return '';
        }

        [$path, $query] = array_pad(explode('?', $successUrl, 2), 2, null);

        return rtrim($path, '/') . '/' . rawurlencode($orderId) . ($query !== null ? '?' . $query : '');
    }

    /**
     * Establish, from Safepay, whether a session was paid.
     *
     * The payload must name the tracker, and should carry the amount and
     * currency the charge expects: a captured session for a different amount is
     * not payment for this order, and the order id recorded on the session must
     * be this reference whenever it is present.
     *
     * Never FAILED for something that may still resolve — an unreachable API or
     * a session still in progress is PENDING, because cancelling a subscription
     * on a guess is gambling with someone else's money. FAILED is kept for a
     * mismatch, which no amount of waiting fixes.
     */
    public function verifyPayment(string $reference, array $payload = []): PaymentResult
    {
        $tracker = (string) ($payload['tracker'] ?? '');

        if ($tracker === '') {
            return PaymentResult::pending($reference, 'No Safepay session to check.', $payload);
        }

        $payment = $this->lookup($tracker);

        if ($payment === null) {
            return PaymentResult::pending(
                $reference,
                'Could not reach Safepay to confirm the payment.',
                ['tracker' => $tracker, 'unreachable' => true],
            );
        }

        $state = (string) ($payment['state'] ?? '');

        if ($state !== self::STATE_PAID) {
            return PaymentResult::pending($reference, "Safepay reports the session as {$state}.", $payment);
        }

        $amount   = (int) (data_get($payment, 'purchase_totals.quote_amount.amount')
            ?? data_get($payment, 'purchase_totals.base_amount.amount')
            ?? -1);
        $currency = strtoupper((string) (data_get($payment, 'purchase_totals.quote_amount.currency')
            ?? data_get($payment, 'purchase_totals.base_amount.currency')
            ?? ''));

        return $this->matches($reference, $payload, $tracker, $amount, $currency, self::orderIdFrom($payment['metadata'] ?? []), $payment)
            ?? PaymentResult::paid($reference, $amount, $currency, $payment);
    }

    /**
     * What a verified 2.0 webhook says about one of our charges.
     *
     * The whole event is signed, so its fields can be believed without a
     * second call — which matters, since Safepay gives an endpoint ten seconds
     * before queueing a retry. Only a capture counts as payment; everything
     * else (failed attempts, authorisations, refunds) is not.
     */
    public function resultFromEvent(object $charge, array $event): PaymentResult
    {
        $reference = (string) $charge->reference;
        $data      = (array) ($event['data'] ?? []);
        $type      = (string) ($event['type'] ?? '');

        if ($type !== 'payment.succeeded') {
            return PaymentResult::pending($reference, "A {$type} event is not a captured payment.", $data);
        }

        $tracker  = (string) ($data['tracker'] ?? '');
        $amount   = (int) ($data['amount'] ?? -1);
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        // Bound to the session opened for this charge, which checkout stored.
        // A charge with none was opened by v1 and has no 2.0 session to match.
        if (! $charge->gateway_ref || $charge->gateway_ref !== $tracker) {
            return PaymentResult::failed($reference, 'The event is for a different Safepay session.', $data);
        }

        return $this->matches(
            $reference,
            ['amount_cents' => (int) $charge->amount_cents, 'currency' => $charge->currency ?: 'PKR'],
            $tracker,
            $amount,
            $currency,
            self::orderIdFrom($data['metadata'] ?? []),
            $data,
        ) ?? PaymentResult::paid($reference, $amount, $currency, $data);
    }

    /**
     * Refuse a captured payment that is not THIS order's, or null if it is.
     *
     * Each check closes a way to pay for one thing with another: a session for
     * a smaller amount, one in another currency, one labelled with a different
     * order. Logged as an error because every one of them means either a bug in
     * how sessions are opened or somebody trying it on.
     */
    private function matches(
        string $reference,
        array $expected,
        string $tracker,
        int $amount,
        string $currency,
        ?string $orderId,
        array $raw,
    ): ?PaymentResult {
        $problem = match (true) {
            isset($expected['amount_cents']) && $amount !== (int) $expected['amount_cents']
                => "Safepay captured {$amount}, but the order is for {$expected['amount_cents']}.",
            isset($expected['currency']) && $currency !== strtoupper((string) $expected['currency'])
                => "Safepay captured {$currency}, but the order is in {$expected['currency']}.",
            $orderId !== null && $orderId !== $reference
                => "The Safepay session belongs to order {$orderId}.",
            default => null,
        };

        if ($problem === null) {
            return null;
        }

        Log::error('safepay.payment_mismatch', [
            'reference' => $reference,
            'tracker'   => $tracker,
            'problem'   => $problem,
        ]);

        return PaymentResult::failed($reference, $problem, $raw);
    }

    /**
     * A session as the reporter API sees it, or null when it cannot be read.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $tracker): ?array
    {
        try {
            $body = $this->send('GET', sprintf(self::PATH_PAYMENT, rawurlencode($tracker)));
        } catch (GuzzleException $e) {
            Log::warning('safepay.lookup_failed', [
                'tracker' => $tracker,
                'error'   => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }

        $data = $body['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /**
     * The order reference recorded on a session, in whichever shape it arrived.
     *
     * The same metadata comes back three ways: flat in a webhook
     * ({order_id: "X"}), as a record in the reporter ({order_id: {value: "X"}}),
     * and nested as it was sent ({data: {order_id: "X"}}).
     */
    public static function orderIdFrom(mixed $metadata): ?string
    {
        $metadata = (array) $metadata;
        $value    = $metadata['order_id'] ?? data_get($metadata, 'data.order_id');

        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Which scheme signed a webhook: SIGNED_EVENT, SIGNED_DATA, or null for
     * none — in which case nothing in the body may be believed.
     *
     * 2.0 signs the whole event with HMAC-SHA512. The docs describe the input
     * as the payload re-encoded as JSON, and the SDK re-encodes PHP's decoded
     * array; those agree with the raw bytes until the payload holds an empty
     * object (PHP turns {} into []) or non-ASCII text (PHP escapes it, and
     * JavaScript does not). So the raw body is tried first and the re-encodings
     * after it. That weakens nothing: every candidate is derived from the body
     * that arrived, and each still needs the secret to match.
     *
     * v1 signed only `data`, leaving `type` outside the signature. It is still
     * recognised, for payments that were already in flight when 2.0 went live,
     * but reported separately so the caller confirms those with Safepay rather
     * than trusting a type anyone could have rewritten.
     */
    public function webhookScheme(string $rawBody, string $signature): ?string
    {
        $secret    = (string) $this->config('webhook_secret');
        $signature = strtolower(trim($signature));

        if ($secret === '' || $signature === '') {
            return null;
        }

        $asObjects = json_decode($rawBody);
        $asArrays  = json_decode($rawBody, true);

        if (! $asObjects instanceof \stdClass || ! is_array($asArrays)) {
            return null;
        }

        $valid = fn (string $candidate) => hash_equals(hash_hmac('sha512', $candidate, $secret), $signature);

        // JSON_UNESCAPED_SLASHES throughout: PHP escapes "/" by default, and a
        // URL inside the payload would otherwise re-encode differently from
        // what Safepay signed.
        $event = array_unique(array_filter([
            $rawBody,
            json_encode($asObjects, JSON_UNESCAPED_SLASHES),
            json_encode($asObjects, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($asArrays, JSON_UNESCAPED_SLASHES),
        ]));

        foreach ($event as $candidate) {
            if ($valid($candidate)) {
                return self::SIGNED_EVENT;
            }
        }

        if (! isset($asArrays['data'])) {
            return null;
        }

        $data = array_unique(array_filter([
            json_encode($asArrays['data'], JSON_UNESCAPED_SLASHES),
            json_encode($asObjects->data, JSON_UNESCAPED_SLASHES),
        ]));

        foreach ($data as $candidate) {
            if ($valid($candidate)) {
                return self::SIGNED_DATA;
            }
        }

        return null;
    }

    /** Signed by Safepay under either scheme. */
    public function webhookValid(string $rawBody, string $signature): bool
    {
        return $this->webhookScheme($rawBody, $signature) !== null;
    }

    /**
     * One authenticated call to Safepay's API.
     *
     * Guzzle's default of throwing on 4xx/5xx is kept: a refused session must
     * stop the checkout with Safepay's reason attached, not carry on with an
     * empty body.
     *
     * @return array<string, mixed>
     *
     * @throws GuzzleException
     */
    private function send(string $method, string $path, ?array $json = null): array
    {
        $options = [
            'headers' => [
                'X-SFPY-MERCHANT-SECRET' => (string) $this->config('secret_key'),
                'Accept'                 => 'application/json',
            ],
            'timeout' => (int) $this->config('timeout', 20),
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $response = $this->http->request($method, rtrim($this->baseUrl(), '/') . $path, $options);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    /** `sandbox` or `production`, as both the API and the checkout page name them. */
    public function environment(): string
    {
        return $this->config('sandbox') ? 'sandbox' : 'production';
    }

    /** Where the API lives — sessions, tokens, lookups. */
    public function baseUrl(): string
    {
        return (string) $this->config(
            $this->config('sandbox') ? 'base_url.sandbox' : 'base_url.production'
        );
    }

    /**
     * Where the CUSTOMER goes: a different host from the API in production
     * (getsafepay.com, not api.getsafepay.com), the same one in sandbox.
     */
    public function checkoutHost(): string
    {
        return rtrim((string) $this->config(
            $this->config('sandbox') ? 'checkout_host.sandbox' : 'checkout_host.production'
        ), '/');
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("billing.safepay.{$key}", $default);
    }
}
