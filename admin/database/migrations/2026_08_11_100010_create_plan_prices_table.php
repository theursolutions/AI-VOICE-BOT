<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (plan × billing interval). Append-only in spirit.
 *
 * WHY APPEND-ONLY: Stripe Prices are immutable. Raising $19 -> $29 must NOT
 * edit this row — it creates a NEW row with a NEW stripe_price_id and marks
 * the old one inactive + archived. Existing subscribers keep pointing at the
 * old row via `subscriptions.plan_price_id`, so they are grandfathered
 * automatically and new signups get the new price. That behaviour is the
 * whole reason this table exists separately from `plans`.
 *
 * Money is an INTEGER count of USD cents. Never a float — the legacy
 * `payment_plans.price` float column is exactly the mistake being avoided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plan_id')->index();

            // monthly | quarterly | annually. Stored as a string rather than
            // an enum so a new interval is a data change, not a migration.
            $table->string('interval', 20)->index();

            // Always 'usd'. Present so the column can never be misread as
            // "the customer's currency" — it is the CHARGE currency.
            $table->string('currency', 3)->default('usd');

            // Integer minor units. $19.00 => 1900.
            $table->unsignedInteger('unit_amount');

            // Optional strike-through anchor for the pricing page, e.g. show
            // the monthly-equivalent crossed out beside an annual price.
            $table->unsignedInteger('compare_at_amount')->nullable();

            // ── Stripe linkage ───────────────────────────────────────────
            // NOT named *_id: App\Http\Middleware\DecodeHashids rewrites any
            // request key matching `*_id` through the hashid decoder, which
            // would corrupt a posted Stripe reference. See ANALYSIS §5 C1.
            $table->string('stripe_price_ref', 120)->nullable()->unique();
            $table->string('stripe_product_ref', 120)->nullable()->index();
            // Test-mode and live-mode Stripe objects have different ids.
            // Recording which mode minted this ref lets the sync command warn
            // instead of silently checking out against a test price in prod.
            $table->boolean('stripe_livemode')->nullable();
            $table->timestamp('stripe_synced_at')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            // At most one ACTIVE price per plan+interval is enforced in
            // PlanService (a partial unique index isn't portable to MySQL).
            $table->index(['plan_id', 'interval', 'is_active'], 'plan_prices_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
    }
};
