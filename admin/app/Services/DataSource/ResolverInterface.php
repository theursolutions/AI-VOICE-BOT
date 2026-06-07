<?php

namespace App\Services\DataSource;

use App\Models\DataSource;

interface ResolverInterface
{
    /** The DataSource::TYPE_* constant this resolver handles. */
    public function type(): string;

    /**
     * Answer the user's query against this data source.
     * Implementations must NEVER throw — wrap failures in
     * ResolverResult::error() so the router can keep going
     * with other sources.
     */
    public function resolve(string $userQuery, DataSource $source, array $context = []): ResolverResult;

    /**
     * Validate a config payload before persisting. Returns an array
     * of {field: error_message} pairs — empty array means OK.
     */
    public function validateConfig(array $config): array;

    /**
     * True if this source type requires periodic ingestion
     * (crawl/index/embed). False for live-query types.
     */
    public function needsSync(): bool;

    /** Run sync. No-op if needsSync() is false. */
    public function sync(DataSource $source): void;
}
