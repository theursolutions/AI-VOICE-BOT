<?php

namespace App\Services\Currency\Drivers;

/**
 * Fetch USD-base exchange rates from a third party.
 *
 * CONTRACT:
 *  • Return ['PKR' => 283.4123, 'GBP' => 0.7891, ...] keyed by uppercase
 *    ISO-4217, values as "1 base = N of currency".
 *  • Return an EMPTY ARRAY on any failure. Never throw — the caller is a
 *    scheduled command whose failure must not cascade, and the read path
 *    falls back to the last good stored rate.
 *
 * Implementations are selected by config('billing.fx.driver'), which is what
 * makes the provider swappable without touching a caller.
 */
interface ExchangeRateProvider
{
    /** @return array<string,float> */
    public function fetchRates(string $base = 'USD'): array;

    public function name(): string;
}
