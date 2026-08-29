<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Safepay webhook.
 *
 * Without its signature check this route is an unauthenticated "make me a
 * subscriber" API — anyone who knows the URL could post a payment event and be
 * granted a plan. So the tests that matter most here are the ones that prove a
 * forged or unsigned delivery changes nothing.
 */
class SafepayWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'webhook-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.safepay.webhook_secret' => self::SECRET,
            'billing.safepay.v1_secret'      => 'v1-test-secret',
            'billing.safepay.api_key'        => 'sec_test',
        ]);
    }

    private function charge(array $overrides = []): object
    {
        $clientId = DB::table('clients')->insertGetId([
            'name'           => 'Webhook Test',
            'slug'           => 'wh-' . uniqid(),
            'client_api_key' => 'test-' . uniqid(),
            'created_at'     => time(),
            'updated_at'     => time(),
        ]);

        $id = DB::table('gateway_charges')->insertGetId(array_merge([
            'client_id'    => $clientId,
            'gateway'      => 'safepay',
            'reference'    => 'ORDER-' . uniqid(),
            'amount_cents' => 750000,
            'currency'     => 'PKR',
            'status'       => 'pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ], $overrides));

        return DB::table('gateway_charges')->find($id);
    }

    private function deliver(array $payload, ?string $signature = null)
    {
        $raw = json_encode($payload);

        return $this->call(
            'POST',
            '/billing/safepay/webhook',
            [], [], [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_X_SFPY_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, self::SECRET),
            ],
            $raw,
        );
    }

    // ── The security boundary ───────────────────────────────────────────

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        $charge = $this->charge();

        $this->deliver(['tracker' => 'trk_1', 'order_id' => $charge->reference], '')
            ->assertStatus(400);

        $this->assertSame('pending', DB::table('gateway_charges')->find($charge->id)->status);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $charge = $this->charge();

        $this->deliver(['tracker' => 'trk_1', 'order_id' => $charge->reference], hash_hmac('sha256', 'anything', 'wrong-secret'))
            ->assertStatus(400);

        $this->assertSame(
            'pending',
            DB::table('gateway_charges')->find($charge->id)->status,
            'A forged webhook marked a charge as paid',
        );
    }

    /**
     * The MAC covers the bytes that arrived. A signature computed over a
     * DIFFERENT body must not validate this one, or an attacker could replay a
     * genuine signature against an altered payload.
     */
    public function test_a_signature_for_a_different_body_is_rejected(): void
    {
        $charge = $this->charge();
        $other  = hash_hmac('sha256', json_encode(['tracker' => 'someone-elses']), self::SECRET);

        $this->deliver(['tracker' => 'trk_1', 'order_id' => $charge->reference], $other)
            ->assertStatus(400);

        $this->assertSame('pending', DB::table('gateway_charges')->find($charge->id)->status);
    }

    // ── The happy path ──────────────────────────────────────────────────

    public function test_a_signed_delivery_marks_the_charge_paid(): void
    {
        $charge = $this->charge();

        $this->deliver(['tracker' => 'trk_ok', 'order_id' => $charge->reference])
            ->assertOk();

        $fresh = DB::table('gateway_charges')->find($charge->id);

        $this->assertSame('paid', $fresh->status);
        $this->assertSame('trk_ok', $fresh->gateway_ref);
        $this->assertNotNull($fresh->paid_at);
    }

    /**
     * Safepay retries, and the redirect can arrive at the same moment. A second
     * delivery must be a no-op rather than a second payment.
     */
    public function test_a_repeated_delivery_changes_nothing(): void
    {
        $charge = $this->charge();
        $body   = ['tracker' => 'trk_ok', 'order_id' => $charge->reference];

        $this->deliver($body)->assertOk();
        $paidAt = DB::table('gateway_charges')->find($charge->id)->paid_at;

        $this->deliver($body)->assertOk();

        $this->assertSame($paidAt, DB::table('gateway_charges')->find($charge->id)->paid_at);
    }

    /**
     * A payment we have no record of starting is accepted, not errored — a 4xx
     * would have Safepay redeliver for days over something a retry can never
     * resolve.
     */
    public function test_an_unknown_charge_is_acknowledged_rather_than_retried(): void
    {
        $this->deliver(['tracker' => 'trk_x', 'order_id' => 'NOT-OURS'])->assertOk();
    }

    /** A paid charge extends the subscription to the period it was raised for. */
    public function test_payment_extends_the_subscription_period(): void
    {
        $plan = \App\Models\Billing\Plan::where('type', 'standard')->first();

        if (! $plan) {
            $this->markTestSkipped('No standard plan seeded.');
        }

        $clientId = DB::table('clients')->insertGetId([
            'name' => 'Period Test', 'slug' => 'pt-' . uniqid(),
            'client_api_key' => 'test-' . uniqid(),
            'created_at' => time(), 'updated_at' => time(),
        ]);

        $subscription = \App\Models\Billing\Subscription::create([
            'client_id'          => $clientId,
            'plan_id'            => $plan->id,
            'status'             => 'past_due',
            'interval'           => 'monthly',
            'currency'           => 'pkr',
            'current_period_end' => now()->subDay(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $periodEnd = now()->addMonth()->startOfMinute();

        $charge = $this->charge([
            'client_id'       => $clientId,
            'subscription_id' => $subscription->id,
            'period_start'    => now()->startOfMinute(),
            'period_end'      => $periodEnd,
        ]);

        $this->deliver(['tracker' => 'trk_ext', 'order_id' => $charge->reference])->assertOk();

        $fresh = $subscription->fresh();

        $this->assertSame('active', $fresh->status);
        $this->assertSame(
            $periodEnd->toDateTimeString(),
            $fresh->current_period_end->toDateTimeString(),
            'The period must extend to what the charge was raised for, not to an arbitrary now+1 month',
        );
    }
}
