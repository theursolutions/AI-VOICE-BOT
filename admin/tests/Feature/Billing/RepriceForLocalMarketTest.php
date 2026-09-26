<?php

namespace Tests\Feature\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\PlanGrant;
use App\Models\Billing\PlanPrice;
use App\Models\Client;
use App\Services\Billing\LocalPriceService;
use App\Services\Billing\PlanFeatureService;
use App\Services\Billing\WorkspacePlanService;
use Illuminate\Support\Facades\DB;

/**
 * The September 2026 repricing for the local market.
 *
 * Three things are pinned here. The catalogue itself: a starting price inside
 * Rs 3,000–5,000 and a margin inside 50–70% on every paid tier, with the
 * customer using ALL of their allowance. The migration: new prices as new rows,
 * rupee prices re-minted, down() restoring the old ones. And the part that
 * matters most to people already paying: nobody loses allowance they bought.
 */
class RepriceForLocalMarketTest extends BillingTestCase
{
    private const MIGRATION = '2026_09_27_100000_reprice_for_local_market.php';

    // ── Unit costs the margins are held against (see the migration) ─────
    private const PER_MESSAGE      = 0.0003;
    private const PER_PHONE_MINUTE = 0.012;
    private const PER_NUMBER       = 1.15;
    private const PER_VOICE_MSG    = 0.0005;
    private const PER_SEAT_ASK     = 20 * 0.000185;
    private const PKR_PER_USD      = 280;   // interbank, not the minting rate

    private function features(): PlanFeatureService
    {
        return app(PlanFeatureService::class);
    }

    private function migration(): object
    {
        static $migration;

        return $migration ??= require database_path('migrations/' . self::MIGRATION);
    }

    private function limit(string $slug, string $key): ?int
    {
        $this->features()->flush();

        return $this->features()->planLimit(Plan::where('slug', $slug)->firstOrFail(), $key);
    }

    private function priceOf(string $slug, string $interval, string $currency = 'usd'): ?PlanPrice
    {
        return Plan::where('slug', $slug)->firstOrFail()->priceFor($interval, $currency);
    }

    // ── The catalogue ───────────────────────────────────────────────────

    public function test_the_starting_price_is_between_three_and_five_thousand_rupees(): void
    {
        $starter = $this->priceOf('starter', 'monthly', 'pkr');

        $this->assertNotNull($starter, 'Starter has no rupee price');
        $this->assertGreaterThanOrEqual(300000, $starter->unit_amount);
        $this->assertLessThanOrEqual(500000, $starter->unit_amount);
        $this->assertSame('Rs 4,500', $starter->formatted());

        $this->assertSame('Rs 4,500', Plan::startingPrice('PKR')?->formatted());
        $this->assertSame('$15', Plan::startingPrice()?->formatted());
    }

    public function test_the_published_prices(): void
    {
        foreach ([
            'starter' => [1500, 15000, 450000, 4500000],
            'growth'  => [3900, 39000, 1200000, 11700000],
            'scale'   => [9900, 99000, 3000000, 29700000],
        ] as $slug => [$usdMonthly, $usdAnnual, $pkrMonthly, $pkrAnnual]) {
            $this->assertSame($usdMonthly, $this->priceOf($slug, 'monthly')->unit_amount, "{$slug} monthly USD");
            $this->assertSame($usdAnnual, $this->priceOf($slug, 'annually')->unit_amount, "{$slug} annual USD");
            $this->assertSame($pkrMonthly, $this->priceOf($slug, 'monthly', 'pkr')->unit_amount, "{$slug} monthly PKR");
            $this->assertSame($pkrAnnual, $this->priceOf($slug, 'annually', 'pkr')->unit_amount, "{$slug} annual PKR");
        }
    }

