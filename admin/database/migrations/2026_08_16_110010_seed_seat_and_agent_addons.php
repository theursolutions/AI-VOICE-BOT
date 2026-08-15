<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two launch add-ons: an extra team seat and an extra AI agent.
 *
 * Each is a Plan with `type = 'addon'`, priced monthly AND annually so it can
 * match whichever interval the subscription is on (Stripe refuses to mix
 * cadences on one subscription). Its `plan_features` row declares what ONE
 * unit grants — `seats = 1` / `agents = 1` — which is what
 * PlanFeatureService::clientLimit() multiplies by the quantity bought.
 *
 * Prices follow the add-on pricing already published in
 * PRICING_RECOMMENDATION.md §4: extra seat +$5/mo, and an AI agent priced a
 * little above it because agents carry model cost, seats don't.
 * Annual = 10× monthly, the same "2 months free" the plans use.
 *
 * Idempotent: re-running creates nothing twice and overwrites no price.
 * Stripe objects are NOT created here — run `php artisan billing:sync-stripe`.
 */
return new class extends Migration
{
    private const ADDONS = [
        [
            'slug'    => 'addon-seat',
            'name'    => 'Extra team seat',
            'tagline' => 'One more person in your workspace.',
            'feature' => 'seats',
            'monthly' => 500,     // $5
            'annual'  => 5000,    // $50 — 2 months free
            'sort'    => 90,
        ],
        [
            'slug'    => 'addon-agent',
            'name'    => 'Extra AI agent',
            'tagline' => 'Another persona with its own voice, skills and knowledge.',
            'feature' => 'agents',
            'monthly' => 900,     // $9 — above a seat: agents carry model cost
            'annual'  => 9000,
            'sort'    => 91,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plans') || ! Schema::hasTable('features')) {
            return;
        }

        $now = now();

        foreach (self::ADDONS as $spec) {
            $planId = DB::table('plans')->where('slug', $spec['slug'])->value('id');

            if (! $planId) {
                $planId = DB::table('plans')->insertGetId([
                    'name'        => $spec['name'],
                    'slug'        => $spec['slug'],
                    'tagline'     => $spec['tagline'],
                    'type'        => 'addon',
                    // Never on the public pricing page: an add-on is sold from
                    // the billing screen against an existing subscription.
                    // scopePublic() already filters to free/standard/custom,
                    // so this is belt and braces.
                    'is_public'   => false,
                    'is_active'   => true,
                    'is_featured' => false,
                    'sort_order'  => $spec['sort'],
                    'trial_days'  => 0,
                    'trial_requires_payment_method' => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
            }

            foreach (['monthly' => $spec['monthly'], 'annually' => $spec['annual']] as $interval => $cents) {
                $exists = DB::table('plan_prices')
                    ->where('plan_id', $planId)
                    ->where('interval', $interval)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('plan_prices')->insert([
                    'plan_id'        => $planId,
                    'interval'       => $interval,
                    'currency'       => 'usd',
                    'unit_amount'    => $cents,
                    'is_active'      => true,
                    'effective_from' => $now,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            }

            // What ONE unit grants.
            //
            // On a FRESH install the `features` table is still empty here —
            // it's populated by BillingSeeder, which runs after migrate — so
            // this lookup legitimately misses and writes nothing. BillingSeeder
            // ::seedAddonGrants() creates the same row once features exist;
            // without that pairing the add-on would bill and grant nothing.
            $featureId = DB::table('features')->where('key', $spec['feature'])->value('id');

            if ($featureId) {
                $has = DB::table('plan_features')
                    ->where('plan_id', $planId)
                    ->where('feature_id', $featureId)
                    ->exists();

                if (! $has) {
                    DB::table('plan_features')->insert([
                        'plan_id'    => $planId,
                        'feature_id' => $featureId,
                        'value'      => '1',
                        'sort_order' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        try {
            app(\App\Services\Billing\PlanFeatureService::class)->flush();
        } catch (\Throwable $e) {
            // Cache unavailable during migrate; the TTL clears it.
        }
    }

    public function down(): void
    {
        $ids = DB::table('plans')->whereIn('slug', array_column(self::ADDONS, 'slug'))->pluck('id');

        DB::table('plan_features')->whereIn('plan_id', $ids)->delete();
        DB::table('plan_prices')->whereIn('plan_id', $ids)->delete();
        DB::table('subscription_addons')->whereIn('plan_id', $ids)->delete();
        DB::table('plans')->whereIn('id', $ids)->delete();
    }
};
