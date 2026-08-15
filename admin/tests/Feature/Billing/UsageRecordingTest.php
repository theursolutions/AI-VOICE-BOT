<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\SubscriptionService;
use App\Services\Billing\UsageLimitService;
use App\Services\Billing\UsageRecorder;

/**
 * The metering itself: does usage actually get counted?
 *
 * Everything else in the billing suite tested the ENGINE (allowances, overage
 * splitting, period resets). None of it proved the engine was ever called —
 * `record()` had zero call sites, so every allowance was advisory and a
 * customer could never exceed a limit.
 *
 * These tests drive UsageRecorder directly rather than through a full
 * conversation: the AI reply paths need the tenant database and a live model
 * host, neither of which this suite provisions. The call sites themselves are
 * one line each; the logic worth pinning is in the recorder.
 */
class UsageRecordingTest extends BillingTestCase
{
    private function recorder(): UsageRecorder
    {
        return app(UsageRecorder::class);
    }

    private function usage(): UsageLimitService
    {
        return app(UsageLimitService::class);
    }

    /** @return array{0: \App\Models\Client, 1: \App\Models\Project} */
    private function paidWorkspace(string $plan = 'growth'): array
    {
        [$client] = $this->makeWorkspace();
        app(SubscriptionService::class)->startFreeWindow($client);

        $this->postWebhook(
            $this->subscriptionEvent('customer.subscription.created', $client, 'active', $plan)
        )->assertOk();

        $client->forgetSubscription();

        return [$client->fresh(), \App\Models\Project::where('client_id', $client->id)->firstOrFail()];
    }

    // ── Telephony ────────────────────────────────────────────────────

    public function test_a_call_is_billed_in_whole_minutes_rounded_up(): void
    {
        [$client, $project] = $this->paidWorkspace();

        // Carriers bill part-minutes as whole minutes, so the allowance has to
        // count the same way or it can never cover its own cost.
        $this->recorder()->callCompleted($project->id, 5);      // 5s  → 1 min
        $this->assertSame(1, $this->usage()->usedFor($client, 'telephony_minutes'));

        $this->recorder()->callCompleted($project->id, 61);     // 61s → 2 min
        $this->assertSame(3, $this->usage()->usedFor($client, 'telephony_minutes'));

        $this->recorder()->callCompleted($project->id, 600);    // 10m → 10
        $this->assertSame(13, $this->usage()->usedFor($client, 'telephony_minutes'));
    }

    public function test_a_zero_length_call_is_not_billed(): void
    {
        [$client, $project] = $this->paidWorkspace();

        // A call that never connected. Twilio still fires the status webhook.
        $this->recorder()->callCompleted($project->id, 0);

        $this->assertSame(0, $this->usage()->usedFor($client, 'telephony_minutes'));
    }

    public function test_call_minutes_count_towards_the_plan_allowance(): void
    {
        [$client, $project] = $this->paidWorkspace();   // Growth = 300 minutes

        $this->assertSame(300, $this->usage()->allowanceFor($client, 'telephony_minutes'));

        $this->recorder()->callCompleted($project->id, 60 * 290);

        $this->assertSame(290, $this->usage()->usedFor($client, 'telephony_minutes'));
        $this->assertSame(10, $this->usage()->remainingFor($client, 'telephony_minutes'));
        $this->assertTrue($this->usage()->allows($client, 'telephony_minutes', 5));
    }

    public function test_going_over_the_minute_allowance_records_overage_and_keeps_working(): void
    {
        [$client, $project] = $this->paidWorkspace();

        $this->recorder()->callCompleted($project->id, 60 * 305);   // 305 of 300

        $summary = $this->usage()->summaryFor($client)['telephony_minutes'];

        $this->assertSame(305, $summary['used']);
        $this->assertSame(5, $summary['overage']);

        // A paid plan keeps answering; the excess bills as overage.
        $this->assertTrue($this->usage()->allows($client, 'telephony_minutes'));
    }

    // ── Indexed pages & storage ──────────────────────────────────────

    public function test_indexed_pages_and_storage_accumulate(): void
    {
        [$client, $project] = $this->paidWorkspace();

        $this->recorder()->pagesIndexed($project->id, 120);
        $this->recorder()->pagesIndexed($project->id, 80);
        $this->recorder()->storageUsed($project->id, 15);

        $this->assertSame(200, $this->usage()->usedFor($client, 'indexed_pages'));
        $this->assertSame(15, $this->usage()->usedFor($client, 'storage_mb'));
    }

