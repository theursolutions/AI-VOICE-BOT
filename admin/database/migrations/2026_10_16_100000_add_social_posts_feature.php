<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan allocation decision of 2026-10-16: Social Posts → Growth and above.
 *
 * AI-written posts published and scheduled to Facebook Pages and Instagram.
 * Gating it through a feature row with module_key `social` is what makes the
 * sidebar padlock, the 402 upsell page and the route gate all agree — a module
 * with no gating feature is open to every plan, which is how it shipped.
 *
 * Custom plans: new ones copy their template tier's features
 * (CustomPlanService::applyFeatures), so they pick this up on their own. For
 * EXISTING custom plans this resolves the template the same way the builder
 * does — by message volume — and grants only when that template is itself
 * Growth or above, so a Starter-sized custom deal does not get it for free.
 *
 * Idempotent and non-destructive like its siblings: it never recreates the
 * feature and never overwrites a per-plan value an operator has edited.
 */
return new class extends Migration
{
    private const KEY = 'social_posts';

    /** Published tiers that get it. Free and Starter deliberately get no row. */
    private const TIERS = ['growth', 'scale', 'enterprise'];

    public function up(): void
    {
        if (! Schema::hasTable('features') || ! Schema::hasTable('plans')) {
            return;
        }

        $now = now();

        if (! DB::table('features')->where('key', self::KEY)->exists()) {
            DB::table('features')->insert([
                'key'         => self::KEY,
                'name'        => 'Social media posting with AI',
                'description' => 'AI-written posts, Reels and Stories published or scheduled to your Facebook Pages and Instagram.',
                'value_type'  => 'boolean',
                'group'       => 'Channels & power features',
                // Gates the `social` module (config/modules.php).
                'module_key'  => 'social',
                'metric_key'  => null,
                'sort_order'  => 337,
                'is_visible'  => true,
                'is_headline' => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $featureId = DB::table('features')->where('key', self::KEY)->value('id');

        $planIds = DB::table('plans')->whereIn('slug', self::TIERS)->pluck('id')->all();

        // Existing custom plans whose template tier is Growth or above.
        try {
            $templates = app(\App\Services\Billing\CustomPlanService::class);
            $limits    = app(\App\Services\Billing\PlanFeatureService::class);
            $limits->flush();

            foreach (\App\Models\Billing\Plan::where('type', 'custom')->get() as $custom) {
                $messages = $limits->planLimit($custom, 'messages');
                $template = $messages === null ? null : $templates->templateFor($messages);

                if ($template && in_array($template->slug, self::TIERS, true)) {
                    $planIds[] = $custom->id;
                }
            }
        } catch (\Throwable $e) {
            // Custom plans are rare and editable in Ops; never fail a deploy over them.
        }

        foreach (array_unique($planIds) as $planId) {
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
                'value'      => '1',
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Entitlements are cached per plan for an hour; writing to the tables
        // directly bypasses the flush the Ops UI does.
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
