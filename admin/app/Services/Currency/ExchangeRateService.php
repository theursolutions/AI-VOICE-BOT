<?php

namespace App\Services\Currency;

use App\Models\Billing\ExchangeRate;
use App\Services\Currency\Drivers\ExchangeRateProvider;
use App\Services\Currency\Drivers\HttpRateProvider;
use App\Services\Currency\Drivers\NullRateProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * USD → local-currency conversion for APPROXIMATE DISPLAY ONLY.
 *
 * THE HARD RULE: nothing this class returns may ever become a charge amount,
 * be sent to Stripe, or be accepted back from a client. Stripe charges the USD
 * `plan_prices.unit_amount`, resolved server-side from a plan slug + interval.
 * See BillingService::checkout().
 *
 * READ PATH (never makes an outbound HTTP call):
 *
 *     Cache  →  exchange_rates table  →  null (render USD only)
 *
 * The middle tier matters: a deploy clears the cache, and if the FX provider
 * happens to be down at that moment a cache-only design blanks the local price
 * for every visitor. A slightly stale rate is fine — the figure is explicitly
 * labelled approximate — so we serve the last good one until it passes
 * `fx.max_age_hours`, then fall back to USD-only rather than show something
 * misleading.
 *
 * WRITE PATH: only `php artisan billing:refresh-rates` (scheduled every 6h).
 * No user request can be slowed down or broken by a third-party API.
 */
class ExchangeRateService
{
    private const CACHE_PREFIX = 'fx:usd:';

    private ?ExchangeRateProvider $provider = null;

    /** Per-request memo — the pricing page converts many amounts per render. */
    private array $memo = [];

    // ── Reading ──────────────────────────────────────────────────────

    /**
     * Rate for 1 USD, or null when unavailable/stale/disabled.
     * Null is a normal outcome: callers must degrade to USD-only.
     */
    public function rateFor(string $currency): ?float
    {
        $currency = strtoupper(trim($currency));

        if ($currency === '' || strlen($currency) !== 3) {
            return null;
        }

        if ($currency === 'USD') {
            return 1.0;
        }

        if (! config('billing.fx.enabled', true)) {
            return null;
        }

        if (array_key_exists($currency, $this->memo)) {
            return $this->memo[$currency];
        }

        return $this->memo[$currency] = $this->lookup($currency);
    }

    private function lookup(string $currency): ?float
    {
        $ttl = (int) config('billing.fx.cache_ttl', 21600);

        // Cache::remember would re-run the closure on every miss; that's fine
        // here because the closure only hits our own DB, never the provider.
        $rate = Cache::remember(
            self::CACHE_PREFIX . $currency,
            $ttl,
            function () use ($currency) {
                $row = ExchangeRate::query()
                    ->where('base', 'USD')
                    ->where('currency', $currency)
                    ->first();

                if (! $row || $row->isStale()) {
                    // Cache the miss as `false` (not null) so a missing
                    // currency doesn't re-query the DB on every render.
                    return false;
                }

                return (float) $row->rate;
            }
        );

        return $rate === false ? null : (float) $rate;
    }

    // ── Converting ───────────────────────────────────────────────────

    /**
     * Convert integer USD cents to a display-rounded local amount.
     * Null when no usable rate exists.
     */
    public function convert(int $usdCents, string $currency): ?float
    {
        $rate = $this->rateFor($currency);

        if ($rate === null) {
            return null;
        }

        return $this->roundForDisplay(($usdCents / 100) * $rate, $currency);
    }

    /**
     * Round so an approximation LOOKS like one.
     *
     * "Rs 5,432.19" reads as a quote a customer might hold us to;
     * "Rs 5,400" reads as the estimate it actually is. The step scales with
     * magnitude so both Rs 5,400 and Rp 300,000 look deliberate.
     *
     * Currencies with real minor units (KWD, BHD, OMR — all worth >$2) keep
     * their decimals, since rounding those to whole units would distort the
     * figure by a meaningful amount.
     */
    public function roundForDisplay(float $amount, string $currency = ''): float
    {
        $decimals = (int) (config("billing.currencies." . strtoupper($currency) . ".decimals") ?? 0);

        if ($decimals > 0) {
            return round($amount, $decimals);
        }

        $step = (int) config('billing.rounding.default_step', 100000);

        foreach ((array) config('billing.rounding.steps', []) as $threshold => $candidate) {
            if ($amount < (float) $threshold) {
                $step = (int) $candidate;
                break;
            }
        }

        return $step <= 1 ? round($amount) : round($amount / $step) * $step;
    }