    /**
     * Worst case on purpose: every message, minute, number and voice note in the
     * allowance used, through the dearer of the two gateways for each currency,
     * on both intervals. A real workspace uses a fraction and earns more.
     */
    public function test_every_paid_tier_holds_a_fifty_to_seventy_percent_margin_at_full_usage(): void
    {
        foreach (['starter', 'growth', 'scale'] as $slug) {
            $cost = $this->limit($slug, 'messages') * self::PER_MESSAGE
                + $this->limit($slug, 'telephony_minutes') * self::PER_PHONE_MINUTE
                + $this->limit($slug, 'phone_numbers') * self::PER_NUMBER
                + $this->limit($slug, 'voice_messages') * self::PER_VOICE_MSG
                + $this->limit($slug, 'seats') * self::PER_SEAT_ASK;

            foreach (['monthly' => 1, 'annually' => 12] as $interval => $months) {
                // Abroad: Paddle, 5% + $0.50 a transaction.
                $usd     = $this->priceOf($slug, $interval)->unit_amount / 100;
                $revenue = $usd / $months;
                $fee     = ($usd * 0.05 + 0.50) / $months;
                $this->assertMarginInBand($slug, "{$interval} via Paddle", $revenue, $cost + $fee);

                // At home: Safepay, ~3%, rupees converted at the interbank rate.
                $pkr     = $this->priceOf($slug, $interval, 'pkr')->unit_amount / 100 / self::PKR_PER_USD;
                $revenue = $pkr / $months;
                $this->assertMarginInBand($slug, "{$interval} via Safepay", $revenue, $cost + $revenue * 0.03);
            }
        }
    }

    private function assertMarginInBand(string $slug, string $how, float $revenue, float $cost): void
    {
        $margin = ($revenue - $cost) / $revenue;

        $this->assertGreaterThanOrEqual(0.50, $margin, sprintf('%s %s: margin %.1f%% is below 50%%', $slug, $how, $margin * 100));
        $this->assertLessThanOrEqual(0.70, $margin, sprintf('%s %s: margin %.1f%% is above 70%%', $slug, $how, $margin * 100));
    }

    /** Volumes shrink; features do not. */
    public function test_only_the_allowances_that_cost_money_changed(): void
    {
        $this->assertSame(10000, $this->limit('starter', 'messages'));
        $this->assertSame(25000, $this->limit('growth', 'messages'));
        $this->assertSame(60000, $this->limit('scale', 'messages'));

        $this->assertSame([50, 200, 500], array_map(fn ($s) => $this->limit($s, 'telephony_minutes'), ['starter', 'growth', 'scale']));
        $this->assertSame([1, 2, 5], array_map(fn ($s) => $this->limit($s, 'phone_numbers'), ['starter', 'growth', 'scale']));

        // Untouched: team size, agents, projects, knowledge, history.
        $this->assertSame([3, 10, 25], array_map(fn ($s) => $this->limit($s, 'seats'), ['starter', 'growth', 'scale']));
        $this->assertSame([1, 3, 10], array_map(fn ($s) => $this->limit($s, 'projects'), ['starter', 'growth', 'scale']));
        $this->assertSame([500, 5000, 25000], array_map(fn ($s) => $this->limit($s, 'indexed_pages'), ['starter', 'growth', 'scale']));

        // A sample of the gates, exactly where they were.
        $this->assertTrue($this->features()->planHas(Plan::where('slug', 'growth')->first(), 'database_connector'));
        $this->assertTrue($this->features()->planHas(Plan::where('slug', 'scale')->first(), 'white_label'));
        $this->assertFalse($this->features()->planHas(Plan::where('slug', 'starter')->first(), 'api_access'));
    }

    /**
     * A fresh install used to get NO message allowance: the repricing
     * migrations run against empty plan tables, and the seeder never wrote the
     * figure while the metric capped on it — so the free plan stopped at its
     * first reply.
     */
    public function test_a_fresh_install_has_a_message_allowance_on_every_plan(): void
    {
        foreach (['free' => 500, 'starter' => 10000, 'growth' => 25000, 'scale' => 60000] as $slug => $messages) {
            $this->assertSame($messages, $this->limit($slug, 'messages'), "{$slug} messages");
        }

        $this->assertNull(
            DB::table('features')->where('key', 'conversations')->value('metric_key'),
            'Conversations are derived from messages; capping on both limits a plan twice',
        );
    }

    // ── The migration ───────────────────────────────────────────────────

    public function test_the_migration_changes_nothing_on_a_database_already_repriced(): void
    {
        $before = PlanPrice::count();

        $this->migration()->up();

        $this->assertSame($before, PlanPrice::count(), 'A rerun must not churn price rows');
        $this->assertSame(0, PlanGrant::count());
    }

