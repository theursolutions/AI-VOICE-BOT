<?php

namespace Tests\Feature;

use App\Models\Billing\Plan;
use App\Models\Client;
use App\Services\Billing\ConversationPricer;
use App\Services\Billing\CustomPlanService;
use App\Services\Billing\PlanFeatureService;
use App\Services\Billing\PlanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The custom plan: its pricing, and who is allowed to buy it.
 *
 * The pricing tests exist because a generated price is only as good as the
 * relationships it preserves — chiefly that a custom quote never undercuts the
 * published tier of the same shape, since the moment it does the catalogue
 * becomes unsellable and every customer configures their way around it.
 *
 * The access test exists because a custom plan is a price agreed with one
 * workspace, sitting in a shared catalogue behind a guessable slug.
 */
class CustomPlanTest extends TestCase
{
    use DatabaseTransactions;

    private function pricer(): ConversationPricer
    {
        ConversationPricer::forget();

        return app(ConversationPricer::class);
    }

    private function makeClient(string $name = 'Custom Plan Test'): Client
    {
        $id = DB::table('clients')->insertGetId([
            'name'           => $name,
            'slug'           => 'cpt-' . uniqid(),
            'client_api_key' => 'test-' . uniqid(),
            'created_at'     => time(),
            'updated_at'     => time(),
        ]);

        return Client::find($id);
    }

    // ── Pricing relationships ───────────────────────────────────────────

    /**
     * The one that protects the catalogue. At each published tier's exact
     * shape, the custom quote must be at least that tier's price — a customer
     * who wants Growth's shape should buy Growth.
     */
    public function test_a_custom_quote_never_undercuts_the_published_tier_it_matches(): void
    {
        $features = app(PlanFeatureService::class);
        $features->flush();

        $shapes = [
            'starter' => ['conversations' => 1000, 'replies_per_conversation' => 20, 'seats' => 3,  'agents' => 2,  'phone_numbers' => 1,  'phone_minutes' => 60],
            'growth'  => ['conversations' => 2500, 'replies_per_conversation' => 30, 'seats' => 10, 'agents' => 10, 'phone_numbers' => 3,  'phone_minutes' => 300],
            'scale'   => ['conversations' => 5000, 'replies_per_conversation' => 30, 'seats' => 25, 'agents' => 20, 'phone_numbers' => 10, 'phone_minutes' => 1200],
        ];

        $checked = 0;

        foreach ($shapes as $slug => $config) {
            $plan  = Plan::where('slug', $slug)->first();
            $price = $plan?->priceFor('monthly');

            if (! $price) {
                continue;
            }

            $quote = $this->pricer()->quote($config);
            $fixed = $price->unit_amount / 100;

            $this->assertGreaterThanOrEqual(
                $fixed,
                $quote['price_usd'],
                "A custom quote at {$slug}'s own shape came out at \${$quote['price_usd']} against "
                . "the published \${$fixed} — the tier is undercut and nobody would buy it.",
            );

            $checked++;
        }

        if ($checked === 0) {
            $this->markTestSkipped('No priced standard plans seeded.');
        }
    }

    /** Margin must stay inside the band the tiers were approved against. */
    public function test_every_quote_stays_inside_the_approved_margin_band(): void
    {
        $configs = [
            ['conversations' => 50,    'replies_per_conversation' => 5,  'seats' => 1,  'agents' => 1],
            ['conversations' => 1000,  'replies_per_conversation' => 20, 'seats' => 3,  'agents' => 2],
            ['conversations' => 2500,  'replies_per_conversation' => 30, 'seats' => 10, 'agents' => 10],
            ['conversations' => 20000, 'replies_per_conversation' => 40, 'seats' => 60, 'agents' => 40],
        ];

        foreach ($configs as $config) {
            $quote = $this->pricer()->quote($config);

            // The floor deliberately produces a very high margin on a tiny
            // configuration — $9 against thirty cents of cost. That is the floor
            // doing its job, not a mispriced plan, so it is excluded.
            if ($quote['breakdown']['floor_applied']) {
                continue;
            }

            $this->assertGreaterThanOrEqual(0.50, $quote['effective_margin']);
            $this->assertLessThanOrEqual(0.95, $quote['effective_margin']);
        }
    }

    public function test_nothing_is_quoted_below_the_floor(): void
    {
        $quote = $this->pricer()->quote([
            'conversations' => 50, 'replies_per_conversation' => 5, 'seats' => 1, 'agents' => 1,
        ]);

        $this->assertSame((int) ConversationPricer::FLOOR_USD, $quote['price_usd']);
        $this->assertTrue($quote['breakdown']['floor_applied']);
    }

