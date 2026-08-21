<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The purchase never leaves the product.
 *
 * Buying, upgrading and adding seats all complete inside the app on our own
 * Stripe Elements form. The hosted Checkout redirect and the hosted Billing
 * Portal still exist in the code — they are a supported fallback, not dead
 * weight — but while billing.checkout.in_app_only holds, nothing reaches them.
 *
 * Asserted at the source rather than by driving HTTP, because every one of these
 * routes sits behind auth, a workspace, an owner check and the checkout master
 * switch; a test that stood all that up would be testing the middleware stack.
 * What is worth protecting is narrower and structural: that the guard exists at
 * the endpoint, that no view links past it, and that the switch is on.
 */
class InAppBillingTest extends TestCase
{
    private function source(string $relative): string
    {
        return file_get_contents(base_path($relative));
    }

    public function test_the_in_app_only_switch_defaults_on(): void
    {
        $this->assertTrue(
            (bool) config('billing.checkout.in_app_only'),
            'The whole point is that this is the default, not an opt-in',
        );
    }

    /**
     * The redirect to Stripe's hosted Checkout must be unreachable — and the
     * guard has to come BEFORE the redirect, or it guards nothing.
     */
    public function test_the_hosted_checkout_is_guarded_before_it_redirects(): void
    {
        $src = $this->source('app/Http/Controllers/Billing/CheckoutController.php');

        $guardAt    = strpos($src, "config('billing.checkout.in_app_only'");
        $redirectAt = strpos($src, 'redirect()->away($session->url)');

        $this->assertNotFalse($guardAt, 'The in-app guard has been removed from the checkout path');
        $this->assertNotFalse($redirectAt, 'The hosted path has been deleted rather than guarded — update this test');

        $this->assertLessThan(
            $redirectAt,
            $guardAt,
            'The guard must precede the off-site redirect, or the redirect happens first',
        );
    }

    public function test_the_hosted_portal_is_guarded_before_it_redirects(): void
    {
        $src = $this->source('app/Http/Controllers/Billing/BillingController.php');

        $portalAt   = strpos($src, 'public function portal');
        $body       = substr($src, $portalAt);

        $guardAt    = strpos($body, "config('billing.checkout.in_app_only'");
        $redirectAt = strpos($body, 'redirect()->away(');

        $this->assertNotFalse($guardAt, 'The portal is reachable again');
        $this->assertLessThan($redirectAt, $guardAt);
    }

    /**
     * An upgrade by someone who has never paid used to be routed to the
     * hosted-session starter — the one action that could still walk a customer
     * off the product, and only for that subset.
     */
    public function test_an_upgrade_without_a_live_subscription_goes_to_our_own_form(): void
    {
        $src  = $this->source('app/Http/Controllers/Billing/BillingController.php');
        $body = substr($src, strpos($src, 'public function change'));
        $body = substr($body, 0, strpos($body, 'public function cancel') ?: null);

        $this->assertStringContainsString("route('billing.checkout'", $body);
        $this->assertStringNotContainsString(
            "route('billing.checkout.store'",
            $body,
            'Plan changes must not start a hosted Stripe session',
        );
    }

    /**
     * No customer-facing view may link past the guards. A refused endpoint with
     * a live button in front of it is a dead end the customer finds first.
     */
    public function test_no_view_links_to_the_hosted_paths(): void
    {
        $offenders = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (str_contains($contents, "route('billing.portal'")
                || str_contains($contents, "route('billing.checkout.store'")) {
                $offenders[] = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'These views still send the customer off-site: ' . implode(', ', $offenders));
    }

    /**
     * Retiring the portal must not cost a capability. It offered billing
     * address, tax id and receipts; the app has to own all three.
     */
    public function test_everything_the_portal_did_has_an_in_app_replacement(): void
    {
        // Billing details, including the tax number the portal used to own.
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('clients', 'billing_tax_id'),
            'Tax numbers have nowhere to live, so the portal cannot be retired',
        );
        $this->assertTrue($this->routeExists('billing.details'), 'No in-app billing details form');

        // Cards.
        $this->assertTrue($this->routeExists('billing.cards.store'));
        $this->assertTrue($this->routeExists('billing.cards.default'));
        $this->assertTrue($this->routeExists('billing.cards.destroy'));

        // Our own invoice, rather than Stripe's PDF.
        $this->assertTrue($this->routeExists('billing.invoice'));

        // And the payment itself.
        $this->assertTrue($this->routeExists('billing.subscribe'));
        $this->assertTrue($this->routeExists('billing.confirm'));
    }

    /** Seats and AI agents are bought in-app, via a subscription-item update. */
    public function test_seats_are_bought_in_app(): void
    {
        $this->assertTrue($this->routeExists('billing.addons.update'));
        $this->assertTrue($this->routeExists('billing.addons.preview'));
    }

    private function routeExists(string $name): bool
    {
        return \Illuminate\Support\Facades\Route::has($name);
    }
}
