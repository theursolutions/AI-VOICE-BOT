<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\ExchangeRate;
use App\Services\Currency\Drivers\ExchangeRateProvider;
use App\Services\Currency\ExchangeRateService;
use App\Services\Geo\Drivers\GeoLocationDriver;
use App\Services\Geo\GeoLocationService;

/**
 * Regional price DISPLAY: IP → country → currency → approximate local amount.
 *
 * The invariant every test here defends: the local figure is decoration.
 * Stripe always charges the USD amount, and every failure mode degrades to
 * USD-only rather than to an error.
 */
class RegionalPricingTest extends BillingTestCase
{
    private function seedRate(string $currency, float $rate, ?\DateTimeInterface $fetchedAt = null): void
    {
        ExchangeRate::updateOrCreate(
            ['base' => 'USD', 'currency' => $currency],
            ['rate' => $rate, 'provider' => 'test', 'fetched_at' => $fetchedAt ?? now()]
        );
    }

    /**
     * Force the IP driver to report a country.
     *
     * Needed because the request IP in tests is 127.0.0.1, which
     * GeoLocationService correctly refuses to look up — so the IP path can
     * only be exercised by swapping the driver AND a public address.
     */
    private function driverReporting(?string $code): GeoLocationDriver
    {
        return new class($code) implements GeoLocationDriver {
            public function __construct(private ?string $code)
            {
            }

            public function name(): string
            {
                return 'test';
            }

            public function countryFor(string $ip): ?string
            {
                return $this->code;
            }
        };
    }

    // ── Conversion ───────────────────────────────────────────────────

    public function test_usd_is_converted_and_rounded_so_it_reads_as_approximate(): void
    {
        $this->seedRate('PKR', 283.4123);

        $fx = app(ExchangeRateService::class);

        // $19 × 283.4123 = 5,384.83 → rounded to a "nice" step.
        // "Rs 5,384.83" reads like a quote; "Rs 5,400" reads like an estimate.
        $this->assertSame(5400.0, $fx->convert(1900, 'PKR'));
        $this->assertSame('Rs 5,400', $fx->convertAndFormat(1900, 'PKR'));

        // $59 × 283.4123 = 16,721.3 → 16,500 on a 500 step.
        $this->assertSame('Rs 16,500', $fx->convertAndFormat(5900, 'PKR'));

        // An alphabetic symbol gets a separating space; a glyph must not.
        $this->seedRate('GBP', 0.7891);
        $this->assertSame('£15', $fx->convertAndFormat(1900, 'GBP'));
    }

    public function test_rounding_never_overstates_the_price_by_much(): void
    {
        // Reading HIGHER than we actually charge is the one direction an
        // approximation must not err in — it makes us look more expensive.
        $this->seedRate('PKR', 283.4123);
        $this->seedRate('AED', 3.6725);

        $fx = app(ExchangeRateService::class);

        foreach ([1900, 5900, 14900, 19000, 59000, 149000] as $cents) {
            foreach (['PKR', 'AED'] as $currency) {
                $exact   = ($cents / 100) * $fx->rateFor($currency);
                $shown   = $fx->convert($cents, $currency);
                $driftPc = abs($shown - $exact) / $exact * 100;

                $this->assertLessThan(
                    2.0,
                    $driftPc,
                    "{$currency} {$cents}c drifted {$driftPc}% (exact {$exact}, shown {$shown})"
                );
            }
        }
    }

    public function test_currencies_with_real_minor_units_keep_their_decimals(): void
    {
        // KWD is worth >$3; rounding it to whole units would distort the
        // figure by a meaningful amount.
        $this->seedRate('KWD', 0.3065);

        $this->assertSame('KWD 5.82', app(ExchangeRateService::class)->convertAndFormat(1900, 'KWD'));
    }

    public function test_symbol_and_position_come_from_config(): void
    {
        $this->seedRate('GBP', 0.7891);
        $this->seedRate('VND', 25400.0);

        $fx = app(ExchangeRateService::class);

        $this->assertStringStartsWith('£', (string) $fx->convertAndFormat(1900, 'GBP'));
        // VND puts the symbol after the number.
        $this->assertStringEndsWith('₫', (string) $fx->convertAndFormat(1900, 'VND'));
    }

    public function test_usd_always_converts_one_to_one_without_a_stored_rate(): void
    {
        $this->assertSame(1.0, app(ExchangeRateService::class)->rateFor('USD'));
    }

