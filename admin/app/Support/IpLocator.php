<?php

namespace App\Support;

use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IP → approximate location.
 *
 * Two drivers, tried in this order:
 *
 *   maxmind — a local GeoLite2 .mmdb file. No network call, nothing leaves
 *             the server, microseconds per lookup. Used automatically when
 *             the file exists (the geoip2/geoip2 package is already a
 *             dependency). This is the one you want in production.
 *   http    — a free JSON endpoint, for boxes with no .mmdb. Rate-limited
 *             by the provider, so it is NEVER called during a visitor's
 *             request: the visitor row is written with geo_status=pending
 *             and resolved afterwards by `php artisan visitors:geolocate`
 *             or the "Resolve locations" button in the ops console.
 *
 * Results are cached per IP because location-by-IP barely moves.
 */
final class IpLocator
{
    /**
     * Resolve an address.
     *
     * @return array<string,mixed> keys: status, continent, country, country_code,
     *         region, city, postal, timezone, org, asn, connection_type,
     *         latitude, longitude
     */
    public function locate(string $ip): array
    {
        $ip = trim($ip);

        if ($ip === '' || ! $this->isPublic($ip)) {
            // Loopback, LAN, CGNAT — no amount of retrying will place these.
            return $this->blank('private');
        }

        $ttl = (int) config('visitors.geo.cache_ttl', 86400);

        return Cache::remember('ipgeo:' . sha1($ip), $ttl, function () use ($ip) {
            $viaDb = $this->viaMaxmind($ip);
            if ($viaDb !== null) {
                return $viaDb;
            }

            return $this->viaHttp($ip) ?? $this->blank('failed');
        });
    }

    /**
     * True when a lookup can be answered with no outbound HTTP request —
     * i.e. a local database is present. The middleware uses this to decide
     * whether it can safely resolve inline or must defer.
     */
    public function isInstant(): bool
    {
        return is_file((string) config('visitors.geo.database_path'));
    }

    /**
     * True when THIS address can be settled without any network call — either
     * a local database is installed, or the address is private/loopback and so
     * has a known answer already. Lets the tracking middleware resolve inline
     * where it's free and defer only where it would cost a visitor latency.
     */
    public function canResolveOffline(string $ip): bool
    {
        return $this->isInstant() || ! $this->isPublic(trim($ip));
    }

    // ── Drivers ──────────────────────────────────────────────────────

    /** @return array<string,mixed>|null null = no database, fall through */
    private function viaMaxmind(string $ip): ?array
    {
        $path = (string) config('visitors.geo.database_path');
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        try {
            $reader = new Reader($path);

            // A City database answers city(); a Country-only database throws
            // on it. Try the richer call and fall back rather than making the
            // operator declare which edition they downloaded.
            try {
                $r = $reader->city($ip);

                // array_merge, not `+`: with `+` the blank template's nulls
                // would take precedence over the values we just resolved.
                return array_merge($this->blank('done'), [
                    'continent'    => $r->continent->name ?? null,
                    'country'      => $r->country->name,
                    'country_code' => $r->country->isoCode,
                    'region'       => $r->mostSpecificSubdivision->name ?? null,
                    'city'         => $r->city->name,
                    'postal'       => $r->postal->code ?? null,
                    'timezone'     => $r->location->timeZone ?? null,
                    'latitude'     => $r->location->latitude ?? null,
                    'longitude'    => $r->location->longitude ?? null,
                ]);
            } catch (\BadMethodCallException|\GeoIp2\Exception\InvalidDatabaseException $e) {
                // Country-only edition — city() isn't available on it.
                $r = $reader->country($ip);

                return array_merge($this->blank('done'), [
                    'continent'    => $r->continent->name ?? null,
                    'country'      => $r->country->name,
                    'country_code' => $r->country->isoCode,
                ]);
            }
        } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
            // Valid public IP the database simply doesn't cover. Don't retry.
            return $this->blank('failed');
        } catch (\Throwable $e) {
            Log::warning('IpLocator: maxmind lookup failed', ['ip' => $ip, 'err' => $e->getMessage()]);

            return null;   // let the HTTP driver try
        }
    }

    /** @return array<string,mixed>|null */
    private function viaHttp(string $ip): ?array
    {
        $endpoint = (string) config('visitors.geo.http_endpoint');
        if ($endpoint === '') {
            return null;
        }

        try {
            $res = Http::timeout((int) config('visitors.geo.http_timeout', 4))
                ->acceptJson()
                ->get(str_replace('{ip}', urlencode($ip), $endpoint));

            if (! $res->successful()) {
                return null;
            }

            $d = (array) $res->json();

            // ipwho.is reports failures as HTTP 200 with success:false.
            if (array_key_exists('success', $d) && $d['success'] === false) {
                return $this->blank('failed');
            }
            if (($d['status'] ?? null) === 'fail') {
                return $this->blank('failed');
            }

            $get = fn (string ...$keys) => collect($keys)
                ->map(fn ($k) => data_get($d, $k))
                ->first(fn ($v) => is_scalar($v) && trim((string) $v) !== '');

            $lat = $get('latitude', 'lat');
            $lon = $get('longitude', 'lon');
            $asn = $get('connection.asn', 'asn', 'as');

            return array_merge($this->blank('done'), [
                'continent'       => $get('continent', 'continent_name'),
                'country'         => $get('country', 'country_name'),
                'country_code'    => strtoupper((string) ($get('country_code', 'countryCode') ?: '')) ?: null,
                'region'          => $get('region', 'regionName', 'region_name'),
                'city'            => $get('city'),
                'postal'          => $get('postal', 'postal_code', 'zip'),
                'timezone'        => $get('timezone.id', 'timezone', 'time_zone.id'),
                'org'             => $get('connection.org', 'connection.isp', 'org', 'isp'),
                // Providers report this either as 17557 or "AS17557".
                'asn'             => $asn ? (ctype_digit((string) $asn) ? 'AS' . $asn : (string) $asn) : null,
                'connection_type' => $get('connection.type', 'type'),
                'latitude'        => is_numeric($lat) ? (float) $lat : null,
                'longitude'       => is_numeric($lon) ? (float) $lon : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('IpLocator: http lookup failed', ['ip' => $ip, 'err' => $e->getMessage()]);

            return null;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** Routable on the public internet (so worth looking up at all). */
    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Full-shape result with everything unresolved. Every driver merges over
     * this so callers always get the same keys back, whatever happened.
     *
     * @return array<string,mixed>
     */
    private function blank(string $status): array
    {
        return [
            'status'    => $status,
            'continent' => null, 'country' => null, 'country_code' => null,
            'region'    => null, 'city' => null, 'postal' => null,
            'timezone'  => null, 'org' => null, 'asn' => null,
            'connection_type' => null,
            'latitude'  => null, 'longitude' => null,
        ];
    }
}