    public function test_the_migration_replaces_prices_as_new_rows_and_down_restores_them(): void
    {
        $this->rewindToPreviousCatalogue();

        $oldStarter = $this->priceOf('starter', 'monthly');
        $this->assertSame(2600, $oldStarter->unit_amount);
        $this->assertSame(800000, $this->priceOf('starter', 'monthly', 'pkr')->unit_amount, 'Rs 8,000 before');

        $this->migration()->up();

        $new = $this->priceOf('starter', 'monthly');
        $this->assertSame(1500, $new->unit_amount);
        $this->assertNotSame($oldStarter->id, $new->id, 'Prices must be new rows — Stripe prices are immutable');
        $this->assertNull($new->stripe_price_ref, 'A new price starts unsynced, so checkout refuses it until synced');

        $archived = $oldStarter->fresh();
        $this->assertFalse((bool) $archived->is_active);
        $this->assertSame('price_old_starter_monthly', $archived->stripe_price_ref, 'The old provider id is kept for down()');

        $this->assertSame(450000, $this->priceOf('starter', 'monthly', 'pkr')->unit_amount, 'Rs 4,500 after');
        $this->assertSame(10000, $this->limit('starter', 'messages'));

        $this->migration()->down();

        $restored = $this->priceOf('starter', 'monthly');
        $this->assertSame($oldStarter->id, $restored->id);
        $this->assertSame('price_old_starter_monthly', $restored->stripe_price_ref);
        $this->assertSame(800000, $this->priceOf('starter', 'monthly', 'pkr')->unit_amount);
        $this->assertSame(20000, $this->limit('starter', 'messages'));
    }

    // ── People already paying ───────────────────────────────────────────

    /** Stripe renews at the OLD price, so the old allowance goes with it. */
    public function test_a_self_renewing_subscriber_keeps_the_old_allowance(): void
    {
        $this->rewindToPreviousCatalogue();
        $client = $this->subscriber('starter', ['stripe_subscription_ref' => 'sub_old'], now()->addDays(20));

        $this->migration()->up();

        $grant = PlanGrant::where('client_id', $client->id)->where('feature_key', 'messages')->first();
        $this->assertNotNull($grant);
        $this->assertSame(10000, (int) $grant->value, '20,000 bought, 10,000 on the plan now');
        $this->assertNull($grant->expires_at, 'Kept while they pay the original price');

        $this->assertSame(20000, $this->features()->clientLimit(Client::find($client->id), 'messages'));
        $this->assertSame(60, $this->features()->clientLimit(Client::find($client->id), 'telephony_minutes'));
    }

    /** Safepay renews at the NEW price, so the protection ends with the period paid for. */
    public function test_a_subscriber_who_renews_by_checkout_keeps_it_until_the_period_ends(): void
    {
        $this->rewindToPreviousCatalogue();
        $ends   = now()->addDays(10)->startOfSecond();
        $client = $this->subscriber('growth', [], $ends);

        $this->migration()->up();

        $grant = PlanGrant::where('client_id', $client->id)->where('feature_key', 'messages')->first();
        $this->assertSame(50000, (int) $grant->value);
        $this->assertSame($ends->toDateTimeString(), $grant->expires_at->toDateTimeString());

        $this->assertSame(75000, $this->features()->clientLimit(Client::find($client->id), 'messages'));
    }

    public function test_an_unlimited_allowance_that_became_bounded_stays_unlimited(): void
    {
        $this->rewindToPreviousCatalogue();
        $client = $this->subscriber('scale', ['paddle_subscription_id' => 'sub_paddle'], now()->addDays(5));

        $this->migration()->up();

        $grant = PlanGrant::where('client_id', $client->id)->where('feature_key', 'voice_messages')->first();
        $this->assertSame(PlanGrant::UNLIMITED, (int) $grant->value);
        $this->assertNull($this->features()->clientLimit(Client::find($client->id), 'voice_messages'));
    }

    /** An operator's grant is their decision; it is logged, never overwritten. */
    public function test_an_existing_grant_is_left_alone(): void
    {
        $this->rewindToPreviousCatalogue();
        $client = $this->subscriber('starter', ['stripe_subscription_ref' => 'sub_x'], now()->addDays(20));
        app(WorkspacePlanService::class)->grant($client, 'messages', 999, null, 'Agreed with sales');

        $this->migration()->up();

        $grant = PlanGrant::where('client_id', $client->id)->where('feature_key', 'messages')->first();
        $this->assertSame(999, (int) $grant->value);
        $this->assertSame('Agreed with sales', $grant->note);
    }

