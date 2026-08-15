<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\SubscriptionAddon;
use App\Services\Billing\AddonService;
use App\Services\Billing\PlanFeatureService;
use App\Services\Billing\SubscriptionService;

/**
 * Add-ons: extra seats and extra AI agents bought on top of a plan.
 *
 * The point of the whole feature is the LAST assertion in most of these —
 * buying capacity has to raise the real ceiling everywhere it's enforced, not
 * just add a line to the invoice.
 */
class AddonTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The two launch add-ons arrive with the schema (migration
        // 2026_08_16_110010), so RefreshDatabase has already created them and
        // BillingTestCase has already given their prices a Stripe ref. Assert
        // rather than re-create: if the migration ever stops seeding them, the
        // failure should say so here instead of surfacing as a confusing
        // "add-on isn't available" three tests down.
        $this->assertSame(
            2,
            Plan::query()->addons()->whereIn('slug', ['addon-seat', 'addon-agent'])->count(),
            'Expected the seat and agent add-ons to be seeded by migration.'
        );

        app(PlanFeatureService::class)->flush();
    }

    /** A workspace on a live paid subscription. */
    private function subscribed(string $planSlug = 'growth', string $interval = 'monthly'): array
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.created', $client, 'active', $planSlug, $interval)
        )->assertOk();

        $client->forgetSubscription();

        return [$client->fresh(), $user];
    }

    /** Stripe double for the subscription-items API. */
    private function fakeItems(string $itemId = 'si_addon_1'): void
    {
        $items = \Mockery::mock();
        $items->shouldReceive('create')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => $itemId, 'object' => 'subscription_item'], [])
        );
        $items->shouldReceive('update')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => $itemId, 'object' => 'subscription_item'], [])
        );
        $items->shouldReceive('delete')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['id' => $itemId, 'deleted' => true], [])
        );

        $this->fakeStripe($this->savedCardServices() + ['subscriptionItems' => $items]);
    }

    // ── The whole point ──────────────────────────────────────────────

    public function test_buying_seats_raises_the_effective_seat_limit(): void
    {
        [$client] = $this->subscribed('growth');            // Growth = 10 seats
        $features = app(PlanFeatureService::class);

        $this->assertSame(10, $features->clientLimit($client, 'seats'));

        $this->fakeItems();
        app(AddonService::class)->setQuantity($client, 'addon-seat', 5);

        $client->forgetSubscription();

        // Not "10 on the plan and 5 on the invoice" — 15 everywhere the
        // ceiling is consulted.
        $this->assertSame(15, $features->clientLimit($client->fresh(), 'seats'));
    }

    public function test_buying_agents_raises_only_the_agent_limit(): void
    {
        [$client] = $this->subscribed('growth');            // 10 agents, 10 seats
        $features = app(PlanFeatureService::class);

        $this->fakeItems();
        app(AddonService::class)->setQuantity($client, 'addon-agent', 3);
        $client->forgetSubscription();

        $fresh = $client->fresh();

        $this->assertSame(13, $features->clientLimit($fresh, 'agents'));
        $this->assertSame(10, $features->clientLimit($fresh, 'seats'), 'Seats must be untouched.');
    }

    public function test_extra_seats_actually_let_another_member_in(): void
    {
        [$client] = $this->subscribed('growth');
        $features = app(PlanFeatureService::class);

        // Squeeze the plan to 2 seats, then fill it.
        $features->setFeature(
            $this->plan('growth'),
            \App\Models\Billing\Feature::where('key', 'seats')->firstOrFail(),
            '2'
        );

        // members.store only accepts a NON-owner role.
        $agentRole = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent', 'modules' => ['dashboard'],
            'is_owner' => false, 'created_at' => time(), 'updated_at' => time(),
        ]);

        $second = \App\Models\User::create([
            'name' => 'Member 2', 'email' => 'seat2@acme.test',
            'password' => bcrypt('x'), 'email_verified_at' => now(),
        ]);
        $second->attachMembership($client->id, null, $second->id, $agentRole->id);

        $owner   = $client->billingOwner();
        $payload = [
            'name'    => 'Member 3',
            'email'   => 'third@acme.test',
            'role_id' => $agentRole->id,
            'scope'   => 'all',
        ];

        // Plan full → hard stop.
        $this->actingAs($owner)
             ->post(route('members.store', ['client' => $client->slug]), $payload)
             ->assertSessionHas('error');

        $this->assertSame(2, $client->fresh()->users()->count());

        // Buy a seat → the identical request now goes through.
        $this->fakeItems();
        app(AddonService::class)->setQuantity($client->fresh(), 'addon-seat', 1);
        $client->forgetSubscription();

        $this->assertSame(3, $features->clientLimit($client->fresh(), 'seats'));

        $this->actingAs($owner)
             ->post(route('members.store', ['client' => $client->slug]), $payload)
             ->assertSessionHasNoErrors();

        $this->assertSame(3, $client->fresh()->users()->count());
    }

    // ── Quantity handling ────────────────────────────────────────────

    public function test_changing_the_quantity_updates_rather_than_stacking(): void
    {
        [$client] = $this->subscribed('growth');
        $this->fakeItems();

        $service = app(AddonService::class);

        $service->setQuantity($client, 'addon-seat', 2);
        $client->forgetSubscription();
        $service->setQuantity($client->fresh(), 'addon-seat', 7);

        // One line per add-on; buying more raises the quantity.
        $this->assertSame(1, SubscriptionAddon::where('client_id', $client->id)->count());
        $this->assertSame(7, (int) SubscriptionAddon::where('client_id', $client->id)->value('quantity'));
    }

    public function test_a_repeated_request_for_the_same_quantity_is_a_no_op(): void
    {
        [$client] = $this->subscribed('growth');
        $this->fakeItems();

        $service = app(AddonService::class);
        $service->setQuantity($client, 'addon-seat', 3);
        $client->forgetSubscription();

        // A double-submitted form must not silently charge for six.
        $service->setQuantity($client->fresh(), 'addon-seat', 3);

        $this->assertSame(3, (int) SubscriptionAddon::where('client_id', $client->id)->value('quantity'));
    }

    public function test_setting_the_quantity_to_zero_removes_the_addon(): void
    {
        [$client] = $this->subscribed('growth');
        $features = app(PlanFeatureService::class);
        $this->fakeItems();

        app(AddonService::class)->setQuantity($client, 'addon-seat', 4);
        $client->forgetSubscription();
        $this->assertSame(14, $features->clientLimit($client->fresh(), 'seats'));

        app(AddonService::class)->setQuantity($client->fresh(), 'addon-seat', 0);
        $client->forgetSubscription();

        // The allowance must drop back, not linger.
        $this->assertSame(10, $features->clientLimit($client->fresh(), 'seats'));
        $this->assertNotNull(SubscriptionAddon::where('client_id', $client->id)->value('cancelled_at'));
    }

    // ── Interval + pricing ───────────────────────────────────────────

    public function test_the_addon_follows_the_subscriptions_billing_interval(): void
    {
        // Stripe refuses to mix a monthly and an annual price on one
        // subscription, and an annual customer shouldn't get a separate
        // monthly seat charge.
        [$client] = $this->subscribed('growth', 'annually');
        $this->fakeItems();

        app(AddonService::class)->setQuantity($client, 'addon-seat', 2);

        $addon = SubscriptionAddon::where('client_id', $client->id)->firstOrFail();

        $this->assertSame('annually', $addon->interval);
        $this->assertSame(5000, $addon->unit_amount);        // $50/yr, not $5/mo
        $this->assertSame('$100', $addon->formattedLineTotal());
    }

    public function test_the_addon_total_is_reported_for_the_billing_page(): void
    {
        [$client] = $this->subscribed('growth');
        $this->fakeItems();

        app(AddonService::class)->setQuantity($client, 'addon-seat', 3);   // 3 × $5
        $client->forgetSubscription();

        $this->assertSame(1500, app(AddonService::class)->monthlyTotalCents($client->fresh()));
    }

    // ── Guards ───────────────────────────────────────────────────────

    public function test_addons_need_an_active_subscription(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->fakeItems();

        $this->expectException(\RuntimeException::class);

        app(AddonService::class)->setQuantity($client->fresh(), 'addon-seat', 1);
    }

    public function test_an_unknown_addon_is_rejected(): void
    {
        [$client] = $this->subscribed('growth');
        $this->fakeItems();

        $this->expectException(\RuntimeException::class);

        app(AddonService::class)->setQuantity($client, 'addon-unicorn', 1);
    }

    public function test_the_endpoint_is_owner_only(): void
    {
        [$client] = $this->subscribed('growth');

        $member = \App\Models\User::create([
            'name' => 'Agent', 'email' => 'notowner@acme.test',
            'password' => bcrypt('x'), 'email_verified_at' => now(),
        ]);
        $role = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent', 'modules' => ['dashboard'],
            'is_owner' => false, 'created_at' => time(), 'updated_at' => time(),
        ]);
        $member->attachMembership($client->id, null, $member->id, $role->id);
        $member->forceFill(['active_client_id' => $client->id])->save();

        $this->actingAs($member)
             ->post(route('billing.addons.update', ['client' => $client->slug]), [
                 'addon' => 'addon-seat', 'quantity' => 1,
             ])
             ->assertForbidden();
    }

    public function test_addons_never_appear_on_the_public_pricing_page(): void
    {
        // They're sold from the billing screen against an existing
        // subscription, not as a plan someone could pick at signup.
        $public = app(\App\Services\Billing\PlanService::class)->publicPlans();

        $this->assertSame(0, $public->where('type', 'addon')->count());

        $this->get('/pricing')->assertOk()->assertDontSee('Extra team seat', false);
        $this->get('/')->assertOk()->assertDontSee('Extra team seat', false);
    }

    public function test_an_unlimited_allowance_cannot_be_topped_up(): void
    {
        // Scale has unlimited agents. Adding to infinity is meaningless, and
        // the limit must stay unlimited rather than becoming a number.
        [$client] = $this->subscribed('scale');
        $features = app(PlanFeatureService::class);

        $this->assertNull($features->clientLimit($client, 'agents'));

        $this->fakeItems();
        app(AddonService::class)->setQuantity($client, 'addon-agent', 5);
        $client->forgetSubscription();

        $this->assertNull($features->clientLimit($client->fresh(), 'agents'));
    }

    public function test_the_plans_page_offers_the_addons(): void
    {
        // The page someone lands on after hitting a limit must let them buy
        // one more seat, not only upgrade the whole tier.
        [$client, $user] = $this->subscribed('growth');
        $this->fakeItems();

        $this->actingAs($user)
             ->get(route('billing.plans', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('Extra team seat', false)
             ->assertSee('name="addon"', false);
    }

    public function test_the_plans_page_does_not_offer_addons_without_a_plan(): void
    {
        // Nothing to attach a subscription item to yet.
        [$client, $user] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);

        $this->actingAs($user)
             ->get(route('billing.plans', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('Choose a plan first', false)
             ->assertDontSee('name="addon"', false);
    }

    public function test_the_billing_page_offers_the_addons(): void
    {
        [$client, $user] = $this->subscribed('growth');
        $this->fakeItems();

        $this->actingAs($user)
             ->get(route('billing.index', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('Add-ons', false)
             ->assertSee('Extra team seat', false)
             ->assertSee('Extra AI agent', false)
             ->assertSee('name="addon"', false);
    }
}
