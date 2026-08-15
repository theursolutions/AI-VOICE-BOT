<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\StripeEvent;
use App\Models\Billing\Subscription;

/**
 * The webhook endpoint: authenticity, idempotency, and correct ACKs.
 *
 * These are the three properties that make this endpoint safe. Signatures are
 * generated for real (see BillingTestCase::postWebhook) so the verification
 * path is genuinely exercised rather than mocked away — without it this route
 * is an unauthenticated "make me a subscriber" API.
 */
class WebhookTest extends BillingTestCase
{
    public function test_an_unsigned_request_is_rejected_and_not_recorded(): void
    {
        $this->postJson('/stripe/webhook', ['id' => 'evt_x', 'type' => 'invoice.paid'])
             ->assertStatus(400);

        $this->assertSame(0, StripeEvent::count(), 'A forged request must leave no trace of being accepted.');
    }

    public function test_a_wrongly_signed_request_is_rejected(): void
    {
        [$client] = $this->makeWorkspace();

        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.updated', $client),
            secret: 'whsec_the_wrong_secret'
        )->assertStatus(400);

        $this->assertSame(0, StripeEvent::count());
        $this->assertSame(0, Subscription::whereNotNull('stripe_subscription_ref')->count());
    }

    public function test_a_stale_timestamp_is_rejected(): void
    {
        [$client] = $this->makeWorkspace();

        // Replay protection: a correctly-signed body captured hours ago must
        // not be accepted.
        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.updated', $client),
            timestamp: time() - 4000
        )->assertStatus(400);
    }

    public function test_subscription_created_activates_the_workspace(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);

        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.created', $client, 'active', 'growth', 'monthly')
        )->assertOk();

        $sub = $client->fresh()->currentSubscription();

        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertSame('active', $sub->stripe_status);
        $this->assertSame('growth', $sub->plan->slug);
        $this->assertSame('monthly', $sub->interval);
        $this->assertSame(5900, $sub->unit_amount);
        $this->assertSame('sub_test_1', $sub->stripe_subscription_ref);

        // The free window must be replaced, not stacked on top of.
        $this->assertSame(1, Subscription::where('client_id', $client->id)->count());

        $this->assertSame('active', $client->fresh()->access_state);
        $this->assertTrue($client->fresh()->hasBillingAccess());
    }

    public function test_the_same_event_delivered_twice_is_processed_once(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);

        $event = $this->subscriptionEvent('customer.subscription.created', $client);

        $this->postWebhook($event)->assertOk();
        // Stripe retries every non-2xx and can re-send spontaneously, so a
        // duplicate is normal traffic. It must ACK and change nothing.
        $this->postWebhook($event)->assertOk();

        $this->assertSame(1, StripeEvent::where('stripe_event_id', $event['id'])->count());
        $this->assertSame(1, StripeEvent::count());
        $this->assertSame(1, Subscription::where('client_id', $client->id)->count());
    }

    public function test_an_unhandled_event_type_is_acked_and_skipped(): void
    {
        // A 500 here would make Stripe retry an event we don't care about for
        // days and bury the real failures.
        $this->postWebhook([
            'id' => 'evt_unhandled', 'object' => 'event', 'livemode' => false,
            'type' => 'customer.discount.created',
            'data' => ['object' => ['id' => 'di_1']],
        ])->assertOk();

        $this->assertSame(StripeEvent::STATUS_SKIPPED, StripeEvent::first()->status);
    }

    public function test_payment_failure_marks_past_due_but_keeps_access_during_grace(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();

        $this->postWebhook([
            'id' => 'evt_failed_1', 'object' => 'event', 'livemode' => false,
            'type' => 'invoice.payment_failed',
            'data' => ['object' => [
                'id' => 'in_1', 'object' => 'invoice',
                'subscription' => 'sub_test_1', 'attempt_count' => 1,
            ]],
        ])->assertOk();

        $sub = $client->fresh()->currentSubscription();

        $this->assertSame(Subscription::STATUS_PAST_DUE, $sub->status);
        $this->assertNotNull($sub->past_due_since);

        // A bounced card must not instantly silence someone's phone line.
        $this->assertTrue($sub->grantsAccess(), 'past_due keeps access during the grace window.');
    }

    public function test_access_is_revoked_once_the_past_due_grace_expires(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();
        $this->postWebhook([
            'id' => 'evt_failed_2', 'object' => 'event', 'livemode' => false,
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['id' => 'in_2', 'subscription' => 'sub_test_1']],
        ])->assertOk();

        $this->travel(config('billing.lifecycle.past_due_grace_days') + 1)->days();
        $client->forgetSubscription();

        $this->assertFalse($client->currentSubscription()->grantsAccess());
    }

    public function test_a_successful_payment_clears_past_due_and_the_read_only_flag(): void
    {
        [$client] = $this->makeWorkspace();

        // invoice.paid re-reads the subscription from Stripe (one code path owns
        // period dates), so the handler needs the subscriptions double.
        //
        // Bound BEFORE the first request, not mid-test: container instances
        // registered after a test request has already been dispatched don't
        // reliably reach the next one, and the symptom is a live API call with
        // a fake key — a 500 that looks nothing like a wiring problem.
        $this->fakeStripe([
            'subscriptions' => $this->subscriptionServiceReturning(
                $this->subscriptionEvent('customer.subscription.updated', $client)['data']['object']
            ),
        ]);

        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();
        $this->postWebhook([
            'id' => 'evt_failed_3', 'object' => 'event', 'livemode' => false,
            'type' => 'invoice.payment_failed',
            'data' => ['object' => ['id' => 'in_3', 'subscription' => 'sub_test_1']],
        ])->assertOk();

        $this->postWebhook([
            'id' => 'evt_paid_1', 'object' => 'event', 'livemode' => false,
            'type' => 'invoice.paid',
            'data' => ['object' => ['id' => 'in_4', 'subscription' => 'sub_test_1']],
        ])->assertOk();

        $sub = $client->fresh()->currentSubscription();

        // Recovery must never be half-applied.
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        $this->assertNull($sub->past_due_since);
        $this->assertNull($sub->read_only_since);
        $this->assertNull($sub->purge_after);
    }

    public function test_subscription_deleted_cancels_and_schedules_retention(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();

        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.deleted', $client, 'canceled')
        )->assertOk();

        $sub = $client->fresh()->currentSubscription();

        $this->assertSame(Subscription::STATUS_CANCELED, $sub->status);
        $this->assertNotNull($sub->read_only_since);
        $this->assertFalse($sub->grantsAccess());
    }

    public function test_cancel_at_period_end_keeps_access_until_the_paid_period_ends(): void
    {
        [$client] = $this->makeWorkspace();
        app(\App\Services\Billing\SubscriptionService::class)->startFreeWindow($client);

        $periodEnd = now()->addDays(20);

        $this->postWebhook($this->subscriptionEvent(
            'customer.subscription.updated', $client, 'active', 'growth', 'monthly', 'sub_test_1',
            [
                'cancel_at_period_end' => true,
                'current_period_end'   => $periodEnd->getTimestamp(),
            ]
        ))->assertOk();

        $sub = $client->fresh()->currentSubscription();

        // The customer paid for this period; cancelling must not delete it.
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertTrue($sub->onGracePeriod());
        $this->assertTrue($sub->grantsAccess());
        $this->assertNull($sub->nextBillingDate(), 'Nothing further will be charged.');
    }

    public function test_a_payment_method_records_the_card_fingerprint_against_the_free_window(): void
    {
        [$client] = $this->makeWorkspace();
        $client->forceFill(['stripe_customer_ref' => 'cus_test_1'])->save();

        $this->postWebhook([
            'id' => 'evt_pm_1', 'object' => 'event', 'livemode' => false,
            'type' => 'payment_method.attached',
            'data' => ['object' => [
                'id' => 'pm_1', 'object' => 'payment_method', 'type' => 'card',
                'customer' => 'cus_test_1',
                'card' => ['brand' => 'visa', 'last4' => '4242', 'fingerprint' => 'fp_abc123'],
            ]],
        ])->assertOk();

        $client->refresh();

        $this->assertSame('visa', $client->pm_type);
        $this->assertSame('4242', $client->pm_last_four);

        // Stripe's card fingerprint is stable across customers — the strongest
        // signal we have that one physical card is farming free windows.
        $this->assertDatabaseHas('trial_fingerprints', [
            'kind'       => 'card',
            'value_hash' => \App\Models\Billing\TrialFingerprint::hash('fp_abc123'),
        ]);
    }

    // ── API-version shape changes ────────────────────────────────────

    public function test_period_dates_are_read_from_the_subscription_item(): void
    {
        // Stripe moved current_period_start/end off the subscription and onto
        // each ITEM in API version 2025-03-31.basil. Reading only the old
        // location writes NULL on a modern account — no error anywhere, but
        // renewal quota resets, the usage window and `ends_at` all break at
        // once. This is the modern payload shape.
        [$client] = $this->makeWorkspace();

        $start = now()->subDays(3)->getTimestamp();
        $end   = now()->addDays(27)->getTimestamp();

        $event = $this->subscriptionEvent('customer.subscription.created', $client, 'active');

        unset($event['data']['object']['current_period_start']);
        unset($event['data']['object']['current_period_end']);

        $event['data']['object']['items']['data'][0]['current_period_start'] = $start;
        $event['data']['object']['items']['data'][0]['current_period_end']   = $end;

        $this->postWebhook($event)->assertOk();

        $subscription = Subscription::where('client_id', $client->id)->latest('id')->first();

        $this->assertNotNull($subscription->current_period_start, 'Period start must survive the newer payload shape.');
        $this->assertSame($start, $subscription->current_period_start->getTimestamp());
        $this->assertSame($end, $subscription->current_period_end->getTimestamp());
    }

    public function test_an_addon_item_does_not_hijack_the_plan(): void
    {
        // Add-ons are extra items on the SAME subscription and Stripe makes no
        // promise about their order. Reading items.data[0] blindly would
        // rewrite the workspace's plan to "Extra team seat" — downgrading a
        // paying customer to an add-on.
        [$client] = $this->makeWorkspace();

        $growth = $this->price('growth', 'monthly');
        $seat   = $this->plan('addon-seat')->priceFor('monthly');

        $event = $this->subscriptionEvent('customer.subscription.updated', $client, 'active');

        // Add-on FIRST, plan second.
        array_unshift($event['data']['object']['items']['data'], [
            'id'       => 'si_addon',
            'quantity' => 3,
            'price'    => [
                'id'          => $seat->stripe_price_ref,
                'unit_amount' => $seat->unit_amount,
                'currency'    => 'usd',
            ],
        ]);

        $this->postWebhook($event)->assertOk();

        $subscription = Subscription::where('client_id', $client->id)->latest('id')->first();

        $this->assertSame($this->plan('growth')->id, $subscription->plan_id);
        $this->assertSame($growth->stripe_price_ref, $subscription->stripe_price_ref);
        $this->assertSame(5900, $subscription->unit_amount);
        $this->assertSame(1, $subscription->quantity, 'Quantity must come from the plan line, not the add-on.');
    }

    public function test_an_event_for_an_unknown_subscription_is_acked_not_retried_forever(): void
    {
        // e.g. a subscription created directly in the Stripe dashboard.
        [$client] = $this->makeWorkspace();

        $event = $this->subscriptionEvent('customer.subscription.updated', $client, 'active');
        $event['data']['object']['metadata'] = [];
        $event['data']['object']['customer'] = 'cus_unknown';

        $this->postWebhook($event)->assertOk();

        $this->assertSame(StripeEvent::STATUS_PROCESSED, StripeEvent::first()->status);
    }
}
