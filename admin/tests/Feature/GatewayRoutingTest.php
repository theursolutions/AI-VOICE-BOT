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
        config(['billing.payfast.merchant_id' => null, 'billing.payfast.secured_key' => null]);
        app()->forgetInstance(GatewayRegistry::class);

        $registry = app(GatewayRegistry::class);

        $this->assertFalse(app(PayFastGateway::class)->isConfigured());
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
}
