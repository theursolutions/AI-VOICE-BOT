<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give every PRE-EXISTING workspace a grandfathered subscription.
 *
 * WHY THIS IS THE MOST DANGEROUS MIGRATION IN THE SET, and how it's defused:
 *
 * The obvious implementation — put existing workspaces on the free plan — would
 * silently start a 7-day countdown on every customer who signed up before
 * billing existed. Seven days after deploying, every one of them would have
 * their agent switched off. That is a catastrophic, self-inflicted outage.
 *
 * So existing workspaces get a row with:
 *   status        = 'free'
 *   free_ends_at  = NULL   → PERMANENT. Subscription::freeWindowHasElapsed()
 *                            treats NULL as "never ends".
 *   plan_id       = NULL   → PlanFeatureService fails OPEN on a null plan, so
 *                            they keep every feature they had yesterday.
 *
 * Net effect: nothing changes for anyone who already uses the product. Only
 * workspaces created AFTER this deploy get the 7-day window, via
 * SubscriptionService::startFreeWindow(). Migrating grandfathered accounts onto
 * a paid plan is then a deliberate commercial decision, made per customer from
 * the ops console — not a side effect of shipping code.
 *
 * `metadata.grandfathered` marks them so they can be found later:
 *   SELECT * FROM subscriptions WHERE JSON_EXTRACT(metadata,'$.grandfathered') = true
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients') || ! Schema::hasTable('subscriptions')) {
            return;
        }

        $now = now();

        // Only clients with no subscription row at all.
        $clientIds = DB::table('clients')
            ->whereNull('deleted_at')
            ->whereNotIn('id', function ($q) {
                $q->select('client_id')->from('subscriptions');
            })
            ->pluck('id');

        if ($clientIds->isEmpty()) {
            return;
        }

        foreach ($clientIds->chunk(200) as $chunk) {
            $rows = [];

            foreach ($chunk as $clientId) {
                $rows[] = [
                    'client_id'       => $clientId,
                    'plan_id'         => null,          // fails open on features
                    'plan_price_id'   => null,
                    'type'            => 'default',
                    'status'          => 'free',
                    'free_started_at' => $now,
                    'free_ends_at'    => null,          // PERMANENT — the whole point
                    'currency'        => 'usd',
                    'quantity'        => 1,
                    'metadata'        => json_encode([
                        'grandfathered' => true,
                        'reason'        => 'Existed before billing was introduced. Permanent free access until migrated deliberately.',
                        'backfilled_at' => $now->toDateTimeString(),
                    ]),
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }

            DB::table('subscriptions')->insert($rows);
        }

        // Keep the derived cache on `clients` consistent with what we just
        // wrote, so the access gate reads 'active' rather than a NULL default.
        DB::table('clients')
            ->whereIn('id', $clientIds)
            ->update([
                'billing_status'    => 'free',
                'access_state'      => 'active',
                'billing_synced_at' => $now,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('subscriptions')) {
            return;
        }

        // Remove ONLY the rows this migration created. A customer who has since
        // subscribed for real must never be touched by a rollback.
        DB::table('subscriptions')
            ->where('status', 'free')
            ->whereNull('free_ends_at')
            ->whereNull('stripe_subscription_ref')
            ->where('metadata', 'like', '%"grandfathered":true%')
            ->delete();
    }
};
