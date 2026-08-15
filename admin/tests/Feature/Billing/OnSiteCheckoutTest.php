<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Subscription;
use App\Services\Billing\SubscriptionService;

/**
 * The on-site (Stripe Elements) purchase journey:
 *   billing → choose plan → checkout → subscribe → confirm.
 *
 * The property under test throughout: only opaque identifiers ever leave the
 * browser. No amount, currency or price reference is accepted from a request.
 */
class OnSiteCheckoutTest extends BillingTestCase
{
    private function subscriptionsDoubleFor($client, string $intentStatus = 'succeeded', string $subRef = 'sub_new_1'): object
    {
        $payload = $this->subscriptionEvent(
            'customer.subscription.created', $client, 'active', 'growth', 'monthly', $subRef
        )['data']['object'];

        // What Stripe returns from subscriptions->create() with
        // payment_behavior=default_incomplete + expand=latest_invoice.payment_intent
        $payload['latest_invoice'] = [
            'id'             => 'in_new_1',
            'payment_intent' => [
                'id'            => 'pi_new_1',
                'status'        => $intentStatus,
                'client_secret' => 'pi_new_1_secret_abc',
            ],
        ];

        $double = \Mockery::mock();
        $double->shouldReceive('create')->andReturn(\Stripe\Util\Util::convertToStripeObject($payload, []));
        $double->shouldReceive('retrieve')->andReturn(\Stripe\Util\Util::convertToStripeObject($payload, []));
        $double->shouldReceive('update')->andReturn(\Stripe\Util\Util::convertToStripeObject($payload, []));

        return $double;
    }

    // ── Choose-plan page ─────────────────────────────────────────────

    public function test_the_plans_page_marks_the_current_plan(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();

        $this->actingAs($user)
             ->get(route('billing.plans', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee('Your plan', false)        // the ribbon on Growth
             ->assertSee('Current plan', false)     // its disabled button
             ->assertSee('Starter', false)
             ->assertSee('Scale', false)
             // The tiering cue that makes the ladder legible.
             ->assertSee('Everything in Starter, plus:', false);
    }

    public function test_the_plans_page_is_owner_only(): void
    {
        [$client] = $this->makeWorkspace();

        $member = \App\Models\User::create([
            'name' => 'Agent', 'email' => 'agent2@acme.test',
            'password' => bcrypt('x'), 'email_verified_at' => now(),
        ]);
        $role = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent', 'modules' => ['dashboard'],
            'is_owner' => false, 'created_at' => time(), 'updated_at' => time(),
        ]);
        $member->attachMembership($client->id, null, $member->id, $role->id);
        $member->forceFill(['active_client_id' => $client->id])->save();

        $this->actingAs($member)
             ->get(route('billing.plans', ['client' => $client->slug]))
             ->assertForbidden();
    }

    // ── Checkout page ────────────────────────────────────────────────

    public function test_the_checkout_page_prices_from_the_database(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        // A saved card only exists once the workspace has a Stripe customer —
        // which it gets on its first purchase or when it adds a card.
        $client->forceFill(['stripe_customer_ref' => 'cus_test_1'])->save();

        $this->fakeStripe($this->savedCardServices());

        $response = $this->actingAs($user)->get(route('billing.checkout', [
            'client' => $client->slug, 'plan' => 'growth', 'interval' => 'annually',
        ]));

        $response->assertOk();
        $response->assertSee('Growth', false);
        $response->assertSee('$590', false);
        $response->assertSee('$49.17/mo', false);       // effective monthly
        $response->assertSee('You save', false);
        $response->assertSee('4242', false);            // saved card, pre-selected
        $response->assertSee('Order summary', false);
    }

    public function test_the_checkout_page_offers_a_new_card_when_none_is_saved(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->fakeStripe(['customers' => $this->customerServiceReturning()]);

        $this->actingAs($user)
             ->get(route('billing.checkout', ['client' => $client->slug, 'plan' => 'starter', 'interval' => 'monthly']))
             ->assertOk()
             ->assertSee('Add your card', false)
             ->assertSee('ck-element', false)        // the Elements mount point
             ->assertSee('js.stripe.com', false);
    }