    public function test_nothing_is_recorded_for_a_zero_or_negative_amount(): void
    {
        [$client, $project] = $this->paidWorkspace();

        $this->recorder()->pagesIndexed($project->id, 0);
        $this->recorder()->pagesIndexed($project->id, -5);
        $this->recorder()->storageUsed($project->id, 0);

        $this->assertSame(0, $this->usage()->usedFor($client, 'indexed_pages'));
        $this->assertSame(0, $this->usage()->usedFor($client, 'storage_mb'));
    }

    // ── Robustness: metering must never break the product ────────────

    public function test_an_unknown_project_is_ignored_rather_than_thrown(): void
    {
        // A deleted project, or a webhook arriving for another install.
        $this->recorder()->callCompleted(999999, 120);
        $this->recorder()->pagesIndexed(999999, 10);

        $this->assertTrue(true, 'Recording against a missing project must not throw.');
    }

    public function test_a_broken_metering_layer_does_not_escape_into_the_caller(): void
    {
        // THE most important property here. If the billing tables are briefly
        // unreachable, a customer's AI agent must still reply — an
        // under-counted invoice can be reconciled, a 500 mid-conversation
        // cannot be taken back.
        $this->app->bind(UsageLimitService::class, function () {
            return new class extends UsageLimitService {
                public function __construct()
                {
                }

                public function record($client, string $metric, int $amount = 1, ?int $projectId = null): \App\Models\Billing\UsageCounter
                {
                    throw new \RuntimeException('billing tables are on fire');
                }
            };
        });

        [, $project] = $this->paidWorkspace();

        $recorder = new UsageRecorder(app(UsageLimitService::class));

        $recorder->callCompleted($project->id, 120);
        $recorder->pagesIndexed($project->id, 10);
        $recorder->storageUsed($project->id, 10);

        $this->assertTrue(true, 'A failing meter must never surface to the caller.');
    }

    // ── Absolute metrics: measured, not counted ──────────────────────

    public function test_setting_an_absolute_metric_is_idempotent(): void
    {
        [$client, $project] = $this->paidWorkspace();

        // The property that makes reconciliation safe to re-run. record()
        // would ADD each time, so measuring 40 MB twice would report 80.
        $this->usage()->setAbsolute($client, 'storage_mb', 40, $project->id);
        $this->usage()->setAbsolute($client, 'storage_mb', 40, $project->id);
        $this->usage()->setAbsolute($client, 'storage_mb', 40, $project->id);

        $this->assertSame(40, $this->usage()->usedFor($client, 'storage_mb'));
    }

    public function test_an_absolute_metric_can_go_down_when_files_are_deleted(): void
    {
        [$client, $project] = $this->paidWorkspace();

        $this->usage()->setAbsolute($client, 'storage_mb', 500, $project->id);
        $this->assertSame(500, $this->usage()->usedFor($client, 'storage_mb'));

        // A counter that can only ever increase would bill deleted files
        // forever.
        $this->usage()->setAbsolute($client, 'storage_mb', 120, $project->id);
        $this->assertSame(120, $this->usage()->usedFor($client, 'storage_mb'));
    }

    public function test_an_absolute_metric_records_overage_against_the_allowance(): void
    {
        [$client, $project] = $this->paidWorkspace();   // Growth = 5,000 pages

        $this->usage()->setAbsolute($client, 'indexed_pages', 5_250, $project->id);

        $summary = $this->usage()->summaryFor($client)['indexed_pages'];

        $this->assertSame(5250, $summary['used']);
        $this->assertSame(250, $summary['overage']);
    }

    public function test_reconcile_measures_storage_from_disk(): void
    {
        [$client, $project] = $this->paidWorkspace();

        $dir = storage_path("app/data_sources/project_{$project->id}");
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/a.pdf', str_repeat('x', 3 * 1048576));   // 3 MB
        file_put_contents($dir . '/b.pdf', str_repeat('x', 2 * 1048576));   // 2 MB

        try {
            $this->artisan('billing:reconcile-usage', ['--client' => $client->id])
                 ->assertSuccessful();

            $this->assertSame(5, $this->usage()->usedFor($client, 'storage_mb'));
        } finally {
            @unlink($dir . '/a.pdf');
            @unlink($dir . '/b.pdf');
            @rmdir($dir);
        }
    }

