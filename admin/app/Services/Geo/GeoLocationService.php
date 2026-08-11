<?php

namespace App\Services\Geo;

use App\Services\Geo\Drivers\GeoLocationDriver;
use App\Services\Geo\Drivers\HttpDriver;
use App\Services\Geo\Drivers\IpLocatorDriver;
use App\Services\Geo\Drivers\NullDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Country / currency detection for DISPLAY pricing.
 *
 * DESIGN RULES, in priority order:
 *
 *  1. This never gates access and never changes what Stripe charges. Total
 *     failure degrades to "no local line", which is always a correct page.
 *  2. Explicit beats inferred: ?country= wins over the cookie, which wins
 *     over the IP. VPN users and anyone we guess wrong about can self-correct,
 *     and it makes the whole feature testable without fixture IPs.
 *  3. Private / loopback IPs resolve to nothing rather than to a wrong
 *     country — on a dev box every visitor would otherwise look identical.
 */
class GeoLocationService
{
    private ?GeoLocationDriver $driver = null;

    /** Per-request memo: the pricing page asks several times while rendering. */
    private ?GeoResult $memo = null;
    private bool $memoSet = false;

    // ── Public API ───────────────────────────────────────────────────

    /**
     * Resolve the visitor's country + currency, or null if unknown.
     * Null is a normal, expected outcome — callers must handle it.
     */
    public function resolve(Request $request): ?GeoResult
    {
        if ($this->memoSet) {
            return $this->memo;
        }

        $this->memoSet = true;

        return $this->memo = $this->detect($request);
    }

    /** Look up a country code directly. Cached per IP. */
    public function countryForIp(string $ip): ?string
    {
        if (! $this->isPublicIp($ip)) {
            return null;
        }

        $ttl = (int) config('billing.geo.cache_ttl', 86400);

        return Cache::remember(
            'geo:ip:' . sha1($ip),
            $ttl,
            fn () => $this->driver()->countryFor($ip)
        );
    }

    /**
     * Build a GeoResult from a country code alone (no IP lookup).
     *
     * Returns NULL for a country we have no currency mapping for. A result
     * with a country but no currency can't do anything useful on a pricing
     * page, and returning one would make callers carry a "known country,
     * unusable" case that only ever means "show USD".
     */
    public function forCountry(?string $countryCode, string $source = 'override'): ?GeoResult
    {
        $code = strtoupper(trim((string) $countryCode));
        if (strlen($code) !== 2 || ! ctype_alpha($code)) {
            return null;
        }

        $currency = config("billing.country_currency.{$code}");

        if (! $currency) {
            return null;
        }

        $meta = config("billing.currencies.{$currency}");

        return new GeoResult(
            countryCode: $code,
            countryName: $this->countryName($code),
            currency:    $currency,
            symbol:      $meta['symbol']   ?? null,
            decimals:    (int) ($meta['decimals'] ?? 0),
            source:      $source,
        );
    }

    /** The cookie name the front end sets when a visitor picks a country. */
    public function cookieName(): string
    {
        return (string) config('billing.geo.cookie', 'serveai_country');
    }

    // ── Detection chain ──────────────────────────────────────────────

    private function detect(Request $request): ?GeoResult
    {
        // 1. Explicit query override — highest priority. Also how the feature
        //    is exercised in tests and by support ("send me /pricing?country=PK").
        if (config('billing.geo.allow_query_override')) {
            $param = (string) config('billing.geo.query_parameter', 'country');
            if ($value = $request->query($param)) {
                if ($result = $this->forCountry(is_string($value) ? $value : null, 'override')) {
                    return $result;
                }
            }
        }

        // 2. Sticky visitor choice.
        if ($cookie = $request->cookie($this->cookieName())) {
            if ($result = $this->forCountry(is_string($cookie) ? $cookie : null, 'cookie')) {
                return $result;
            }
        }

        // 3. IP lookup. TrustProxies is configured for the Caddy→HAProxy chain
        //    (RFC-1918 only), so $request->ip() is the genuine client address
        //    and a spoofed X-Forwarded-For from the public internet is ignored.
        $ip = (string) $request->ip();
        if ($code = $this->countryForIp($ip)) {
            if ($result = $this->forCountry($code, $this->driver()->name())) {
                return $result;
            }
        }

        // 4. Configured fallback market, if the operator set one.
        if ($fallback = config('billing.geo.fallback_country')) {
            return $this->forCountry($fallback, 'fallback');
        }

        // 5. Unknown. USD only — always a correct page.
        return null;
    }

    /**
     * Public, routable IPv4/IPv6 only.
     *
     * Without this every request from a dev machine (127.0.0.1) or from
     * inside the Docker network would be handed to the lookup driver, which
     * at best wastes a call and at worst caches a nonsense answer.
     */
    private function isPublicIp(string $ip): bool
    {
        if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    // ── Driver resolution ────────────────────────────────────────────

    public function driver(): GeoLocationDriver
    {
        return $this->driver ??= $this->makeDriver((string) config('billing.geo.driver', 'maxmind'));
    }

    /** Swap the driver at runtime — used by tests. */
    public function setDriver(GeoLocationDriver $driver): self
    {
        $this->driver  = $driver;
        $this->memo    = null;
        $this->memoSet = false;

        return $this;
    }

    private function makeDriver(string $name): GeoLocationDriver
    {
        return match ($name) {
            // Reuses the app's existing IpLocator (shared .mmdb, shared cache)
            // with a guard that stops a pricing-page render blocking on its
            // HTTP fallback. See IpLocatorDriver.
            'iplocator', 'maxmind' => new IpLocatorDriver(app(\App\Support\IpLocator::class)),
            'http' => new HttpDriver(
                (string) config('billing.geo.http.endpoint'),
                (string) config('billing.geo.http.country_path', 'country_code'),
                (int) config('billing.geo.http.timeout', 3),
            ),
            default => new NullDriver(),
        };
    }

    // ── Country names ────────────────────────────────────────────────

    /**
     * Display name for a country code. Uses ext-intl when available and falls
     * back to a small table of the markets we actually name in the UI — the
     * extension is not guaranteed on every deployment target.
     */
    public function countryName(string $code): ?string
    {
        if (class_exists(\Locale::class) && class_exists(\ResourceBundle::class)) {
            $name = \Locale::getDisplayRegion('-' . $code, 'en');
            if ($name && $name !== $code) {
                return $name;
            }
        }

        return self::COUNTRY_NAMES[$code] ?? null;
    }

    private const COUNTRY_NAMES = [
        'PK' => 'Pakistan',       'IN' => 'India',            'BD' => 'Bangladesh',
        'LK' => 'Sri Lanka',      'NP' => 'Nepal',            'AF' => 'Afghanistan',
        'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
        'KW' => 'Kuwait',         'BH' => 'Bahrain',          'OM' => 'Oman',
        'JO' => 'Jordan',         'EG' => 'Egypt',            'TR' => 'Türkiye',
        'GB' => 'United Kingdom', 'US' => 'United States',    'CA' => 'Canada',
        'AU' => 'Australia',      'NZ' => 'New Zealand',      'SG' => 'Singapore',
        'MY' => 'Malaysia',       'ID' => 'Indonesia',        'PH' => 'Philippines',
        'ZA' => 'South Africa',   'NG' => 'Nigeria',          'KE' => 'Kenya',
        'DE' => 'Germany',        'FR' => 'France',           'ES' => 'Spain',
        'IT' => 'Italy',          'NL' => 'Netherlands',      'IE' => 'Ireland',
    ];
}
