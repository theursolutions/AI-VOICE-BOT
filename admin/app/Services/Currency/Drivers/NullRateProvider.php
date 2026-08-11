<?php

namespace App\Services\Currency\Drivers;

/**
 * Returns no rates, so no local-currency line is ever rendered.
 *
 * The test-suite default (so tests never hit a live FX API) and a production
 * kill switch via FX_ENABLED=false.
 */
class NullRateProvider implements ExchangeRateProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function fetchRates(string $base = 'USD'): array
    {
        return [];
    }
}
