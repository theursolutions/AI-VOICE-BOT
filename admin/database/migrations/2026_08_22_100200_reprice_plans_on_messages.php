<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reprice the catalogue and move the ceiling onto messages.
 *
 * The allowance was "conversations", which is not a bounded quantity: a reply
 * costs four LLM calls whether it is the first of a session or the two
 * hundredth, so "1,000 conversations" is 20,000 messages at twenty turns and
 * 100,000 at a hundred, on one price. Every paid tier was underwater past a
 * certain chattiness. This is the step that makes the sold unit and the costed
 * unit the same thing.
 *
 *   plan      price      messages   conversations   replies each
 *   Free      $0            500          25              20
 *   Starter   $26        20,000       1,000              20
 *   Growth    $75        75,000       2,500              30
 *   Scale     $199      150,000       5,000              30
 *
 * Conversations stay on the cards because that is the unit a customer can
 * picture, but they are now DERIVED — messages divided by replies-per-
 * conversation — and no longer cap anything. Priced on the hybrid brain routing
 * at $0.30 per thousand messages, which puts these tiers at 70%, 62% and 66%
 * gross margin on variable cost.
 *
 * REPLIES PER CONVERSATION IS A PLAN FEATURE, not one global default. Without
 * that, Growth's 75,000 messages divided by a fixed 20 advertises 3,750
 * conversations where the plan was designed and sold as 2,500 — the cards would
 * contradict the pricing they were derived from. The workspace owner still
 * overrides it within ConversationBudget's bounds; this only sets where each
 * tier starts.
 *
 * PRICES ARE NEW ROWS, NOT EDITS. Every existing plan_price is already synced to
 * a live Stripe price, and Stripe prices are immutable: changing unit_amount in
 * place would leave the page saying $26 while Stripe went on charging $19
 * against the same ref, which is the kind of divergence nobody notices until a
 * customer does. Old rows are deactivated and archived; new rows land with a
 * null stripe_price_ref.
 *
 * SO CHECKOUT IS DELIBERATELY BROKEN UNTIL SOMEONE SYNCS. BillingService refuses
 * a price with no stripe_price_ref, which is the correct failure — better a
 * checkout that declines than one that takes the wrong amount. Run Super Admin →
 * Plans → Sync Stripe (or `prices.sync` per row) to mint the new Stripe prices.
 *
 * EXISTING SUBSCRIBERS ARE NOT MOVED. Their subscription keeps its own Stripe
 * price and goes on billing what they agreed to; the archived local row is still
 * there for display. Migrating anyone onto new pricing is a commercial decision
 * and does not belong in a migration.
 */
