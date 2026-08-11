<?php

namespace App\Services\Currency\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generic JSON exchange-rate provider, configured entirely from
 * config('billing.fx.drivers.*').
 *
 * One class covers open.er-api.com, exchangerate.host and
 * openexchangerates.org because all three return "a JSON object of
 * currency => rate at some path". Adding another provider is a config entry,
 * not a new class — which is the swappability the brief asked for.
 *
 * `key_prefix` handles providers (exchangerate.host) that key pairs as
 * "USDPKR" instead of "PKR".
 */
class HttpRateProvider implements ExchangeRateProvider
{
    public function __construct(
        private readonly string $driverName,
        private readonly string $endpoint,
        private readonly string $ratesPath = 'rates',
        private readonly ?string $apiKey = null,
        private readonly int $timeout = 8,
        private readonly ?string $keyPrefix = null,
    ) {
    }

    public function name(): string
    {
        return $this->driverName;
    }

    public function fetchRates(string $base = 'USD'): array
    {
        try {
            $query = [];

            if ($this->apiKey) {
                // The two keyed providers we support use different parameter
                // names for the same thing.
                $query[$this->driverName === 'openexchangerates' ? 'app_id' : 'access_key'] = $this->apiKey;
            }

            $response = Http::timeout($this->timeout)
                            ->retry(2, 500)
                            ->acceptJson()
                            ->get($this->endpoint, $query);

            if (! $response->successful()) {
                Log::warning('fx.fetch.http_error', [
                    'driver' => $this->driverName,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $raw = data_get($response->json(), $this->ratesPath);

            if (! is_array($raw) || $raw === []) {
                Log::warning('fx.fetch.empty_payload', [
                    'driver' => $this->driverName,
                    'path'   => $this->ratesPath,
                ]);

                return [];
            }

            return $this->normalise($raw, $base);
        } catch (\Throwable $e) {
            Log::warning('fx.fetch.failed', [
                'driver' => $this->driverName,
                'error'  => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,float>
     */
    private function normalise(array $raw, string $base): array
    {
        $out    = [];
        $prefix = $this->keyPrefix ? strtoupper($this->keyPrefix) : null;

        foreach ($raw as $key => $value) {
            $code = strtoupper((string) $key);

            // "USDPKR" → "PKR"
            if ($prefix && str_starts_with($code, $prefix) && strlen($code) === strlen($prefix) + 3) {
                $code = substr($code, strlen($prefix));
            }

            if (strlen($code) !== 3 || ! is_numeric($value)) {
                continue;
            }

            $rate = (float) $value;

            // A zero or negative rate would silently render a price of 0.
            if ($rate <= 0) {
                continue;
            }

            $out[$code] = $rate;
        }

        // Anchor the base to itself so lookups for USD are always coherent.
        $out[strtoupper($base)] = 1.0;

        return $out;
    }
}