    public function test_nobody_is_granted_anything_they_were_not_paying_for(): void
    {
        $this->rewindToPreviousCatalogue();

        $canceled = $this->subscriber('growth', ['stripe_subscription_ref' => 'sub_c'], now()->addDays(5), 'canceled');
        $lapsed   = $this->subscriber('growth', [], now()->subDay());

        $this->migration()->up();

        $this->assertSame(0, PlanGrant::whereIn('client_id', [$canceled->id, $lapsed->id])->count());
    }

    public function test_down_takes_back_only_the_grants_it_gave(): void
    {
        $this->rewindToPreviousCatalogue();
        $client = $this->subscriber('starter', ['stripe_subscription_ref' => 'sub_d'], now()->addDays(20));
        app(WorkspacePlanService::class)->grant($client, 'seats', 2, null, 'Agreed with sales');

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(
            ['seats'],
            PlanGrant::where('client_id', $client->id)->pluck('feature_key')->all(),
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /**
     * Put the database back to how production looked before this migration:
     * the August catalogue's allowances, and its prices as live, synced rows
     * with rupee rows minted from them.
     */
    private function rewindToPreviousCatalogue(): void
    {
        $values = [
            'messages'                 => ['starter' => '20000', 'growth' => '75000', 'scale' => '150000'],
            'conversations'            => ['starter' => '1000',  'growth' => '2500',  'scale' => '5000'],
            'replies_per_conversation' => ['starter' => '20',    'growth' => '30',    'scale' => '30'],
            'telephony_minutes'        => ['starter' => '60',    'growth' => '300',   'scale' => '1200'],
            'phone_numbers'            => ['starter' => '1',     'growth' => '3',     'scale' => '10'],
            'voice_messages'           => ['starter' => '500',   'growth' => '3000',  'scale' => '-1'],
        ];

        foreach ($values as $key => $byPlan) {
            $featureId = DB::table('features')->where('key', $key)->value('id');

            foreach ($byPlan as $slug => $value) {
                DB::table('plan_features')->updateOrInsert(
                    ['plan_id' => Plan::where('slug', $slug)->value('id'), 'feature_id' => $featureId],
                    ['value' => $value],
                );
            }
        }

        $prices = ['starter' => [2600, 26000], 'growth' => [7500, 75000], 'scale' => [19900, 199000]];

        foreach ($prices as $slug => [$monthly, $annual]) {
            $plan = Plan::where('slug', $slug)->firstOrFail();

            PlanPrice::where('plan_id', $plan->id)->delete();

            foreach (['monthly' => $monthly, 'annually' => $annual] as $interval => $amount) {
                PlanPrice::create([
                    'plan_id'            => $plan->id,
                    'interval'           => $interval,
                    'currency'           => 'usd',
                    'unit_amount'        => $amount,
                    'stripe_price_ref'   => "price_old_{$slug}_{$interval}",
                    'stripe_product_ref' => "prod_{$slug}",
                    'stripe_synced_at'   => now(),
                    'is_active'          => true,
                    'effective_from'     => now()->subMonth(),
                ]);
            }

            app(LocalPriceService::class)->mirror($plan->fresh(), 'PKR');
        }

        $this->features()->flush();
    }

    private function subscriber(string $slug, array $refs, \DateTimeInterface $periodEnd, string $status = 'active'): Client
    {
        static $n = 0;
        $n++;

        [$client] = $this->makeWorkspace("Subscriber {$n}", "owner{$n}@reprice.test");

        DB::table('subscriptions')->where('client_id', $client->id)->delete();

        DB::table('subscriptions')->insert($refs + [
            'client_id'          => $client->id,
            'plan_id'            => Plan::where('slug', $slug)->value('id'),
            'type'               => 'default',
            'status'             => $status,
            'interval'           => 'monthly',
            'currency'           => 'usd',
            'current_period_start' => now()->subDays(10),
            'current_period_end' => $periodEnd,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        return Client::find($client->id);
    }
}
