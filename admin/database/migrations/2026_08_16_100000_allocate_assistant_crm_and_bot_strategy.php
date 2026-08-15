<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan allocation decisions of 2026-08-16, applied to existing installs.
 *
 *   Team Assistant   → Starter and above   (it burns LLM tokens on every
 *                       question and was free to everyone, including Free)
 *   CRM connectors   → Growth and above    (split out of "unlimited data
 *                       sources"; the strongest upsell in the product)
 *   Bot Strategy     → Starter and above   (was Scale-only by accident, see
 *                       the config/modules.php split)
 *   Brain Settings   → Scale and above     (unchanged tier, now its own module
 *                       so it stops dragging Bot Strategy up with it)
 *   Compute Mesh     → all plans           (left ungated — no feature needed)
 *   Voices           → Starter and above   (confirmed: Free has no Voices
 *                       section; it uses the default voice for widget audio)
 *
 * BillingSeeder carries the same values for fresh installs. This migration
 * exists because the seeder is deliberately non-destructive and will not touch
 * a plan that already has feature rows.
 *
 * Idempotent throughout: safe to re-run, and it never overwrites a value an
 * operator has since changed by hand.
 */
return new class extends Migration
{
    /** feature key => [name, type, group, module, metric, sort, headline] */
    private const NEW_FEATURES = [
        'assistant_access' => [
            'Team Assistant (in-app AI)', 'boolean', 'Channels & power features',
            'assistant', null, 345, false,
            'Ask-AI inside the admin. Every question costs LLM tokens, so it is not on the free plan.',
        ],
        'crm_connectors' => [
            'CRM connectors (HubSpot, Salesforce, Pipedrive, Zoho)', 'boolean',
            'Channels & power features', null, null, 365, true,
            'Two-way sync with an existing CRM. Enforced when adding a crm_oauth data source.',
        ],
        'bot_strategy' => [
            'Bot knowledge strategy', 'boolean', 'Channels & power features',
            'bot_strategy', null, 335, false,
            'Choose which data tiers the bot may draw on when answering.',
        ],
    ];

    /** feature key => [free, starter, growth, scale, enterprise] — null = not granted */
    private const ALLOCATION = [
        'assistant_access' => [null, '1', '1', '1', '1'],
        'crm_connectors'   => [null, null, '1', '1', '1'],
        'bot_strategy'     => [null, '1', '1', '1', '1'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('features') || ! Schema::hasTable('plans')) {
            return;
        }

        $now = now();

        // 1. Create the new features (skip any that already exist).
        foreach (self::NEW_FEATURES as $key => [$name, $type, $group, $module, $metric, $sort, $headline, $desc]) {
            if (DB::table('features')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('features')->insert([
                'key'         => $key,
                'name'        => $name,
                'description' => $desc,
                'value_type'  => $type,
                'group'       => $group,
                'module_key'  => $module,
                'metric_key'  => $metric,
                'sort_order'  => $sort,
                'is_visible'  => true,
                'is_headline' => $headline,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // 2. Re-point byo_llm at the NEW brain_settings module. It used to gate
        //    `bot_strategy`, which is what dragged the knowledge toggles up to
        //    the Scale tier. Its own tier is unchanged.
        DB::table('features')
            ->where('key', 'byo_llm')
            ->update([
                'module_key' => 'brain_settings',
                'name'       => 'Brain Settings — bring your own AI keys / local model',
                'updated_at' => $now,
            ]);

        // 3. `voice_cloning` gates the whole Voices section, not just cloning.
        //    Name it for what it actually controls so the pricing page is honest.
        DB::table('features')
            ->where('key', 'voice_cloning')
            ->update([
                'name'       => 'Voices — 30 stock voices + voice cloning',
                'updated_at' => $now,
            ]);

        // 4. Apply the allocation.
        $planIds = DB::table('plans')->pluck('id', 'slug');
        $order   = ['free', 'starter', 'growth', 'scale', 'enterprise'];

        foreach (self::ALLOCATION as $featureKey => $values) {
            $featureId = DB::table('features')->where('key', $featureKey)->value('id');

            if (! $featureId) {
                continue;
            }

            foreach ($order as $i => $slug) {
                $planId = $planIds[$slug] ?? null;

                if (! $planId) {
                    continue;   // operator renamed or removed the plan
                }

                $exists = DB::table('plan_features')
                    ->where('plan_id', $planId)
                    ->where('feature_id', $featureId)
                    ->exists();

                if ($exists) {
                    continue;   // never overwrite a hand-edited value
                }

                // null = not granted → deliberately write NO row at all.
                if ($values[$i] === null) {
                    continue;
                }

                DB::table('plan_features')->insert([
                    'plan_id'    => $planId,
                    'feature_id' => $featureId,
                    'value'      => $values[$i],
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 5. Free had voice_cloning stored as "0", which reads as "granted, but
        //    off". A missing row is the canonical "not granted", and it stops
        //    the value showing up as a crossed-out row in the admin matrix.
        $freeId  = $planIds['free'] ?? null;
        $voiceId = DB::table('features')->where('key', 'voice_cloning')->value('id');

        if ($freeId && $voiceId) {
            DB::table('plan_features')
                ->where('plan_id', $freeId)
                ->where('feature_id', $voiceId)
                ->whereIn('value', ['0', ''])
                ->delete();
        }

        // 6. Flush the resolved-entitlement cache.
        //
        // PlanFeatureService memoises each plan's feature map for an hour.
        // The Ops UI flushes it on every save, but writing straight to the
        // tables here bypasses that — without this the new allocation is
        // invisible to the app for up to an hour after deploy, which looks
        // exactly like the migration having silently done nothing.
        $this->flushEntitlementCache();
    }

    private function flushEntitlementCache(): void
    {
        try {
            app(\App\Services\Billing\PlanFeatureService::class)->flush();
        } catch (\Throwable $e) {
            // Cache store unavailable during migrate (e.g. a cold container).
            // The TTL will clear it within the hour; not worth failing on.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        $ids = DB::table('features')->whereIn('key', array_keys(self::NEW_FEATURES))->pluck('id');

        DB::table('plan_features')->whereIn('feature_id', $ids)->delete();
        DB::table('features')->whereIn('id', $ids)->delete();

        DB::table('features')->where('key', 'byo_llm')->update(['module_key' => 'bot_strategy']);
    }
};
