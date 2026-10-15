<?php

namespace App\Support;

/** Outcome of SafeFetcher::get(). `blockedReason` set = we refused to fetch. */
final class SafeFetchResult
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $status,
        public readonly ?string $contentType,
        public readonly ?string $body,
        public readonly ?string $blockedReason,
        public readonly ?string $error = null,
    ) {}

    public static function blocked(string $url, string $reason): self
    {
        return new self($url, null, null, null, $reason);
    }

    public static function failed(string $url, string $error): self
    {
        return new self($url, null, null, null, null, $error);
    }

    public function ok(): bool
    {
        return $this->blockedReason === null && $this->error === null
            && $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function isHtml(): bool
    {
        return $this->contentType !== null && preg_match('#text/html|application/xhtml#i', $this->contentType) === 1;
    }
}
