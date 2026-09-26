<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\PlanPrice;
use App\Models\Client;
use App\Services\Billing\Gateways\SafepayGateway;
use GuzzleHttp\Client as Http;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * What the Safepay gateway sends and how it reads the answers — Payments 2.0.
 *
 * Pinned to the official SDK (getsafepay/sfpy-php) and to the sandbox: the
 * paths, the X-SFPY-MERCHANT-SECRET header, the request bodies and the
 * checkout URL. The amount gets the most attention, because 2.0 takes paisa
 * where v1 took rupees and a mistake in either direction is a factor of 100.
 */
class SafepayGatewayTest extends TestCase
{
    private MockHandler $api;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.safepay.api_key'        => 'sec_public_test',
            'billing.safepay.secret_key'     => 'secret-test-key',
            'billing.safepay.webhook_secret' => 'hook-test-secret',
            'billing.safepay.sandbox'        => true,
        ]);

        $this->api = new MockHandler();
    }

    private function gateway(): SafepayGateway
    {
        $stack = HandlerStack::create($this->api);
        $stack->push(Middleware::history($this->sent));

        return new SafepayGateway(new Http(['handler' => $stack]));
    }

    /** The three answers a checkout needs, in the order it asks. */
    private function safepayOpensASession(string $tracker = 'track_abc'): void
    {
        $this->api->append(
            new Response(201, [], json_encode(['data' => ['tracker' => ['token' => $tracker, 'state' => 'TRACKER_STARTED']]])),
            new Response(201, [], json_encode(['data' => ['tracker' => ['token' => $tracker]]])),
            new Response(200, [], json_encode(['data' => 'tbt-token-value'])),
        );
    }

    private function price(int $minor = 7500): PlanPrice
    {
        return new PlanPrice(['unit_amount' => $minor, 'currency' => 'pkr', 'interval' => 'monthly']);
    }

    private function checkout(array $context = [], int $minor = 7500)
    {
        return $this->gateway()->startCheckout(new Client(['name' => 'Test']), $this->price($minor), $context + [
            'basket_id'   => 'ABCD-0123456789',
            'success_url' => 'https://app.test/billing/safepay/return',
            'cancel_url'  => 'https://app.test/billing',
        ]);
    }

    /** @return array<string, mixed> */
    private function body(int $index): array
    {
        return json_decode((string) $this->sent[$index]['request']->getBody(), true) ?: [];
    }

    // ── Opening a checkout ──────────────────────────────────────────────

    public function test_checkout_opens_a_2_0_session_authenticated_by_the_secret_key(): void
    {
        $this->safepayOpensASession();

        $this->checkout();

        $session = $this->sent[0]['request'];
        $this->assertSame('POST', $session->getMethod());
        $this->assertSame('https://sandbox.api.getsafepay.com/order/payments/v3/', (string) $session->getUri());
        $this->assertSame('secret-test-key', $session->getHeaderLine('X-SFPY-MERCHANT-SECRET'));
        $this->assertSame([
            'merchant_api_key' => 'sec_public_test',
            'intent'           => 'CYBERSOURCE',
            'mode'             => 'payment',
            'currency'         => 'PKR',
            'amount'           => 7500,
        ], $this->body(0));
    }

    /**
     * THE line. A Rs 75 price is stored as 7500 and 2.0 takes paisa, so 7500
     * goes out unchanged. v1 divided by 100 — on 2.0 that charges Rs 0.75.
     */
    public function test_the_amount_is_sent_in_paisa_not_rupees(): void
    {
        $this->safepayOpensASession();

        $this->checkout([], 249900);

        $this->assertSame(249900, $this->body(0)['amount'], 'Rs 2,499 must be sent as 249900 paisa');
    }

    /** An add-on is billed for the rest of a period, in the same unit. */
    public function test_an_amount_override_is_sent_unchanged(): void
    {
        $this->safepayOpensASession();

        $this->checkout(['amount_override' => 123456]);

        $this->assertSame(123456, $this->body(0)['amount']);
    }

    public function test_our_reference_is_attached_to_the_session(): void
    {
        $this->safepayOpensASession('track_meta');

        $this->checkout();

        $metadata = $this->sent[1]['request'];
        $this->assertSame(
            'https://sandbox.api.getsafepay.com/order/payments/v3/track_meta/metadata',
            (string) $metadata->getUri(),
        );
        $this->assertSame(['data' => ['source' => 'billing', 'order_id' => 'ABCD-0123456789']], $this->body(1));

        $token = $this->sent[2]['request'];
        $this->assertSame('https://sandbox.api.getsafepay.com/client/passport/v1/token', (string) $token->getUri());
        $this->assertSame('secret-test-key', $token->getHeaderLine('X-SFPY-MERCHANT-SECRET'));
    }

    public function test_the_customer_is_sent_to_the_embedded_page_with_session_and_token(): void
    {
        $this->safepayOpensASession('track_url');

        $handoff = $this->checkout();

        $this->assertStringStartsWith('https://sandbox.api.getsafepay.com/embedded?', $handoff->url);
        parse_str((string) parse_url($handoff->url, PHP_URL_QUERY), $query);

        $this->assertSame([
            'environment'  => 'sandbox',
            'tracker'      => 'track_url',
            'source'       => 'hosted',
            'tbt'          => 'tbt-token-value',
            // Our reference in the PATH, so the return finds its charge
            // whatever Safepay appends.
            'redirect_url' => 'https://app.test/billing/safepay/return/ABCD-0123456789',
            'cancel_url'   => 'https://app.test/billing',
        ], $query);

        $this->assertSame('ABCD-0123456789', $handoff->reference);
        $this->assertSame('track_url', $handoff->gatewayRef, 'The session must come back to be stored on the charge');
    }

    public function test_a_return_url_with_a_query_keeps_it(): void
    {
        $this->safepayOpensASession();

        $handoff = $this->checkout(['success_url' => 'https://app.test/r?lang=ur']);

        parse_str((string) parse_url($handoff->url, PHP_URL_QUERY), $query);
        $this->assertSame('https://app.test/r/ABCD-0123456789?lang=ur', $query['redirect_url']);
    }

    /**
     * Production is two hosts: api.getsafepay.com for the API and
     * getsafepay.com for the customer. Deriving one from the other sends
     * customers to a page that does not exist.
     */
    public function test_production_uses_the_live_api_and_checkout_hosts(): void
    {
        config(['billing.safepay.sandbox' => false]);
        $this->safepayOpensASession();

        $handoff = $this->checkout();

        $this->assertSame('https://api.getsafepay.com/order/payments/v3/', (string) $this->sent[0]['request']->getUri());
        $this->assertStringStartsWith('https://getsafepay.com/embedded?', $handoff->url);
        $this->assertStringContainsString('environment=production', $handoff->url);
    }

    /** The label is for the dashboard; the stored session is what pays. */
    public function test_a_failed_metadata_call_does_not_stop_the_checkout(): void
    {
        $this->api->append(
            new Response(201, [], json_encode(['data' => ['tracker' => ['token' => 'track_x']]])),
            new Response(500, [], '{"status":{"errors":["boom"]}}'),
            new Response(200, [], json_encode(['data' => 'tbt'])),
        );

        $handoff = $this->checkout();

        $this->assertSame('track_x', $handoff->gatewayRef);
    }

    public function test_no_tracker_means_no_checkout(): void
    {
        $this->api->append(new Response(201, [], json_encode(['data' => []])));

        $this->expectException(\RuntimeException::class);

        $this->checkout();
    }

    public function test_a_refused_session_stops_the_checkout(): void
    {
        $this->api->append(new Response(401, [], '{"status":{"errors":["invalid secret"]}}'));

        $this->expectException(\GuzzleHttp\Exception\ClientException::class);

        $this->checkout();
    }

    /** A dollar price must never become a rupee session for the dollar figure. */
    public function test_a_price_in_another_currency_opens_no_session(): void
    {
        $price = new PlanPrice(['unit_amount' => 5900, 'currency' => 'usd', 'interval' => 'monthly']);

        try {
            $this->gateway()->startCheckout(new Client(['name' => 'Test']), $price, ['basket_id' => 'X-1']);
            $this->fail('A USD price reached Safepay');
        } catch (\InvalidArgumentException) {
            $this->assertCount(0, $this->sent, 'No session may be opened for it');
        }
    }

    public function test_a_basket_id_is_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->gateway()->startCheckout(new Client(['name' => 'Test']), $this->price(), []);
    }

    public function test_it_is_configured_by_the_public_and_secret_keys(): void
    {
        $this->assertTrue($this->gateway()->isConfigured());

        config(['billing.safepay.secret_key' => '']);
        $this->assertFalse($this->gateway()->isConfigured());
    }

    // ── Confirming a payment ────────────────────────────────────────────

    private function reporterSays(string $state, int $amount = 7500, string $currency = 'PKR', ?string $orderId = 'ABCD-1'): void
    {
        $this->api->append(new Response(200, [], json_encode(['ok' => true, 'data' => [
            'token'           => 'track_1',
            'state'           => $state,
            'purchase_totals' => ['quote_amount' => ['currency' => $currency, 'amount' => $amount]],
            'metadata'        => $orderId === null ? [] : ['order_id' => ['value' => $orderId]],
        ]])));
    }

    private function verify(): \App\Services\Billing\Gateways\PaymentResult
    {
        return $this->gateway()->verifyPayment('ABCD-1', ['tracker' => 'track_1', 'amount_cents' => 7500, 'currency' => 'PKR']);
    }

    public function test_a_captured_session_for_the_right_amount_is_paid(): void
    {
        $this->reporterSays(SafepayGateway::STATE_PAID);

        $result = $this->verify();

        $this->assertTrue($result->paid);
        $this->assertSame(7500, $result->amountCents);
        $this->assertSame('GET', $this->sent[0]['request']->getMethod());
        $this->assertSame(
            'https://sandbox.api.getsafepay.com/reporter/api/v2/payments/track_1',
            (string) $this->sent[0]['request']->getUri(),
        );
    }

    public function test_a_session_without_our_label_is_judged_on_amount_alone(): void
    {
        $this->reporterSays(SafepayGateway::STATE_PAID, 7500, 'PKR', null);

        $this->assertTrue($this->verify()->paid);
    }

    public function test_a_session_still_in_progress_is_pending(): void
    {
        $this->reporterSays('TRACKER_STARTED');

        $result = $this->verify();

        $this->assertFalse($result->paid);
        $this->assertTrue($result->isPending());
    }

    /** Unreachable is not failed: the customer may well have paid. */
    public function test_an_unreachable_api_is_pending_and_says_so(): void
    {
        $this->api->append(new ConnectException('down', new PsrRequest('GET', 'x')));

        $result = $this->verify();

        $this->assertTrue($result->isPending());
        $this->assertTrue($result->raw['unreachable'] ?? false);
    }

    public function test_no_tracker_is_pending_without_a_lookup(): void
    {
        $result = $this->gateway()->verifyPayment('ABCD-1', []);

        $this->assertTrue($result->isPending());
        $this->assertCount(0, $this->sent);
    }

    /** Each is a way to pay for one thing with another; none may count. */
    public function test_a_captured_session_that_is_not_this_order_is_refused(): void
    {
        foreach ([
            'smaller amount' => [100, 'PKR', 'ABCD-1'],
            'other currency' => [7500, 'USD', 'ABCD-1'],
            'other order'    => [7500, 'PKR', 'ZZZZ-9'],
        ] as $case => [$amount, $currency, $orderId]) {
            $this->reporterSays(SafepayGateway::STATE_PAID, $amount, $currency, $orderId);

            $result = $this->verify();

            $this->assertFalse($result->paid, "A session with a {$case} was accepted");
            $this->assertSame('failed', $result->status, "A {$case} will never resolve, so it is not pending");
        }
    }

    // ── Webhook signatures ──────────────────────────────────────────────

    public function test_webhooks_are_verified_over_the_whole_event_with_sha512(): void
    {
        $gateway = $this->gateway();
        $body    = json_encode(['type' => 'payment.succeeded', 'data' => ['tracker' => 't', 'url' => 'https://x.test/a/b']], JSON_UNESCAPED_SLASHES);
        $secret  = 'hook-test-secret';

        $this->assertSame(SafepayGateway::SIGNED_EVENT, $gateway->webhookScheme($body, hash_hmac('sha512', $body, $secret)));
        $this->assertSame(
            SafepayGateway::SIGNED_EVENT,
            $gateway->webhookScheme($body, strtoupper(hash_hmac('sha512', $body, $secret))),
            'Hex case is not part of the signature',
        );

        $this->assertNull($gateway->webhookScheme($body, hash_hmac('sha256', $body, $secret)), 'SHA-256 is not the scheme');
        $this->assertNull($gateway->webhookScheme($body, hash_hmac('sha512', $body, 'secret-test-key')), 'The API secret is not the webhook secret');
        $this->assertNull($gateway->webhookScheme($body, ''), 'Unsigned');
        $this->assertNull($gateway->webhookScheme('not json', hash_hmac('sha512', 'not json', $secret)), 'Not an event');
    }

    /** The v1 scheme, still recognised — and labelled, so it is not trusted as far. */
    public function test_a_data_only_signature_is_recognised_as_such(): void
    {
        $data = ['tracker' => 't', 'url' => 'https://x.test/a/b'];
        $body = json_encode(['type' => 'payment.succeeded', 'data' => $data]);

        $this->assertSame(
            SafepayGateway::SIGNED_DATA,
            $this->gateway()->webhookScheme($body, hash_hmac('sha512', json_encode($data, JSON_UNESCAPED_SLASHES), 'hook-test-secret')),
        );
    }

    public function test_no_webhook_secret_verifies_nothing(): void
    {
        config(['billing.safepay.webhook_secret' => '']);
        $body = '{"type":"payment.succeeded","data":{}}';

        $this->assertNull($this->gateway()->webhookScheme($body, hash_hmac('sha512', $body, '')));
    }

    public function test_the_order_reference_is_read_from_every_shape_it_arrives_in(): void
    {
        $this->assertSame('A-1', SafepayGateway::orderIdFrom(['order_id' => 'A-1']));
        $this->assertSame('A-1', SafepayGateway::orderIdFrom(['order_id' => ['value' => 'A-1', 'key' => 'order_id']]));
        $this->assertSame('A-1', SafepayGateway::orderIdFrom(['data' => ['order_id' => 'A-1']]));
        $this->assertNull(SafepayGateway::orderIdFrom([]));
        $this->assertNull(SafepayGateway::orderIdFrom(['order_id' => '']));
    }
}
