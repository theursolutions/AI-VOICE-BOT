<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan allocation decision of 2026-08-16: Skills → every plan except Free.
 *
 * Skills are the call-centre routing categories plus the prebuilt tool/action
 * library — multi-agent routing, in other words. The module shipped ungated,
 * so it was silently free to everyone including Free, despite being described
 * as a paid capability since the original pricing work.
 *
 * Idempotent and non-destructive, same as its sibling migration: it will not
 * create a feature that already exists, and it never overwrites a per-plan
 * value an operator has since edited by hand.
 */
return new class extends Migration
{
    private const KEY = 'skills';

    /** [free, starter, growth, scale, enterprise] — null = no row = not granted */
    private const ALLOCATION = [null, '1', '1', '1', '1'];

    public function up(): void
    {
        if (! Schema::hasTable('features') || ! Schema::hasTable('plans')) {
            return;
        }

        $now = now();

        if (! DB::table('features')->where('key', self::KEY)->exists()) {
            DB::table('features')->insert([
                'key'         => self::KEY,
                'name'        => 'Skills & multi-agent routing',
                'description' => 'Route conversations to the right agent by skill, with a library of prebuilt actions.',
                'value_type'  => 'boolean',
                'group'       => 'Channels & power features',
                // Gates the `skills` module (config/modules.php), so the
                // sidebar entry and the routes disappear together.
                'module_key'  => 'skills',
                'metric_key'  => null,
                'sort_order'  => 336,
                'is_visible'  => true,
                'is_headline' => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $featureId = DB::table('features')->where('key', self::KEY)->value('id');
        $planIds   = DB::table('plans')->pluck('id', 'slug');

        foreach (['free', 'starter', 'growth', 'scale', 'enterprise'] as $i => $slug) {
            $planId = $planIds[$slug] ?? null;
            $value  = self::ALLOCATION[$i];

            if (! $planId || $value === null) {
                continue;   // Free deliberately gets NO row
            }

            $exists = DB::table('plan_features')
                ->where('plan_id', $planId)
                ->where('feature_id', $featureId)
                ->exists();

            if ($exists) {
                continue;   // respect a hand-edited value
            }

            DB::table('plan_features')->insert([
                'plan_id'    => $planId,
                'feature_id' => $featureId,
                'value'      => $value,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Resolved entitlements are cached per plan for an hour. Writing
        // straight to the tables bypasses the flush the Ops UI does, so
        // without this the change is invisible until the TTL expires — which
        // looks exactly like the migration having done nothing.
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
