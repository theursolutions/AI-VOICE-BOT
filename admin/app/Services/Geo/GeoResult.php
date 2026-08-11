<?php

namespace App\Services\Geo;

/**
 * The outcome of a country lookup. Immutable value object.
 *
 * Presence of a GeoResult never implies the price changes — it only decides
 * whether an approximate local-currency line is rendered beneath the USD
 * price. USD is always what Stripe charges.
 */
final class GeoResult
{
    public function __construct(
        public readonly string $countryCode,   // ISO-3166 alpha-2, uppercase
        public readonly ?string $countryName = null,
        public readonly ?string $currency = null,     // ISO-4217, uppercase
        public readonly ?string $symbol = null,
        public readonly int $decimals = 0,
        public readonly string $source = 'unknown',   // maxmind|http|override|cookie|fallback
    ) {
    }

    /** Do we know enough to show a local price at all? */
    public function hasCurrency(): bool
    {
        return $this->currency !== null && $this->currency !== '';
    }

    /**
     * A USD visitor needs no conversion line — showing "≈ $19" under "$19"
     * is noise.
     */
    public function isUsd(): bool
    {
        return $this->currency === 'USD';
    }

    public function toArray(): array
    {
        return [
            'country_code' => $this->countryCode,
            'country_name' => $this->countryName,
            'currency'     => $this->currency,
            'symbol'       => $this->symbol,
            'decimals'     => $this->decimals,
            'source'       => $this->source,
        ];
    }
}
