<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paddle's own identifiers for the things we already have rows for.
 *
 * A price in Paddle is a separate object with its own id, exactly as in Stripe,
 * and a transaction can only be created against one. Without this column there
 * is no way to say "the Growth monthly plan is THIS Paddle price", so a
 * separate column rather than a metadata key: it is looked up on every
 * international checkout, it must be unique, and a JSON key can be neither
 * indexed nor constrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->string('paddle_price_id', 64)->nullable()->after('stripe_synced_at');
            $table->timestamp('paddle_synced_at')->nullable()->after('paddle_price_id');

            // Two plan prices pointing at one Paddle price would make a
            // customer's invoice disagree with the plan they think they bought.
            $table->unique('paddle_price_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Paddle bills recurring subscriptions itself, so a renewal arrives
            // as a webhook naming a subscription we did not raise a charge for.
            // This is the only way to map it back to a workspace.
            $table->string('paddle_subscription_id', 64)->nullable()->after('stripe_status');
            $table->string('paddle_customer_id', 64)->nullable()->after('paddle_subscription_id');

            $table->index('paddle_subscription_id');
        });

        Schema::table('gateway_charges', function (Blueprint $table) {
            $table->string('paddle_subscription_id', 64)->nullable()->after('gateway_ref');
        });
    }

    public function down(): void
    {
        Schema::table('plan_prices', function (Blueprint $table) {
            $table->dropUnique(['paddle_price_id']);
            $table->dropColumn(['paddle_price_id', 'paddle_synced_at']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['paddle_subscription_id']);
            $table->dropColumn(['paddle_subscription_id', 'paddle_customer_id']);
        });

        Schema::table('gateway_charges', function (Blueprint $table) {
            $table->dropColumn('paddle_subscription_id');
        });
    }
};
