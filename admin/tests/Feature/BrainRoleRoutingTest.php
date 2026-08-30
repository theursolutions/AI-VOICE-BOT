<?php

namespace Tests\Feature;

use App\Models\AiBrain;
use App\Models\Project;
use App\Services\Conversation\BrainResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Per-call-type brain routing.
 *
 * One customer message costs four LLM calls and only the reply is read by a
 * person, so the other three can run on a cheaper model. These tests pin the two
 * halves of that: the split works on OUR pool, and it never happens on a
 * client's own key.
 *
 * The second half is the one that matters most. Every call carries the customer's
 * words — tool selection sees the message, capture sees the transcript,
 * summarisation sees the whole conversation — so splitting a client's traffic to
 * save a fraction of a cent would send their customers' data to our vendor,
 * which is the exact thing bring-your-own-key exists to prevent.
 */
class BrainRoleRoutingTest extends TestCase
{
    use DatabaseTransactions;

    private const CALLS = [
        BrainResolver::CALL_REPLY,
        BrainResolver::CALL_ROUTE,
        BrainResolver::CALL_CAPTURE,
        BrainResolver::CALL_SUMMARY,
    ];

    private Project $testProject;

    protected function setUp(): void
    {
        parent::setUp();

        // Any pre-existing brain would decide these tests for us. Inside the
        // transaction, so nothing is actually lost.
        AiBrain::query()->delete();
        BrainResolver::forget();

        $this->testProject = $this->makeProject();
    }

