<?php

namespace App\Services\DataSource;

/**
 * Uniform return type for every Resolver.
 *
 *   records  — structured rows ({col: val} dicts), e.g. SQL query output
 *   passages — unstructured text chunks for RAG-style context injection
 *   empty    — resolver succeeded but had nothing relevant
 *   error    — resolver couldn't run
 *
 * `citations` lets the LLM cite where each passage/record came from so
 * we can render "according to [doc]" in the assistant reply.
 */
class ResolverResult
{
    public const KIND_RECORDS  = 'records';
    public const KIND_PASSAGES = 'passages';
    public const KIND_EMPTY    = 'empty';
    public const KIND_ERROR    = 'error';

    public function __construct(
        public readonly string $kind,
        public readonly int $sourceId,
        public readonly string $sourceType,
        public readonly array $items = [],
        public readonly array $citations = [],
        public readonly ?string $error = null,
        public readonly array $metadata = [],
    ) {}

    public static function records(int $sourceId, string $sourceType, array $rows, array $metadata = []): self
    {
        return new self(self::KIND_RECORDS, $sourceId, $sourceType, $rows, [], null, $metadata);
    }

    public static function passages(int $sourceId, string $sourceType, array $passages, array $citations = []): self
    {
        return new self(self::KIND_PASSAGES, $sourceId, $sourceType, $passages, $citations);
    }

    public static function empty(int $sourceId, string $sourceType): self
    {
        return new self(self::KIND_EMPTY, $sourceId, $sourceType);
    }

    public static function error(int $sourceId, string $sourceType, string $message): self
    {
        return new self(self::KIND_ERROR, $sourceId, $sourceType, [], [], $message);
    }

    public function isUsable(): bool
    {
        return in_array($this->kind, [self::KIND_RECORDS, self::KIND_PASSAGES], true)
            && !empty($this->items);
    }
}
