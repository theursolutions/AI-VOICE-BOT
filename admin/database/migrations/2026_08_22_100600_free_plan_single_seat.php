<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Free gets one seat, not two.
 *
 * A second seat on a free 7-day window is a team feature given away before
 * anyone has paid for anything, and it is the one allowance a trialling
 * workspace does not need: the person evaluating the product is the person who
 * signed up. Inviting a colleague is a reason to be on Starter.
 */
return new class extends Migration
{
    private const PLAN = 'free';
    private const FEATURE = 'seats';

    public function up(): void
    {
        $this->setSeats('1');
    }

    public function down(): void
    {
        $this->setSeats('2');
    }

    private function setSeats(string $value): void
    {
        $planId    = DB::table('plans')->where('slug', self::PLAN)->value('id');
        $featureId = DB::table('features')->where('key', self::FEATURE)->value('id');

        if (! $planId || ! $featureId) {
            return;
        }

        DB::table('plan_features')
            ->where('plan_id', $planId)
            ->where('feature_id', $featureId)
            ->update(['value' => $value, 'updated_at' => now()]);

        // The resolved entitlement set is cached per plan, so an edit here is
        // invisible until it is dropped.
        app(\App\Services\Billing\PlanFeatureService::class)->flush($planId);
    }
};
