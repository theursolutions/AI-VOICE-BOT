<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\PlanFeatureService;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\UsageLimitService;

/**
 * Feature entitlements and usage quotas.
 *
 * The two bugs these exist to prevent:
 *   1. Treating "unlimited" (null) as "zero" — `if (!$limit) block()`.
 *   2. Treating a missing plan_features row as "granted".
 */
class PlanLimitsTest extends BillingTestCase
{
    private function usage(): UsageLimitService
    {
        return app(UsageLimitService::class);
    }

    private function features(): PlanFeatureService
    {
        return app(PlanFeatureService::class);
    }

    // ── Entitlements ─────────────────────────────────────────────────

    public function test_a_missing_feature_row_means_not_granted(): void
    {
        $free = $this->plan('free');

        // Nothing was ever written for these on the free plan.
        $this->assertFalse($this->features()->planHas($free, 'api_access'));
        $this->assertFalse($this->features()->planHas($free, 'database_connector'));
        $this->assertSame(0, $this->features()->planLimit($free, 'phone_numbers'));
    }

    public function test_unlimited_is_null_and_is_not_confused_with_zero(): void
    {
        $growth = $this->plan('growth');

        // -1 in the DB → null from the service. A caller writing
        // `if (!$limit) deny()` would break every unlimited plan.
        $this->assertNull($this->features()->planLimit($growth, 'history_days'));
        $this->assertNull($this->features()->planLimit($growth, 'data_sources'));

        // Zero means "none", which is a completely different answer.
        $this->assertSame(0, $this->features()->planLimit($this->plan('free'), 'telephony_minutes'));
    }

    public function test_the_eight_gates_land_on_the_approved_plans(): void
    {
        $expected = [
            //                        free   starter  growth  scale
            'telephony'          => [false, true,  true,  true],
            'shared_inbox'       => [false, true,  true,  true],
            'team_roles'         => [false, false, true,  true],
            'api_access'         => [false, false, true,  true],
            'database_connector' => [false, false, true,  true],
            'remove_branding'    => [false, false, true,  true],
            'white_label'        => [false, false, false, true],
            'byo_llm'            => [false, false, false, true],
        ];

        foreach ($expected as $feature => $rows) {
            foreach (['free', 'starter', 'growth', 'scale'] as $i => $slug) {
                $this->assertSame(
                    $rows[$i],
                    $this->features()->planHas($this->plan($slug), $feature),
                    "{$feature} on {$slug}"
                );
            }
        }
    }

    public function test_core_features_are_on_every_plan_including_free(): void
    {
        // Gating any of these makes the product feel like a demo and destroys
        // the reason someone picks us over a cheaper point tool.
        foreach (['voice_cloning', 'multi_language', 'lead_capture', 'web_widget', 'knowledge_base'] as $feature) {
            foreach (['starter', 'growth', 'scale'] as $slug) {
                $this->assertTrue(
                    $this->features()->planHas($this->plan($slug), $feature),
                    "{$feature} must be included on {$slug}"
                );
            }
        }

        // Free gets everything except cloning (stock voices only) — but it
        // definitely keeps multi-language, which a competitor charges $99/mo for.
        $free = $this->plan('free');
        $this->assertTrue($this->features()->planHas($free, 'multi_language'));
        $this->assertTrue($this->features()->planHas($free, 'lead_capture'));
        $this->assertTrue($this->features()->planHas($free, 'web_widget'));
    }

    public function test_module_gate_blocks_a_feature_the_plan_lacks_and_upsells(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        // Telephony is off on Free. 402 (not 403): this isn't an error, it's a
        // sales moment — the page names the feature and the cheapest plan that
        // includes it. The heading uses the MODULE label from
        // config/modules.php, which is how the gate and the roles matrix stay
        // in one vocabulary.
        $this->actingAs($user)
             ->get(route('telephony.index', ['client' => $client->slug]))
             ->assertStatus(402)
             ->assertSee('Telephony', false)
             ->assertSee('Starter', false);   // the cheapest plan that unlocks it
    }

    public function test_an_owner_does_not_bypass_the_plan_gate(): void
    {
        // Owners bypass RBAC — but being the owner says nothing about what the
        // workspace has paid for.
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->assertTrue($user->isOwnerOf($client->id));

        $this->actingAs($user)
             ->get(route('telephony.index', ['client' => $client->slug]))
             ->assertStatus(402);
    }

