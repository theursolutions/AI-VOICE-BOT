<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-ons bought on top of a plan — extra seats, extra AI agents.
 *
 * MODELLED AS PLANS, deliberately. An add-on is a row in `plans` with
 * `type = 'addon'`, priced through `plan_prices` exactly like everything else.
 * That means it inherits, for free:
 *
 *   • Stripe product/price sync and the immutable-price versioning
 *   • the Super Admin editor (price changes, activate/deactivate, archive)
 *   • monthly/annual intervals
 *
 * and — the useful part — its `plan_features` rows declare what ONE unit
 * grants. "Extra seat" carries `seats = 1`, so the effective allowance is
 * simply the base plan's value plus the quantity bought. No second concept of
 * "what does this add-on do"; it's the same feature system.
 *
 * This table is the join: which add-ons a subscription holds, and how many.
 * One row per (subscription, add-on), quantity carrying the count — mirroring
 * how Stripe models it as subscription ITEMS on the one subscription, so a
 * customer gets a single invoice rather than one per add-on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_addons', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('subscription_id')->index();
            $table->unsignedBigInteger('client_id')->index();     // denormalised: every lookup is per workspace
            $table->unsignedBigInteger('plan_id')->index();       // the add-on plan
            $table->unsignedBigInteger('plan_price_id')->nullable()->index();

            $table->unsignedInteger('quantity')->default(1);

            // The Stripe subscription ITEM, not a price. Not named *_id:
            // DecodeHashids rewrites request keys matching `*_id`, and this
            // value round-trips through forms. See ANALYSIS §5 C1.
            $table->string('stripe_item_ref', 120)->nullable()->unique();

            // Snapshot of what was charged, so an archived price still reports
            // truthfully on the billing page (same reasoning as
            // subscriptions.unit_amount).
            $table->unsignedInteger('unit_amount')->nullable();
            $table->string('currency', 3)->default('usd');
            $table->string('interval', 20)->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // One line per add-on per subscription; buying more raises the
            // quantity rather than adding a second row.
            $table->unique(['subscription_id', 'plan_id'], 'subscription_addons_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_addons');
    }
};