    // ── Failure modes: all degrade to USD-only ───────────────────────

    public function test_a_missing_rate_returns_null_rather_than_guessing(): void
    {
        $fx = app(ExchangeRateService::class);

        $this->assertNull($fx->rateFor('ZWL'));
        $this->assertNull($fx->convert(1900, 'ZWL'));
        $this->assertNull($fx->convertAndFormat(1900, 'ZWL'));
    }

    public function test_a_stale_rate_is_refused(): void
    {
        // A wrong-looking number is worse than no number, even labelled
        // "approximate".
        $this->seedRate('PKR', 283.41, now()->subDays(30));

        $this->assertNull(app(ExchangeRateService::class)->rateFor('PKR'));
    }

    public function test_a_provider_outage_leaves_existing_rates_untouched(): void
    {
        $this->seedRate('PKR', 283.41);

        $failing = new class implements ExchangeRateProvider {
            public function name(): string { return 'broken'; }
            public function fetchRates(string $base = 'USD'): array { return []; }
        };

        $fx = app(ExchangeRateService::class);
        $fx->setProvider($failing);

        $this->assertSame(0, $fx->refresh(), 'A failed fetch must be a no-op.');

        // The critical part: a bad response must never wipe good data and
        // blank every price on the site.
        $this->assertSame(283.41, (float) ExchangeRate::where('currency', 'PKR')->value('rate'));
    }

    public function test_refresh_stores_rates_and_ignores_junk_values(): void
    {
        $provider = new class implements ExchangeRateProvider {
            public function name(): string { return 'test'; }
            public function fetchRates(string $base = 'USD'): array
            {
                return ['PKR' => 283.41, 'GBP' => 0.79, 'BAD' => 0.0, 'XXX' => -5.0];
            }
        };

        $fx = app(ExchangeRateService::class);
        $fx->setProvider($provider);
        $fx->refresh();

        $this->assertSame(283.41, (float) ExchangeRate::where('currency', 'PKR')->value('rate'));

        // A zero or negative rate would silently render a price of 0.
        $this->assertDatabaseMissing('exchange_rates', ['currency' => 'BAD']);
        $this->assertDatabaseMissing('exchange_rates', ['currency' => 'XXX']);
    }

    public function test_the_pricing_page_never_calls_the_fx_provider(): void
    {
        $this->seedRate('PKR', 283.41);

        $provider = new class implements ExchangeRateProvider {
            public bool $called = false;
            public function name(): string { return 'spy'; }
            public function fetchRates(string $base = 'USD'): array
            {
                $this->called = true;

                return ['PKR' => 1.0];
            }
        };

        app(ExchangeRateService::class)->setProvider($provider);

        $this->get('/pricing?country=PK')->assertOk();

        // A visitor request must never be able to trigger an outbound FX call.
        $this->assertFalse($provider->called);
    }

    // ── Country detection ────────────────────────────────────────────

    public function test_country_maps_to_currency_and_symbol(): void
    {
        $geo = app(GeoLocationService::class);

        $pk = $geo->forCountry('PK');
        $this->assertSame('PKR', $pk->currency);
        $this->assertSame('Rs', $pk->symbol);
        $this->assertSame('Pakistan', $pk->countryName);

        $this->assertSame('AED', $geo->forCountry('AE')->currency);
        $this->assertSame('GBP', $geo->forCountry('GB')->currency);
        $this->assertSame('EUR', $geo->forCountry('DE')->currency);
        $this->assertSame('SAR', $geo->forCountry('SA')->currency);
    }

    public function test_a_us_visitor_gets_no_conversion_line(): void
    {
        $geo = app(GeoLocationService::class);
        $us  = $geo->forCountry('US');

        // "≈ $19" under "$19" is noise.
        $this->assertTrue($us->isUsd());
    }

    public function test_an_unknown_country_yields_nothing_rather_than_a_wrong_guess(): void
    {
        $geo = app(GeoLocationService::class);

        $this->assertNull($geo->forCountry(null));
        $this->assertNull($geo->forCountry('ZZ'));
        $this->assertNull($geo->forCountry('nonsense'));
    }

