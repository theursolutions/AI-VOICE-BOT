<?php

namespace Tests\Feature;

use App\Services\Billing\Gateways\SafepayGateway;
use GuzzleHttp\Client as Http;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Safepay's webhook and return, Payments 2.0.
 *
 * Without its signature check the webhook is an unauthenticated "make me a
 * subscriber" API, and the return — which 2.0 does not sign at all — would be
 * one too if it believed anything the browser sent. So the tests that matter
 * most here prove that a forged, replayed or mismatched message changes
 * nothing.
 */
class SafepayWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'webhook-test-secret';

    /** Safepay's API, answered from a queue rather than the network. */
    private MockHandler $api;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.safepay.webhook_secret' => self::SECRET,
            'billing.safepay.secret_key'     => 'secret-test-key',
            'billing.safepay.api_key'        => 'sec_test',
            'billing.safepay.sandbox'        => true,
        ]);

        $this->api = new MockHandler();
        $stack     = HandlerStack::create($this->api);
        $stack->push(Middleware::history($this->sent));

        $this->app->instance(SafepayGateway::class, new SafepayGateway(new Http(['handler' => $stack])));
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /** A pending charge as 2.0 checkout leaves one: with its session stored. */
    private function charge(array $overrides = []): object
    {
        $clientId = DB::table('clients')->insertGetId([
            'name'           => 'Webhook Test',
            'slug'           => 'wh-' . uniqid(),
            'client_api_key' => 'test-' . uniqid(),
            'created_at'     => time(),
            'updated_at'     => time(),
        ]);

        $id = DB::table('gateway_charges')->insertGetId(array_merge([
            'client_id'    => $clientId,
            'gateway'      => 'safepay',
            'reference'    => 'ORDER-' . uniqid(),
            'gateway_ref'  => 'track_' . uniqid(),
            'amount_cents' => 750000,
            'currency'     => 'PKR',
            'status'       => 'pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ], $overrides));

        return DB::table('gateway_charges')->find($id);
    }

    /**
     * A 2.0 event for a charge, shaped like the examples in Safepay's docs.
     *
     * @return array<string, mixed>
     */
    private function event(object $charge, string $type = 'payment.succeeded', array $data = []): array
    {
        return [
            'token'            => 'evt_' . uniqid(),
            'version'          => '2.0.0',
            'merchant_api_key' => 'sec_test',
            'type'             => $type,
            'endpoint'         => 'https://app.test/billing/safepay/webhook',
            'data'             => array_merge([
                'tracker'        => $charge->gateway_ref,
                'intent'         => 'CYBERSOURCE',
                'state'          => 'TRACKER_ENDED',
                'net'            => 725000,
                'fee'            => 25000,
                'customer_email' => 'buyer@example.com',
                'amount'         => (int) $charge->amount_cents,
                'currency'       => 'PKR',
                'metadata'       => ['order_id' => $charge->reference, 'source' => 'billing'],
                'charged_at'     => ['seconds' => 1698754230, 'nanos' => 752997627],
            ], $data),
            'created_at' => ['seconds' => 1698754230, 'nanos' => 912705711],
        ];
    }

    /** Signed the way 2.0 signs: HMAC-SHA512 over the whole body. */
    private function sign(string $raw, string $secret = self::SECRET): string
    {
        return hash_hmac('sha512', $raw, $secret);
    }

    /** @param array<string, mixed>|string $event */
    private function deliver(array|string $event, ?string $signature = null)
    {
        $raw = is_string($event) ? $event : json_encode($event, JSON_UNESCAPED_SLASHES);

        return $this->call(
            'POST',
            '/billing/safepay/webhook',
            [], [], [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_X_SFPY_SIGNATURE' => $signature ?? $this->sign($raw),
            ],
            $raw,
        );
    }

    /** Queue the reporter API's answer for the next lookup. */
    private function reporterSays(object $charge, string $state, ?int $amount = null, ?string $orderId = null): void
    {
        $this->api->append(new Response(200, [], json_encode([
            'ok'   => true,
            'data' => [
                'token'           => $charge->gateway_ref,
                'state'           => $state,
                'purchase_totals' => [
                    'quote_amount' => ['currency' => 'PKR', 'amount' => $amount ?? (int) $charge->amount_cents],
                ],
                'metadata' => [
                    'order_id' => ['key' => 'order_id', 'value' => $orderId ?? $charge->reference],
                ],
            ],
        ])));
    }

    private function statusOf(object $charge): string
    {
        return DB::table('gateway_charges')->find($charge->id)->status;
    }

    // ── The security boundary ───────────────────────────────────────────

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        $charge = $this->charge();

        $this->deliver($this->event($charge), '')->assertStatus(400);

        $this->assertSame('pending', $this->statusOf($charge));
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $charge = $this->charge();
        $raw    = json_encode($this->event($charge), JSON_UNESCAPED_SLASHES);

        $this->deliver($raw, $this->sign($raw, 'wrong-secret'))->assertStatus(400);

        $this->assertSame('pending', $this->statusOf($charge), 'A forged webhook marked a charge as paid');
    }

    /**
     * A genuine signature must not validate an altered body, or an attacker
     * could replay one real delivery with a different amount or order.
     */
    public function test_a_signature_for_a_different_body_is_rejected(): void
    {
        $charge  = $this->charge();
        $genuine = json_encode($this->event($charge, 'payment.failed'), JSON_UNESCAPED_SLASHES);
        $altered = json_encode($this->event($charge), JSON_UNESCAPED_SLASHES);

        $this->deliver($altered, $this->sign($genuine))->assertStatus(400);

        $this->assertSame('pending', $this->statusOf($charge));
    }

    /**
     * The v1 scheme signed only `data`, so the event's TYPE was never covered.
     * Such a delivery is not believed — Safepay is asked instead.
     */
    public function test_an_event_signed_over_data_alone_is_confirmed_with_safepay_not_believed(): void
    {
        $charge = $this->charge();
        $event  = $this->event($charge);
        $this->reporterSays($charge, 'TRACKER_STARTED');

        $this->deliver($event, $this->sign(json_encode($event['data'], JSON_UNESCAPED_SLASHES)))->assertOk();

        $this->assertSame('pending', $this->statusOf($charge), 'A data-only signature was taken as proof of payment');
        $this->assertCount(1, $this->sent);
        $this->assertStringEndsWith(
            '/reporter/api/v2/payments/' . $charge->gateway_ref,
            (string) $this->sent[0]['request']->getUri(),
        );
    }

    public function test_an_event_signed_over_data_alone_pays_once_safepay_confirms_it(): void
    {
        $charge = $this->charge();
        $event  = $this->event($charge);
        $this->reporterSays($charge, SafepayGateway::STATE_PAID);

        $this->deliver($event, $this->sign(json_encode($event['data'], JSON_UNESCAPED_SLASHES)))->assertOk();

        $this->assertSame('paid', $this->statusOf($charge));
    }

    /** Safepay's own API down: back into their retry queue, not dropped. */
    public function test_an_unconfirmable_delivery_is_retried(): void
    {
        $charge = $this->charge();
        $event  = $this->event($charge);
        $this->api->append(new ConnectException('down', new PsrRequest('GET', 'x')));

        $this->deliver($event, $this->sign(json_encode($event['data'], JSON_UNESCAPED_SLASHES)))->assertStatus(503);

        $this->assertSame('pending', $this->statusOf($charge));
    }

    // ── The happy path ──────────────────────────────────────────────────

    /** A 2.0 event is signed whole, so it is read without a second call. */
    public function test_a_signed_payment_succeeded_marks_the_charge_paid(): void
    {
        $charge = $this->charge();

        $this->deliver($this->event($charge))->assertOk();

        $fresh = DB::table('gateway_charges')->find($charge->id);

        $this->assertSame('paid', $fresh->status);
        $this->assertSame($charge->gateway_ref, $fresh->gateway_ref);
        $this->assertNotNull($fresh->paid_at);
        $this->assertCount(0, $this->sent, 'A whole-event signature needs no lookup');
    }

    public function test_the_charge_is_found_by_its_session_when_the_metadata_is_missing(): void
    {
        $charge = $this->charge();

        $this->deliver($this->event($charge, 'payment.succeeded', ['metadata' => (object) []]))->assertOk();

        $this->assertSame('paid', $this->statusOf($charge));
    }

    /**
     * Safepay retries, and the redirect can arrive at the same moment. A second
     * delivery must be a no-op rather than a second payment.
     */
    public function test_a_repeated_delivery_changes_nothing(): void
    {
        $charge = $this->charge();
        $event  = $this->event($charge);

        $this->deliver($event)->assertOk();
        $paidAt = DB::table('gateway_charges')->find($charge->id)->paid_at;

        $this->deliver($event)->assertOk();

        $this->assertSame($paidAt, DB::table('gateway_charges')->find($charge->id)->paid_at);
    }

    /**
     * A payment we have no record of starting is accepted, not errored — a 4xx
     * would have Safepay redeliver for days over something a retry can never
     * resolve.
     */
    public function test_an_unknown_charge_is_acknowledged_rather_than_retried(): void
    {
        $stranger = (object) ['gateway_ref' => 'track_nobody', 'reference' => 'NOT-OURS', 'amount_cents' => 100];

        $this->deliver($this->event($stranger))->assertOk();
    }

    /** A paid charge extends the subscription to the period it was raised for. */
    public function test_payment_extends_the_subscription_period(): void
    {
        $plan = \App\Models\Billing\Plan::where('type', 'standard')->first();

        if (! $plan) {
            $this->markTestSkipped('No standard plan seeded.');
        }

        $clientId = DB::table('clients')->insertGetId([
            'name' => 'Period Test', 'slug' => 'pt-' . uniqid(),
            'client_api_key' => 'test-' . uniqid(),
            'created_at' => time(), 'updated_at' => time(),
        ]);

        $subscription = \App\Models\Billing\Subscription::create([
            'client_id'          => $clientId,
            'plan_id'            => $plan->id,
            'status'             => 'past_due',
            'interval'           => 'monthly',
            'currency'           => 'pkr',
            'current_period_end' => now()->subDay(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $periodEnd = now()->addMonth()->startOfMinute();

        // Every real checkout charge names the price it was raised for — that is
        // how the payment is mapped back to a plan. Without it the charge is
        // "paid for something we cannot map" and deliberately extends nothing,
        // which this fixture went unnoticed as while the test always skipped.
        $price = $plan->priceFor('monthly', 'pkr') ?? $plan->priceFor('monthly');

        $charge = $this->charge([
            'client_id'       => $clientId,
            'subscription_id' => $subscription->id,
            'plan_price_id'   => $price?->id,
            'interval'        => 'monthly',
            'period_start'    => now()->startOfMinute(),
            'period_end'      => $periodEnd,
        ]);

        $this->deliver($this->event($charge))->assertOk();

        $fresh = $subscription->fresh();

        $this->assertSame('active', $fresh->status);
        $this->assertSame(
            $periodEnd->toDateTimeString(),
            $fresh->current_period_end->toDateTimeString(),
            'The period must extend to what the charge was raised for, not to an arbitrary now+1 month',
        );
    }

    // ── Genuine events that must not pay THIS order ────────────────────

    public function test_a_captured_payment_for_a_different_amount_does_not_pay_the_order(): void
    {
        $charge = $this->charge();

        $this->deliver($this->event($charge, 'payment.succeeded', ['amount' => 100]))->assertOk();

        $this->assertSame('pending', $this->statusOf($charge));
    }

    public function test_an_event_for_another_session_does_not_pay_the_order(): void
    {
        $charge = $this->charge();

        $this->deliver($this->event($charge, 'payment.succeeded', ['tracker' => 'track_someone_else']))->assertOk();

        $this->assertSame('pending', $this->statusOf($charge));
    }

    /** v1 charges stored no session, so no 2.0 event can be bound to one. */
    public function test_a_charge_opened_by_v1_is_not_paid_by_a_webhook(): void
    {
        $charge = $this->charge(['gateway_ref' => null]);

        $this->deliver($this->event($charge, 'payment.succeeded', ['tracker' => 'track_any']))->assertOk();

        $this->assertSame('pending', $this->statusOf($charge));
    }

    public function test_events_other_than_a_capture_change_nothing(): void
    {
        $charge = $this->charge();

        foreach (['authorization.succeeded', 'payment.refunded', 'void.succeeded'] as $type) {
            $this->deliver($this->event($charge, $type))->assertOk();
        }

        $this->assertSame('pending', $this->statusOf($charge));
    }

    /**
     * A decline must not end the charge: the customer can try another card on
     * the same session, and a charge marked failed could never record the
     * success that follows.
     */
    public function test_a_declined_attempt_keeps_the_charge_payable(): void
    {
        $charge = $this->charge();

        $this->deliver($this->event($charge, 'payment.failed', [
            'state'    => 'TRACKER_ENROLLED',
            'category' => 'PAYMENT_METHOD_ERROR',
            'message'  => 'The card has been declined.',
        ]))->assertOk();

        $afterDecline = DB::table('gateway_charges')->find($charge->id);
        $this->assertSame('pending', $afterDecline->status);
        $this->assertStringContainsString('declined', (string) $afterDecline->failure_reason);

        $this->deliver($this->event($charge))->assertOk();

        $afterPayment = DB::table('gateway_charges')->find($charge->id);
        $this->assertSame('paid', $afterPayment->status);
        $this->assertNull($afterPayment->failure_reason, 'A paid charge must not keep a red "Failure" line');
    }

    // ── What the signature is computed over ────────────────────────────

    /**
     * PHP decodes {} to [] and re-encodes it as [] — so a verifier that
     * re-encodes the decoded array, as the SDK's own sample does, rejects every
     * payload with an empty object. Safepay's payment.failed example has one.
     */
    public function test_a_payload_with_an_empty_object_verifies(): void
    {
        $charge = $this->charge();
        $raw    = '{"token":"evt_1","version":"2.0.0","type":"payment.failed","data":{"tracker":"'
            . $charge->gateway_ref . '","state":"TRACKER_ENROLLED","metadata":{},"message":"Declined"}}';

        $this->deliver($raw)->assertOk()->assertJson(['note' => 'attempt failed']);
    }

    /** JavaScript and Go send non-ASCII as UTF-8; PHP escapes it by default. */
    public function test_a_payload_with_non_ascii_text_verifies(): void
    {
        $charge = $this->charge();
        $event  = $this->event($charge, 'payment.succeeded', ['customer_email' => 'خریدار@example.com']);
        $raw    = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->deliver($raw)->assertOk();

        $this->assertSame('paid', $this->statusOf($charge));
    }

    // ── The local simulator ─────────────────────────────────────────────

    /** Its whole value is producing what Safepay produces; so it must pay. */
    public function test_the_simulator_pays_a_charge_with_a_genuine_2_0_event(): void
    {
        $charge = $this->charge();

        $this->artisan('safepay:simulate', ['--charge' => $charge->reference])->assertExitCode(0);

        $this->assertSame('paid', $this->statusOf($charge));
    }

    public function test_the_simulator_shows_a_forgery_being_refused(): void
    {
        $charge = $this->charge();

        $this->artisan('safepay:simulate', ['--charge' => $charge->reference, '--tamper' => true])->assertExitCode(0);

        $this->assertSame('pending', $this->statusOf($charge));
    }

    public function test_the_simulator_can_decline_without_ending_the_charge(): void
    {
        $charge = $this->charge();

        $this->artisan('safepay:simulate', ['--charge' => $charge->reference, '--event' => 'payment.failed'])->assertExitCode(0);

        $fresh = DB::table('gateway_charges')->find($charge->id);
        $this->assertSame('pending', $fresh->status);
        $this->assertNotNull($fresh->failure_reason);
    }

    public function test_the_simulator_will_not_pay_a_v1_charge(): void
    {
        $charge = $this->charge(['gateway_ref' => null]);

        $this->artisan('safepay:simulate', ['--charge' => $charge->reference])->assertExitCode(1);

        $this->assertSame('pending', $this->statusOf($charge));
    }

    // ── The return ──────────────────────────────────────────────────────

    public function test_the_return_confirms_the_stored_session_with_safepay(): void
    {
        $charge = $this->charge();
        $this->reporterSays($charge, SafepayGateway::STATE_PAID);

        $response = $this->get('/billing/safepay/return/' . $charge->reference);

        $response->assertRedirect();
        $this->assertStringContainsString('paid=' . $charge->reference, (string) $response->headers->get('Location'));
        $this->assertSame('paid', $this->statusOf($charge));

        $lookup = $this->sent[0]['request'];
        $this->assertStringEndsWith('/reporter/api/v2/payments/' . $charge->gateway_ref, (string) $lookup->getUri());
        $this->assertSame('secret-test-key', $lookup->getHeaderLine('X-SFPY-MERCHANT-SECRET'));
    }

    /** Safepay may POST the customer back rather than redirect them. */
    public function test_the_return_accepts_a_post(): void
    {
        $charge = $this->charge();
        $this->reporterSays($charge, SafepayGateway::STATE_PAID);

        $this->post('/billing/safepay/return/' . $charge->reference)->assertRedirect();

        $this->assertSame('paid', $this->statusOf($charge));
    }

    /**
     * 2.0 does not sign the return, so a tracker in it proves nothing. A
     * customer who paid Rs 1 elsewhere must not be able to name that session
     * against a Rs 7,500 order.
     */
    public function test_the_return_ignores_a_tracker_named_by_the_browser(): void
    {
        $charge = $this->charge();
        $this->reporterSays($charge, 'TRACKER_STARTED');

        $this->get('/billing/safepay/return/' . $charge->reference . '?tracker=track_paid_for_one_rupee');

        $this->assertStringEndsWith(
            '/reporter/api/v2/payments/' . $charge->gateway_ref,
            (string) $this->sent[0]['request']->getUri(),
        );
        $this->assertSame('pending', $this->statusOf($charge));
    }

    public function test_an_unpaid_session_leaves_the_charge_pending(): void
    {
        $charge = $this->charge();
        $this->reporterSays($charge, 'TRACKER_STARTED');

        $response = $this->get('/billing/safepay/return/' . $charge->reference);

        $response->assertRedirect();
        $this->assertStringNotContainsString('paid=', (string) $response->headers->get('Location'));
        $this->assertSame('pending', $this->statusOf($charge));
    }

    public function test_a_return_for_the_wrong_amount_is_refused(): void
    {
        $charge = $this->charge();
        $this->reporterSays($charge, SafepayGateway::STATE_PAID, 100);

        $this->get('/billing/safepay/return/' . $charge->reference);

        $this->assertSame('pending', $this->statusOf($charge));
    }

    public function test_a_session_labelled_with_another_order_is_refused(): void
    {
        $charge = $this->charge();
        $this->reporterSays($charge, SafepayGateway::STATE_PAID, null, 'SOMEONE-ELSES-ORDER');

        $this->get('/billing/safepay/return/' . $charge->reference);

        $this->assertSame('pending', $this->statusOf($charge));
    }

    /**
     * A customer who left under v1 and comes back after the deploy. The v1
     * return carried a genuine signature, but over a session anyone could open
     * with the public key — so it waits for a person rather than being paid.
     */
    public function test_a_v1_return_is_left_for_a_person(): void
    {
        $charge = $this->charge(['gateway_ref' => null]);

        $this->post('/billing/safepay/return', [
            'order_id' => $charge->reference,
            'tracker'  => 'track_v1',
            'sig'      => hash_hmac('sha256', 'track_v1', 'secret-test-key'),
        ])->assertRedirect();

        $this->assertSame('pending', $this->statusOf($charge));
        $this->assertCount(0, $this->sent, 'A v1 session cannot be confirmed, so it must not be looked up');
    }
}
