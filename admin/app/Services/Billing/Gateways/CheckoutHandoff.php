<?php

namespace App\Services\Billing\Gateways;

/**
 * How the browser continues a payment the server has started.
 *
 * Two shapes, because providers genuinely differ. Stripe hands back a URL to
 * redirect to (or a client secret to confirm in-page); PayFast expects a form
 * POSTed to its host carrying a signed set of fields. Collapsing both into "a
 * URL" would mean synthesising a GET for a provider that only accepts a POST,
 * so the difference is represented rather than hidden.
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

    public function isPost(): bool
    {
        return $this->type === 'post';
    }
}