    public function test_private_and_loopback_addresses_are_never_looked_up(): void
    {
        // A driver that would answer for ANY address, to prove the guard
        // short-circuits before the driver is ever consulted.
        $geo = (new GeoLocationService())->setDriver($this->driverReporting('PK'));

        // On a dev box every visitor would otherwise look identical, and the
        // driver would burn a lookup on an unresolvable address.
        $this->assertNull($geo->countryForIp('127.0.0.1'));
        $this->assertNull($geo->countryForIp('192.168.1.10'));
        $this->assertNull($geo->countryForIp('10.0.0.5'));
        $this->assertNull($geo->countryForIp('::1'));
        $this->assertNull($geo->countryForIp(''));

        // ...but a public address does resolve.
        $this->assertSame('PK', $geo->countryForIp('39.63.1.1'));
    }

    public function test_ip_detection_feeds_the_currency_when_no_override_is_present(): void
    {
        $geo = (new GeoLocationService())->setDriver($this->driverReporting('AE'));

        $request = \Illuminate\Http\Request::create('/pricing', 'GET', server: ['REMOTE_ADDR' => '94.200.1.1']);

        $result = $geo->resolve($request);

        $this->assertNotNull($result);
        $this->assertSame('AE', $result->countryCode);
        $this->assertSame('AED', $result->currency);
        $this->assertSame('test', $result->source);
    }

    public function test_an_explicit_country_override_beats_ip_detection(): void
    {
        // VPN users, and anyone we guess wrong about, must be able to correct it.
        $geo = (new GeoLocationService())->setDriver($this->driverReporting('US'));

        $request = \Illuminate\Http\Request::create('/pricing?country=PK', 'GET', server: ['REMOTE_ADDR' => '8.8.8.8']);

        $result = $geo->resolve($request);

        $this->assertSame('PKR', $result->currency);
        $this->assertSame('override', $result->source);
    }

    public function test_a_geolocation_failure_degrades_to_usd_only(): void
    {
        $geo = (new GeoLocationService())->setDriver($this->driverReporting(null));

        $request = \Illuminate\Http\Request::create('/pricing', 'GET', server: ['REMOTE_ADDR' => '8.8.8.8']);

        // Null is a normal, expected outcome — never an exception.
        $this->assertNull($geo->resolve($request));
    }

    // ── The page itself ──────────────────────────────────────────────

    public function test_the_pricing_page_shows_usd_and_an_approximate_local_price(): void
    {
        $this->seedRate('PKR', 283.4123);

        $response = $this->get('/pricing?country=PK');

        $response->assertOk();
        $response->assertSee('$19', false);
        $response->assertSee('$59', false);
        $response->assertSee('Rs 5,400', false);
        $response->assertSee('All plans are charged in USD', false);
        $response->assertSee('approximate', false);
    }

    public function test_the_pricing_page_still_renders_when_geo_and_fx_are_unavailable(): void
    {
        // No rates seeded, no country detected. This is the most common
        // real-world state (no .mmdb on a fresh box) and must be a good page.
        $response = $this->get('/pricing');

        $response->assertOk();
        $response->assertSee('$19', false);
        $response->assertSee('$149', false);
        $response->assertDontSee('≈', false);
    }

    public function test_the_pricing_page_renders_without_a_rate_for_the_detected_country(): void
    {
        // Country known, rate missing — the local line must simply not appear.
        $response = $this->get('/pricing?country=PK');

        $response->assertOk();
        $response->assertSee('$19', false);
        $response->assertDontSee('Rs ', false);
    }

    public function test_the_interval_toggle_switches_the_rendered_price(): void
    {
        $this->get('/pricing?billing=annually')
             ->assertOk()
             ->assertSee('$590', false)
             ->assertSee('Save 17%', false);
    }

    public function test_quarterly_is_not_offered_on_the_page_by_default(): void
    {
        // Supported by the schema, deliberately not shown — no competitor
        // publishes a quarterly price and the approved offer is monthly+annual.
        $this->assertSame(['monthly', 'annually'], config('billing.intervals.offered'));

        $intervals = app(\App\Services\Billing\PlanService::class)->offeredIntervals();

        $this->assertNotContains('quarterly', $intervals);
    }

    public function test_the_pricing_page_can_be_switched_off_by_a_super_admin(): void
    {
        \App\Models\SiteSetting::set('billing.pricing_page_enabled', false);
        \App\Models\SiteSetting::flushCache();

        $this->get('/pricing')->assertNotFound();
    }

    public function test_pricing_is_in_the_sitemap_and_indexable(): void
    {
        $body = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString('/pricing', $body);

        $this->get('/pricing')->assertDontSee('noindex', false);
    }
}
