<?php

namespace Tests\Feature\Billing;

use App\Models\SiteSetting;
use App\Services\Billing\Gateways\GatewayRegistry;
use App\Services\Billing\Gateways\PaddleGateway;
use App\Services\Billing\Gateways\PaymentGateway;
use App\Support\Payments;

/**
 * Who takes the money, from where, and in what currency.
 *
 * The routing rule is the CUSTOMER'S country, and it is theirs to set. Every
 * assertion here exists because getting one of them wrong is invisible from our
 * side and total from theirs: a Karachi buyer shown a Visa-only form, a German
 * one quoted rupees, or a provider switched on with no credentials and skipped
 * silently at the moment somebody tries to pay.
 */
class PaymentRoutingTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.safepay.api_key'    => 'sec_test',
            'billing.safepay.v1_secret'  => 'v1_test',
            'billing.paddle.api_key'     => 'apikey_test',
            'billing.paddle.client_token'=> 'test_token',
            'billing.paddle.webhook_secret' => 'pdl_ntfset_test',
        ]);

        // The switchboard lives in site_settings, which memoises the whole
        // table in a STATIC — so it survives the transaction rollback and would
        // otherwise leak a narrowed country list into every later test.
        SiteSetting::flushCache();
    }

    protected function tearDown(): void
    {
        SiteSetting::flushCache();

        parent::tearDown();
    }

    private function registry(): GatewayRegistry
    {
        return app(GatewayRegistry::class);
    }

    // ── Country → gateway ───────────────────────────────────────────

    public function test_pakistan_is_served_by_the_local_gateway(): void
    {
        $this->assertSame('safepay', $this->registry()->forCountry('PK')?->key());
        $this->assertSame('PKR', $this->registry()->currencyForCountry('PK'));
    }

    public function test_everywhere_else_is_served_by_paddle(): void
    {
        foreach (['GB', 'US', 'DE', 'AE', 'AU'] as $country) {
            $this->assertSame(
                'paddle',
                $this->registry()->forCountry($country)?->key(),
                "{$country} should route to Paddle",
            );
            $this->assertSame('USD', $this->registry()->currencyForCountry($country));
        }
    }

    /** A workspace we cannot place still gets a checkout rather than none. */
    public function test_an_unknown_country_still_gets_a_gateway(): void
    {
        $this->assertNotNull($this->registry()->forCountry(null));
        $this->assertNotNull($this->registry()->forCountry(''));
    }

    public function test_the_country_is_read_from_the_workspace(): void
    {
        [$client] = $this->makeWorkspace('Karachi Co', 'k@example.test');

        $client->forceFill(['billing_country' => 'PK'])->save();
        $this->assertSame('safepay', $this->registry()->forClient($client->fresh())?->key());

        $client->forceFill(['billing_country' => 'DE'])->save();
        $this->assertSame('paddle', $this->registry()->forClient($client->fresh())?->key());
    }

    // ── The switchboard ─────────────────────────────────────────────

    /**
     * The whole migration to Stripe, in one setting. Nothing is deleted to get
     * there and nothing is deployed.
     */
    public function test_switching_a_gateway_off_reroutes_its_customers(): void
    {
        config(['billing.stripe.secret' => 'sk_test_x', 'billing.stripe.key' => 'pk_test_x']);

        Payments::setEnabledGateways(['stripe']);

        $this->assertSame('stripe', $this->registry()->forCountry('PK')?->key());
        $this->assertSame('stripe', $this->registry()->forCountry('GB')?->key());
    }

    /**
     * Enabled is permission; configured is capability. A provider missing
     * either is skipped rather than chosen and failed at — otherwise a
     * half-finished setup breaks checkout for customers another gateway was
     * already serving.
     */
    public function test_a_gateway_with_no_credentials_is_skipped_even_when_enabled(): void
    {
        config(['billing.paddle.api_key' => '', 'billing.paddle.client_token' => '']);

        Payments::setEnabledGateways(Payments::KNOWN);

        $this->assertNotSame('paddle', $this->registry()->forCountry('GB')?->key());
    }

    public function test_a_new_gateway_is_off_until_an_operator_says_otherwise(): void
    {
        // An operator has made a decision that does not mention Paddle. Storing
        // what is DISABLED would have switched it on the day it shipped.
        Payments::setEnabledGateways(['safepay']);

        $this->assertFalse(Payments::gatewayEnabled('paddle'));
        $this->assertNotSame('paddle', $this->registry()->forCountry('GB')?->key());
    }

    // ── Country restrictions ────────────────────────────────────────

    /**
     * `null` and `[]` mean opposite things. Coalescing them would turn "sell
     * everywhere" into "sell nowhere" the first time somebody saved the form.
     */
    public function test_no_restriction_is_not_the_same_as_an_empty_one(): void
    {
        Payments::allowAllCountries();

        $this->assertNull(Payments::allowedCountries());
        $this->assertTrue(Payments::countryAllowed('DE'));
        $this->assertGreaterThan(50, count(Payments::sellableCountries()));

        Payments::setAllowedCountries([]);

        $this->assertSame([], Payments::allowedCountries());
        $this->assertFalse(Payments::countryAllowed('DE'));
    }

    public function test_a_restriction_narrows_what_can_be_chosen(): void
    {
        Payments::setAllowedCountries(['PK', 'GB']);

        $sellable = Payments::sellableCountries();

        $this->assertCount(2, $sellable);
        $this->assertArrayHasKey('PK', $sellable);
        $this->assertArrayNotHasKey('DE', $sellable);
        $this->assertFalse(Payments::countryAllowed('DE'));
    }

    /** Detection failing is our problem, not the visitor's. */
    public function test_a_visitor_we_cannot_place_is_not_refused(): void
    {
        Payments::setAllowedCountries(['PK']);

        $this->assertTrue(Payments::countryAllowed(null));
        $this->assertTrue(Payments::countryAllowed(''));
    }

    /**
     * The Ops page states that an unselected country cannot reach checkout.
     * Until this was enforced that was simply untrue — the restriction filtered
     * the dropdown and nothing else, so a workspace whose country was set
     * before the restriction walked straight past it.
     */
    public function test_a_restricted_country_cannot_reach_checkout(): void
    {
        [$client, $owner] = $this->makeWorkspace('Excluded Ltd', 'excluded@example.test');

        $client->forceFill(['billing_country' => 'DE'])->save();

        // Selling to Pakistan only, as an operator would set for Safepay-only.
        Payments::setAllowedCountries(['PK']);

        $this->actingAs($owner)
            ->get(route('billing.checkout', [
                'client' => $client->slug, 'plan' => 'growth', 'interval' => 'monthly',
            ]))
            ->assertRedirect(route('billing.plans', ['client' => $client->slug]))
            ->assertSessionHas('error');
    }

    /** A guard the form can be posted around is not a guard. */
    public function test_a_restricted_country_cannot_post_a_payment_either(): void
    {
        [$client, $owner] = $this->makeWorkspace('Sneaky Ltd', 'sneaky@example.test');

        $client->forceFill(['billing_country' => 'DE'])->save();

        Payments::setAllowedCountries(['PK']);

        $this->actingAs($owner)
            ->from(route('billing.plans', ['client' => $client->slug]))
            ->post(route('billing.checkout.pay', ['client' => $client->slug]), [
                'plan' => 'growth', 'interval' => 'monthly',
            ])
            ->assertSessionHas('error');

        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('gateway_charges')->where('client_id', $client->id)->count(),
            'A charge was raised for a country we have stopped selling to',
        );
    }

    /** An allowed country is unaffected — the restriction is not a blanket stop. */
    public function test_an_allowed_country_still_reaches_checkout(): void
    {
        [$client, $owner] = $this->makeWorkspace('Karachi Ltd', 'karachi-ok@example.test');

        $client->forceFill(['billing_country' => 'PK'])->save();

        app(\App\Services\Billing\LocalPriceService::class)->mirrorAll('PKR');

        Payments::setAllowedCountries(['PK']);

        $this->actingAs($owner)
            ->get(route('billing.checkout', [
                'client' => $client->slug, 'plan' => 'growth', 'interval' => 'monthly',
            ]))
            ->assertOk();
    }

    /**
     * Failing to detect somebody is our problem, not a reason to refuse their
     * money — so a workspace with no country set is let through.
     */
    public function test_a_workspace_with_no_country_is_not_refused(): void
    {
        [$client, $owner] = $this->makeWorkspace('Unknown Ltd', 'unknown@example.test');

        Payments::setAllowedCountries(['PK']);

        $this->assertNull($client->fresh()->billing_country);

        $this->actingAs($owner)
            ->get(route('billing.checkout', [
                'client' => $client->slug, 'plan' => 'growth', 'interval' => 'monthly',
            ]))
            ->assertOk();
    }

    // ── Capabilities ────────────────────────────────────────────────

    /**
     * The answer every part of the subscription lifecycle needs before it
     * assumes a renewal will happen on its own — and the reason the renewal
     * notices exist for Safepay and stand down for Paddle.
     */
    public function test_only_the_gateways_that_really_bill_renewals_claim_to(): void
    {
        $this->assertTrue($this->registry()->get('paddle')->supports(PaymentGateway::CAP_RECURRING));
        $this->assertFalse($this->registry()->get('safepay')->supports(PaymentGateway::CAP_RECURRING));
    }

    // ── The picker ──────────────────────────────────────────────────

    public function test_an_owner_can_change_the_country_and_it_sticks(): void
    {
        [$client, $owner] = $this->makeWorkspace('Movers Ltd', 'movers@example.test');

        $this->actingAs($owner)
            ->from(route('billing.plans', ['client' => $client->slug]))
            ->post(route('billing.country', ['client' => $client->slug]), ['country' => 'DE'])
            ->assertRedirect();

        $this->assertSame('DE', $client->fresh()->billing_country);
        $this->assertSame('paddle', $this->registry()->forClient($client->fresh())?->key());
    }

    /** A country an operator has switched off must not be selectable. */
    public function test_a_country_we_do_not_sell_to_is_refused(): void
    {
        [$client, $owner] = $this->makeWorkspace('Blocked Ltd', 'blocked@example.test');

        Payments::setAllowedCountries(['PK']);

        $this->actingAs($owner)
            ->from(route('billing.plans', ['client' => $client->slug]))
            ->post(route('billing.country', ['client' => $client->slug]), ['country' => 'DE'])
            ->assertSessionHas('error');

        $this->assertNotSame('DE', $client->fresh()->billing_country);
    }

    /** The country decides the invoice currency; a member does not set it. */
    public function test_a_member_cannot_change_the_workspace_country(): void
    {
        [$client] = $this->makeWorkspace('Member Ltd', 'owner-m@example.test');

        $client->forceFill(['billing_country' => 'PK'])->save();

        $member = \App\Models\User::create([
            'name' => 'Member', 'email' => 'member-m@example.test', 'password' => bcrypt('password'),
        ]);

        $role = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent',
            'modules' => ['dashboard'], 'is_owner' => false,
            'created_at' => time(), 'updated_at' => time(),
        ]);

        $member->attachMembership($client->id, null, $member->id, $role->id);
        $member->forceFill(['active_client_id' => $client->id, 'email_verified_at' => now()])->save();

        $this->actingAs($member->fresh())
            ->from(route('billing.index', ['client' => $client->slug]))
            ->post(route('billing.country', ['client' => $client->slug]), ['country' => 'DE']);

        $this->assertSame(
            'PK',
            $client->fresh()->billing_country,
            'A member changed the currency the workspace is invoiced in',
        );
    }

    // ── Paddle's webhook signature ──────────────────────────────────

    /**
     * Without this check the webhook is an unauthenticated "make me a
     * subscriber" endpoint, so these are the assertions that matter most.
     */
    public function test_paddle_webhook_signatures(): void
    {
        $paddle = app(PaddleGateway::class);
        $secret = 'pdl_ntfset_test';
        $body   = '{"event_type":"transaction.completed","data":{"id":"txn_1"}}';
        $ts     = time();

        $sign = fn (string $b, int $t, string $s) => 'ts=' . $t . ';h1=' . hash_hmac('sha256', $t . ':' . $b, $s);

        $this->assertTrue($paddle->webhookValid($body, $sign($body, $ts, $secret)));
        $this->assertFalse($paddle->webhookValid($body, $sign($body, $ts, 'wrong-secret')));
        $this->assertFalse($paddle->webhookValid('{"tampered":true}', $sign($body, $ts, $secret)));
        $this->assertFalse($paddle->webhookValid($body, ''));
        $this->assertFalse($paddle->webhookValid($body, 'garbage'));
    }

    /**
     * Paddle retries with the ORIGINAL timestamp, so a tight tolerance rejects
     * every retry — the delivery that only happens when the first one failed.
     * Their own documentation suggests five seconds; this is why we do not.
     */
    public function test_a_retry_with_an_old_timestamp_is_still_accepted(): void
    {
        $paddle = app(PaddleGateway::class);
        $body   = '{"event_type":"transaction.completed"}';
        $old    = time() - 3600;

        $this->assertTrue($paddle->webhookValid(
            $body,
            'ts=' . $old . ';h1=' . hash_hmac('sha256', $old . ':' . $body, 'pdl_ntfset_test'),
        ));
    }

    /** But not forever: a captured delivery must not be replayable next week. */
    public function test_a_very_old_delivery_is_refused(): void
    {
        $paddle = app(PaddleGateway::class);
        $body   = '{"event_type":"transaction.completed"}';
        $stale  = time() - (86400 * 3);

        $this->assertFalse($paddle->webhookValid(
            $body,
            'ts=' . $stale . ';h1=' . hash_hmac('sha256', $stale . ':' . $body, 'pdl_ntfset_test'),
        ));
    }

    public function test_the_webhook_route_refuses_a_forged_delivery(): void
    {
        $this->call(
            'POST',
            '/billing/paddle/webhook',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_PADDLE_SIGNATURE' => 'ts=' . time() . ';h1=deadbeef'],
            '{"event_type":"transaction.completed","data":{"id":"txn_forged"}}',
        )->assertStatus(400);
    }

    // ── Ops → Payments ──────────────────────────────────────────────

    public function test_the_payments_page_refuses_to_switch_everything_off(): void
    {
        $admin = \App\Models\User::create([
            'name' => 'Ops', 'email' => 'ops-p@example.test',
            'password' => bcrypt('password'), 'is_super_admin' => 1,
            'email_verified_at' => now(),
        ]);

        $before = Payments::enabledGateways();

        $this->actingAs($admin)
            ->from(route('ops.payments.index'))
            ->post(route('ops.payments.update'), ['gateways' => []])
            ->assertSessionHas('error');

        $this->assertSame(
            $before,
            Payments::enabledGateways(),
            'Every payment provider was switched off, which looks to customers like a broken checkout',
        );
    }

    /**
     * 404, not 403 — IsSuperAdmin hides the ops console rather than admitting
     * it exists. A 403 would confirm the URL to anyone who guessed it.
     */
    public function test_the_payments_page_is_super_admin_only(): void
    {
        [, $owner] = $this->makeWorkspace('Nosy Ltd', 'nosy@example.test');

        $this->actingAs($owner)->get(route('ops.payments.index'))->assertStatus(404);
    }
}
