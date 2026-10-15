<?php

namespace App\Services\LeadGen\Providers;

use InvalidArgumentException;

/**
 * The discovery sources this install offers. Adding a source = implement
 * LeadSource and list its class here. Unconfigured sources stay listed (the
 * UI shows what they need) but can't start a search.
 */
class ProviderRegistry
{
    /** @var array<string, class-string<LeadSource>> */
    private const SOURCES = [
        OvertureProvider::KEY => OvertureProvider::class,
    ];

    /** @return array<string, LeadSource> */
    public function all(): array
    {
        $out = [];
        foreach (self::SOURCES as $key => $class) {
            $out[$key] = app($class);
        }
        return $out;
    }

    public function get(string $key): LeadSource
    {
        $class = self::SOURCES[$key] ?? throw new InvalidArgumentException("Unknown lead source [{$key}]");
        return app($class);
    }

    public function has(string $key): bool
    {
        return isset(self::SOURCES[$key]);
    }
}
