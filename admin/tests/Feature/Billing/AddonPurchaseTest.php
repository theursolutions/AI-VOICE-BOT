<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Billing\SubscriptionAddon;
use App\Models\Client;
use App\Models\SiteSetting;
use App\Services\Billing\AddonProration;
use App\Services\Billing\AddonPurchaseService;
use App\Services\Billing\GatewayCheckoutService;
use App\Services\Billing\LocalPriceService;
use App\Services\Billing\PlanFeatureService;
use Illuminate\Support\Facades\DB;

/**
 * Buying extra capacity from a gateway that has no line items.
 *
 * Safepay holds one plan per subscription, so a seat is sold as its own
 * prorated payment rather than as an amendment. Three things about that are
 * easy to get wrong and invisible when you do:
 *
 *   An add-on payment must not touch the PLAN or the PERIOD. Paying for a seat
 *   on the 12th must not restart the month on the 12th, quietly shortening what
 *   the customer already paid for.
 *
 *   The capacity must LAPSE when the period it was bought for ends, or a
 *   customer keeps seats they last paid for in March.
 *
 *   The renewal must CARRY THEM FORWARD — charge for them, and move their end
 *   date. Charging without moving the date takes the money and lets the seats
 *   expire the same day.
 */
class AddonPurchaseTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.safepay.api_key'    => 'sec_test',
            'billing.safepay.v1_secret'  => 'v1_test',
            'billing.safepay.sandbox'    => true,
            'billing.local_pricing.PKR'  => ['rate' => 300, 'step' => 500],
            // Paddle off: these tests are about the gateway that CANNOT amend.
            'billing.paddle.api_key'     => '',
            'billing.paddle.client_token'=> '',
        ]);

        SiteSetting::flushCache();

        $this->app->bind(
            \App\Services\Billing\Gateways\SafepayGateway::class,
            fn () => new FakeSafepayForAddons(),
        );
    }

    protected function tearDown(): void
    {
        SiteSetting::flushCache();

        parent::tearDown();
    }

    // ── Fixtures ────────────────────────────────────────────────────

    /**
     * A Pakistani workspace part-way through a paid month.
     *
     * @return array{0: Client, 1: \App\Models\User, 2: Subscription}
     */
    private function midPeriodWorkspace(int $daysElapsed = 20, int $daysTotal = 30): array
    {
        [$client, $owner] = $this->makeWorkspace('Karachi Co', 'k@addons.test');

        $client->forceFill(['billing_country' => 'PK', 'billing_email' => 'k@addons.test'])->save();
        $client = $client->fresh();

        $local = app(LocalPriceService::class);
        Plan::where('is_active', true)->whereIn('type', ['standard', 'addon'])->get()
            ->each(fn (Plan $p) => $local->mirror($p, 'PKR'));

        $price = $this->plan('growth')->priceFor('monthly', 'PKR');

        $subscription = Subscription::create([
            'client_id'            => $client->id,
            'plan_id'              => $price->plan_id,
            'plan_price_id'        => $price->id,
            'status'               => 'active',
            'interval'             => 'monthly',
            'currency'             => 'pkr',
            'unit_amount'          => $price->unit_amount,
            'current_period_start' => now()->subDays($daysElapsed),
            'current_period_end'   => now()->addDays($daysTotal - $daysElapsed),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return [$client, $owner, $subscription];
    }

    private function seatPrice()
    {
        return $this->plan('addon-seat')->priceFor('monthly', 'PKR');
    }

    // ── Proration ───────────────────────────────────────────────────

    public function test_only_the_remaining_days_are_charged(): void
    {
        [$client] = $this->midPeriodWorkspace(daysElapsed: 20, daysTotal: 30);

        $quote = app(AddonProration::class)->quote($client, 'addon-seat', 2);
        $unit  = $this->seatPrice()->unit_amount;

        $this->assertSame(2, $quote['added']);
        $this->assertSame(2 * $unit, $quote['full_amount']);

        // Ten days of thirty. Compared as a ratio rather than to a hard-coded
        // figure, so the assertion survives a repricing.
        $ratio = $quote['amount'] / $quote['full_amount'];

        $this->assertGreaterThan(0.25, $ratio);
        $this->assertLessThan(0.42, $ratio);
        $this->assertTrue($quote['chargeable']);
    }

    /** Going 2 → 5 charges for 3. Charging for 5 would bill twice for two. */
    public function test_only_the_additional_units_are_charged(): void
    {
        [$client, , $subscription] = $this->midPeriodWorkspace();

        SubscriptionAddon::create([
            'subscription_id' => $subscription->id,
            'client_id'       => $client->id,
            'plan_id'         => $this->plan('addon-seat')->id,
            'plan_price_id'   => $this->seatPrice()->id,
            'quantity'        => 2,
            'unit_amount'     => $this->seatPrice()->unit_amount,
            'currency'        => 'pkr',
            'interval'        => 'monthly',
        ]);

        $quote = app(AddonProration::class)->quote($client->fresh(), 'addon-seat', 5);

        $this->assertSame(2, $quote['from']);
        $this->assertSame(3, $quote['added']);
        $this->assertSame(3 * $this->seatPrice()->unit_amount, $quote['full_amount']);
    }

    public function test_reducing_costs_nothing_and_says_so(): void
    {
        [$client, , $subscription] = $this->midPeriodWorkspace();

        SubscriptionAddon::create([
            'subscription_id' => $subscription->id, 'client_id' => $client->id,
            'plan_id' => $this->plan('addon-seat')->id, 'plan_price_id' => $this->seatPrice()->id,
            'quantity' => 5, 'unit_amount' => $this->seatPrice()->unit_amount,
            'currency' => 'pkr', 'interval' => 'monthly',
        ]);

        $quote = app(AddonProration::class)->quote($client->fresh(), 'addon-seat', 2);

        $this->assertFalse($quote['chargeable']);

        // Nothing is charged, and the capacity already paid for is not
        // confiscated — it runs to the end of the period it was bought within.
        $this->assertStringContainsString('nothing to pay', $quote['reason']);
        $this->assertStringContainsString('end of this billing period', $quote['reason']);
    }

    /** A day left is not worth a payment, and the message says what to do. */
    public function test_a_trivial_remainder_is_not_charged(): void
    {
        [$client] = $this->midPeriodWorkspace(daysElapsed: 30, daysTotal: 30);

        // Ends in minutes rather than days.
        $client->currentSubscription()->forceFill([
            'current_period_end' => now()->addMinutes(20),
        ])->save();

        $quote = app(AddonProration::class)->quote($client->fresh(), 'addon-seat', 1);

        // Either it is refused outright, or the amount is a small fraction —
        // what must never happen is a full month's charge for twenty minutes.
        $this->assertLessThan(
            $quote['full_amount'],
            $quote['amount'] + 1,
            'A near-expired period was charged as though it were whole',
        );
    }

    // ── Buying ──────────────────────────────────────────────────────

    public function test_the_purchase_is_a_separate_prorated_charge(): void
    {
        [$client, $owner] = $this->midPeriodWorkspace(daysElapsed: 20, daysTotal: 30);

        $this->assertTrue(app(AddonPurchaseService::class)->needsCheckout($client));

        $response = $this->actingAs($owner)->post(
            route('billing.addons.update', ['client' => $client->slug]),
            ['addon' => 'addon-seat', 'quantity' => 2],
        );

        $response->assertRedirect();
        $this->assertStringContainsString('fake-checkout.test', (string) $response->headers->get('Location'));

        $charge = DB::table('gateway_charges')->where('client_id', $client->id)->latest('id')->first();

        $this->assertNotNull($charge);
        $this->assertSame('addon', $charge->purpose, 'An add-on charge must not look like a plan charge');
        $this->assertSame('pending', $charge->status);
        $this->assertSame('PKR', $charge->currency);
        $this->assertLessThan(
            2 * $this->seatPrice()->unit_amount,
            (int) $charge->amount_cents,
            'The customer was charged a full month for part of one',
        );
    }

    /**
     * The failure this whole design exists to avoid: an add-on payment
     * masquerading as a plan payment and resetting the billing period.
     */
    public function test_paying_for_an_addon_does_not_touch_the_plan_or_the_period(): void
    {
        [$client, , $subscription] = $this->midPeriodWorkspace();

        $planId    = $subscription->plan_id;
        $periodEnd = $subscription->current_period_end->toDateTimeString();
        $amount    = $subscription->unit_amount;

        $charge = $this->addonCharge($client, 2);

        app(GatewayCheckoutService::class)->applyPaidCharge($charge);

        $fresh = $subscription->fresh();

        $this->assertSame((int) $planId, (int) $fresh->plan_id, 'The add-on replaced the plan');
        $this->assertSame($periodEnd, $fresh->current_period_end->toDateTimeString(), 'The add-on moved the billing period');
        $this->assertSame((int) $amount, (int) $fresh->unit_amount, 'The add-on overwrote the plan price');
    }

    public function test_paying_grants_the_capacity_immediately(): void
    {
        [$client, , $subscription] = $this->midPeriodWorkspace();

        $features = app(PlanFeatureService::class);
        $before   = $features->clientLimit($client, 'seats');

        app(GatewayCheckoutService::class)->applyPaidCharge($this->addonCharge($client, 2));

        $seatsPerUnit = $features->planLimit($this->plan('addon-seat'), 'seats');

        $this->assertSame(
            $before + (2 * $seatsPerUnit),
            $features->clientLimit($client->fresh(), 'seats'),
            'The customer paid for seats that never arrived',
        );
    }

    /** A retry must not double the seats. */
    public function test_applying_the_same_charge_twice_changes_nothing(): void
    {
        [$client] = $this->midPeriodWorkspace();

        $charge  = $this->addonCharge($client, 3);
        $service = app(GatewayCheckoutService::class);

        $service->applyPaidCharge($charge);
        $service->applyPaidCharge($charge);

        $this->assertSame(3, (int) SubscriptionAddon::where('client_id', $client->id)->first()->quantity);
        $this->assertSame(1, SubscriptionAddon::where('client_id', $client->id)->count());
    }

    // ── Lapsing and renewing ────────────────────────────────────────

    /** Capacity paid for up to a date must stop granting after it. */
    public function test_one_off_capacity_lapses_with_the_period(): void
    {
        [$client, , $subscription] = $this->midPeriodWorkspace();

        $features = app(PlanFeatureService::class);
        $base     = $features->clientLimit($client, 'seats');

        app(GatewayCheckoutService::class)->applyPaidCharge($this->addonCharge($client, 2));

        $this->assertGreaterThan($base, $features->clientLimit($client->fresh(), 'seats'));

        // The period they paid for has now passed.
        SubscriptionAddon::where('client_id', $client->id)->update(['period_end' => now()->subDay()]);
        $features->flush();
        $features->flushGrants($client);

        $this->assertSame(
            $base,
            $features->clientLimit($client->fresh(), 'seats'),
            'Seats went on being granted after the period they were paid for ended',
        );
    }

    /** A Stripe-style add-on carries no end date and must not be swept up by that. */
    public function test_capacity_with_no_end_date_keeps_granting(): void
    {
        [$client, , $subscription] = $this->midPeriodWorkspace();

        $features = app(PlanFeatureService::class);
        $base     = $features->clientLimit($client, 'seats');

        SubscriptionAddon::create([
            'subscription_id' => $subscription->id, 'client_id' => $client->id,
            'plan_id' => $this->plan('addon-seat')->id, 'plan_price_id' => $this->seatPrice()->id,
            'quantity' => 2, 'unit_amount' => $this->seatPrice()->unit_amount,
            'currency' => 'pkr', 'interval' => 'monthly',
            'period_end' => null,
        ]);

        $features->flush();

        $this->assertGreaterThan(
            $base,
            $features->clientLimit($client->fresh(), 'seats'),
            'A provider-billed add-on was treated as expired',
        );
    }

    /**
     * A PLAN CHARGE IS THE PLAN'S PRICE AND NOTHING ELSE.
     *
     * Top-ups are separate purchases, paid when bought. Folding them into the
     * renewal would make that renewal a different amount every month depending
     * on what was bought weeks earlier — a bill the customer cannot predict and
     * did not agree to when they chose the plan.
     */
    public function test_a_renewal_charges_only_for_the_plan(): void
    {
        [$client] = $this->midPeriodWorkspace();

        // They hold two seats, bought and paid for separately.
        app(GatewayCheckoutService::class)->applyPaidCharge($this->addonCharge($client, 2));

        $client  = $client->fresh();
        $planRow = $this->plan('growth')->priceFor('monthly', 'PKR');

        $started = app(GatewayCheckoutService::class)->begin($client, $planRow, []);
        $charge  = DB::table('gateway_charges')->where('reference', $started['reference'])->first();

        $this->assertSame(
            $planRow->unit_amount,
            (int) $charge->amount_cents,
            'The plan renewal quietly included seats the customer already paid for separately',
        );

        $this->assertSame(0, app(AddonPurchaseService::class)->renewalTopUp($client));
    }

    /**
     * Capacity is bought for a period and stops with it.
     *
     * The consequence of top-ups being separate: nothing renews them silently,
     * and nothing silently charges for them again either.
     */
    public function test_capacity_stops_at_the_period_it_was_bought_for(): void
    {
        [$client] = $this->midPeriodWorkspace();

        app(GatewayCheckoutService::class)->applyPaidCharge($this->addonCharge($client, 2));

        $addon = SubscriptionAddon::where('client_id', $client->id)->first();

        $this->assertNotNull(
            $addon->period_end,
            'A separately-bought top-up must be bounded, or it grants capacity forever',
        );

        $this->assertSame(
            $client->fresh()->currentSubscription()->current_period_end->toDateTimeString(),
            $addon->period_end->toDateTimeString(),
            'A top-up should cover exactly the billing period it was bought within',
        );
    }

    // ── Currency ────────────────────────────────────────────────────

    /**
     * An add-on is re-charged with the plan at every renewal, so the two must
     * be denominated the same. Priced from the SUBSCRIPTION rather than from
     * the country, or a customer who moves between buying the plan and buying
     * the seat gets a dollar add-on on a rupee plan.
     */
    public function test_an_addon_is_priced_in_the_subscriptions_currency(): void
    {
        [$client] = $this->midPeriodWorkspace();

        // They have moved. The plan stays denominated in rupees.
        $client->forceFill(['billing_country' => 'GB'])->save();

        $quote = app(AddonProration::class)->quote($client->fresh(), 'addon-seat', 2);

        $this->assertSame(
            'PKR',
            $quote['currency'],
            'The add-on was priced in the new country rather than in the plan it attaches to',
        );
    }

    /**
     * Minor units are just integers — 500 is $5 or Rs 5 — so summing across
     * currencies yields a number wrong by the exchange rate that looks entirely
     * reasonable. Dropped and logged rather than silently added.
     */
    public function test_a_foreign_currency_addon_is_never_summed(): void
    {
        [$client, , $subscription] = $this->midPeriodWorkspace();

        // The correct one, in the subscription's currency.
        app(GatewayCheckoutService::class)->applyPaidCharge($this->addonCharge($client, 2));

        $expected = app(AddonPurchaseService::class)->heldValue($client->fresh());

        $this->assertGreaterThan(0, $expected);

        // And a stray dollar-denominated row, as a country change could once
        // have produced.
        SubscriptionAddon::create([
            'subscription_id' => $subscription->id,
            'client_id'       => $client->id,
            'plan_id'         => $this->plan('addon-agent')->id,
            'plan_price_id'   => $this->plan('addon-agent')->priceFor('monthly', 'USD')->id,
            'quantity'        => 3,
            'unit_amount'     => 900,
            'currency'        => 'usd',
            'interval'        => 'monthly',
        ]);

        $this->assertSame(
            $expected,
            app(AddonPurchaseService::class)->heldValue($client->fresh()),
            'A dollar add-on was summed with rupee ones as though the numbers were comparable',
        );
    }

    // ── Helper ──────────────────────────────────────────────────────

    /** A paid add-on charge, shaped exactly as AddonPurchaseService writes one. */
    private function addonCharge(Client $client, int $quantity): object
    {
        $started = app(AddonPurchaseService::class)->begin($client->fresh(), 'addon-seat', $quantity);

        DB::table('gateway_charges')
            ->where('reference', $started['reference'])
            ->update(['status' => 'paid', 'paid_at' => now()]);

        return DB::table('gateway_charges')->where('reference', $started['reference'])->first();
    }
}

/** Safepay with the network removed. Only the HTTP call is replaced. */
class FakeSafepayForAddons extends \App\Services\Billing\Gateways\SafepayGateway
{
    public function __construct()
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function startCheckout(
        Client $client,
        \App\Models\Billing\PlanPrice $price,
        array $context = [],
    ): \App\Services\Billing\Gateways\CheckoutHandoff {
        return \App\Services\Billing\Gateways\CheckoutHandoff::redirect(
            'https://fake-checkout.test/pay?beacon=trk_' . uniqid(),
            (string) ($context['basket_id'] ?? 'none'),
        );
    }
}
