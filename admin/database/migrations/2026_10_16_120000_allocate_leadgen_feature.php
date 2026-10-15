<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan allocation decision of 2026-10-16: Lead Generation & Outreach → Growth
 * and every plan above it (Growth, Scale, Enterprise). Not Free, not Starter.
 *
 * Gates the `leadgen` module (config/modules.php): EnsurePlanFeature refuses
 * its routes with the upgrade page, and the sidebar shows it locked.
 *
 * Custom plans copy their features from the standard plan they're quoted
 * from, so new ones inherit this automatically. Existing custom plans get it
 * only when their message allowance is at or above Growth's — i.e. they sit
 * at Growth level or higher.
 *
 * Idempotent and non-destructive, like its siblings: never creates the
 * feature twice and never overwrites a per-plan value an operator has edited.
 */
return new class extends Migration
{
    private const KEY = 'leadgen';

    /** Standard plans that get it. */
    private const PLANS = ['growth', 'scale', 'enterprise'];

    public function up(): void
    {
        if (! Schema::hasTable('features') || ! Schema::hasTable('plans')) {
            return;
        }

        $now = now();

        if (! DB::table('features')->where('key', self::KEY)->exists()) {
            DB::table('features')->insert([
                'key'         => self::KEY,
                'name'        => 'Lead Generation & Outreach',
                'description' => 'Find businesses that fit your offer, check and score them with AI, and prepare personalised outreach.',
                'value_type'  => 'boolean',
                'group'       => 'Channels & power features',
                'module_key'  => 'leadgen',
                'metric_key'  => null,
                'sort_order'  => 338,
                'is_visible'  => true,
                'is_headline' => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $featureId = DB::table('features')->where('key', self::KEY)->value('id');
        $plans     = DB::table('plans')->get(['id', 'slug', 'type']);

        $messagesId = DB::table('features')->where('key', 'messages')->value('id');
        $growthId   = $plans->firstWhere('slug', 'growth')?->id;
        $growthMsgs = $messagesId && $growthId
            ? (int) DB::table('plan_features')->where('plan_id', $growthId)->where('feature_id', $messagesId)->value('value')
            : null;

        foreach ($plans as $plan) {
            $grant = in_array($plan->slug, self::PLANS, true);

            if (! $grant && $plan->type === 'custom' && $growthMsgs) {
                $msgs  = (int) DB::table('plan_features')->where('plan_id', $plan->id)->where('feature_id', $messagesId)->value('value');
                $grant = $msgs === -1 || $msgs >= $growthMsgs;   // -1 = unlimited
            }
            if (! $grant) {
                continue;
            }

            $exists = DB::table('plan_features')->where('plan_id', $plan->id)->where('feature_id', $featureId)->exists();
            if ($exists) {
                continue;   // respect a hand-edited value
            }

            DB::table('plan_features')->insert([
                'plan_id'    => $plan->id,
                'feature_id' => $featureId,
                'value'      => '1',
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Resolved entitlements are cached per plan for an hour; flush so the
        // gate applies now rather than after the TTL.
        try {
            app(\App\Services\Billing\PlanFeatureService::class)->flush();
        } catch (\Throwable $e) {
            // Cache store unavailable during migrate; the TTL will clear it.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        $id = DB::table('features')->where('key', self::KEY)->value('id');

        if ($id) {
            DB::table('plan_features')->where('feature_id', $id)->delete();
            DB::table('features')->where('id', $id)->delete();
        }
    }
};