    public function test_reconcile_does_not_wipe_page_counts_when_the_engine_is_down(): void
    {
        // The one way a reconciler can be worse than none: an unreachable
        // engine looking like a customer who has indexed nothing.
        [$client, $project] = $this->paidWorkspace();

        $this->usage()->setAbsolute($client, 'indexed_pages', 4_000, $project->id);

        \App\Models\DataSource::create([
            'project_id' => $project->id,
            'type'       => \App\Models\DataSource::TYPE_WEBSITE,
            'name'       => 'Marketing site',
            'status'     => \App\Models\DataSource::STATUS_ACTIVE,
            'config'     => ['url' => 'https://example.com'],
            'created_at' => time(),
        ]);

        // No voice-engine running in tests, so every duckQuery throws.
        $this->artisan('billing:reconcile-usage', ['--client' => $client->id])
             ->assertSuccessful();

        $this->assertSame(
            4000,
            $this->usage()->usedFor($client, 'indexed_pages'),
            'A dead engine must not be mistaken for zero indexed pages.'
        );
    }

    public function test_reconcile_is_a_no_op_in_dry_run(): void
    {
        [$client, $project] = $this->paidWorkspace();

        $dir = storage_path("app/data_sources/project_{$project->id}");
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/a.pdf', str_repeat('x', 1048576));

        try {
            $this->artisan('billing:reconcile-usage', ['--client' => $client->id, '--dry-run' => true])
                 ->assertSuccessful();

            $this->assertSame(0, $this->usage()->usedFor($client, 'storage_mb'));
        } finally {
            @unlink($dir . '/a.pdf');
            @rmdir($dir);
        }
    }

    // ── Structural quotas (hard stops, not overage) ──────────────────

    public function test_the_seat_limit_blocks_an_extra_member(): void
    {
        // Growth, not Starter: the Team & Roles module itself is Growth+, so a
        // Starter workspace is refused at the module gate long before the seat
        // quota is ever consulted.
        [$client] = $this->paidWorkspace('growth');

        // Squeeze the allowance rather than creating ten users — the quota
        // logic is what's under test, not the ability to loop.
        app(\App\Services\Billing\PlanFeatureService::class)->setFeature(
            $this->plan('growth'),
            \App\Models\Billing\Feature::where('key', 'seats')->firstOrFail(),
            '2'
        );

        $role = \App\Models\Role::where('client_id', $client->id)->value('id');

        // Owner holds seat 1; add seat 2 directly to fill the plan.
        $second = \App\Models\User::create([
            'name' => 'Member 2', 'email' => 'm2@acme.test',
            'password' => bcrypt('x'), 'email_verified_at' => now(),
        ]);
        $second->attachMembership($client->id, null, $second->id, $role);

        $this->assertSame(2, $client->users()->count());

        $owner = $client->billingOwner();

        // A structural limit is a HARD stop — an eleventh seat on a ten-seat
        // plan isn't overage, it simply isn't in the plan.
        $this->actingAs($owner)
             ->post(route('members.store', ['client' => $client->slug]), [
                 'email'   => 'third@acme.test',
                 'role_id' => $role,
             ])
             ->assertRedirect()
             ->assertSessionHas('error');

        $this->assertSame(2, $client->fresh()->users()->count());
    }

    public function test_an_unlimited_quota_never_blocks(): void
    {
        // Scale grants unlimited agents. `null` must not be mistaken for zero —
        // `if (!$limit) deny()` would lock out exactly the top-paying plans.
        [$client] = $this->paidWorkspace('scale');

        $features = app(\App\Services\Billing\PlanFeatureService::class);

        $this->assertNull($features->clientLimit($client, 'agents'));
        $this->assertTrue($this->usage()->allows($client, 'voice_messages', 1_000_000));
    }

    // ── The meters the plans actually promise ────────────────────────

    public function test_every_metered_allowance_is_now_recordable(): void
    {
        [$client, $project] = $this->paidWorkspace();

        // Each metric named in config/billing.php must have a path that
        // actually increments it — the gap this whole file exists to close.
        $this->recorder()->callCompleted($project->id, 60);
        $this->recorder()->pagesIndexed($project->id, 1);
        $this->recorder()->storageUsed($project->id, 1);
        $this->usage()->record($client, 'conversations', 1, $project->id);
        $this->usage()->record($client, 'voice_messages', 1, $project->id);

        foreach (['conversations', 'telephony_minutes', 'voice_messages', 'indexed_pages', 'storage_mb'] as $metric) {
            $this->assertGreaterThan(
                0,
                $this->usage()->usedFor($client, $metric),
                "{$metric} was not recorded"
            );
        }
    }
}
