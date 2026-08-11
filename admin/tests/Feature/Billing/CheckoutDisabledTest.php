<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\SubscriptionService;

/**
 * Information-only mode: `billing.checkout.enabled = false` (the shipped
 * default while billing is being finished).
 *
 * Plans are fully visible — prices, limits, every feature — but nothing can be
 * bought, and the UI says nothing about *why*. It simply reads as a plan list.
 */
class CheckoutDisabledTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Undo the base class, which turns checkout ON for the rest of the suite.
        config(['billing.checkout.enabled' => false]);
    }

    // ── The public surfaces ──────────────────────────────────────────

    public function test_the_homepage_shows_plans_but_no_purchase_buttons(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // Everything informational is still there.
        $response->assertSee('$19', false);
        $response->assertSee('$59', false);
        $response->assertSee('$149', false);
        $response->assertSee('Most popular', false);
        $response->assertSee('5,000 AI conversations per month', false);
        $response->assertSee('Compare every feature across all plans', false);

        // But there is nothing to click and nothing to submit.
        $response->assertDontSee('pricing/checkout', false);
        $response->assertDontSee('name="plan"', false);
    }

    public function test_the_pricing_page_shows_plans_but_no_purchase_buttons(): void
    {
        $response = $this->get('/pricing');

        $response->assertOk();
        $response->assertSee('$59', false);
        $response->assertDontSee('pricing/checkout', false);
    }

    public function test_no_coming_soon_or_unavailable_wording_is_shown(): void
    {
        // The plan list must read as a plan list, not as a broken shop.
        foreach (['/', '/pricing'] as $url) {
            $response = $this->get($url);

            foreach (['Coming soon', 'aren’t available', 'not available to purchase', 'finishing the billing'] as $phrase) {
                $response->assertDontSee($phrase, false);
            }
        }
    }

    public function test_free_signup_and_enterprise_contact_still_work(): void
    {
        // Neither takes money, and they're the site's main conversion paths.
        $response = $this->get('/');

        $response->assertSee(url('/register'), false);
        $response->assertSee('Talk to us', false);
    }

    // ── The server must refuse too ───────────────────────────────────

    public function test_the_public_checkout_endpoint_refuses(): void
    {
        // A hidden button in front of a live public POST route is not disabled.
        $this->post(route('pricing.checkout'), ['plan' => 'growth', 'interval' => 'monthly'])
             ->assertSessionHas('info')
             ->assertSessionMissing('billing.intent');
    }

    public function test_the_workspace_checkout_endpoint_refuses(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->actingAs($user)
             ->post(route('billing.checkout.store', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly',
             ])
             ->assertSessionHas('info');

        // Nothing was created at Stripe and nothing changed locally.
        $this->assertNull($client->fresh()->stripe_customer_ref);
        $this->assertSame('free', $client->fresh()->currentSubscription()->status);
    }

    public function test_an_existing_subscriber_cannot_swap_plans_either(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        config(['billing.checkout.enabled' => true]);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();
        config(['billing.checkout.enabled' => false]);

        $this->actingAs($user)
             ->post(route('billing.change', ['client' => $client->slug]), [
                 'plan' => 'scale', 'interval' => 'monthly',
             ])
             ->assertSessionHas('info');

        $this->assertSame('growth', $client->fresh()->currentSubscription()->plan->slug);
    }

    // ── The billing page on a brand-new workspace ────────────────────

    public function test_a_first_time_workspace_sees_no_payment_setup_nagging(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $response = $this->actingAs($user)
            ->get(route('billing.index', ['client' => $client->slug]))
            ->assertOk();

        // Someone who has never paid is not failing at a setup task.
        $response->assertDontSee('None on file', false);
        $response->assertDontSee('No invoices yet', false);
        $response->assertDontSee('Payment method', false);

        // An operator's Stripe misconfiguration is not the customer's problem.
        $response->assertDontSee('Stripe isn’t configured', false);
        $response->assertDontSee('Payments aren’t configured', false);

        // What they SHOULD see: their plan and how long they've got.
        $response->assertSee('Free', false);
        $response->assertSee('left of free access', false);
    }

    public function test_a_super_admin_does_see_the_stripe_misconfiguration_warning(): void
    {
        config(['billing.stripe.key' => '', 'billing.stripe.secret' => '']);

        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $user->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($user->fresh())
             ->get(route('billing.index', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('only you can see this', false);
    }

    public function test_payment_details_appear_once_there_actually_are_some(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        config(['billing.checkout.enabled' => true]);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();

        $client->forceFill(['stripe_customer_ref' => 'cus_test_1'])->save();
        $this->postWebhook([
            'id' => 'evt_pm_x', 'object' => 'event', 'livemode' => false,
            'type' => 'payment_method.attached',
            'data' => ['object' => [
                'id' => 'pm_1', 'object' => 'payment_method', 'type' => 'card',
                'customer' => 'cus_test_1',
                'card' => ['brand' => 'visa', 'last4' => '4242', 'fingerprint' => 'fp_x'],
            ]],
        ])->assertOk();

        $this->fakeStripe(['customers' => $this->customerServiceReturning()]);

        $this->actingAs($user)
             ->get(route('billing.index', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('Billing currency', false);
    }
}
