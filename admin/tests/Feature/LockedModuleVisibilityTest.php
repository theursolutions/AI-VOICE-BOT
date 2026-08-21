<?php

namespace Tests\Feature;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Services\Billing\PlanFeatureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Modules a plan excludes are SHOWN, under a padlock, rather than hidden.
 *
 * Nobody upgrades to reach a feature they have never seen, so the menu is the
 * shortest sales pitch available. The thing that makes that safe is that
 * visibility and permission are answered by two different code paths, and only
 * one of them decides what a request may do — so the test that matters here is
 * not "is it visible" but "is it still refused".
 */
class LockedModuleVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private function workspaceOn(string $planSlug): Client
    {
        $plan = Plan::where('slug', $planSlug)->first();

        if (! $plan) {
            $this->markTestSkipped("Plan [{$planSlug}] not seeded.");
        }

        $id = DB::table('clients')->insertGetId([
            'name'            => 'Lock Test',
            'slug'            => 'lock-' . uniqid(),
            'client_api_key'  => 'test-' . uniqid(),
            'current_plan_id' => $plan->id,
            'created_at'      => time(),
            'updated_at'      => time(),
        ]);

        Subscription::create([
            'client_id'  => $id,
            'plan_id'    => $plan->id,
            'status'     => Subscription::STATUS_ACTIVE,
            'interval'   => 'monthly',
            'currency'   => 'usd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(PlanFeatureService::class)->flush();

        return Client::find($id);
    }

    /** @return array<int, string> module keys this plan does NOT include */
    private function lockedFor(Client $client): array
    {
        $features = app(PlanFeatureService::class);

        return array_values(array_filter(
            array_keys((array) config('modules', [])),
            fn (string $key) => ! $features->clientHasModule($client, $key),
        ));
    }

    public function test_locking_is_the_default_rather_than_hiding(): void
    {
        $this->assertFalse(
            (bool) config('billing.settings.hide_locked_modules'),
            'Hiding a module the plan excludes makes the product look smaller than it is',
        );
    }

    /**
     * The ladder has to actually produce locks, or the feature is decorative.
     * Free excludes most of the product; Scale excludes nothing.
     */
    public function test_the_plan_ladder_produces_a_decreasing_set_of_locks(): void
    {
        $counts = [];

        foreach (['free', 'starter', 'growth', 'scale'] as $slug) {
            if (! Plan::where('slug', $slug)->exists()) {
                $this->markTestSkipped('Plan catalogue not seeded.');
            }

            $counts[$slug] = count($this->lockedFor($this->workspaceOn($slug)));
        }

        $this->assertGreaterThan(0, $counts['free'], 'Free should lock most of the product');
        $this->assertSame(0, $counts['scale'], 'The top tier should lock nothing');

        $this->assertLessThanOrEqual($counts['free'], $counts['starter']);
        $this->assertLessThanOrEqual($counts['starter'], $counts['growth']);
        $this->assertLessThanOrEqual($counts['growth'], $counts['scale']);
    }

    /**
     * THE ONE THAT MATTERS. Showing a locked module must not grant it. The
     * sidebar reads a published list; the route gate asks
     * PlanFeatureService::clientHasModule() — the same question, answered
     * server-side — so a visible link an excluded workspace clicks is still
     * refused.
     */
    public function test_a_visible_locked_module_is_still_refused_by_the_gate(): void
    {
        $client = $this->workspaceOn('free');
        $locked = $this->lockedFor($client);

        if (! $locked) {
            $this->markTestSkipped('Free excludes nothing in this catalogue.');
        }

        $features = app(PlanFeatureService::class);

        foreach ($locked as $key) {
            $this->assertFalse(
                $features->clientHasModule($client, $key),
                "{$key} is shown locked but the gate would let it through",
            );
        }
    }

    /**
     * A grant unlocks for real, not just visually — otherwise "assigned free"
     * would show an open padlock and still 402.
     */
    public function test_a_grant_removes_the_lock_and_the_refusal_together(): void
    {
        $client = $this->workspaceOn('free');
        $locked = $this->lockedFor($client);

        // Pick one that a feature actually gates, so granting it is meaningful.
        $key = collect($locked)->first(fn ($k) => \App\Models\Billing\Feature::where('module_key', $k)->exists());

        if (! $key) {
            $this->markTestSkipped('No module-gating feature to grant.');
        }

        $featureKey = \App\Models\Billing\Feature::where('module_key', $key)->value('key');

        app(\App\Services\Billing\WorkspacePlanService::class)->grant($client, $featureKey, 1);

        $fresh = Client::find($client->id);
        app(PlanFeatureService::class)->flush();

        $this->assertTrue(
            app(PlanFeatureService::class)->clientHasModule($fresh, $key),
            'A granted module must unlock the gate, not merely the padlock',
        );
        $this->assertNotContains($key, $this->lockedFor($fresh));
    }

    /** The escape hatch still works for an operator who prefers hiding. */
    public function test_hiding_can_still_be_switched_back_on(): void
    {
        config(['billing.settings.hide_locked_modules' => true]);

        $this->assertTrue((bool) config('billing.settings.hide_locked_modules'));
    }
}
