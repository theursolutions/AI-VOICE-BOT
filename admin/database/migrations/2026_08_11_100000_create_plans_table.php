<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription plans — the top of the database-driven pricing model.
 *
 * A plan carries NO price. Prices live in `plan_prices`, one row per billing
 * interval, so a plan can gain a quarterly price later without a migration.
 * Feature values live in `plan_features`. That three-table split is what lets
 * a super-admin change $19 -> $29, add a plan, or re-gate a feature with no
 * developer involvement.
 *
 * Timestamps here are standard Laravel datetimes, NOT the integer unix
 * timestamps used by the legacy master tables (clients/projects/roles).
 * See SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §5 C2 — new billing tables
 * deliberately sit on the modern convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('tagline', 255)->nullable();     // one-liner under the plan name
            $table->text('description')->nullable();

            // free      — the 7-day no-card window; never has a Stripe price
            // standard  — a normal self-serve paid plan
            // enterprise— "talk to us"; rendered as a CTA band, not a price card
            // custom    — a private, negotiated plan (is_public = false)
            $table->enum('type', ['free', 'standard', 'enterprise', 'custom', 'addon'])
                  ->default('standard')
                  ->index();

            $table->boolean('is_active')->default(true)->index();   // sellable at all
            $table->boolean('is_public')->default(true);            // visible on /pricing
            $table->boolean('is_featured')->default(false);         // the "most popular" card
            $table->unsignedInteger('sort_order')->default(0)->index();

            $table->string('badge', 40)->nullable();        // e.g. "Most popular"
            $table->string('cta_label', 60)->nullable();    // e.g. "Start free"
            $table->string('cta_url', 255)->nullable();     // enterprise → /contact

            // ── Trial ────────────────────────────────────────────────────
            // 0 on every plan under the approved model: the 7-day FREE window
            // replaces the paid trial. Kept configurable so a super-admin can
            // switch a paid-plan trial back on with no deploy.
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('trial_requires_payment_method')->default(true);

            // ── Free window (type = free only) ───────────────────────────
            // How many days of no-card access before the workspace degrades
            // to the configured on-expiry behaviour. NULL = permanent free.
            $table->unsignedSmallInteger('free_window_days')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();  // never hard-delete a plan someone is on
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
