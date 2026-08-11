<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue of things a plan can grant. Values are per-plan and live in
 * `plan_features`; this table only defines what a feature IS.
 *
 * `value_type` decides how the string in plan_features.value is interpreted:
 *
 *   boolean   "1" / "0"          → API access = Yes
 *   numeric   "10"               → 10 seats
 *   unlimited (value ignored)    → Unlimited agents
 *   text      "Priority support" → free-form line on the pricing card
 *
 * Two optional links tie a feature to the rest of the app:
 *
 *   module_key — a key from config/modules.php. EnsurePlanFeature uses it to
 *                gate the matching admin routes, so plan entitlements reuse
 *                the same 17 module keys RBAC already knows about.
 *   metric_key — a key from config/billing.php `metrics`. UsageLimitService
 *                uses it to turn a numeric feature into an enforced quota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('key', 80)->unique();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();

            $table->enum('value_type', ['boolean', 'numeric', 'unlimited', 'text'])
                  ->default('boolean');

            // "conversation", "minute", "seat", "GB" — rendered after a number.
            $table->string('unit', 40)->nullable();

            // Gate the matching admin module (config/modules.php key).
            $table->string('module_key', 60)->nullable()->index();

            // Enforce as a usage quota (config/billing.php metrics key).
            $table->string('metric_key', 60)->nullable()->index();

            // Section heading on the pricing-page comparison table.
            $table->string('group', 80)->nullable()->index();

            $table->unsignedInteger('sort_order')->default(0)->index();

            // Show in the public feature-comparison table?
            $table->boolean('is_visible')->default(true);
            // Show as a bullet on the plan card itself (the short list)?
            $table->boolean('is_headline')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
