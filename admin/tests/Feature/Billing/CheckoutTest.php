<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\PlanPrice;

/**
 * Checkout, and the trust boundary around it.
 *
 * The security property under test: the browser submits a plan SLUG and an
 * INTERVAL NAME, nothing else. Amount, currency and Stripe price are resolved
 * server-side, so there is no request field for an attacker to tamper with.
 */
class CheckoutTest extends BillingTestCase
{
    public function test_checkout_resolves_the_price_server_side_and_redirects_to_stripe(): void
    {
        [$client, $user] = $this->makeWorkspace();

        $captured = null;

        $sessions = \Mockery::mock();
        $sessions->shouldReceive('create')
                 ->once()
                 ->andReturnUsing(function (array $payload) use (&$captured) {
                     $captured = $payload;

                     return \Stripe\Util\Util::convertToStripeObject([
                         'id' => 'cs_test_1', 'object' => 'checkout.session',
                         'url' => 'https://checkout.stripe.test/x',
                     ], []);
                 });

        $this->fakeStripe([
            'checkout'  => new class($sessions) { public function __construct(public $sessions) {} },
            'customers' => $this->customerServiceReturning(),
        ]);

        $this->actingAs($user)
             ->post(route('billing.checkout.store', ['client' => $client->slug]), [
                 'plan'     => 'growth',
                 'interval' => 'annually',
             ])
             ->assertRedirect('https://checkout.stripe.test/x');

        $expected = $this->price('growth', 'annually');

        $this->assertSame('subscription', $captured['mode']);
        $this->assertSame($expected->stripe_price_ref, $captured['line_items'][0]['price']);
        $this->assertSame((string) $client->id, $captured['metadata']['client_ref']);
        $this->assertSame('growth', $captured['metadata']['plan_slug']);

        // Nothing money-shaped may be passed through from the request.
        $this->assertArrayNotHasKey('unit_amount', $captured);
        $this->assertArrayNotHasKey('amount', $captured);
    }

    public function test_a_tampered_amount_in_the_request_is_ignored_entirely(): void
    {
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
            'plan'        => 'growth',
            'interval'    => 'monthly',
            // Everything an attacker might try.
            'amount'      => 1,
            'unit_amount' => 1,
            'price'       => 'price_free',
            'currency'    => 'pkr',
            'local_price' => '1',
        ]);

        // The server used ITS OWN price row regardless.
        $this->assertSame(
            $this->price('growth', 'monthly')->stripe_price_ref,
            $captured['line_items'][0]['price']
        );
        $this->assertSame(5900, $this->price('growth', 'monthly')->unit_amount);
    }

    public function test_an_unsynced_price_cannot_be_checked_out(): void
    {
        [$client, $user] = $this->makeWorkspace();

        // A price with no Stripe object would fail at Stripe with an opaque
        // error; resolvePrice refuses it up front with an actionable message.
        PlanPrice::query()
            ->where('plan_id', $this->plan('growth')->id)
            ->update(['stripe_price_ref' => null]);

        $this->fakeStripe(['customers' => $this->customerServiceReturning()]);

        $this->actingAs($user)
             ->post(route('billing.checkout.store', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly',
             ])
             ->assertSessionHas('error');
    }

    public function test_the_free_plan_cannot_be_checked_out(): void
    {
        [$client, $user] = $this->makeWorkspace();
        $this->fakeStripe(['customers' => $this->customerServiceReturning()]);

        $this->actingAs($user)
             ->post(route('billing.checkout.store', ['client' => $client->slug]), [
                 'plan' => 'free', 'interval' => 'monthly',
             ])
             ->assertSessionHas('error');
    }

    public function test_the_enterprise_plan_cannot_be_checked_out(): void
    {
        [$client, $user] = $this->makeWorkspace();
        $this->fakeStripe(['customers' => $this->customerServiceReturning()]);

        $this->actingAs($user)
             ->post(route('billing.checkout.store', ['client' => $client->slug]), [
                 'plan' => 'enterprise', 'interval' => 'monthly',
             ])
             ->assertSessionHas('error');
    }

    public function test_unknown_plan_or_interval_is_rejected(): void
    {
        [$client, $user] = $this->makeWorkspace();
        $this->fakeStripe(['customers' => $this->customerServiceReturning()]);

        $this->actingAs($user)
             ->post(route('billing.checkout.store', ['client' => $client->slug]), [
                 'plan' => 'does-not-exist', 'interval' => 'monthly',
             ])
             ->assertSessionHas('error');

        $this->actingAs($user)
             ->post(route('billing.checkout.store', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'fortnightly',
             ])
             ->assertSessionHas('error');
    }

    public function test_a_non_owner_member_cannot_start_checkout(): void
    {
        [$client] = $this->makeWorkspace();

        $member = \App\Models\User::create([
            'name' => 'Agent', 'email' => 'agent@acme.test', 'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $role = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent',
            'modules' => ['dashboard'], 'is_owner' => false,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $member->attachMembership($client->id, null, $member->id, $role->id);
        $member->forceFill(['active_client_id' => $client->id])->save();

        $this->actingAs($member)
             ->post(route('billing.checkout.store', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly',
             ])
             ->assertForbidden();
    }

    public function test_an_anonymous_visitor_keeps_their_plan_choice_through_registration(): void
    {
        // Losing the selection at the login wall is the most expensive bug a
        // pricing funnel can have.
        $this->post(route('pricing.checkout'), ['plan' => 'growth', 'interval' => 'annually'])
             ->assertRedirect(route('register'))
             ->assertSessionHas('billing.intent', ['plan' => 'growth', 'interval' => 'annually']);
    }

    public function test_the_success_page_does_not_by_itself_activate_a_subscription(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);

        $this->fakeStripe();

        // Anyone can visit this URL. Only the webhook may grant paid state.
        $this->actingAs($user)
             ->get(route('billing.checkout.success', ['client' => $client->slug]))
             ->assertOk();

        $this->assertSame(
            \App\Models\Billing\Subscription::STATUS_FREE,
            $client->fresh()->currentSubscription()->status
        );
    }
}