    public function test_a_module_no_feature_maps_to_stays_open(): void
    {
        [$client, $user] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        // No feature declares module_key='profile', so it must not be gated —
        // otherwise adding a new admin module would hide it from every paying
        // customer until someone remembered to create a feature row.
        $this->assertTrue($this->features()->clientHasModule($client, 'profile'));
        $this->assertTrue($this->features()->clientHasModule($client, 'dashboard'));

        // project-profile only reads the master `projects` table; the dashboard
        // queries the (unprovisioned) tenant DB and would 500 for reasons that
        // have nothing to do with entitlements.
        $this->actingAs($user)
             ->get(route('project-profile.index', ['client' => $client->slug]))
             ->assertOk();
    }

    // ── Usage quotas ─────────────────────────────────────────────────

    public function test_usage_is_recorded_atomically_and_accumulates(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->usage()->record($client, 'conversations', 3);
        $this->usage()->record($client, 'conversations', 2);

        $this->assertSame(5, $this->usage()->usedFor($client, 'conversations'));
        $this->assertSame(95, $this->usage()->remainingFor($client, 'conversations'));
    }

    public function test_the_free_plan_hard_stops_at_its_allowance(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->usage()->record($client, 'conversations', 100);   // the whole free allowance

        // No paid subscription means nobody to bill overage to, so it stops.
        $this->assertFalse($this->usage()->allows($client, 'conversations'));
    }

    public function test_a_zero_allowance_metric_is_always_refused(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        // Free has no telephony minutes at all.
        $this->assertSame(0, $this->usage()->allowanceFor($client, 'telephony_minutes'));
        $this->assertFalse($this->usage()->allows($client, 'telephony_minutes'));

        // ...but widget voice messages are allowed, because they're cheap.
        $this->assertTrue($this->usage()->allows($client, 'voice_messages'));
    }

    public function test_a_paid_plan_keeps_working_past_its_allowance_and_records_overage(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook($this->subscriptionEvent('customer.subscription.created', $client))->assertOk();
        $client->forgetSubscription();

        $allowance = $this->usage()->allowanceFor($client, 'telephony_minutes');   // 300 on Growth
        $this->assertSame(300, $allowance);

        // A 5-minute call with 2 minutes of allowance left must split 2/3, not
        // record all 5 as overage or none of it.
        $this->usage()->record($client, 'telephony_minutes', 298);
        $counter = $this->usage()->record($client, 'telephony_minutes', 5);

        $this->assertSame(303, $counter->used);
        $this->assertSame(3, $counter->overage, 'Only the portion above the allowance is overage.');

        // An AI receptionist that stops answering mid-month is worse for the
        // customer than a slightly larger invoice.
        $this->assertTrue($this->usage()->allows($client, 'telephony_minutes'));
    }

    public function test_unlimited_metrics_never_block(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);
        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.created', $client, 'active', 'scale')
        )->assertOk();
        $client->forgetSubscription();

        $this->assertNull($this->usage()->allowanceFor($client, 'voice_messages'));
        $this->assertTrue($this->usage()->allows($client, 'voice_messages', 1_000_000));
        $this->assertNull($this->usage()->remainingFor($client, 'voice_messages'));
    }

    public function test_the_usage_summary_hides_metrics_the_plan_excludes(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $summary = $this->usage()->summaryFor($client);

        // Showing "0 / 0 phone minutes" on the free plan is noise, not information.
        $this->assertArrayNotHasKey('telephony_minutes', $summary);
        $this->assertArrayHasKey('conversations', $summary);
        $this->assertSame(100, $summary['conversations']['allowance']);
    }

    public function test_editing_a_limit_takes_effect_immediately(): void
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->assertSame(100, $this->usage()->allowanceFor($client, 'conversations'));

        // A super-admin raises the free allowance. The entitlement cache must
        // be invalidated, not wait out a TTL.
        $free    = $this->plan('free');
        $feature = \App\Models\Billing\Feature::where('key', 'conversations')->firstOrFail();
        $this->features()->setFeature($free, $feature, '250');

        $client->forgetSubscription();

        $this->assertSame(250, $this->usage()->allowanceFor($client, 'conversations'));
    }
}
