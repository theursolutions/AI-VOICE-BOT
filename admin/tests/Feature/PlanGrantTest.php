<?php

namespace Tests\Feature;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanGrant;
use App\Models\Billing\Subscription;
use App\Models\Client;
use App\Services\Billing\PlanFeatureService;
use App\Services\Billing\WorkspacePlanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Super-admin grants and free plan assignment.
 *
 * A grant is a third term in PlanFeatureService::clientLimit(), alongside the
 * plan and purchased add-ons — so what these tests really assert is that the
 * number an operator types is the number the product enforces. Anything else
 * means two answers to "what is this workspace allowed", and the one enforced
 * would not be the one granted.
 */
class PlanGrantTest extends TestCase
{
    use DatabaseTransactions;

    private function features(): PlanFeatureService
    {
        $f = app(PlanFeatureService::class);
        $f->flush();

        return $f;
    }

    private function workspace(string $planSlug = 'growth'): Client
    {
        $id = DB::table('clients')->insertGetId([
            'name'           => 'Grant Test Co',
            'slug'           => 'grant-' . uniqid(),
            'client_api_key' => 'test-' . uniqid(),
            'created_at'     => time(),
            'updated_at'     => time(),
        ]);

        $plan = Plan::where('slug', $planSlug)->first();

        if (! $plan) {
            $this->markTestSkipped("Plan [{$planSlug}] not seeded.");
        }

        Subscription::create([
            'client_id'  => $id,
            'plan_id'    => $plan->id,
            'status'     => Subscription::STATUS_ACTIVE,
            'interval'   => 'monthly',
            'currency'   => 'usd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Client::find($id);
    }

    // ── Grants ──────────────────────────────────────────────────────────

    public function test_a_grant_raises_the_enforced_limit(): void
    {
        $client = $this->workspace();
        $before = $this->features()->clientLimit($client, 'messages');

        app(WorkspacePlanService::class)->grant($client, 'messages', 25_000);

        $this->assertSame(
            $before + 25_000,
            $this->features()->clientLimit(Client::find($client->id), 'messages'),
            'A granted allowance must be what the product enforces, not just what the page shows',
        );
    }

    public function test_a_grant_of_minus_one_removes_the_ceiling(): void
    {
        $client = $this->workspace();

        app(WorkspacePlanService::class)->grant($client, 'messages', PlanGrant::UNLIMITED);

        $this->assertNull(
            $this->features()->clientLimit(Client::find($client->id), 'messages'),
            'Null is how the whole codebase spells unlimited; a sentinel that resolved to a big '
            . 'number would eventually be exhausted',
        );
    }

    /**
     * An expired grant must stop applying without anyone doing anything. It is
     * kept in the table — the record of what was given is the reason this is a
     * table — so every read has to exclude it.
     */
    public function test_an_expired_grant_stops_applying(): void
    {
        $client = $this->workspace();
        $base   = $this->features()->clientLimit($client, 'messages');

        app(WorkspacePlanService::class)->grant(
            $client, 'messages', 50_000, null, 'lapsed', new \DateTimeImmutable('-1 hour')
        );

        $this->assertSame(
            $base,
            $this->features()->clientLimit(Client::find($client->id), 'messages'),
            'A lapsed grant went on applying',
        );

        $this->assertDatabaseHas('plan_grants', [
            'client_id'   => $client->id,
            'feature_key' => 'messages',
        ]);
    }

    public function test_a_grant_of_zero_revokes_rather_than_storing_nothing(): void
    {
        $client = $this->workspace();
        $svc    = app(WorkspacePlanService::class);

        $svc->grant($client, 'seats', 5);
        $this->assertDatabaseHas('plan_grants', ['client_id' => $client->id, 'feature_key' => 'seats']);

        $svc->grant($client, 'seats', 0);
        $this->assertDatabaseMissing('plan_grants', ['client_id' => $client->id, 'feature_key' => 'seats']);
    }

    /** Re-granting edits, so the total can never depend on rows nobody sees. */
    public function test_granting_twice_replaces_rather_than_accumulates(): void
    {
        $client = $this->workspace();
        $svc    = app(WorkspacePlanService::class);
        $base   = $this->features()->clientLimit($client, 'seats');

        $svc->grant($client, 'seats', 5);
        $svc->grant($client, 'seats', 3);

        $this->assertSame(1, PlanGrant::where('client_id', $client->id)->where('feature_key', 'seats')->count());
        $this->assertSame($base + 3, $this->features()->clientLimit(Client::find($client->id), 'seats'));
    }

    /**
     * A grant on an on/off feature must unlock its MODULE too, or the
     * entitlement shows in the usage panel and still 402s at the door.
     */
    public function test_a_boolean_grant_unlocks_the_feature_and_its_module(): void
    {
        $client = $this->workspace('starter');
        $f      = $this->features();

        // A feature Starter does not include, that gates a module.
        $gated = \App\Models\Billing\Feature::whereNotNull('module_key')
            ->get()
            ->first(fn ($feature) => ! $f->clientHas($client, $feature->key));

        if (! $gated) {
            $this->markTestSkipped('Starter already includes every module-gating feature.');
        }

        $this->assertFalse($f->clientHasModule($client, $gated->module_key));

        app(WorkspacePlanService::class)->grant($client, $gated->key, 1);

        $fresh = Client::find($client->id);
        $this->assertTrue($this->features()->clientHas($fresh, $gated->key));
        $this->assertTrue(
            $this->features()->clientHasModule($fresh, $gated->module_key),
            "Granting {$gated->key} did not open the {$gated->module_key} module",
        );
    }

    /** Negative grants are allowed, but an allowance must never go below none. */
    public function test_a_negative_grant_cannot_push_an_allowance_below_zero(): void
    {
        $client = $this->workspace();

        app(WorkspacePlanService::class)->grant($client, 'seats', -1_000);

        $this->assertSame(
            0,
            $this->features()->clientLimit(Client::find($client->id), 'seats'),
            'A negative allowance would compare as smaller than any usage and read as permanently '
            . 'exhausted rather than as zero',
        );
    }

    // ── Free assignment ─────────────────────────────────────────────────

    public function test_assigning_free_grants_access_without_touching_stripe(): void
    {
        $client = $this->workspace('starter');
        $scale  = Plan::where('slug', 'scale')->first();

        if (! $scale) {
            $this->markTestSkipped('Scale not seeded.');
        }

        $sub = app(WorkspacePlanService::class)->assignFree($client, $scale, null, 'pilot');

        $this->assertSame($scale->id, $sub->plan_id);
        $this->assertTrue($sub->grantsAccess(), 'An assigned plan must actually grant access');
        $this->assertNull($sub->stripe_subscription_ref, 'Nothing may be sent to Stripe');
        $this->assertSame(0, (int) $sub->unit_amount, 'A free assignment reporting list price would overstate MRR');
        $this->assertTrue((bool) data_get($sub->metadata, 'assigned_by_super_admin'));

        // And the entitlement actually moves.
        $this->assertSame(
            $this->features()->planLimit($scale, 'messages'),
            $this->features()->clientLimit(Client::find($client->id), 'messages'),
        );
    }

    /**
     * Refusing to assign over a live Stripe subscription. Overwriting it would
     * leave Stripe billing a plan the product no longer thinks they are on —
     * the customer keeps paying for something invisible.
     */
    public function test_it_refuses_to_assign_over_a_live_stripe_subscription(): void
    {
        $client = $this->workspace('starter');
        $scale  = Plan::where('slug', 'scale')->first();

        if (! $scale) {
            $this->markTestSkipped('Scale not seeded.');
        }

        $client->currentSubscription()->forceFill([
            'stripe_subscription_ref' => 'sub_live_test',
            'status'                  => Subscription::STATUS_ACTIVE,
        ])->save();

        $this->expectException(\RuntimeException::class);

        app(WorkspacePlanService::class)->assignFree(Client::find($client->id), $scale);
    }
}