    /**
     * A client and project of our own rather than whatever the database happens
     * to hold. Resolution only reads Project::find() and its client_id, so no
     * tenant database is provisioned and none is needed.
     */
    private function makeProject(): Project
    {
        $clientId = \Illuminate\Support\Facades\DB::table('clients')->insertGetId([
            'name'           => 'Brain Routing Test Co',
            'slug'           => 'brain-routing-test-' . uniqid(),
            'client_api_key' => 'test-' . uniqid(),
            'created_at'     => time(),
            'updated_at'     => time(),
        ]);

        return Project::create([
            'client_id'  => $clientId,
            'name'       => 'Routing test project',
            'slug'       => 'routing-test-' . uniqid(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function project(): Project
    {
        return $this->testProject;
    }

    private function brain(?int $clientId, string $serves, int $priority, string $model): AiBrain
    {
        return AiBrain::create([
            'client_id'    => $clientId,
            'name'         => "brain-{$model}",
            'preset'       => 'groq',
            'kind'         => AiBrain::KIND_OPENAI_COMPAT,
            'base_url'     => 'https://api.groq.com/openai/v1',
            'model'        => $model,
            'api_key'      => 'test-key',
            'max_tokens'   => 4096,
            'priority'     => $priority,
            'serves'       => $serves,
            'is_active'    => true,
            'is_verified'  => true,
            'quota_window' => 'total',
            'created_at'   => time(),
            'updated_at'   => time(),
        ]);
    }

    /** @return array<string, string> call type => model that served it */
    private function routing(int $projectId): array
    {
        $resolver = app(BrainResolver::class);
        $out      = [];

        foreach (self::CALLS as $call) {
            BrainResolver::forget($projectId);
            $out[$call] = $resolver->optionsFor($projectId, $call)['model'] ?? null;
        }

        return $out;
    }

    // ── The platform pool ───────────────────────────────────────────────

    public function test_a_lone_catch_all_serves_every_call(): void
    {
        $project = $this->project();
        $this->brain(null, AiBrain::SERVES_ANY, 10, 'catchall');

        $this->assertSame(
            array_fill_keys(self::CALLS, 'catchall'),
            $this->routing($project->id),
            'An install that never touches `serves` must behave exactly as it did before the column existed',
        );
    }

    /**
     * The whole feature hinges on this. If priority won, marking a brain
     * "background tasks only" would do nothing until its priority was also
     * reordered above every catch-all — so the setting would look broken.
     */
    public function test_a_specialist_beats_a_catch_all_on_worse_priority(): void
    {
        $project = $this->project();
        $this->brain(null, AiBrain::SERVES_ANY, 10, 'catchall');
        $this->brain(null, AiBrain::SERVES_MACHINERY, 90, 'cheap');

        $routing = $this->routing($project->id);

        $this->assertSame('catchall', $routing[BrainResolver::CALL_REPLY]);
        $this->assertSame('cheap', $routing[BrainResolver::CALL_ROUTE]);
        $this->assertSame('cheap', $routing[BrainResolver::CALL_CAPTURE]);
        $this->assertSame('cheap', $routing[BrainResolver::CALL_SUMMARY]);
    }

    public function test_a_reply_specialist_and_a_machinery_specialist_split_the_traffic(): void
    {
        $project = $this->project();
        $this->brain(null, AiBrain::SERVES_REPLY, 80, 'good');
        $this->brain(null, AiBrain::SERVES_MACHINERY, 90, 'cheap');

        $routing = $this->routing($project->id);

        $this->assertSame('good', $routing[BrainResolver::CALL_REPLY]);
        $this->assertSame('cheap', $routing[BrainResolver::CALL_ROUTE]);
        $this->assertSame('cheap', $routing[BrainResolver::CALL_CAPTURE]);
        $this->assertSame('cheap', $routing[BrainResolver::CALL_SUMMARY]);
    }

    /**
     * A role nobody claims must still land on a brain. Falling through to the
     * engine's own default instead would spend money no brain is charged for,
     * and ai_brain_usage silently missing a share of the bill is what makes
     * cost-derived pricing wrong.
     */
    public function test_an_unclaimed_role_falls_back_to_any_usable_brain(): void
    {
        $project = $this->project();
        $this->brain(null, AiBrain::SERVES_REPLY, 10, 'only-reply');

        $routing = $this->routing($project->id);

        foreach (self::CALLS as $call) {
            $this->assertSame('only-reply', $routing[$call], "{$call} escaped the pool");
        }
    }

    public function test_an_inactive_specialist_is_skipped(): void
    {
        $project = $this->project();
        $this->brain(null, AiBrain::SERVES_ANY, 10, 'catchall');
        $this->brain(null, AiBrain::SERVES_MACHINERY, 20, 'cheap')
            ->forceFill(['is_active' => false])->save();

        $this->assertSame(
            array_fill_keys(self::CALLS, 'catchall'),
            $this->routing($project->id),
        );
    }

    public function test_an_unverified_specialist_is_skipped(): void
    {
        $project = $this->project();
        $this->brain(null, AiBrain::SERVES_ANY, 10, 'catchall');
        $this->brain(null, AiBrain::SERVES_MACHINERY, 20, 'cheap')
            ->forceFill(['is_verified' => false])->save();

        $this->assertSame(
            array_fill_keys(self::CALLS, 'catchall'),
            $this->routing($project->id),
        );
    }

    // ── The client's own key ────────────────────────────────────────────

    /**
     * The security test. A client brain takes ALL FOUR calls even when the
     * platform pool offers a tempting cheaper specialist for three of them.
     */
    public function test_a_client_brain_is_never_split_across_providers(): void
    {
        $project  = $this->project();
        $clientId = (int) $project->client_id;

        $this->brain(null, AiBrain::SERVES_REPLY, 10, 'platform-good');
        $this->brain(null, AiBrain::SERVES_MACHINERY, 20, 'platform-cheap');
        $this->brain($clientId, AiBrain::SERVES_ANY, 10, 'client-own');

        $routing = $this->routing($project->id);

        foreach (self::CALLS as $call) {
            $this->assertSame(
                'client-own',
                $routing[$call],
                "{$call} leaked to the platform pool — the customer's words went to our vendor, not the client's",
            );
        }
    }

    /**
     * Even a client brain explicitly marked "replies only" keeps all four. The
     * role column narrows OUR pool; it is not a licence to route a client's
     * customer data through our account for the calls their brain declined.
     */
    public function test_a_client_brain_marked_reply_only_still_takes_machinery(): void
    {
        $project  = $this->project();
        $clientId = (int) $project->client_id;

        $this->brain(null, AiBrain::SERVES_MACHINERY, 10, 'platform-cheap');
        $this->brain($clientId, AiBrain::SERVES_REPLY, 10, 'client-own');

        $routing = $this->routing($project->id);

        foreach (self::CALLS as $call) {
            $this->assertSame('client-own', $routing[$call], "{$call} leaked to the platform pool");
        }
    }

    // ── Cache correctness ───────────────────────────────────────────────

    /**
     * Each role caches its own decision, so forgetting must clear all of them.
     * Clearing one leaves the other serving a brain that was just switched off,
     * for up to the TTL, on the half of the traffic nobody thinks to check.
     */
    public function test_forget_clears_every_role_variant(): void
    {
        $project = $this->project();
        $catchall = $this->brain(null, AiBrain::SERVES_ANY, 10, 'catchall');

        // Warm both role caches.
        $resolver = app(BrainResolver::class);
        foreach (self::CALLS as $call) {
            $resolver->optionsFor($project->id, $call);
        }

        $catchall->forceFill(['is_active' => false])->save();
        BrainResolver::forget($project->id);

        foreach (self::CALLS as $call) {
            $this->assertSame(
                [],
                $resolver->optionsFor($project->id, $call),
                "{$call} still resolved to a disabled brain from a stale cache entry",
            );
        }
    }

    public function test_no_brains_configured_leaves_the_engine_default_alone(): void
    {
        $project = $this->project();

        foreach (self::CALLS as $call) {
            $this->assertSame(
                [],
                app(BrainResolver::class)->optionsFor($project->id, $call),
                'An unconfigured install must send no overrides at all',
            );
        }
    }

    public function test_only_the_reply_call_maps_to_the_reply_role(): void
    {
        $this->assertSame(AiBrain::SERVES_REPLY, BrainResolver::roleForCall(BrainResolver::CALL_REPLY));

        foreach ([BrainResolver::CALL_ROUTE, BrainResolver::CALL_CAPTURE, BrainResolver::CALL_SUMMARY] as $call) {
            $this->assertSame(AiBrain::SERVES_MACHINERY, BrainResolver::roleForCall($call));
        }
    }

    /**
     * An unrecognised call type must resolve as a REPLY — the expensive, safe
     * assumption. A future call type silently demoted to the cheap model would
     * be a quality regression nobody would think to look for.
     */
    public function test_an_unknown_call_type_is_treated_as_a_reply(): void
    {
        $this->assertSame(AiBrain::SERVES_REPLY, BrainResolver::roleForCall('some_future_call'));
    }
}