return new class extends Migration
{
    /** slug => [monthly cents, annual cents] */
    private const PRICES = [
        'starter' => [2600,  26000],
        'growth'  => [7500,  75000],
        'scale'   => [19900, 199000],
    ];

    /** feature key => [slug => value]. -1 is the unlimited convention. */
    private const VALUES = [
        'messages' => [
            'free' => '500', 'starter' => '20000', 'growth' => '75000', 'scale' => '150000',
            'enterprise' => '-1',
        ],
        'conversations' => [
            'free' => '25', 'starter' => '1000', 'growth' => '2500', 'scale' => '5000',
            'enterprise' => '-1',
        ],
        'replies_per_conversation' => [
            'free' => '20', 'starter' => '20', 'growth' => '30', 'scale' => '30',
            'enterprise' => '-1',
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $this->ensureRepliesFeature();

            foreach (self::VALUES as $featureKey => $byPlan) {
                $featureId = DB::table('features')->where('key', $featureKey)->value('id');

                if (! $featureId) {
                    continue;
                }

                foreach ($byPlan as $slug => $value) {
                    $planId = DB::table('plans')->where('slug', $slug)->value('id');

                    if (! $planId) {
                        continue;
                    }

                    DB::table('plan_features')->updateOrInsert(
                        ['plan_id' => $planId, 'feature_id' => $featureId],
                        ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }

            // Move the ceiling. Both in one transaction, because a moment with
            // neither metric claimed is a moment with no cap at all, and a
            // moment with both claimed makes which one applies depend on row
            // order — allowanceFor() resolves a metric with a single
            // where(metric_key)->value('key').
            DB::table('features')->where('key', 'messages')->update(['metric_key' => 'messages']);
            DB::table('features')->where('key', 'conversations')->update([
                'metric_key'  => null,
                'description' => 'A session with at least one AI reply. Shown so you can see how '
                               . 'your messages divide up; your allowance is measured in messages.',
                'is_headline' => 1,
            ]);

            $this->repriceAll();
        });

        // The resolved-feature cache is keyed per plan and holds the old numbers.
        app(\App\Services\Billing\PlanFeatureService::class)->flush();
    }

    /**
     * The per-plan starting point for ConversationBudget.
     *
     * Not a headline bullet: on a card it is a qualifier on the conversation
     * figure ("2,500 conversations, 30 replies each"), and as its own ticked
     * line it reads like a limit being advertised rather than a capacity.
     */
    private function ensureRepliesFeature(): void
    {
        if (DB::table('features')->where('key', 'replies_per_conversation')->exists()) {
            return;
        }

        DB::table('features')->insert([
            'key'         => 'replies_per_conversation',
            'name'        => 'AI replies per conversation',
            'description' => 'How many times the assistant answers in one conversation before it '
                           . 'hands over to a person. Adjustable from your billing page.',
            'value_type'  => 'numeric',
            'unit'        => 'per conversation',
            'module_key'  => null,
            // No metric_key: this is a divisor, not something that accumulates.
            'metric_key'  => null,
            'group'       => 'Volume',
            'sort_order'  => 7,
            'is_visible'  => 1,
            'is_headline' => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function repriceAll(): void
    {
        foreach (self::PRICES as $slug => [$monthly, $annual]) {
            $planId = DB::table('plans')->where('slug', $slug)->value('id');

            if (! $planId) {
                continue;
            }

            foreach (['monthly' => $monthly, 'annually' => $annual] as $interval => $amount) {
                $this->replacePrice((int) $planId, $interval, $amount);
            }
        }
    }

    private function replacePrice(int $planId, string $interval, int $amount): void
    {
        $existing = DB::table('plan_prices')
            ->where('plan_id', $planId)
            ->where('interval', $interval)
            ->where('is_active', true)
            ->get();

        // Already at this amount and synced? Leave it entirely alone — rerunning
        // must not churn Stripe refs or archive a price that is still correct.
        if ($existing->count() === 1 && (int) $existing->first()->unit_amount === $amount) {
            return;
        }

        // Deactivate BEFORE inserting. priceFor() takes the first active row for
        // an interval, so an overlap makes the live price arbitrary.
        DB::table('plan_prices')
            ->whereIn('id', $existing->pluck('id'))
            ->update(['is_active' => false, 'archived_at' => now(), 'updated_at' => now()]);

        DB::table('plan_prices')->insert([
            'plan_id'            => $planId,
            'interval'           => $interval,
            'currency'           => 'usd',
            'unit_amount'        => $amount,
            'compare_at_amount'  => null,
            // Null on purpose: a new Stripe price has to be minted. Checkout
            // refuses until it is, which is the safe direction to fail.
            'stripe_price_ref'   => null,
            'stripe_product_ref' => $existing->first()->stripe_product_ref ?? null,
            'stripe_livemode'    => 0,
            'stripe_synced_at'   => null,
            'is_active'          => true,
            'effective_from'     => now(),
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /**
     * Restores the previous prices by reactivating the archived rows, which
     * still carry their working Stripe refs — the reason the up() path archives
     * rather than deletes.
     */
    public function down(): void
    {
        DB::transaction(function () {
            DB::table('features')->where('key', 'messages')->update(['metric_key' => null]);
            DB::table('features')->where('key', 'conversations')->update(['metric_key' => 'conversations']);

            foreach (array_keys(self::PRICES) as $slug) {
                $planId = DB::table('plans')->where('slug', $slug)->value('id');

                if (! $planId) {
                    continue;
                }

                foreach (['monthly', 'annually'] as $interval) {
                    DB::table('plan_prices')
                        ->where('plan_id', $planId)->where('interval', $interval)
                        ->whereNull('stripe_price_ref')
                        ->delete();

                    DB::table('plan_prices')
                        ->where('plan_id', $planId)->where('interval', $interval)
                        ->whereNotNull('stripe_price_ref')
                        ->update(['is_active' => true, 'archived_at' => null, 'updated_at' => now()]);
                }
            }
        });

        app(\App\Services\Billing\PlanFeatureService::class)->flush();
    }
};
