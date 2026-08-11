<?php

namespace App\Services\Geo\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP geolocation lookup (ipapi.co by default).
 *
 * A fallback for dev boxes with no .mmdb file, not the production default:
 * it adds a synchronous third-party round-trip to a page a visitor is
 * waiting on, and free tiers rate-limit. The per-IP cache in
 * GeoLocationService keeps the call count sane if it is used in production.
 */
class HttpDriver implements GeoLocationDriver
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $countryPath = 'country_code',
        private readonly int $timeout = 3,
    ) {
    }

    public function name(): string
    {
        return 'http';
    }

    public function countryFor(string $ip): ?string
    {
        $url = str_replace('{ip}', urlencode($ip), $this->endpoint);

        try {
            $response = Http::timeout($this->timeout)
                            ->retry(1, 200)
                            ->acceptJson()
                            ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $code = data_get($response->json(), $this->countryPath);

            // Two chars only — providers return "Undefined" or an error blob
            // for unroutable addresses, and that must not become a country.
            return (is_string($code) && strlen($code) === 2) ? strtoupper($code) : null;
        } catch (\Throwable $e) {
            Log::debug('geoip.http.lookup_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
