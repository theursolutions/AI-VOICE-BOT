<?php

namespace App\Services\LeadGen\Providers;

/**
 * A place prospects can be discovered from.
 *
 * Adapters never fabricate results: an adapter that isn't configured says so
 * through isConfigured()/configurationHint() and throws ProviderNotConfigured
 * if asked to search anyway. Everything an adapter returns is normalised into
 * NormalizedProspect arrays with its source attribution attached.
 */
interface LeadSource
{
    /** Stable key stored on searches and prospects ("overture", "import"). */
    public function key(): string;

    public function label(): string;

    /**
     * What this source can do, for the search form and the docs:
     *   filters        list of SearchCriteria fields it honours
     *   estimate       bool — can count results before a search runs
     *   storage        'storable' | 'id_only' | 'display_only' (licence terms)
     *   attribution    text that must be shown with its data, or null
     *   fields         prospect fields it can return
     */
    public function capabilities(): array;

    public function isConfigured(): bool;

    /** Why it isn't available, phrased for an operator. Null when configured. */
    public function configurationHint(): ?string;

    /**
     * Prepare whatever the source needs before paging (e.g. loading an area).
     * Returns true when ready, false while still preparing — the caller
     * retries later instead of blocking a worker.
     *
     * @throws ProviderException
     */
    public function prepare(SearchCriteria $criteria): bool;

    /** Result count for the criteria, when the source supports it. */
    public function estimate(SearchCriteria $criteria): ?int;

    /**
     * One page of results. $cursor is whatever the previous page returned as
     * `next` (null for the first page).
     *
     * @throws ProviderException
     */
    public function search(SearchCriteria $criteria, ?array $cursor, int $limit): ProviderPage;
}
