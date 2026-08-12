<?php

namespace Tests\Feature\Billing;

use App\Models\User;

/**
 * Smoke-renders every Super Admin billing screen.
 *
 * Blade compiles fine with a syntax error in an `@if` branch that never runs,
 * and these pages are exactly where a bad variable reference hides — an ops
 * console nobody renders in CI is an ops console that 500s the first time
 * somebody opens it.
 */
class OpsBillingViewsTest extends BillingTestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Ops', 'email' => 'ops@serveai.test',
            'password' => bcrypt('password'), 'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_every_ops_billing_screen_renders(): void
    {
        // Real data behind each page: a workspace, a paid subscription and a
        // recorded webhook, so the tables render populated rather than empty.
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();

        $this->actingAs($this->admin());

        foreach ([
            'ops.billing.plans.index',
            'ops.billing.plans.create',
            'ops.billing.features.index',
            'ops.billing.subscriptions.index',
            'ops.billing.subscriptions.events',
        ] as $route) {
            $this->get(route($route))->assertOk();
        }

        // Per-plan edit screens, including the free and enterprise variants
        // whose forms take different branches.
        foreach (['free', 'starter', 'growth', 'scale', 'enterprise'] as $slug) {
            $this->get(route('ops.billing.plans.edit', ['id' => $this->plan($slug)->id]))
                 ->assertOk()
                 ->assertSee($this->plan($slug)->name, false);
        }
    }

    public function test_the_subscriptions_screen_reports_mrr_normalised_to_a_month(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);

        // An ANNUAL subscription must contribute its monthly equivalent
        // ($590/yr → $49), not its full sticker price.
        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.created', $client, 'active', 'growth', 'annually')
        )->assertOk();

        $response = $this->actingAs($this->admin())
             ->get(route('ops.billing.subscriptions.index'))
             ->assertOk();

        // MRR normalises the annual price to a month: $590/yr → $49/mo. That's
        // committed monthly revenue, which is the number worth watching.
        $response->assertSee('Est. MRR', false);
        $response->assertSee('$49', false);

        // The row's Amount column still shows what is actually charged, so the
        // two figures are both present and mean different things.
        $response->assertSee('$590.00', false);
    }

    public function test_the_plans_screen_warns_when_prices_are_not_synced_to_stripe(): void
    {
        \App\Models\Billing\PlanPrice::query()->update(['stripe_price_ref' => null]);

        $this->actingAs($this->admin())
             ->get(route('ops.billing.plans.index'))
             ->assertOk()
             ->assertSee('not synced', false);
    }

    public function test_the_customer_billing_page_renders_in_every_state(): void
    {
        [$client, $user] = $this->makeWorkspace();
        $this->fakeStripe(['customers' => $this->customerServiceReturning()]);

        // 1. On the free window. There is no price yet, so no USD line —
        //    what matters is the plan and the countdown.
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);
        $this->actingAs($user)
             ->get(route('billing.index', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('Free', false)
             ->assertSee('days left', false);

        // 2. Paid — now the amount and the currency it's charged in appear.
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();
        $this->get(route('billing.index', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('Growth', false)
             ->assertSee('$59', false)
             ->assertSee('charged in USD', false);

        // 3. Expired free window (the degraded read-only state).
        $client->fresh()->currentSubscription()->forceFill([
            'status'                  => \App\Models\Billing\Subscription::STATUS_EXPIRED,
            'stripe_subscription_ref' => null,
            'read_only_since'         => now(),
            'free_ends_at'            => now()->subDay(),
            'purge_after'             => now()->addDays(30),
        ])->save();

        $this->get(route('billing.index', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('paused', false);
    }
}
