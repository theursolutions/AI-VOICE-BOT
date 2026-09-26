<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\Billing\Gateways\GatewayRegistry;
use App\Services\Billing\Gateways\PayFastGateway;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Services\Billing\Gateways\StripeGateway;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which gateway serves which customer, and what each will honestly do.
 *
 * The routing rule is the customer's country, not ours — a Karachi SME needs
 * JazzCash and a bank account, a London one needs a card — and the two providers
 * coexist permanently because neither can serve the other's customers: PayFast
 * cannot settle USD and Stripe cannot take Easypaisa.
 *
 * The capability assertions matter more than they look. The whole reason for a
 * narrow interface is that PayFast cannot bill recurring, and every part of the
 * subscription lifecycle has to be able to find that out before assuming a
 * renewal will happen on its own.
 */
class GatewayRoutingTest extends TestCase
{
    use DatabaseTransactions;

    private function client(?string $country): Client
    {
        $id = DB::table('clients')->insertGetId([
            'name'            => 'Gateway Test',
            'slug'            => 'gw-' . uniqid(),
            'client_api_key'  => 'test-' . uniqid(),
            'billing_country' => $country,
            'created_at'      => time(),
            'updated_at'      => time(),
        ]);

        return Client::find($id);
    }

    private function registryWithPayFast(): GatewayRegistry
    {
        config([
            'billing.payfast.merchant_id' => 'TEST-MERCHANT',
            'billing.payfast.secured_key' => 'TEST-KEY',
            // Safepay explicitly absent, so these tests keep testing PayFast.
            'billing.safepay.api_key'     => null,
            'billing.safepay.secret_key'   => null,
            // And Paddle, for the same reason: with it configured, every
            // non-Pakistani customer routes to Paddle rather than Stripe, and
            // these assertions would pass or fail according to which keys are
            // in the developer's .env.
            'billing.paddle.api_key'      => null,
            'billing.paddle.client_token' => null,
        ]);

        app()->forgetInstance(GatewayRegistry::class);

        return app(GatewayRegistry::class);
    }

    private function registryWithSafepay(): GatewayRegistry
    {
        config([
            'billing.safepay.api_key'   => 'sec_test',
            'billing.safepay.secret_key' => 'v1-test-secret',
            'billing.payfast.merchant_id' => 'TEST-MERCHANT',
            'billing.payfast.secured_key' => 'TEST-KEY',
            'billing.paddle.api_key'      => null,
            'billing.paddle.client_token' => null,
        ]);

        app()->forgetInstance(GatewayRegistry::class);

        return app(GatewayRegistry::class);
    }

    // ── What each gateway admits to ─────────────────────────────────────

    /**
     * The assertion the whole design rests on. PayFast authorises one payment
     * and keeps no reusable credential, so anything that assumes a renewal will
     * happen by itself has to be told otherwise HERE, not discovered six weeks
     * later when a customer's period ends and nothing charges.
     */
    public function test_payfast_does_not_claim_recurring(): void
    {
        $payfast = app(PayFastGateway::class);

        $this->assertFalse($payfast->supports(PaymentGateway::CAP_RECURRING));
        $this->assertFalse($payfast->supports(PaymentGateway::CAP_SAVED_METHODS));
        $this->assertFalse($payfast->supports(PaymentGateway::CAP_PROVIDER_SUBSCRIPTIONS));
        $this->assertFalse($payfast->supports(PaymentGateway::CAP_PRORATION));
        $this->assertSame(['PKR'], $payfast->currencies());
    }

    public function test_stripe_claims_what_it_can_actually_do(): void
    {
        $stripe = app(StripeGateway::class);

        $this->assertTrue($stripe->supports(PaymentGateway::CAP_RECURRING));
        $this->assertTrue($stripe->supports(PaymentGateway::CAP_SAVED_METHODS));
        $this->assertSame([], $stripe->currencies(), 'No currency restriction');
    }

    // ── Routing ─────────────────────────────────────────────────────────

    public function test_a_pakistani_customer_gets_payfast_and_is_quoted_in_rupees(): void
    {
        $registry = $this->registryWithPayFast();
        $client   = $this->client('PK');

        $this->assertSame('payfast', $registry->forClient($client)?->key());
        $this->assertSame('PKR', $registry->currencyFor($client));
    }

    /**
     * Everyone outside Pakistan goes to the international gateway.
     *
     * Stripe with no Paddle configured, which is the fallback; Paddle when it
     * is, because it is Merchant of Record and carries the tax. Asserted both
     * ways so the preference order is pinned rather than inferred from whatever
     * happens to have credentials.
     *
     * @dataProvider foreignCountries
     */
    public function test_everyone_else_gets_the_international_gateway(?string $country): void
    {
        $registry = $this->registryWithPayFast();

        $this->assertSame('stripe', $registry->forClient($this->client($country))?->key());

        config([
            'billing.paddle.api_key'      => 'apikey_test',
            'billing.paddle.client_token' => 'test_token',
        ]);
        app()->forgetInstance(GatewayRegistry::class);

        $this->assertSame(
            'paddle',
            app(GatewayRegistry::class)->forClient($this->client($country))?->key(),
            'Paddle is preferred over Stripe internationally — it is Merchant of Record',
        );
    }

    public static function foreignCountries(): array
    {
        return [['GB'], ['US'], ['AE'], ['IN'], [null]];
    }

    /** Country is matched case-insensitively — stored data is not always tidy. */
    public function test_the_country_match_is_case_insensitive(): void
    {
        $registry = $this->registryWithPayFast();

        $this->assertSame('payfast', $registry->forClient($this->client('pk'))?->key());
    }