    public function test_the_checkout_page_rejects_an_unknown_plan(): void
    {
        [$client, $user] = $this->makeWorkspace();
        $this->fakeStripe($this->savedCardServices());

        $this->actingAs($user)
             ->get(route('billing.checkout', ['client' => $client->slug, 'plan' => 'nope', 'interval' => 'monthly']))
             ->assertRedirect(route('billing.plans', ['client' => $client->slug]));
    }

    public function test_the_checkout_page_404s_while_purchasing_is_switched_off(): void
    {
        config(['billing.checkout.enabled' => false]);

        [$client, $user] = $this->makeWorkspace();

        $this->actingAs($user)
             ->get(route('billing.checkout', ['client' => $client->slug, 'plan' => 'growth', 'interval' => 'monthly']))
             ->assertNotFound();
    }

    // ── Subscribe ────────────────────────────────────────────────────

    public function test_subscribing_with_a_card_creates_the_subscription(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $captured = null;
        $subs = \Mockery::mock();
        $subs->shouldReceive('create')->once()->andReturnUsing(function (array $payload) use (&$captured, $client) {
            $captured = $payload;

            $body = $this->subscriptionEvent('x', $client, 'active', 'growth', 'monthly', 'sub_new_1')['data']['object'];
            $body['latest_invoice'] = ['payment_intent' => [
                'status' => 'succeeded', 'client_secret' => 'pi_secret',
            ]];

            return \Stripe\Util\Util::convertToStripeObject($body, []);
        });
        $subs->shouldReceive('retrieve')->andReturn(\Stripe\Util\Util::convertToStripeObject(
            $this->subscriptionEvent('x', $client, 'active')['data']['object'], []
        ));

        $this->fakeStripe($this->savedCardServices() + ['subscriptions' => $subs]);

        $this->actingAs($user)
             ->postJson(route('billing.subscribe', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_test_1',
             ])
             ->assertOk()
             ->assertJsonPath('status', 'succeeded')
             ->assertJsonPath('requires_action', false);

        // default_incomplete is what makes 3-D Secure possible at all — without
        // it Stripe charges server-side and SCA cards fail outright.
        $this->assertSame('default_incomplete', $captured['payment_behavior']);
        $this->assertSame('pm_test_1', $captured['default_payment_method']);
        $this->assertSame(
            $this->price('growth', 'monthly')->stripe_price_ref,
            $captured['items'][0]['price']
        );
        $this->assertContains('latest_invoice.payment_intent', $captured['expand']);
        $this->assertSame('on_subscription', $captured['payment_settings']['save_default_payment_method']);
    }

    public function test_a_freshly_tokenised_card_is_attached_before_subscribing(): void
    {
        // THE FIRST-CHECKOUT BUG. A card tokenised by Elements belongs to
        // nobody yet. Passing it straight to subscriptions->create() as
        // default_payment_method makes Stripe answer "The customer does not
        // have a payment method with the ID pm_…", so the very first purchase
        // any customer ever attempts fails. It must be attached first.
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $attached = [];

        $card = [
            'id' => 'pm_fresh_1', 'object' => 'payment_method', 'type' => 'card',
            'customer' => null,                      // ← straight from Elements
            'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12,
                       'exp_year' => (int) now()->addYears(3)->format('Y')],
        ];

