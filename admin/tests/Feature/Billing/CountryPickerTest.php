<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Models\SiteSetting;
use App\Services\Billing\Gateways\GatewayRegistry;
use App\Services\Billing\LocalPriceService;
use App\Services\Billing\PlanService;
use App\Services\Billing\PricingPresenter;
use App\Services\Geo\Drivers\GeoLocationDriver;
use App\Services\Geo\GeoLocationService;
use Illuminate\Http\Request;

/**
 * Where the billing country comes from, and who may change it.
 *
 * The rule in one line: GUESS UNTIL TOLD. An IP address is a good default and a
 * poor verdict — VPNs, travel, and a company registered somewhere other than
 * where its people sit are all ordinary — so the guess follows the customer
 * around until they correct it, and then stops for good.
 *
 * Both halves matter. Without the following, a visitor is quoted the wrong
 * country's prices. Without the stopping, a customer who corrects us is
 * corrected back on their next page load, which is worse than never asking.
 */
class CountryPickerTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.safepay.api_key'   => 'sec_test',
            'billing.safepay.v1_secret' => 'v1_test',
        ]);

        SiteSetting::flushCache();
    }

    protected function tearDown(): void
    {
        SiteSetting::flushCache();

        parent::tearDown();
    }

    /**
     * Pin the IP lookup, so no assertion depends on where the suite is run.
     *
     * The cache and the container instance are both cleared first. Country-by-IP
     * is cached for a day and memoised per instance — sensible in production,
     * and in a test it means the FIRST answer given for an address is the one
     * every later test receives, however the driver is swapped afterwards.
     */
    private function detectAs(?string $country): void
    {
        // Country-by-IP is cached for a day, so without this the FIRST answer
        // given for an address is the one every later test receives however the
        // driver is swapped afterwards.
        \Illuminate\Support\Facades\Cache::flush();

        // BOUND AS AN INSTANCE, not merely configured. GeoLocationService is
        // not registered as a singleton, so every `app()` call builds a fresh
        // one — calling setDriver() on a resolved copy configures a throwaway
        // and the presenter goes on using the null driver from the suite's
        // config. Binding the instance is what makes the fake actually reach it.
        $geo = new GeoLocationService();
        $geo->setDriver(new FakeGeoDriver($country));

        $this->app->instance(GeoLocationService::class, $geo);
        $this->app->forgetInstance(PricingPresenter::class);
    }

    private function context(?Client $client): array
    {
        // A public, routable address. The geo service refuses to look up a
        // private one — which is every request in a test otherwise.
        $request = Request::create('/billing', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);

        return app(PricingPresenter::class)->countryContext($request, $client);
    }

    // ── Guessing ────────────────────────────────────────────────────

    public function test_an_unchosen_country_follows_the_ip(): void
    {
        [$client] = $this->makeWorkspace('Drifter Ltd', 'drift@example.test');

        $this->assertNull($client->billing_country);

        $this->detectAs('PK');
        $context = $this->context($client);

        $this->assertSame('PK', $context['current']);
        $this->assertSame('PKR', $context['currency']);

        // Stored, not merely displayed — this is what the gateway routes on.
        $this->assertSame('PK', $client->fresh()->billing_country);
    }

    public function test_the_guess_keeps_up_when_the_ip_changes(): void
    {
        [$client] = $this->makeWorkspace('Nomad Ltd', 'nomad@example.test');

        $this->detectAs('PK');
        $this->context($client);
        $this->assertSame('PK', $client->fresh()->billing_country);

        $this->detectAs('DE');
        $this->assertSame('DE', $this->context($client->fresh())['current']);
        $this->assertSame('DE', $client->fresh()->billing_country);
    }

    // ── And then stopping ───────────────────────────────────────────

    /** The assertion the whole design turns on. */
    public function test_a_chosen_country_is_never_overridden_by_the_ip(): void
    {
        [$client, $owner] = $this->makeWorkspace('Decided Ltd', 'decided@example.test');

        $this->detectAs('DE');
        $this->context($client);
        $this->assertSame('DE', $client->fresh()->billing_country);

        // The customer says otherwise.
        $this->actingAs($owner)
            ->from(route('billing.plans', ['client' => $client->slug]))
            ->post(route('billing.country', ['client' => $client->slug]), ['country' => 'PK']);

        $this->assertSame('PK', $client->fresh()->billing_country);

        // And the IP keeps insisting.
        $this->detectAs('DE');

        $this->assertSame(
            'PK',
            $this->context($client->fresh())['current'],
            'The customer corrected us and we corrected them back',
        );
        $this->assertSame('PK', $client->fresh()->billing_country);
    }

    /** Re-picking the country you already have is still a decision. */
    public function test_choosing_the_country_the_guess_already_gave_still_sticks(): void
    {
        [$client, $owner] = $this->makeWorkspace('Same Ltd', 'same@example.test');

        $this->detectAs('DE');
        $this->context($client);

        $this->actingAs($owner)
            ->from(route('billing.plans', ['client' => $client->slug]))
            ->post(route('billing.country', ['client' => $client->slug]), ['country' => 'DE']);

        $this->detectAs('PK');

        $this->assertSame('DE', $this->context($client->fresh())['current']);
    }

    /**
     * A workspace part-way through a paid period is denominated already — its
     * invoices, its charge history and its renewal. An airport IP does not get
     * to re-route that.
     */
    public function test_a_paying_workspace_is_not_moved_by_its_ip(): void
    {
        [$client] = $this->makeWorkspace('Settled Ltd', 'settled@example.test');

        $client->forceFill(['billing_country' => 'PK'])->save();

        Subscription::create([
            'client_id'          => $client->id,
            'plan_id'            => $this->plan('growth')->id,
            'status'             => 'active',
            'interval'           => 'monthly',
            'currency'           => 'pkr',
            'unit_amount'        => 2250000,
            'current_period_end' => now()->addDays(12),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $this->detectAs('DE');

        $this->assertSame('PK', $this->context($client->fresh())['current']);
        $this->assertSame('PK', $client->fresh()->billing_country);
    }

    // ── What the choice actually changes ────────────────────────────

    public function test_choosing_a_country_changes_the_gateway_and_the_currency(): void
    {
        [$client, $owner] = $this->makeWorkspace('Switcher Ltd', 'switch@example.test');

        app(LocalPriceService::class)->mirrorAll('PKR');

        $registry = app(GatewayRegistry::class);
        $plans    = app(PlanService::class);

        $pick = function (string $country) use ($owner, $client) {
            $this->actingAs($owner)
                ->from(route('billing.plans', ['client' => $client->slug]))
                ->post(route('billing.country', ['client' => $client->slug]), ['country' => $country]);

            return $client->fresh();
        };

        $pk = $pick('PK');
        $this->assertSame('safepay', $registry->forClient($pk)?->key());
        $this->assertSame('PKR', $registry->currencyFor($pk));
        $this->assertSame('pkr', strtolower($plans->resolvePrice('growth', 'monthly', $pk)->currency));

        $gb = $pick('GB');
        $this->assertNotSame('safepay', $registry->forClient($gb)?->key());
        $this->assertSame('USD', $registry->currencyFor($gb));
        $this->assertSame('usd', strtolower($plans->resolvePrice('growth', 'monthly', $gb)->currency));
    }

    /** A country an operator has switched off cannot arrive via the IP either. */
    public function test_the_guess_respects_the_countries_we_sell_to(): void
    {
        [$client] = $this->makeWorkspace('Blocked Ltd', 'blocked-geo@example.test');

        \App\Support\Payments::setAllowedCountries(['PK']);

        $this->detectAs('DE');
        $this->context($client);

        $this->assertNotSame('DE', $client->fresh()->billing_country);
    }
}

/** A geo driver that answers with whatever the test says. */
class FakeGeoDriver implements GeoLocationDriver
{
    public function __construct(private ?string $country)
    {
    }

    public function countryFor(string $ip): ?string
    {
        return $this->country;
    }

    public function name(): string
    {
        return 'fake';
    }
}
