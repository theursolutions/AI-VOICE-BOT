<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Models\User;
use App\Services\Billing\Gateways\CheckoutHandoff;
use App\Services\Billing\Gateways\PaymentResult;
use App\Services\Billing\Gateways\SafepayGateway;
use App\Services\Billing\GatewayCheckoutService;
use App\Services\Billing\LocalPriceService;
use App\Services\Billing\PlanService;
use Illuminate\Support\Facades\DB;

/**
 * Buying a plan through a gateway that hosts its own checkout page.
 *
 * Two things here matter more than the rest.
 *
 * First, that a Pakistani workspace is quoted and charged in RUPEES, from a
 * real price row. Safepay settles nothing else, and handing it the dollar row
 * sends `7500` to a gateway that reads it as Rs 75 — a $75 plan sold for about
 * a fiftieth of its price, with nothing downstream to notice.
 *
 * Second, that a paid charge puts the workspace ON THE PLAN rather than merely
 * forward in time. Entitlements come from the subscription's plan, so extending
 * dates alone bills someone for Growth and leaves them limited to Starter —
 * correctly charged, wrongly served, and invisible from both ends.
 */
class GatewayCheckoutTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.safepay.api_key'   => 'sec_test',
            'billing.safepay.v1_secret' => 'v1_test',
            'billing.safepay.sandbox'   => true,
            'billing.local_pricing.PKR' => ['rate' => 300, 'step' => 500],
        ]);

        // Nothing here may touch the network. The real gateway opens a payment
        // session at Safepay on every `pay`, which would make this suite slow,
        // flaky, and a producer of real sandbox orders.
        $this->app->bind(SafepayGateway::class, fn () => new FakeSafepay());
    }

    // ── Fixtures ────────────────────────────────────────────────────

    /** @return array{0: Client, 1: User} */
    private function pakistaniWorkspace(): array
    {
        [$client, $owner] = $this->makeWorkspace('Karachi Co', 'owner@karachi.test');

        $client->forceFill([
            'billing_country' => 'PK',
            'billing_email'   => 'owner@karachi.test',
        ])->save();

        $this->mintLocalPrices();

        return [$client->fresh(), $owner];
    }

    /** The rupee rows the shipped `billing:local-prices` command produces. */
    private function mintLocalPrices(): void
    {
        $local = app(LocalPriceService::class);

        Plan::where('is_active', true)->whereIn('type', ['standard', 'addon'])->get()
            ->each(fn (Plan $plan) => $local->mirror($plan, 'PKR'));
    }

    private function pkrPrice(string $slug, string $interval = 'monthly'): PlanPrice
    {
        return $this->plan($slug)->priceFor($interval, 'PKR');
    }

    // ── The currency the customer is actually charged ────────────────

    public function test_a_pakistani_workspace_resolves_the_rupee_price(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $price = app(PlanService::class)->resolvePrice('growth', 'monthly', $client);
        $usd   = $this->plan('growth')->priceFor('monthly', 'USD');

        $this->assertSame('pkr', strtolower($price->currency));

        // The USD price at 300, rounded up to a clean Rs 500, held in paisa.
        $expected = (int) (ceil(($usd->unit_amount / 100 * 300) / 500) * 500) * 100;

        $this->assertSame($expected, $price->unit_amount);
    }

    /**
     * The bug this exists to prevent: handing a rupee gateway the dollar row.
     * Nothing downstream can tell 7500 paisa from 7500 cents.
     */
    public function test_the_dollar_row_is_never_used_for_a_rupee_customer(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $price = app(PlanService::class)->resolvePrice('growth', 'monthly', $client);
        $usd   = $this->plan('growth')->priceFor('monthly', 'USD');

        $this->assertNotSame(
            $usd->unit_amount,
            $price->unit_amount,
            'A dollar amount reached the rupee gateway — this charges a fraction of the price',
        );
    }

    public function test_a_workspace_elsewhere_still_pays_in_the_platform_currency(): void
    {
        [$client] = $this->makeWorkspace('London Ltd', 'owner@london.test');

        $client->forceFill(['billing_country' => 'GB'])->save();

        $price = app(PlanService::class)->resolvePrice('growth', 'monthly', $client->fresh());

        $this->assertSame('usd', strtolower($price->currency));
    }

    /**
     * A rupee row has no Stripe Price and never will. Requiring one would leave
     * a business with no Stripe account — the reason the local gateway exists —
     * unable to sell anything at all.
     */
    public function test_a_local_price_needs_no_stripe_reference(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $this->pkrPrice('growth')->forceFill([
            'stripe_price_ref' => null, 'stripe_synced_at' => null,
        ])->save();

        $price = app(PlanService::class)->resolvePrice('growth', 'monthly', $client);

        $this->assertSame('pkr', strtolower($price->currency));
    }

    // ── The page ────────────────────────────────────────────────────

    public function test_the_hosted_page_is_shown_instead_of_the_card_form(): void
    {
        [$client, $owner] = $this->pakistaniWorkspace();

        $this->actingAs($owner)
            ->get(route('billing.checkout', [
                'client' => $client->slug, 'plan' => 'growth', 'interval' => 'monthly',
            ]))
            ->assertOk()
            ->assertSee($this->pkrPrice('growth')->formatted())
            ->assertSee('JazzCash')
            // The Elements mount point must not be on this page: it cannot take
            // this customer's money, and showing a card form hides the methods
            // they actually came to use.
            ->assertDontSee('ck-element');
    }

    /**
     * A GET must not open a payment session. A prefetch, a refresh or a back
     * button would each raise another charge row and another order at the
     * gateway, and the customer would find payments they never attempted.
     */
    public function test_viewing_the_page_creates_no_charge(): void
    {
        [$client, $owner] = $this->pakistaniWorkspace();

        $before = DB::table('gateway_charges')->count();

        $this->actingAs($owner)->get(route('billing.checkout', [
            'client' => $client->slug, 'plan' => 'growth', 'interval' => 'monthly',
        ]))->assertOk();

        $this->assertSame($before, DB::table('gateway_charges')->count());
    }

    // ── Paying ──────────────────────────────────────────────────────

    public function test_paying_records_a_pending_charge_in_rupees_and_leaves(): void
    {
        [$client, $owner] = $this->pakistaniWorkspace();

        $response = $this->actingAs($owner)->post(
            route('billing.checkout.pay', ['client' => $client->slug]),
            ['plan' => 'growth', 'interval' => 'monthly'],
        );

        $response->assertRedirect();
        $this->assertStringContainsString('fake-checkout.test', (string) $response->headers->get('Location'));

        $charge = DB::table('gateway_charges')->where('client_id', $client->id)->latest('id')->first();

        $this->assertNotNull($charge, 'The customer left for the gateway with no record of the payment');
        $this->assertSame('pending', $charge->status);
        $this->assertSame('PKR', $charge->currency);
        $this->assertSame($this->pkrPrice('growth')->unit_amount, (int) $charge->amount_cents);
    }

    /** The amount never comes from the request — only a slug and an interval do. */
    public function test_a_posted_amount_is_ignored(): void
    {
        [$client, $owner] = $this->pakistaniWorkspace();

        $this->actingAs($owner)->post(
            route('billing.checkout.pay', ['client' => $client->slug]),
            ['plan' => 'growth', 'interval' => 'monthly', 'amount' => 1, 'unit_amount' => 1],
        )->assertRedirect();

        $charge = DB::table('gateway_charges')->where('client_id', $client->id)->latest('id')->first();

        $this->assertSame($this->pkrPrice('growth')->unit_amount, (int) $charge->amount_cents);
    }

    public function test_someone_who_is_not_the_owner_cannot_start_a_payment(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $member = User::create([
            'name' => 'Member', 'email' => 'member@karachi.test', 'password' => bcrypt('password'),
        ]);

        $role = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent',
            'modules' => ['dashboard'], 'is_owner' => false,
            'created_at' => time(), 'updated_at' => time(),
        ]);

        $member->attachMembership($client->id, null, $member->id, $role->id);
        $member->forceFill(['active_client_id' => $client->id, 'email_verified_at' => now()])->save();

        $this->actingAs($member->fresh())->post(
            route('billing.checkout.pay', ['client' => $client->slug]),
            ['plan' => 'growth', 'interval' => 'monthly'],
        )->assertForbidden();

        $this->assertSame(0, DB::table('gateway_charges')->where('client_id', $client->id)->count());
    }

    // ── What a payment actually delivers ────────────────────────────

    public function test_a_paid_charge_moves_the_workspace_onto_the_plan(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $starter = $this->pkrPrice('starter');
        $growth  = $this->pkrPrice('growth');

        $subscription = Subscription::create([
            'client_id' => $client->id, 'plan_id' => $starter->plan_id,
            'plan_price_id' => $starter->id, 'status' => 'active',
            'interval' => 'monthly', 'currency' => 'pkr',
            'unit_amount' => $starter->unit_amount,
            'current_period_end' => now()->addDays(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(GatewayCheckoutService::class)
            ->applyPaidCharge($this->pendingCharge($client, $growth, $subscription));

        $fresh = $subscription->fresh();

        $this->assertSame(
            (int) $growth->plan_id,
            (int) $fresh->plan_id,
            'The customer paid for Growth and was left on Starter',
        );
        $this->assertSame($growth->unit_amount, (int) $fresh->unit_amount);
        $this->assertSame('pkr', strtolower($fresh->currency));
        $this->assertSame('active', $fresh->status);
        $this->assertSame((int) $growth->plan_id, (int) $client->fresh()->current_plan_id);
    }

    /** The new plan's limits must be in force immediately, not once a cache expires. */
    public function test_the_new_allowance_takes_effect_at_once(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $features = app(\App\Services\Billing\PlanFeatureService::class);
        $growth   = $this->pkrPrice('growth');

        // Warm the cache on the OLD state, which is what a real request would
        // have done moments before the payment landed.
        $features->clientLimit($client, 'messages');

        app(GatewayCheckoutService::class)
            ->applyPaidCharge($this->pendingCharge($client, $growth, null));

        $this->assertSame(
            $features->planLimit($this->plan('growth'), 'messages'),
            $features->clientLimit($client->fresh(), 'messages'),
            'The upgrade was paid for but the old limits were still in force',
        );
    }

    /** A first purchase has no subscription to update — one has to be created. */
    public function test_a_first_purchase_creates_the_subscription(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $this->assertNull($client->currentSubscription());

        $subscription = app(GatewayCheckoutService::class)
            ->applyPaidCharge($this->pendingCharge($client, $this->pkrPrice('growth'), null));

        $this->assertNotNull($subscription, 'A paying customer was left with no subscription');
        $this->assertSame((int) $this->pkrPrice('growth')->plan_id, (int) $subscription->plan_id);
    }

    /**
     * A Stripe reference left behind would let the next Stripe webhook
     * overwrite this period from an object that is no longer the truth.
     */
    public function test_a_stale_stripe_reference_is_cleared(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $growth = $this->pkrPrice('growth');

        $subscription = Subscription::create([
            'client_id' => $client->id, 'plan_id' => $growth->plan_id,
            'status' => 'active', 'interval' => 'annually', 'currency' => 'usd',
            'stripe_subscription_ref' => 'sub_old', 'stripe_price_ref' => 'price_old',
            'current_period_end' => now()->addMonths(11),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(GatewayCheckoutService::class)
            ->applyPaidCharge($this->pendingCharge($client, $growth, $subscription));

        $this->assertNull($subscription->fresh()->stripe_subscription_ref);
    }

    // ── Renewal versus change ───────────────────────────────────────

    public function test_renewing_the_same_plan_forfeits_nothing(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $price = $this->pkrPrice('growth');

        $subscription = Subscription::create([
            'client_id' => $client->id, 'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id, 'status' => 'active',
            'interval' => 'monthly', 'currency' => 'pkr',
            'unit_amount' => $price->unit_amount,
            'current_period_end' => now()->addDays(9)->startOfMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(GatewayCheckoutService::class);

        $this->assertTrue($service->isRenewal($price, $subscription));
        $this->assertNull(
            $service->forfeits($client->fresh(), $price),
            'A renewal loses nothing and must not be described as though it did',
        );
    }

    /** Renewing early adds time rather than resetting the clock. */
    public function test_renewing_early_extends_from_the_existing_end_date(): void
    {
        [$client] = $this->pakistaniWorkspace();

        $price = $this->pkrPrice('growth');
        $ends  = now()->addDays(9)->startOfMinute();

        Subscription::create([
            'client_id' => $client->id, 'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id, 'status' => 'active',
            'interval' => 'monthly', 'currency' => 'pkr',
            'unit_amount' => $price->unit_amount, 'current_period_end' => $ends,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $started = app(GatewayCheckoutService::class)->begin($client->fresh(), $price, []);
        $charge  = DB::table('gateway_charges')->where('reference', $started['reference'])->first();

        $this->assertSame(
            $ends->toDateTimeString(),
            (string) \Illuminate\Support\Carbon::parse($charge->period_start)->toDateTimeString(),
            'Renewing early threw away the days already paid for',
        );
    }

    /**
     * Switching plans starts TODAY. Extending from the old period would take a
     * customer leaving an annual plan and start their new one when that year
     * ended: they pay today and get nothing for eleven months.
     */
    public function test_changing_plan_starts_now_and_says_what_is_lost(): void
    {
        [$client, $owner] = $this->pakistaniWorkspace();

        $starterAnnual = $this->pkrPrice('starter', 'annually');
        $growth        = $this->pkrPrice('growth');

        $paidUntil = now()->addMonths(8)->startOfMinute();

        Subscription::create([
            'client_id' => $client->id, 'plan_id' => $starterAnnual->plan_id,
            'plan_price_id' => $starterAnnual->id, 'status' => 'active',
            'interval' => 'annually', 'currency' => 'pkr',
            'unit_amount' => $starterAnnual->unit_amount,
            'current_period_end' => $paidUntil,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $client  = $client->fresh();
        $service = app(GatewayCheckoutService::class);

        $this->assertFalse($service->isRenewal($growth, $client->currentSubscription()));

        $this->assertSame(
            $paidUntil->toDateTimeString(),
            $service->forfeits($client, $growth)?->toDateTimeString(),
        );

        // And the customer is told before they pay, not after.
        $this->actingAs($owner)->get(route('billing.checkout', [
            'client' => $client->slug, 'plan' => 'growth', 'interval' => 'monthly',
        ]))->assertOk()->assertSee('paid up to');

        $started = $service->begin($client, $growth, []);
        $charge  = DB::table('gateway_charges')->where('reference', $started['reference'])->first();

        $this->assertLessThan(
            120,
            abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($charge->period_start))),
            'A plan change was scheduled to begin when the old plan ended',
        );
    }

    // ── Helper ──────────────────────────────────────────────────────

    private function pendingCharge(Client $client, PlanPrice $price, ?Subscription $subscription): object
    {
        $id = DB::table('gateway_charges')->insertGetId([
            'client_id'       => $client->id,
            'subscription_id' => $subscription?->id,
            'plan_price_id'   => $price->id,
            'gateway'         => 'safepay',
            'reference'       => 'T-' . strtoupper(uniqid()),
            'amount_cents'    => $price->unit_amount,
            'currency'        => strtoupper($price->currency),
            'status'          => 'pending',
            'interval'        => $price->interval,
            'period_start'    => now()->startOfMinute(),
            'period_end'      => now()->addMonth()->startOfMinute(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return DB::table('gateway_charges')->find($id);
    }
}

/**
 * Safepay with the network taken out.
 *
 * Everything that decides behaviour is the real class — the key, the currency
 * list, the fact that it hosts its own page. Only the HTTP call that opens a
 * session is replaced. A double that also faked `currencies()` or `key()` would
 * pass while the routing it exists to prove was broken.
 */
class FakeSafepay extends SafepayGateway
{
    public function __construct()
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function startCheckout(Client $client, PlanPrice $price, array $context = []): CheckoutHandoff
    {
        return CheckoutHandoff::redirect(
            'https://fake-checkout.test/pay?beacon=trk_' . uniqid(),
            (string) ($context['basket_id'] ?? 'none'),
        );
    }

    public function verifyPayment(string $reference, array $payload = []): PaymentResult
    {
        return PaymentResult::paid($reference, 0, 'PKR', $payload);
    }
}
