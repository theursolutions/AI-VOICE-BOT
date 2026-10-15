<?php

namespace App\Services\LeadGen\Providers;

/** One page of normalised results; `next` null means there are no more. */
final class ProviderPage
{
    /**
     * @param array<int, array> $items NormalizedProspect arrays (see ProspectNormalizer::FIELDS)
     */
    public function __construct(
        public readonly array $items,
        public readonly ?array $next,
        public readonly ?int $total = null,
        public readonly array $meta = [],
    ) {}
}