    /**
     * A half-finished PayFast setup must not break checkout for customers
     * Stripe was already serving. Registered is not the same as usable.
     */
    public function test_an_unconfigured_gateway_is_skipped_rather_than_chosen(): void
    {
        // EVERY local gateway must be unconfigured for this to test what it
        // claims. Nulling only PayFast passed while nothing else had keys, then
        // failed the moment real Safepay credentials reached .env — the test was
        // reading the developer's environment rather than controlling its own.
        config([
            'billing.payfast.merchant_id' => null,
            'billing.payfast.secured_key' => null,
            'billing.safepay.api_key'     => null,
            'billing.safepay.secret_key'   => null,
            'billing.paddle.api_key'      => null,
            'billing.paddle.client_token' => null,
        ]);
        app()->forgetInstance(GatewayRegistry::class);

        $registry = app(GatewayRegistry::class);

        $this->assertFalse(app(PayFastGateway::class)->isConfigured());
        $this->assertFalse(app(\App\Services\Billing\Gateways\SafepayGateway::class)->isConfigured());
        $this->assertSame(
            'stripe',
            $registry->forClient($this->client('PK'))?->key(),
            'A Pakistani customer must still be able to pay while PayFast is being set up',
        );
    }

    // ── The question the lifecycle has to ask ───────────────────────────

    public function test_the_registry_reports_who_is_responsible_for_renewals(): void
    {
        $registry = $this->registryWithPayFast();

        $this->assertFalse(
            $registry->billsRecurringItself($this->client('PK')),
            'PayFast renewals are ours to raise; assuming otherwise silently stops billing',
        );

        $this->assertTrue($registry->billsRecurringItself($this->client('GB')));
    }

    public function test_checkout_cannot_start_without_a_correlation_id(): void
    {
        $price = \App\Models\Billing\PlanPrice::query()->first();

        if (! $price) {
            $this->markTestSkipped('No plan price seeded.');
        }

        $this->expectException(\InvalidArgumentException::class);

        // Without a basket id a callback cannot be matched to a charge, so the
        // payment would be unattributable the moment it succeeded.
        app(PayFastGateway::class)->startCheckout($this->client('PK'), $price, []);
    }

    // ── Safepay ─────────────────────────────────────────────────────────

    /**
     * Payments 2.0 signs the WHOLE webhook event with HMAC-SHA512 under the
     * webhook secret. v1 signed only `data`, leaving `type` outside the
     * signature; checking for that alone would reject every genuine 2.0
     * delivery, and the logs would show only "invalid signature" — which reads
     * like an attack rather than our own mistake.
     */
    public function test_safepay_webhooks_are_signed_over_the_whole_event(): void
    {
        config([
            'billing.safepay.secret_key'     => 'api-test-secret',
            'billing.safepay.webhook_secret' => 'hook-test-secret',
        ]);

        $safepay = app(\App\Services\Billing\Gateways\SafepayGateway::class);

        $body = json_encode([
            'version' => '2.0.0',
            'type'    => 'payment.succeeded',
            'data'    => ['tracker' => 'track_1', 'metadata' => ['order_id' => 'ORDER-1'], 'url' => 'https://example.com/a/b'],
        ], JSON_UNESCAPED_SLASHES);

        $this->assertTrue($safepay->webhookValid($body, hash_hmac('sha512', $body, 'hook-test-secret')));

        $this->assertFalse(
            $safepay->webhookValid($body, hash_hmac('sha256', $body, 'hook-test-secret')),
            'SHA-256 is not the webhook scheme',
        );
        $this->assertFalse(
            $safepay->webhookValid($body, hash_hmac('sha512', $body, 'api-test-secret')),
            'The API secret must not validate a webhook — separate credentials',
        );
        $this->assertFalse(
            $safepay->webhookValid(str_replace('ORDER-1', 'ORDER-2', $body), hash_hmac('sha512', $body, 'hook-test-secret')),
            'A signature must not survive a change to the order it names',
        );
    }

    /**
     * An unverifiable payment is PENDING, never FAILED. With nothing to look
     * up it may still be a payment in flight, and cancelling someone's
     * subscription on that basis is guessing with their money.
     */
    public function test_an_unverifiable_safepay_payment_is_pending_not_failed(): void
    {
        $safepay = app(\App\Services\Billing\Gateways\SafepayGateway::class);

        $result = $safepay->verifyPayment('order-1', ['sig' => 'nonsense']);

        $this->assertFalse($result->paid);
        $this->assertTrue($result->isPending(), 'An unverifiable payment must be reconcilable, not terminal');
    }

    public function test_safepay_serves_pakistan_when_it_has_credentials(): void
    {
        $registry = $this->registryWithSafepay();

        $this->assertSame('safepay', $registry->forClient($this->client('PK'))?->key());
        $this->assertSame('stripe', $registry->forClient($this->client('GB'))?->key());
        $this->assertSame('PKR', $registry->currencyFor($this->client('PK')));
    }

    /**
     * Until a sandbox run proves a saved instrument can be charged with no
     * customer present, Safepay must not claim recurring — claiming it stands
     * the renewal notices down, and customers would lapse silently.
     */
    public function test_safepay_does_not_claim_recurring_until_proven(): void
    {
        $registry = $this->registryWithSafepay();

        $this->assertFalse(
            $registry->billsRecurringItself($this->client('PK')),
            'Renewal notices must keep running until recurring is demonstrated',
        );
    }
}
