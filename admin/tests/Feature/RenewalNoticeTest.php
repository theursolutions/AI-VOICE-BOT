<?php

namespace Tests\Feature;

use App\Mail\RenewalDueMail;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Services\Billing\Gateways\GatewayRegistry;
use App\Services\Billing\RenewalNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Renewal notices for customers whose gateway cannot bill them.
 *
 * Two failure modes matter and they pull in opposite directions. Not sending
 * means a paying customer silently lapses. Sending twice — which the scheduler
 * will do by default, because "period ends in 3 days" stays true all day — trains
 * people to ignore billing mail, and that costs the one message that mattered.
 */
class RenewalNoticeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // A gateway that cannot bill recurring, and no WhatsApp template — so
        // these tests exercise the email leg only, deliberately.
        config([
            'billing.payfast.merchant_id'       => 'TEST',
            'billing.payfast.secured_key'       => 'TEST',
            'billing.renewals.whatsapp.enabled' => false,
            'billing.renewals.days_before'      => [7, 3, 1],
        ]);

        app()->forgetInstance(GatewayRegistry::class);
    }

    private function subscription(array $overrides = []): Subscription
    {
        $plan = Plan::where('type', 'standard')->first();

        if (! $plan) {
            $this->markTestSkipped('No standard plan seeded.');
        }

        $clientId = DB::table('clients')->insertGetId([
            'name'            => 'Renewal Test',
            'slug'            => 'rn-' . uniqid(),
            'client_api_key'  => 'test-' . uniqid(),
            'billing_country' => 'PK',
            'billing_email'   => 'owner-' . uniqid() . '@example.com',
            'created_at'      => time(),
            'updated_at'      => time(),
        ]);

        return Subscription::create(array_merge([
            'client_id'            => $clientId,
            'plan_id'              => $plan->id,
            'plan_price_id'        => $plan->priceFor('monthly')?->id,
            'status'               => Subscription::STATUS_ACTIVE,
            'interval'             => 'monthly',
            'currency'             => 'pkr',
            'current_period_end'   => now()->addDays(3)->setTime(14, 5),
            'created_at'           => now(),
            'updated_at'           => now(),
        ], $overrides));
    }

    public function test_it_notifies_a_customer_whose_gateway_cannot_bill_them(): void
    {
        $this->subscription();

        $result = app(RenewalNotifier::class)->run();

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(RenewalDueMail::class, 1);
    }

    /**
     * THE ONE THAT MATTERS. The scheduler's question stays true for a whole
     * day, so without the claim-before-send guard every run re-sends.
     */
    public function test_running_again_the_same_day_sends_nothing(): void
    {
        $this->subscription();
        $notifier = app(RenewalNotifier::class);

        $this->assertSame(1, $notifier->run()['sent']);
        $this->assertSame(0, $notifier->run()['sent'], 'A second run re-notified the customer');
        $this->assertSame(0, $notifier->run()['sent']);

        Mail::assertSent(RenewalDueMail::class, 1);
    }

    /**
     * Next month's notice must not be suppressed by this month's.
     *
     * Travels forward rather than moving the period out to a month away — that
     * would put it outside every notice window, so a passing test would only
     * prove nothing was due. The real scenario is a month later, with the next
     * period once again three days off.
     */
    public function test_a_later_period_is_notified_again(): void
    {
        $subscription = $this->subscription();
        $notifier     = app(RenewalNotifier::class);

        $this->assertSame(1, $notifier->run()['sent']);

        $this->travel(1)->months();

        // The next billing period, three days out from the new "now".
        $subscription->forceFill(['current_period_end' => now()->addDays(3)->setTime(14, 5)])->save();

        $this->assertSame(
            1,
            $notifier->run()['sent'],
            'The next period was never notified — the notice key is not distinguishing periods',
        );

        $this->travelBack();
    }

    // ── Who must NOT be notified ────────────────────────────────────────

    /** A Stripe customer's renewal happens on its own. */
    public function test_a_customer_whose_gateway_bills_itself_is_skipped(): void
    {
        $subscription = $this->subscription();
        $subscription->client->forceFill(['billing_country' => 'GB'])->save();

        $this->assertFalse(app(RenewalNotifier::class)->needsReminding($subscription->fresh()));

        app(RenewalNotifier::class)->run();
        Mail::assertNothingSent();
    }

    /** Nothing is going to expire, so saying so would be untrue and alarming. */
    public function test_a_complimentary_plan_is_never_asked_to_renew(): void
    {
        $subscription = $this->subscription(['metadata' => ['assigned_by_super_admin' => true]]);

        $this->assertFalse(app(RenewalNotifier::class)->needsReminding($subscription));

        app(RenewalNotifier::class)->run();
        Mail::assertNothingSent();
    }

    /** They have already said they are leaving; asking them to renew argues with that. */
    public function test_a_cancelling_subscription_is_not_chased(): void
    {
        $subscription = $this->subscription(['cancel_at_period_end' => true]);

        $this->assertFalse(app(RenewalNotifier::class)->needsReminding($subscription));
    }

    public function test_a_period_outside_every_window_is_not_notified(): void
    {
        $this->subscription(['current_period_end' => now()->addDays(20)]);

        $this->assertSame(0, app(RenewalNotifier::class)->run()['sent']);
        Mail::assertNothingSent();
    }

    /**
     * A period ending late in the day must still be caught by a morning run —
     * the window is a whole day, not the same instant.
     */
    public function test_the_window_covers_the_whole_day(): void
    {
        $this->subscription(['current_period_end' => now()->addDays(3)->setTime(23, 55)]);

        $this->assertSame(1, app(RenewalNotifier::class)->run()['sent']);
    }

    // ── The message itself ──────────────────────────────────────────────

    /** Three identical notices would be one message sent three times. */
    public function test_the_subject_escalates_as_the_date_approaches(): void
    {
        $subscription = $this->subscription();

        $week = (new RenewalDueMail($subscription, 7))->envelope()->subject;
        $last = (new RenewalDueMail($subscription, 1))->envelope()->subject;

        $this->assertNotSame($week, $last);
        $this->assertStringContainsStringIgnoringCase('last day', $last);
    }

    public function test_every_configured_offset_is_used(): void
    {
        config(['billing.renewals.days_before' => [10, 5, 2]]);

        foreach ([10, 5, 2] as $days) {
            $this->subscription(['current_period_end' => now()->addDays($days)->setTime(12, 0)]);
        }

        $this->assertSame(3, app(RenewalNotifier::class)->run()['sent']);
    }
}
