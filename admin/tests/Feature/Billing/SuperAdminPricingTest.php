<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Models\Billing\Subscription;
use App\Models\User;

/**
 * Super-admin pricing control — and the price-versioning guarantee.
 *
 * THE HEADLINE ASSERTION of this file: changing a price must never change what
 * an existing subscriber pays. That is what makes launching at a low price safe
 * and what the whole plans/plan_prices split exists for.
 */
class SuperAdminPricingTest extends BillingTestCase
{
    private function admin(): User
    {
        return User::create([
            'name' => 'Ops', 'email' => 'ops@serveai.test',
            'password' => bcrypt('password'), 'is_super_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function fakeStripeForSync(): void
    {
        $prices = \Mockery::mock();
        $prices->shouldReceive('create')->andReturnUsing(fn (array $p) => \Stripe\Util\Util::convertToStripeObject([
            'id' => 'price_new_' . bin2hex(random_bytes(4)),
            'object' => 'price', 'livemode' => false,
            'unit_amount' => $p['unit_amount'],
        ], []));
        $prices->shouldReceive('update')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => 'price_x', 'active' => false], [])
        );

        $products = \Mockery::mock();
        $products->shouldReceive('create')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => 'prod_new', 'object' => 'product'], [])
        );
        $products->shouldReceive('update')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => 'prod_new', 'object' => 'product'], [])
        );

        $this->fakeStripe(['prices' => $prices, 'products' => $products]);
    }

    // ── Authorisation ────────────────────────────────────────────────

    public function test_the_billing_admin_is_super_admin_only(): void
    {
        [, $user] = $this->makeWorkspace();

        // Guest first: actingAs() persists for the remainder of the test, so
        // checking the unauthenticated case afterwards would silently test the
        // authenticated one instead.
        $this->get(route('ops.billing.plans.index'))->assertRedirect();   // → login

        // 404, not 403 — IsSuperAdmin deliberately hides the existence of the
        // ops console from customer accounts rather than confirming it with a
        // "forbidden". Billing inherits that.
        $this->actingAs($user)->get(route('ops.billing.plans.index'))->assertNotFound();

        $this->actingAs($this->admin())->get(route('ops.billing.plans.index'))->assertOk();
    }

    // ── THE price-versioning guarantee ───────────────────────────────

    public function test_changing_a_price_grandfathers_existing_subscribers(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();

        $original = $this->price('growth', 'monthly');
        $this->assertSame(5900, $original->unit_amount);

        $subscription = $client->fresh()->currentSubscription();
        $this->assertSame($original->id, $subscription->plan_price_id);

        $this->fakeStripeForSync();

        // $59 → $89 from the admin panel.
        $this->actingAs($this->admin())
             ->patch(route('ops.billing.prices.update', [
                 'id' => $this->plan('growth')->id, 'priceId' => $original->id,
             ]), ['amount' => '89.00'])
             ->assertSessionHas('success');

        $original->refresh();
        $replacement = $this->plan('growth')->fresh()->priceFor('monthly');

        // A NEW row, not an edit.
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame(8900, $replacement->unit_amount);
        $this->assertNotSame($original->stripe_price_ref, $replacement->stripe_price_ref);

        // The old row is retired but preserved.
        $this->assertFalse($original->is_active);
        $this->assertNotNull($original->archived_at);
        $this->assertSame(5900, $original->unit_amount, 'The historical amount must be untouched.');

        // And the existing subscriber still points at it.
        $subscription->refresh();
        $this->assertSame($original->id, $subscription->plan_price_id);
        $this->assertSame(5900, $subscription->unit_amount);

        // New buyers get the new price.
        $this->assertSame(
            8900,
            app(\App\Services\Billing\PlanService::class)->resolvePrice('growth', 'monthly')->unit_amount
        );
    }

    public function test_a_price_change_is_audited_with_the_before_and_after(): void
    {
        $price = $this->price('starter', 'monthly');
        $this->fakeStripeForSync();

        $this->actingAs($this->admin())->patch(route('ops.billing.prices.update', [
            'id' => $this->plan('starter')->id, 'priceId' => $price->id,
        ]), ['amount' => '29.00']);

        $this->assertDatabaseHas('audit_log', ['action' => 'billing.price.changed']);
    }

    public function test_money_is_stored_as_integer_cents_without_float_drift(): void
    {
        $price = $this->price('starter', 'monthly');
        $this->fakeStripeForSync();

        // 19.99 * 100 in floating point is 1998.9999…; a naive (int) cast
        // truncates to 1998 and undercharges by a cent forever.
        $this->actingAs($this->admin())->patch(route('ops.billing.prices.update', [
            'id' => $this->plan('starter')->id, 'priceId' => $price->id,
        ]), ['amount' => '19.99']);

        $this->assertSame(1999, $this->plan('starter')->fresh()->priceFor('monthly')->unit_amount);
    }

    // ── Plan management ──────────────────────────────────────────────

    public function test_a_super_admin_can_create_a_plan_and_add_prices(): void
    {
        $this->fakeStripeForSync();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('ops.billing.plans.store'), [
            'name' => 'Agency', 'type' => 'standard',
            'tagline' => 'For agencies running many clients',
            'is_active' => '1', 'is_public' => '1', 'sort_order' => '5',
            'trial_days' => '0',
        ])->assertRedirect();

        $plan = Plan::where('slug', 'agency')->firstOrFail();

        $this->actingAs($admin)->post(route('ops.billing.prices.store', ['id' => $plan->id]), [
            'interval' => 'monthly', 'amount' => '249',
        ])->assertSessionHas('success');

        $price = $plan->fresh()->priceFor('monthly');

        $this->assertSame(24900, $price->unit_amount);
        $this->assertNotNull($price->stripe_price_ref, 'Adding a price should mint the Stripe price.');
    }

    public function test_deactivating_a_plan_hides_it_but_keeps_subscribers_billing(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();

        $this->actingAs($this->admin())
             ->post(route('ops.billing.plans.toggle', ['id' => $this->plan('growth')->id]))
             ->assertSessionHas('success');

        $this->assertFalse($this->plan('growth')->fresh()->is_active);

        // Gone from the public page…
        $this->assertFalse(
            app(\App\Services\Billing\PlanService::class)->publicPlans()->contains('slug', 'growth')
        );

        // …but the existing subscriber is untouched. Pulling a plan out from
        // under paying customers would be indefensible.
        $sub = $client->fresh()->currentSubscription();
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->grantsAccess());
    }

    public function test_only_one_plan_can_be_marked_popular(): void
    {
        $this->actingAs($this->admin())
             ->post(route('ops.billing.plans.feature', ['id' => $this->plan('scale')->id]));

        $this->assertTrue($this->plan('scale')->fresh()->is_featured);
        $this->assertFalse($this->plan('growth')->fresh()->is_featured);
        $this->assertSame(1, Plan::where('is_featured', true)->count());
    }

    public function test_trial_and_free_window_length_are_editable_without_code(): void
    {
        $admin = $this->admin();

        // The whole point of the DB-driven design: switch a paid-plan trial
        // back on, and change the free window, from a form.
        $this->actingAs($admin)->patch(route('ops.billing.plans.update', ['id' => $this->plan('growth')->id]), [
            'name' => 'Growth', 'type' => 'standard',
            'trial_days' => '14', 'trial_requires_payment_method' => '1',
            'is_active' => '1', 'is_public' => '1',
        ])->assertSessionHas('success');

        $this->assertSame(14, $this->plan('growth')->fresh()->trial_days);
        $this->assertTrue($this->plan('growth')->fresh()->hasTrial());

        $this->actingAs($admin)->patch(route('ops.billing.plans.update', ['id' => $this->plan('free')->id]), [
            'name' => 'Free', 'type' => 'free',
            'free_window_days' => '14', 'is_active' => '1', 'is_public' => '1',
        ]);

        $this->assertSame(14, $this->plan('free')->fresh()->free_window_days);
    }

    public function test_limits_are_editable_through_the_matrix(): void
    {
        $growth  = $this->plan('growth');
        $feature = \App\Models\Billing\Feature::where('key', 'telephony_minutes')->firstOrFail();

        $this->actingAs($this->admin())->post(route('ops.billing.features.matrix'), [
            'values' => [$growth->id => [$feature->id => '900']],
        ])->assertSessionHas('success');

        $this->assertSame(
            900,
            app(\App\Services\Billing\PlanFeatureService::class)->planLimit($growth, 'telephony_minutes')
        );
    }

    public function test_clearing_a_matrix_value_removes_the_entitlement(): void
    {
        $growth  = $this->plan('growth');
        $feature = \App\Models\Billing\Feature::where('key', 'api_access')->firstOrFail();

        $this->actingAs($this->admin())->post(route('ops.billing.features.matrix'), [
            'values' => [$growth->id => [$feature->id => '0']],
        ]);

        // "0" on a boolean deletes the row → not granted.
        $this->assertFalse(
            app(\App\Services\Billing\PlanFeatureService::class)->planHas($growth, 'api_access')
        );
        $this->assertDatabaseMissing('plan_features', [
            'plan_id' => $growth->id, 'feature_id' => $feature->id,
        ]);
    }

    public function test_a_stripe_price_string_survives_the_hashid_middleware(): void
    {
        // Regression guard for ANALYSIS §5 C1: DecodeHashids rewrites any
        // request key matching `*_id`. A field called `stripe_price_id` could
        // be silently turned into an integer. Our forms use `plan` + `interval`
        // and `stripe_price_ref`, and this proves those survive intact.
        [$client, $user] = $this->makeWorkspace();

        $captured = null;
        $sessions = \Mockery::mock();
        $sessions->shouldReceive('create')->andReturnUsing(function (array $p) use (&$captured) {
            $captured = $p;

            return \Stripe\Util\Util::convertToStripeObject(
                ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://x.test'], []
            );
        });

        $this->fakeStripe([
            'checkout'  => new class($sessions) { public function __construct(public $sessions) {} },
            'customers' => $this->customerServiceReturning(),
        ]);

        $this->actingAs($user)->post(route('billing.checkout.store', ['client' => $client->slug]), [
            'plan' => 'growth', 'interval' => 'monthly',
        ]);

        $ref = $captured['line_items'][0]['price'];

        $this->assertIsString($ref);
        $this->assertStringStartsWith('price_', $ref);
    }
}
