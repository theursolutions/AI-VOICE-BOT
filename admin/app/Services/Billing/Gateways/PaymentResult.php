<?php

namespace App\Services\Billing\Gateways;

/**
 * What a provider says happened to one payment.
 *
 * `paid` is the only field anything is allowed to act on, and it is set solely
 * from a server-to-server answer. A callback saying "success" is a claim made
 * by the customer's browser, not by the provider.
 */
class PaymentResult
{
    public function __construct(
        public readonly bool $paid,
        public readonly string $reference,
        public readonly int $amountCents = 0,
        public readonly string $currency = 'PKR',
        public readonly ?string $status = null,
        public readonly ?string $failureReason = null,
        /** @var array<string, mixed> the provider's own answer, kept for support */
        public readonly array $raw = [],
    ) {
    }

    public static function paid(string $reference, int $amountCents, string $currency, array $raw = []): self
    {
        return new self(true, $reference, $amountCents, $currency, 'paid', null, $raw);
    }

    public static function failed(string $reference, ?string $reason = null, array $raw = []): self
    {
        return new self(false, $reference, 0, 'PKR', 'failed', $reason, $raw);
    }

    /**
     * Neither paid nor definitively failed — the provider is still deciding, or
     * could not be reached.
     *
     * Kept distinct from failed() on purpose: an unreachable provider must not
     * cancel a payment the customer may well have made, and a pending charge
     * that is retried later is recoverable where a cancelled one is not.
     */
    public static function pending(string $reference, ?string $reason = null, array $raw = []): self
    {
        return new self(false, $reference, 0, 'PKR', 'pending', $reason, $raw);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