        $paymentMethods = \Mockery::mock();
        $paymentMethods->shouldReceive('retrieve')->andReturn(
            \Stripe\Util\Util::convertToStripeObject($card, [])
        );
        $paymentMethods->shouldReceive('attach')->andReturnUsing(
            function ($id, $params) use (&$attached, $card) {
                $attached[] = [$id, $params['customer'] ?? null];

                return \Stripe\Util\Util::convertToStripeObject($card, []);
            }
        );
        $paymentMethods->shouldReceive('all')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(['object' => 'list', 'data' => [$card]], [])
        );

        $this->fakeStripe([
            'customers'      => $this->customerServiceReturning('cus_test_1'),
            'paymentMethods' => $paymentMethods,
            'subscriptions'  => $this->subscriptionsDoubleFor($client),
        ]);

        $this->actingAs($user)
             ->postJson(route('billing.subscribe', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_fresh_1',
             ])
             ->assertOk()
             ->assertJsonPath('status', 'succeeded');

        $this->assertSame([['pm_fresh_1', 'cus_test_1']], $attached);
    }

    public function test_an_already_attached_card_is_not_re_attached(): void
    {
        // The saved-card path. Re-attaching would be a wasted call and, on a
        // PM already attached elsewhere, an error.
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $services = $this->savedCardServices();          // retrieve() → cus_test_1
        $services['paymentMethods']->shouldReceive('attach')->never();

        $this->fakeStripe($services + ['subscriptions' => $this->subscriptionsDoubleFor($client)]);

        $this->actingAs($user)
             ->postJson(route('billing.subscribe', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_test_1',
             ])
             ->assertOk();
    }

    public function test_another_workspaces_card_cannot_be_used_at_checkout(): void
    {
        // `pm_…` comes from the browser. Stripe's "already been attached"
        // message covers both "to you" and "to someone else", so deciding from
        // the error text would have accepted a foreign customer's card here.
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $foreign = \Mockery::mock();
        $foreign->shouldReceive('retrieve')->andReturn(
            \Stripe\Util\Util::convertToStripeObject([
                'id' => 'pm_theirs', 'object' => 'payment_method', 'type' => 'card',
                'customer' => 'cus_someone_else',
                'card' => ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 1, 'exp_year' => 2031],
            ], [])
        );
        $foreign->shouldReceive('attach')->never();

        $this->fakeStripe([
            'customers'      => $this->customerServiceReturning('cus_test_1'),
            'paymentMethods' => $foreign,
            'subscriptions'  => $this->subscriptionsDoubleFor($client),
        ]);

        $this->actingAs($user)
             ->postJson(route('billing.subscribe', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_theirs',
             ])
             ->assertStatus(422);

        $this->assertSame(0, Subscription::whereNotNull('stripe_subscription_ref')->count());
    }

    public function test_a_card_needing_3ds_asks_the_browser_to_confirm(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->fakeStripe($this->savedCardServices() + [
            'subscriptions' => $this->subscriptionsDoubleFor($client, 'requires_action'),
        ]);

        $this->actingAs($user)
             ->postJson(route('billing.subscribe', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_test_1',
             ])
             ->assertOk()
             ->assertJsonPath('requires_action', true)
             ->assertJsonPath('client_secret', 'pi_new_1_secret_abc');
    }

    public function test_requires_confirmation_also_hands_back_to_the_browser(): void
    {
        // The saved-card case. Treating only requires_action as "browser work"
        // would leave these subscriptions stuck as incomplete forever.
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->fakeStripe($this->savedCardServices() + [
            'subscriptions' => $this->subscriptionsDoubleFor($client, 'requires_confirmation'),
        ]);

        $this->actingAs($user)
             ->postJson(route('billing.subscribe', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_test_1',
             ])
             ->assertOk()
             ->assertJsonPath('requires_action', true);
    }

    public function test_subscribe_refuses_a_tampered_price(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $captured = null;
        $subs = \Mockery::mock();
        $subs->shouldReceive('create')->andReturnUsing(function (array $p) use (&$captured, $client) {
            $captured = $p;
            $body = $this->subscriptionEvent('x', $client, 'active')['data']['object'];
            $body['latest_invoice'] = ['payment_intent' => ['status' => 'succeeded', 'client_secret' => 's']];

            return \Stripe\Util\Util::convertToStripeObject($body, []);
        });
        $subs->shouldReceive('retrieve')->andReturn(\Stripe\Util\Util::convertToStripeObject(
            $this->subscriptionEvent('x', $client, 'active')['data']['object'], []
        ));

        $this->fakeStripe($this->savedCardServices() + ['subscriptions' => $subs]);

        $this->actingAs($user)->postJson(route('billing.subscribe', ['client' => $client->slug]), [
            'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_test_1',
            // Everything an attacker might try:
            'amount' => 1, 'unit_amount' => 1, 'price' => 'price_free', 'currency' => 'pkr',
        ])->assertOk();

        // The server used its own row regardless.
        $this->assertSame(
            $this->price('growth', 'monthly')->stripe_price_ref,
            $captured['items'][0]['price']
        );
    }

    public function test_subscribe_is_owner_only(): void
    {
        [$client] = $this->makeWorkspace();

        $member = \App\Models\User::create([
            'name' => 'Agent', 'email' => 'agent3@acme.test',
            'password' => bcrypt('x'), 'email_verified_at' => now(),
        ]);
        $role = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent', 'modules' => ['dashboard'],
            'is_owner' => false, 'created_at' => time(), 'updated_at' => time(),
        ]);
        $member->attachMembership($client->id, null, $member->id, $role->id);
        $member->forceFill(['active_client_id' => $client->id])->save();

        $this->actingAs($member)
             ->postJson(route('billing.subscribe', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_x',
             ])
             ->assertForbidden();
    }

    public function test_a_declined_card_returns_the_banks_message(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $subs = \Mockery::mock();
        $subs->shouldReceive('create')->andThrow(
            new \Stripe\Exception\CardException('Your card was declined.')
        );

        $this->fakeStripe($this->savedCardServices() + ['subscriptions' => $subs]);

        $this->actingAs($user)
             ->postJson(route('billing.subscribe', ['client' => $client->slug]), [
                 'plan' => 'growth', 'interval' => 'monthly', 'payment_method' => 'pm_test_1',
             ])
             ->assertStatus(422);

        // Nothing was activated locally.
        $this->assertSame(Subscription::STATUS_FREE, $client->fresh()->currentSubscription()->status);
    }

    // ── Saved cards ──────────────────────────────────────────────────

    public function test_a_setup_intent_can_be_created_for_adding_a_card(): void
    {
        [$client, $user] = $this->makeWorkspace();

        $setupIntents = \Mockery::mock();
        $setupIntents->shouldReceive('create')->once()->andReturn(
            \Stripe\Util\Util::convertToStripeObject(
                ['id' => 'seti_1', 'object' => 'setup_intent', 'client_secret' => 'seti_1_secret'], []
            )
        );

        $this->fakeStripe($this->savedCardServices() + ['setupIntents' => $setupIntents]);

        $this->actingAs($user)
             ->postJson(route('billing.cards.intent', ['client' => $client->slug]))
             ->assertOk()
             ->assertJsonPath('client_secret', 'seti_1_secret');
    }

    public function test_a_card_can_be_attached_and_becomes_the_default(): void
    {
        [$client, $user] = $this->makeWorkspace();
        $this->fakeStripe($this->savedCardServices());

        $this->actingAs($user)
             ->post(route('billing.cards.store', ['client' => $client->slug]), [
                 'payment_method' => 'pm_test_1',
             ])
             ->assertRedirect();

        $client->refresh();

        $this->assertSame('visa', $client->pm_type);
        $this->assertSame('4242', $client->pm_last_four);
    }

    public function test_a_payment_method_belonging_to_another_workspace_is_refused(): void
    {
        // A pm_… id from the browser is untrusted input like any other.
        [$client, $user] = $this->makeWorkspace();
        $client->forceFill(['stripe_customer_ref' => 'cus_mine'])->save();

        $foreign = \Mockery::mock();
        $foreign->shouldReceive('retrieve')->andReturn(
            \Stripe\Util\Util::convertToStripeObject(
                ['id' => 'pm_theirs', 'object' => 'payment_method', 'customer' => 'cus_someone_else'], []
            )
        );

        $this->fakeStripe(['paymentMethods' => $foreign, 'customers' => $this->customerServiceReturning('cus_mine')]);

        $this->actingAs($user)
             ->post(route('billing.cards.default', ['client' => $client->slug]), [
                 'payment_method' => 'pm_theirs',
             ])
             ->assertSessionHas('error');
    }

    public function test_card_management_is_owner_only(): void
    {
        [$client] = $this->makeWorkspace();

        $member = \App\Models\User::create([
            'name' => 'Agent', 'email' => 'agent4@acme.test',
            'password' => bcrypt('x'), 'email_verified_at' => now(),
        ]);
        $role = \App\Models\Role::create([
            'client_id' => $client->id, 'name' => 'Agent', 'modules' => ['dashboard'],
            'is_owner' => false, 'created_at' => time(), 'updated_at' => time(),
        ]);
        $member->attachMembership($client->id, null, $member->id, $role->id);
        $member->forceFill(['active_client_id' => $client->id])->save();

        $this->actingAs($member)
             ->post(route('billing.cards.store', ['client' => $client->slug]), ['payment_method' => 'pm_x'])
             ->assertForbidden();
    }

    // ── Invoice ──────────────────────────────────────────────────────

    public function test_the_branded_invoice_renders(): void
    {
        [$client, $user] = $this->makeWorkspace();
        $client->forceFill(['stripe_customer_ref' => 'cus_test_1'])->save();

        $invoices = \Mockery::mock();
        $invoices->shouldReceive('retrieve')->andReturn(\Stripe\Util\Util::convertToStripeObject([
            'id' => 'in_1', 'object' => 'invoice', 'customer' => 'cus_test_1',
            'number' => 'SRV-0001', 'status' => 'paid', 'currency' => 'usd',
            'subtotal' => 5900, 'tax' => 0, 'total' => 5900, 'amount_paid' => 5900,
            'created' => now()->getTimestamp(),
            'status_transitions' => ['paid_at' => now()->getTimestamp()],
            'customer_name' => 'Acme Ltd', 'customer_email' => 'owner@acme.test',
            'lines' => ['data' => [[
                'description' => 'Growth (monthly)', 'quantity' => 1, 'amount' => 5900,
                'period' => ['start' => now()->getTimestamp(), 'end' => now()->addMonth()->getTimestamp()],
            ]]],
        ], []));

        $this->fakeStripe(['invoices' => $invoices]);

        $this->actingAs($user)
             ->get(route('billing.invoice', ['client' => $client->slug, 'invoice' => 'in_1']))
             ->assertOk()
             ->assertSee('SRV-0001', false)
             ->assertSee('Growth (monthly)', false)
             ->assertSee('$59.00', false)
             ->assertSee('Paid', false);
    }

    public function test_an_invoice_from_another_workspace_is_not_readable(): void
    {
        [$client, $user] = $this->makeWorkspace();
        $client->forceFill(['stripe_customer_ref' => 'cus_mine'])->save();

        $invoices = \Mockery::mock();
        $invoices->shouldReceive('retrieve')->andReturn(\Stripe\Util\Util::convertToStripeObject([
            'id' => 'in_theirs', 'object' => 'invoice', 'customer' => 'cus_someone_else',
            'total' => 999999, 'lines' => ['data' => []],
        ], []));

        $this->fakeStripe(['invoices' => $invoices]);

        // An in_… id in the URL must not read another tenant's invoice.
        $this->actingAs($user)
             ->get(route('billing.invoice', ['client' => $client->slug, 'invoice' => 'in_theirs']))
             ->assertNotFound();
    }

    // ── Discoverability (the original complaint) ─────────────────────

    public function test_billing_is_reachable_from_the_account_menu_on_any_page(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $billingUrl = route('billing.index', ['client' => $client->slug]);

        // From a page that has nothing to do with billing.
        $this->actingAs($user)
             ->get(route('project-profile.index', ['client' => $client->slug]))
             ->assertOk()
             ->assertSee($billingUrl, false)
             ->assertSee('Plan &amp; billing', false);
    }
}
