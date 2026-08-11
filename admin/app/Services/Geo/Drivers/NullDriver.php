<?php

namespace App\Services\Geo\Drivers;

/**
 * Detects nothing. Everyone sees USD.
 *
 * Used in the test suite (so tests never depend on a .mmdb file or a network
 * call) and available in production as a kill switch via GEOIP_DRIVER=null.
 */
class NullDriver implements GeoLocationDriver
{
    public function name(): string
    {
        return 'null';
    }

    public function countryFor(string $ip): ?string
    {
        return null;
    }
}