    /**
     * The price must never fall as volume rises.
     *
     * This caught a real defect. With seats charged above a volume-scaled
     * allowance, 3,000 conversations quoted $171 where 2,000 quoted $192 — the
     * larger allowance forgave more seat charge than the extra messages added in
     * usage. A calculator that gets cheaper when you ask for more is both
     * exploitable and reads as broken, so the property is pinned across the
     * whole range rather than at one pair of points.
     */
    public function test_the_price_never_falls_as_volume_rises(): void
    {
        $previous = 0;

        foreach ([100, 500, 1000, 2000, 3000, 5000, 8000, 12000, 20000] as $conversations) {
            $quote = $this->pricer()->quote([
                'conversations' => $conversations, 'replies_per_conversation' => 25,
                'seats' => 2, 'agents' => 1,
            ]);

            $this->assertGreaterThanOrEqual(
                $previous,
                $quote['price_usd'],
                "{$conversations} conversations priced BELOW a smaller configuration",
            );

            $previous = $quote['price_usd'];
        }
    }

    /** Same property along the other volume axis. */
    public function test_the_price_never_falls_as_conversations_get_longer(): void
    {
        $previous = 0;

        foreach ([5, 10, 20, 30, 50, 80, 120, 200] as $replies) {
            $quote = $this->pricer()->quote([
                'conversations' => 1500, 'replies_per_conversation' => $replies,
                'seats' => 2, 'agents' => 1,
            ]);

            $this->assertGreaterThanOrEqual($previous, $quote['price_usd']);
            $previous = $quote['price_usd'];
        }
    }

    /** Telephony is real cash and must be charged for. */
    public function test_telephony_raises_the_price(): void
    {
        $base = ['conversations' => 2000, 'replies_per_conversation' => 25, 'seats' => 2, 'agents' => 1];

        $without = $this->pricer()->quote($base)['price_usd'];
        $with    = $this->pricer()->quote($base + ['phone_numbers' => 5, 'phone_minutes' => 900])['price_usd'];

        $this->assertGreaterThan($without, $with);
    }

    /**
     * Seats and agents are bundled with volume rather than charged, so the price
     * must NOT move with them — otherwise understating a team buys a discount.
     * The cap is what constrains them, and it is enforced server-side.
     */
    public function test_seats_do_not_change_the_price_and_are_capped_by_volume(): void
    {
        $base = ['conversations' => 3000, 'replies_per_conversation' => 25, 'agents' => 1];

        $few  = $this->pricer()->quote($base + ['seats' => 2]);
        $many = $this->pricer()->quote($base + ['seats' => 12]);

        // Ask AI scales faintly with seats, so allow a cent of drift rather than
        // asserting exact equality on a float.
        $this->assertLessThanOrEqual(
            1,
            abs($many['price_usd'] - $few['price_usd']),
            'Seats must not be a price lever — they are capped, not sold',
        );

        [$maxSeats, $maxAgents] = $this->pricer()->allowances(75_000);

        $this->assertSame(12, $maxSeats, '75,000 messages should allow a Growth-sized team');
        $this->assertSame(12, $maxAgents);

        // The smallest configurations still get a workable minimum.
        [$minSeats, $minAgents] = $this->pricer()->allowances(500);
        $this->assertSame(2, $minSeats);
        $this->assertSame(1, $minAgents);
    }

    /** An uncosted brain pool must not be read as "our costs are zero". */
    public function test_it_falls_back_to_the_estimate_without_enough_real_usage(): void
    {
        $rates = $this->pricer()->costPerMessage();

        $this->assertSame('estimated', $rates['basis']);
        $this->assertSame(ConversationPricer::FALLBACK_PER_MESSAGE, $rates['per_message']);
    }

    // ── Plan construction ───────────────────────────────────────────────

    public function test_a_built_plan_is_private_and_owned(): void
    {
        $client = $this->makeClient();

        $plan = app(CustomPlanService::class)->build($client, [
            'conversations' => 1000, 'replies_per_conversation' => 20, 'seats' => 3, 'agents' => 2,
        ]);

        $this->assertSame('custom', $plan->type);
        $this->assertFalse((bool) $plan->is_public, 'A custom plan must never appear on /pricing');
        $this->assertSame($client->id, (int) data_get($plan->metadata, 'client_id'));
        $this->assertNotNull($plan->priceFor('monthly'));
        $this->assertSame(
            $plan->priceFor('monthly')->unit_amount * 10,
            $plan->priceFor('annually')->unit_amount,
            'Annual must be ten months, matching the published catalogue',
        );
    }

