<?php

namespace App\Services\Billing\Gateways;

/**
 * How the browser continues a payment the server has started.
 *
 * Three shapes, because providers genuinely differ. Stripe hands back a URL to
 * redirect to (or a client secret to confirm in-page); PayFast expects a form
 * POSTed to its host carrying a signed set of fields; Paddle draws an overlay
 * on the page we are already on and never navigates at all. Collapsing these
 * into "a URL" would mean synthesising a GET for a provider that only accepts a
 * POST, and a navigation for one whose entire point is not navigating — so the
 * difference is represented rather than hidden.
 */
class CheckoutHandoff
{
    private function __construct(
        public readonly string $type,
        public readonly string $url,
        /** @var array<string, scalar> */
        public readonly array $fields = [],
        public readonly ?string $reference = null,
    ) {
    }

    /** Send the browser to $url. */
    public static function redirect(string $url, ?string $reference = null): self
    {
        return new self('redirect', $url, [], $reference);
    }

    /**
     * Render an auto-submitting form that POSTs $fields to $url.
     *
     * The fields are the provider's, verbatim and already signed — nothing here
     * may rewrite them, because the signature was computed over exactly these
     * values and any tidying breaks it.
     *
     * @param  array<string, scalar>  $fields
     */
    public static function post(string $url, array $fields, ?string $reference = null): self
    {
        return new self('post', $url, $fields, $reference);
    }

    /**
     * Open the provider's own overlay on the current page.
     *
     * No navigation: `url` is empty because there is nowhere to go. The browser
     * needs the provider's public token and a reference to the payment the
     * server has already created — the AMOUNT is not among them, and must never
     * be, because anything handed to the browser can be edited before it is
     * used.
     *
     * @param  array<string, scalar>  $fields  what the provider's JS needs
     */
    public static function overlay(string $reference, array $fields = []): self
    {
        return new self('overlay', '', $fields, $reference);
    }

    public function isPost(): bool
    {
        return $this->type === 'post';
    }

    /** Drawn on our own page rather than somewhere else. */
    public function isOverlay(): bool
    {
        return $this->type === 'overlay';
    }
}