    /** "Rs 5,400" / "£14" / "AED 70" / "300,000 ₫" — all from config. */
    public function format(float $amount, string $currency): string
    {
        $currency = strtoupper($currency);
        $meta     = (array) config("billing.currencies.{$currency}", []);

        $symbol   = trim((string) ($meta['symbol'] ?? $currency));
        $decimals = (int) ($meta['decimals'] ?? 0);
        $position = $meta['position'] ?? 'before';

        $number = number_format($amount, $decimals);

        if ($position === 'after') {
            return $number . ' ' . $symbol;
        }

        // A LETTER-based symbol needs a separating space ("Rs 5,400", "AED 70",
        // "KSh 2,400"); a glyph must hug the number ("£14", "₹1,600", "$19").
        // Deriving this beats relying on whoever edits the config to remember
        // a trailing space — the bug it replaces rendered "Rs5,400".
        $separator = preg_match('/\p{L}$/u', $symbol) === 1 ? ' ' : '';

        return $symbol . $separator . $number;
    }

    /** Convert + format in one step. Null when unavailable. */
    public function convertAndFormat(int $usdCents, string $currency): ?string
    {
        $amount = $this->convert($usdCents, $currency);

        return $amount === null ? null : $this->format($amount, $currency);
    }

    /** When the displayed rate was last fetched — shown as "rates updated …". */
    public function lastUpdatedAt(string $currency): ?\Illuminate\Support\Carbon
    {
        $row = ExchangeRate::query()
            ->where('base', 'USD')
            ->where('currency', strtoupper($currency))
            ->first();

        return $row?->fetched_at;
    }

    // ── Writing (scheduled command only) ─────────────────────────────

    /**
     * Fetch fresh rates and persist them. Returns the number stored.
     *
     * On provider failure this is a NO-OP by design: it returns 0 and leaves
     * the existing rows untouched, so a bad response can never wipe good data
     * and blank every price on the site.
     */
    public function refresh(): int
    {
        if (! config('billing.fx.enabled', true)) {
            return 0;
        }

        $provider = $this->provider();
        $rates    = $provider->fetchRates('USD');

        if ($rates === []) {
            Log::warning('fx.refresh.no_rates_kept_existing', ['driver' => $provider->name()]);

            return 0;
        }

        // Only currencies we can actually display — no point storing 160 rows
        // when the country map references ~55.
        $wanted = array_unique(array_values((array) config('billing.country_currency', [])));

        $now    = now();
        $stored = 0;

        foreach ($rates as $currency => $rate) {
            if ($wanted !== [] && ! in_array($currency, $wanted, true)) {
                continue;
            }

            ExchangeRate::query()->updateOrCreate(
                ['base' => 'USD', 'currency' => $currency],
                [
                    'rate'       => $rate,
                    'provider'   => $provider->name(),
                    'fetched_at' => $now,
                ]
            );

            Cache::put(
                self::CACHE_PREFIX . $currency,
                (float) $rate,
                (int) config('billing.fx.cache_ttl', 21600)
            );

            $stored++;
        }

        $this->memo = [];

        Log::info('fx.refresh.ok', ['driver' => $provider->name(), 'stored' => $stored]);

        return $stored;
    }

    // ── Provider resolution ──────────────────────────────────────────

    public function provider(): ExchangeRateProvider
    {
        return $this->provider ??= $this->makeProvider((string) config('billing.fx.driver', 'erapi'));
    }

    /** Swap the provider at runtime — used by tests. */
    public function setProvider(ExchangeRateProvider $provider): self
    {
        $this->provider = $provider;
        $this->memo     = [];

        return $this;
    }

    private function makeProvider(string $name): ExchangeRateProvider
    {
        $config = (array) config("billing.fx.drivers.{$name}");

        if ($name === 'null' || $config === [] || empty($config['endpoint'])) {
            return new NullRateProvider();
        }

        return new HttpRateProvider(
            driverName: $name,
            endpoint:   (string) $config['endpoint'],
            ratesPath:  (string) ($config['rates_path'] ?? 'rates'),
            apiKey:     $config['api_key'] ?? null,
            timeout:    (int) ($config['timeout'] ?? 8),
            keyPrefix:  $config['key_prefix'] ?? null,
        );
    }
}
