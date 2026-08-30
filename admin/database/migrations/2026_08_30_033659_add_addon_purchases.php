<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a gateway charge buy something other than a plan.
 *
 * Until now every row in `gateway_charges` meant the same thing: somebody paid
 * for a plan, so put them on it. An add-on payment is a different event — it
 * must NOT move the plan, must NOT move the billing period, and must instead
 * raise an allowance. Without a way to tell the two apart, paying for two extra
 * seats would re-apply the plan and reset the period to the day the seats were
 * bought, quietly shortening what the customer had already paid for.
 *
 * `purpose` is therefore not decoration. It is the discriminator the applier
 * branches on, and it defaults to 'plan' so every existing row keeps meaning
 * exactly what it meant before this ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_charges', function (Blueprint $table) {
            $table->string('purpose', 16)->default('plan')->after('gateway');

            // What was bought, in enough detail to explain the amount a year
            // later: the add-on, how many, the unit price, and the proration
            // that produced a part-period figure. A customer asking "why
            // Rs 1,200?" has an answer without anyone recomputing it.
            $table->json('line_items')->nullable()->after('interval');

            $table->index(['client_id', 'purpose']);
        });

        Schema::table('subscription_addons', function (Blueprint $table) {
            // Which gateway the capacity was bought through. A Stripe add-on is
            // a live subscription item; a Safepay one is a one-off payment that
            // has to be re-charged at renewal. The renewal code needs to tell
            // them apart, and stripe_item_ref being null is not a reliable
            // signal — a Stripe item can fail to mint.
            $table->string('gateway', 24)->nullable()->after('stripe_item_ref');

            // Paddle keeps its own line item on its own subscription.
            $table->string('paddle_item_price_id', 64)->nullable()->after('gateway');

            // When one-off capacity stops. Null means "for as long as the
            // subscription runs", which is how a provider-billed add-on behaves.
            $table->timestamp('period_end')->nullable()->after('paddle_item_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_charges', function (Blueprint $table) {
            $table->dropIndex(['client_id', 'purpose']);
            $table->dropColumn(['purpose', 'line_items']);
        });

        Schema::table('subscription_addons', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'paddle_item_price_id', 'period_end']);
        });
    }
};
