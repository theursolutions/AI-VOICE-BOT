<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a specific plan grants for a specific feature.
 *
 * `value` is a string for every value_type so one column covers booleans,
 * numbers, unlimited and free text. Interpretation is driven by
 * features.value_type — see PlanFeatureService::resolve().
 *
 * A MISSING ROW MEANS "NOT GRANTED". That default matters: adding a new
 * feature to the catalogue never accidentally hands it to every existing
 * plan, which is the safe direction for a billing system to fail in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_features', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plan_id')->index();
            $table->unsignedBigInteger('feature_id')->index();

            // boolean   → "1" / "0"
            // numeric   → "5000"
            // unlimited → NULL (or "-1"); both read as unlimited
            // text      → "Priority email"
            $table->string('value', 255)->nullable();

            // Bold this line on the plan card (e.g. the headline quota).
            $table->boolean('is_highlighted')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['plan_id', 'feature_id'], 'plan_features_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
