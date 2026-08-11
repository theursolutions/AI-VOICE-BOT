<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * USD -> local currency rates, for APPROXIMATE DISPLAY ONLY.
 *
 * Nothing here ever reaches Stripe. Stripe charges the USD amount in
 * `plan_prices.unit_amount`, full stop.
 *
 * This table is the DURABLE fallback tier. The read path is:
 *
 *     cache  ->  this table  ->  null (render USD only, no local line)
 *
 * Without the middle tier, a cold cache after a deploy plus an FX provider
 * outage would blank the local price for every visitor. With it, we show a
 * slightly stale rate — which is fine, because the number is explicitly
 * labelled approximate — and only fall back to USD-only once the stored rate
 * passes `fx.max_age_hours`.
 *
 * The pricing page NEVER writes here. `php artisan billing:refresh-rates`
 * (scheduled) is the only writer, so no user request can be slowed or broken
 * by a third-party API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('base', 3)->default('USD');
            $table->string('currency', 3);

            // 1 base unit = `rate` of `currency`. Wide enough for IDR/VND
            // (tens of thousands) and precise enough for KWD/BHD (~0.3).
            $table->decimal('rate', 20, 8);

            $table->string('provider', 40)->nullable();
            $table->timestamp('fetched_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['base', 'currency'], 'exchange_rates_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
