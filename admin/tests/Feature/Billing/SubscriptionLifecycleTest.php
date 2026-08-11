<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Subscription;
use App\Services\Billing\BillingService;

/**
 * Upgrade, downgrade, interval switch, cancel and resume — plus each interval
 * being billable end to end (monthly, quarterly, annual).
 */
class SubscriptionLifecycleTest extends BillingTestCase
{
    /** Put a workspace on a live paid subscription without touching Stripe. */
    private function subscribe(string $planSlug = 'growth', string $interval = 'monthly'): array
    {
        [$client, $user] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);

        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.created', $client, 'active', $planSlug, $interval)
        )->assertOk();

        $client->forceFill(['stripe_customer_ref' => 'cus_test_1'])->save();

        return [$client->fresh(), $user];
    }

    // ── Every offered interval must actually work ─────────────────────

    public function test_monthly_annual_and_quarterly_all_resolve_and_bill(): void
    {
        // Quarterly is supported by the schema but not offered on the pricing
        // page. It must still work end-to-end, because switching it on is
        // meant to be a super-admin action with no deploy.
        $prices = \Mockery::mock();
        $prices->shouldReceive('create')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(
                ['id' => 'price_quarterly_test', 'object' => 'price', 'livemode' => false], []
            )
        );
        $products = \Mockery::mock();
        $products->shouldReceive('create')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => 'prod_q', 'object' => 'product'], [])
        );
        $products->shouldReceive('update')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => 'prod_q', 'object' => 'product'], [])
        );
        $this->fakeStripe(['prices' => $prices, 'products' => $products]);

        $plans = app(\App\Services\Billing\PlanService::class);
        $plans->addPrice($this->plan('growth'), 'quarterly', 15900);

        foreach ([['monthly', 5900], ['quarterly', 15900], ['annually', 59000]] as [$interval, $cents]) {
            $price = $plans->resolvePrice('growth', $interval);

            $this->assertSame($cents, $price->unit_amount, "{$interval} amount");
            $this->assertSame($interval, $price->interval);
        }

        // Stripe's recurring mapping: quarterly is interval=month × 3.
        $this->assertSame(['month', 3], config('billing.intervals.stripe_map.quarterly'));
        $this->assertSame(['year', 1], config('billing.intervals.stripe_map.annually'));
    }

    public function test_annual_price_reports_two_months_free(): void
    {
        $monthly = $this->price('growth', 'monthly');
        $annual  = $this->price('growth', 'annually');

        // The approved discount: 10 × monthly.
        $this->assertSame($monthly->unit_amount * 10, $annual->unit_amount);
        $this->assertSame(17, $annual->savingsPercentAgainst($monthly));
        $this->assertSame('$49.17', $annual->formattedEffectiveMonthly());
        $this->assertSame(11800, $annual->savingsCentsAgainst($monthly));
    }

    // ── Upgrade / downgrade ──────────────────────────────────────────

    public function test_upgrading_swaps_the_stripe_item_with_proration(): void
    {
        [$client, $user] = $this->subscribe('starter', 'monthly');

        $captured = null;

        $subs = \Mockery::mock();
        $subs->shouldReceive('retrieve')->andReturn(
            \Stripe\Util\Util::convertToStripeObject([
                'id' => 'sub_test_1', 'object' => 'subscription',
                'items' => ['data' => [['id' => 'si_test_1']]],
            ], [])
        );
        $subs->shouldReceive('update')->once()->andReturnUsing(function ($id, array $payload) use (&$captured, $client) {
            $captured = $payload;

            return \Stripe\Util\Util::convertToStripeObject(
                $this->subscriptionEvent('x', $client, 'active', 'growth', 'monthly')['data']['object'], []
            );
        });

        $this->fakeStripe(['subscriptions' => $subs]);

        $this->actingAs($user)
             ->post(route('billing.change', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly',
             ])
             ->assertSessionHas('success');

        $this->assertSame(
            $this->price('growth', 'monthly')->stripe_price_ref,
            $captured['items'][0]['price']
        );

        // create_prorations, not always_invoice: an unexpected mid-month charge
        // is the most common complaint about self-serve upgrades.
        $this->assertSame('create_prorations', $captured['proration_behavior']);
        $this->assertSame('unchanged', $captured['billing_cycle_anchor'], 'Same interval keeps the anchor.');

        $this->assertSame('growth', $client->fresh()->currentSubscription()->plan->slug);
    }

    public function test_switching_interval_resets_the_billing_anchor(): void
    {
        [$client, $user] = $this->subscribe('growth', 'monthly');

        $captured = null;
        $subs = \Mockery::mock();
        $subs->shouldReceive('retrieve')->andReturn(
            \Stripe\Util\Util::convertToStripeObject([
                'id' => 'sub_test_1', 'items' => ['data' => [['id' => 'si_test_1']]],
            ], [])
        );
        $subs->shouldReceive('update')->andReturnUsing(function ($id, array $p) use (&$captured, $client) {
            $captured = $p;

            return \Stripe\Util\Util::convertToStripeObject(
                $this->subscriptionEvent('x', $client, 'active', 'growth', 'annually')['data']['object'], []
            );
        });

        $this->fakeStripe(['subscriptions' => $subs]);

        $this->actingAs($user)->post(route('billing.change', ['client' => $client->slug]), [
            'plan' => 'growth', 'interval' => 'annually',
        ]);

        // Without this, a monthly→annual switch leaves the anchor on the old
        // cadence and produces a confusing first invoice date.
        $this->assertSame('now', $captured['billing_cycle_anchor']);
    }

    public function test_downgrading_is_allowed_and_keeps_the_subscription_live(): void
    {
        [$client, $user] = $this->subscribe('scale', 'monthly');

        $subs = \Mockery::mock();
        $subs->shouldReceive('retrieve')->andReturn(
            \Stripe\Util\Util::convertToStripeObject([
                'id' => 'sub_test_1', 'items' => ['data' => [['id' => 'si_test_1']]],
            ], [])
        );
        $subs->shouldReceive('update')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(
                $this->subscriptionEvent('x', $client, 'active', 'starter', 'monthly')['data']['object'], []
            )
        );

        $this->fakeStripe(['subscriptions' => $subs]);

        $this->actingAs($user)->post(route('billing.change', ['client' => $client->slug]), [
            'plan' => 'starter', 'interval' => 'monthly',
        ])->assertSessionHas('success');

        $sub = $client->fresh()->currentSubscription();

        $this->assertSame('starter', $sub->plan->slug);
        $this->assertTrue($sub->grantsAccess());
    }

    public function test_a_workspace_on_the_free_window_is_sent_to_checkout_not_swap(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);

        // No Stripe subscription exists yet, so swap() would have nothing to
        // update — the request must be routed to checkout instead.
        $this->actingAs($user)
             ->post(route('billing.change', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly',
             ])
             ->assertRedirect(route('billing.checkout.store', ['client' => $client->slug]));
    }

    // ── Cancel / resume ──────────────────────────────────────────────

    public function test_cancel_defaults_to_period_end_and_keeps_access(): void
    {
        [$client, $user] = $this->subscribe();

        $periodEnd = now()->addDays(12);

        $subs = \Mockery::mock();
        $subs->shouldReceive('update')->once()->with('sub_test_1', ['cancel_at_period_end' => true])
             ->andReturn(\Stripe\Util\Util::convertToStripeObject(
                 $this->subscriptionEvent('x', $client, 'active', 'growth', 'monthly', 'sub_test_1', [
                     'cancel_at_period_end' => true,
                     'current_period_end'   => $periodEnd->getTimestamp(),
                 ])['data']['object'], []
             ));

        $this->fakeStripe(['subscriptions' => $subs]);

        $this->actingAs($user)
             ->post(route('billing.cancel', ['client' => $client->slug]))
             ->assertSessionHas('success');

        $sub = $client->fresh()->currentSubscription();

        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertTrue($sub->grantsAccess(), 'They paid for this period.');
    }

    public function test_resume_clears_a_pending_cancellation(): void
    {
        [$client, $user] = $this->subscribe();

        $client->currentSubscription()->forceFill([
            'cancel_at_period_end' => true,
            'ends_at'              => now()->addDays(10),
        ])->save();
        $client->forgetSubscription();

        $subs = \Mockery::mock();
        $subs->shouldReceive('update')->once()->with('sub_test_1', ['cancel_at_period_end' => false])
             ->andReturn(\Stripe\Util\Util::convertToStripeObject(
                 $this->subscriptionEvent('x', $client, 'active')['data']['object'], []
             ));

        $this->fakeStripe(['subscriptions' => $subs]);

        $this->actingAs($user)
             ->post(route('billing.resume', ['client' => $client->slug]))
             ->assertSessionHas('success');

        $this->assertFalse($client->fresh()->currentSubscription()->cancel_at_period_end);
    }

    public function test_resume_is_rejected_when_nothing_is_scheduled_to_cancel(): void
    {
        [$client, $user] = $this->subscribe();

        $this->fakeStripe(['subscriptions' => \Mockery::mock()]);

        $this->actingAs($user)
             ->post(route('billing.resume', ['client' => $client->slug]))
             ->assertSessionHas('error');
    }

    public function test_a_non_owner_cannot_cancel(): void
    {
        [$client] = $this->subscribe();

        $member = \App\Models\User::create([
            'name' => 'Agent', 'email' => 'a@acme.test',
            'password' => bcrypt('x'), 'email_verified_at' => now(),
        ]);
        $role = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent', 'modules' => ['dashboard'],
            'is_owner' => false, 'created_at' => time(), 'updated_at' => time(),
        ]);
        $member->attachMembership($client->id, null, $member->id, $role->id);
        $member->forceFill(['active_client_id' => $client->id])->save();

        $this->actingAs($member)
             ->post(route('billing.cancel', ['client' => $client->slug]))
             ->assertForbidden();
    }

    // ── Renewal resets quotas ────────────────────────────────────────

    public function test_a_renewal_resets_period_usage_but_not_absolute_usage(): void
    {
        [$client] = $this->subscribe();

        $usage = app(\App\Services\Billing\UsageLimitService::class);
        $usage->record($client, 'conversations', 400);
        $usage->record($client, 'indexed_pages', 120);

        $this->assertSame(400, $usage->usedFor($client, 'conversations'));

        // New period arrives via the webhook.
        $this->postWebhook($this->subscriptionEvent(
            'customer.subscription.updated', $client, 'active', 'growth', 'monthly', 'sub_test_1',
            [
                'current_period_start' => now()->addMonth()->getTimestamp(),
                'current_period_end'   => now()->addMonths(2)->getTimestamp(),
            ]
        ))->assertOk();

        $client->forgetSubscription();

        $this->assertSame(0, $usage->usedFor($client, 'conversations'), 'Period metric resets on renewal.');
        // Storage doesn't go back to zero because the customer paid again.
        $this->assertSame(120, $usage->usedFor($client, 'indexed_pages'));
    }
}
