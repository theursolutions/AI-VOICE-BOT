<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The metered unit becomes AI messages.
 *
 * A plan sold as "1,000 conversations" was never a bounded quantity: a reply
 * costs four LLM calls whether it is the first of a session or the two
 * hundredth, so the same allowance is 20,000 messages at twenty turns and
 * 100,000 at a hundred — and the same price had to cover both. Every paid tier
 * was underwater past a certain chattiness, and nothing in the product could
 * say which customers those were.
 *
 * This migration only INTRODUCES the meter. It deliberately does NOT move the
 * ceiling onto it:
 *
 *   • `metric_key` is left NULL, so no plan is capped on messages yet.
 *     UsageLimitService::allowanceFor() finds no feature claiming the metric and
 *     returns null, which it reads as unlimited. Recording starts immediately.
 *   • `conversations` keeps its metric_key, so the existing session-based cap
 *     goes on working untouched.
 *
 * The NULL metric_key is load-bearing, not laziness. Setting it here with no
 * plan_features rows behind it would be worse than doing nothing: planLimit()
 * treats an absent feature as 0 — "not granted" — rather than as unlimited, so
 * every plan would resolve to an allowance of zero messages and the free tier
 * would hard-stop on its first reply. Verified against the live client, which
 * reported `allowance=0, allows_next=NO` when this row carried its metric_key.
 *
 * The ordering matters for the same reason in the other direction: clearing the
 * old cap in the same step as adding the new one leaves a window in which
 * nothing is capped at all, on a table whose whole job is capping. The
 * repricing migration seeds the per-plan numbers, sets this metric_key and
 * clears the one on `conversations` together, which is the only point at which
 * every plan has a message figure to be held to.
 */
return new class extends Migration
{
    private const KEY = 'messages';

    public function up(): void
    {
        if (DB::table('features')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('features')->insert([
            'key'         => self::KEY,
            'name'        => 'AI messages',
            'description' => 'One reply from the AI, on any channel. This is what your allowance is measured in.',
            'value_type'  => 'numeric',
            'unit'        => 'per month',
            'module_key'  => null,
            // NULL until the repricing migration seeds a message figure on every
            // plan. Claiming the metric now would cap every plan at zero — see
            // the note at the top of this file.
            'metric_key'  => null,
            'group'       => 'Volume',
            // Ahead of `conversations` (10): the message figure is the one that
            // binds, so it should read first in the comparison table.
            'sort_order'  => 5,
            'is_visible'  => 1,
            'is_headline' => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        // plan_features rows for this feature go with it, or the matrix keeps
        // orphans that reappear the moment the key is recreated.
        $id = DB::table('features')->where('key', self::KEY)->value('id');

        if ($id) {
            DB::table('plan_features')->where('feature_id', $id)->delete();
            DB::table('features')->where('id', $id)->delete();
        }
    }
};