    /**
     * The quote is stored on the plan. Without it, "why is this $82" has no
     * answer once rates or the cost basis have moved on.
     */
    public function test_the_quote_is_kept_for_later_explanation(): void
    {
        $client = $this->makeClient();

        $plan = app(CustomPlanService::class)->build($client, [
            'conversations' => 2500, 'replies_per_conversation' => 30, 'seats' => 10, 'agents' => 4,
        ]);

        $quote = data_get($plan->metadata, 'quote');

        $this->assertIsArray($quote);
        $this->assertSame(75000, $quote['messages']);
        $this->assertArrayHasKey('per_message', $quote);
        $this->assertArrayHasKey('basis', $quote);
    }

    public function test_rebuilding_retires_the_previous_plan(): void
    {
        $client = $this->makeClient();
        $svc    = app(CustomPlanService::class);

        $first = $svc->build($client, ['conversations' => 500, 'replies_per_conversation' => 20, 'seats' => 2, 'agents' => 1]);
        $svc->build($client, ['conversations' => 900, 'replies_per_conversation' => 20, 'seats' => 2, 'agents' => 1]);

        $this->assertFalse((bool) $first->fresh()->is_active, 'The superseded plan must be switched off');
        $this->assertNotNull($first->fresh(), 'It must be deactivated, not deleted — a subscription may reference it');

        $this->assertSame(
            1,
            Plan::where('type', 'custom')->where('is_active', true)
                ->where('metadata->client_id', $client->id)->count(),
            'Exactly one live custom plan per workspace',
        );
    }

    /** Feature access tracks volume, so a small plan cannot buy Scale's features. */
    public function test_module_access_is_inherited_from_the_tier_that_matches_the_volume(): void
    {
        $svc = app(CustomPlanService::class);

        if (! Plan::where('slug', 'scale')->exists()) {
            $this->markTestSkipped('Standard tiers not seeded.');
        }

        $this->assertSame('starter', $svc->templateFor(20_000)?->slug);
        $this->assertSame('growth', $svc->templateFor(75_000)?->slug);
        $this->assertSame('scale', $svc->templateFor(150_000)?->slug);

        // A tiny configuration must not inherit the top tier.
        $this->assertNotSame('scale', $svc->templateFor(2_000)?->slug);
    }

    // ── Who may buy it ──────────────────────────────────────────────────

    /**
     * The revenue control. A custom plan is a price agreed with one workspace,
     * behind a guessable slug (`custom-1-1`), in a catalogue findBySlug() does
     * not scope. Another workspace resolving it would inherit both its price
     * and, through the inherited template, its entitlements.
     */
    public function test_another_workspace_cannot_resolve_someone_elses_custom_plan(): void
    {
        $owner    = $this->makeClient('Owner Co');
        $stranger = $this->makeClient('Stranger Co');

        $plan = app(CustomPlanService::class)->build($owner, [
            'conversations' => 1000, 'replies_per_conversation' => 20, 'seats' => 3, 'agents' => 2,
        ]);

        $this->assertTrue($plan->isAvailableTo($owner));
        $this->assertFalse($plan->isAvailableTo($stranger));
        $this->assertFalse($plan->isAvailableTo(null));

        $this->expectException(\RuntimeException::class);
        app(PlanService::class)->resolvePrice($plan->slug, 'monthly', $stranger);
    }

    public function test_a_standard_plan_stays_available_to_everyone(): void
    {
        $client = $this->makeClient();
        $plan   = Plan::where('type', 'standard')->first();

        if (! $plan) {
            $this->markTestSkipped('No standard plan seeded.');
        }

        $this->assertTrue($plan->isAvailableTo($client));
        $this->assertTrue($plan->isAvailableTo(null), 'Internal callers with no workspace must still resolve public plans');
    }

    /**
     * A custom plan whose metadata has lost its owner must be available to
     * nobody. The safe reading of a corrupted pricing record is not "public".
     */
    public function test_an_ownerless_custom_plan_is_available_to_nobody(): void
    {
        $client = $this->makeClient();

        $plan = Plan::create([
            'name' => 'Orphan', 'slug' => 'custom-orphan-' . uniqid(), 'type' => 'custom',
            'is_active' => true, 'is_public' => false, 'metadata' => [],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($plan->isAvailableTo($client));
        $this->assertFalse($plan->isAvailableTo(null));
    }
}
