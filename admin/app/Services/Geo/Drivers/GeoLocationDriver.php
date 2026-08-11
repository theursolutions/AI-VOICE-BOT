<?php

namespace App\Services\Geo\Drivers;

/**
 * Resolve an IP address to an ISO-3166 alpha-2 country code.
 *
 * CONTRACT: implementations must NEVER throw and must NEVER block for long.
 * Return null on any failure — unknown IP, missing database, timeout, bad
 * response. The caller degrades to USD-only pricing, which is always correct,
 * so a geolocation outage must not surface as an error to a visitor who is
 * trying to look at prices.
 */
interface GeoLocationDriver
{
    public function countryFor(string $ip): ?string;

    /** Short identifier used in logs and in GeoResult::$source. */
    public function name(): string;
}
