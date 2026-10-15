<?php

namespace App\Services\LeadGen\Providers;

use RuntimeException;

/**
 * A source failed in a way the search job can act on.
 *
 *   NOT_CONFIGURED  the source can't run here — fail the search with the hint
 *   RATE_LIMITED    try again after $retryAfter seconds
 *   UNAVAILABLE     temporary (network, worker restarting) — retry with backoff
 *   UNSUPPORTED     the criteria ask for something the source can't do
 *   FAILED          permanent — fail the search with the diagnostic
 */
class ProviderException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const RATE_LIMITED   = 'rate_limited';
    public const UNAVAILABLE    = 'unavailable';
    public const UNSUPPORTED    = 'unsupported';
    public const FAILED         = 'failed';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly array $diagnostic = [],
    ) {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return in_array($this->kind, [self::RATE_LIMITED, self::UNAVAILABLE], true);
    }

    public static function notConfigured(string $why): self
    {
        return new self(self::NOT_CONFIGURED, $why);
    }

    public static function unavailable(string $why, int $retryAfter = 30, array $diag = []): self
    {
        return new self(self::UNAVAILABLE, $why, $retryAfter, $diag);
    }

    public static function rateLimited(int $retryAfter, array $diag = []): self
    {
        return new self(self::RATE_LIMITED, 'Rate limited by the source.', $retryAfter, $diag);
    }

    public static function unsupported(string $what): self
    {
        return new self(self::UNSUPPORTED, $what);
    }

    public static function failed(string $why, array $diag = []): self
    {
        return new self(self::FAILED, $why, null, $diag);
    }
}
