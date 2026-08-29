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
            'billing.safepay.v1_secret'   => null,
        ]);

        app()->forgetInstance(GatewayRegistry::class);

        return app(GatewayRegistry::class);
    }

    private function registryWithSafepay(): GatewayRegistry
    {
        config([
            'billing.safepay.api_key'   => 'sec_test',
            'billing.safepay.v1_secret' => 'v1-test-secret',
            'billing.payfast.merchant_id' => 'TEST-MERCHANT',
            'billing.payfast.secured_key' => 'TEST-KEY',
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

    /** @dataProvider foreignCountries */
    public function test_everyone_else_gets_stripe(?string $country): void
    {
        $registry = $this->registryWithPayFast();

        $this->assertSame('stripe', $registry->forClient($this->client($country))?->key());
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
            'billing.safepay.v1_secret'   => null,
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
     * Safepay's signature is a real HMAC under a shared secret, unlike a scheme
     * whose "signature" carries no secret at all — so verifying the redirect
     * genuinely proves Safepay produced it, and these are the assertions that
     * keep it that way.
     */
    public function test_safepay_verifies_a_genuine_signature_and_rejects_a_forged_one(): void
    {
        config(['billing.safepay.v1_secret' => 'v1-test-secret']);

        $safepay = app(\App\Services\Billing\Gateways\SafepayGateway::class);
        $tracker = 'trk_' . uniqid();
        $valid   = hash_hmac('sha256', $tracker, 'v1-test-secret');

        $this->assertTrue($safepay->signatureValid($tracker, $valid));
        $this->assertFalse($safepay->signatureValid($tracker, $valid . 'x'));
        $this->assertFalse($safepay->signatureValid($tracker, ''));
        $this->assertFalse($safepay->signatureValid('', $valid));
        $this->assertFalse(
            $safepay->signatureValid($tracker, hash_hmac('sha256', 'a-different-tracker', 'v1-test-secret')),
            'A signature for another tracker must not validate this one',
        );
    }

    /**
     * Webhooks use a different secret, a different ALGORITHM and a different
     * input from the redirect: SHA-512 over the `data` object re-encoded, not
     * SHA-256 over the raw body.
     *
     * Pinned against Safepay's own SDK (Verify::webhook) because the first
     * implementation here used the redirect's scheme for both, and the failure
     * is silent in the worst way — every genuine delivery is rejected as a
     * forgery, so payments succeed at Safepay and never post, and the logs show
     * only "invalid signature".
     */
    public function test_safepay_webhooks_use_sha512_over_the_data_object(): void
    {
        config([
            'billing.safepay.v1_secret'      => 'v1-test-secret',
            'billing.safepay.webhook_secret' => 'hook-test-secret',
        ]);

        $safepay = app(\App\Services\Billing\Gateways\SafepayGateway::class);

        $data = ['tracker' => 'trk_1', 'order_id' => 'ORDER-1', 'url' => 'https://example.com/a/b'];
        $body = json_encode(['type' => 'payment.succeeded', 'data' => $data]);

        $correct = hash_hmac('sha512', json_encode($data, JSON_UNESCAPED_SLASHES), 'hook-test-secret');

        $this->assertTrue($safepay->webhookValid($body, $correct));

        $this->assertFalse(
            $safepay->webhookValid($body, hash_hmac('sha256', $body, 'hook-test-secret')),
            'SHA-256 over the raw body is the REDIRECT scheme and must not validate a webhook',
        );
        $this->assertFalse(
            $safepay->webhookValid($body, hash_hmac('sha512', json_encode($data), 'hook-test-secret')),
            'Escaped slashes must not validate — Safepay signs with JSON_UNESCAPED_SLASHES',
        );
        $this->assertFalse(
            $safepay->webhookValid($body, hash_hmac('sha512', json_encode($data, JSON_UNESCAPED_SLASHES), 'v1-test-secret')),
            'The redirect secret must not validate a webhook — separate credentials',
        );
        $this->assertFalse(
            $safepay->webhookValid('{"type":"x"}', $correct),
            'A payload with no data object has nothing to verify',
        );
    }

    /**
     * An unverifiable payment is PENDING, never FAILED. It may be a forgery, but
     * it may equally be a truncated POST or a refresh, and cancelling someone's
     * subscription on that basis is guessing with their money.
     */
    public function test_an_unverifiable_safepay_payment_is_pending_not_failed(): void
    {
        config(['billing.safepay.v1_secret' => 'v1-test-secret']);

        $safepay = app(\App\Services\Billing\Gateways\SafepayGateway::class);

        $noSig = $safepay->verifyPayment('order-1', ['tracker' => 'trk_1']);
        $this->assertFalse($noSig->paid);
        $this->assertTrue($noSig->isPending());

        $badSig = $safepay->verifyPayment('order-1', ['tracker' => 'trk_1', 'sig' => 'nonsense']);
        $this->assertFalse($badSig->paid);
        $this->assertTrue($badSig->isPending(), 'A bad signature must be reconcilable, not terminal');
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
