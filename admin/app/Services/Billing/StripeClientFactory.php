<?php

namespace App\Services\Billing;

use Stripe\StripeClient;

/**
 * Builds the Stripe SDK client.
 *
 * Exists as its own class purely so tests can bind a fake StripeClient into
 * the container and exercise every billing path without a network call or a
 * live API key. Nothing else should construct a StripeClient directly.
 */
class StripeClientFactory
{
    public function make(): StripeClient
    {
        $secret = (string) config('billing.stripe.secret');

        if ($secret === '') {
            throw new \RuntimeException(
                'STRIPE_SECRET is not configured. Set it in .env before using billing.'
            );
        }

        return new StripeClient([
            'api_key'        => $secret,
            'stripe_version' => (string) config('billing.stripe.api_version'),
        ]);
    }

    public function isConfigured(): bool
    {
        return (string) config('billing.stripe.secret') !== ''
            && (string) config('billing.stripe.key') !== '';
    }

    /** True when the configured secret key is a live-mode key. */
    public function isLiveMode(): bool
    {
        return str_starts_with((string) config('billing.stripe.secret'), 'sk_live_');
    }
}
