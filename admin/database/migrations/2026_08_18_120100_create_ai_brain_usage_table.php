<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily token usage per brain, per project, per call type.
 *
 * Two jobs at once, which is why it is a rollup rather than a log:
 *
 *   Quota    ai_brains.tokens_used is the running counter the resolver reads on
 *            every call, so it has to be a single cheap read. This table is the
 *            audit trail behind that number — where the tokens went and when.
 *
 *   Costing  it answers "what does this client actually cost us", which nothing
 *            could answer before. Split by call_type because a customer message
 *            is three calls (route / reply / capture) with very different
 *            profiles, and knowing which one dominates is what tells you where
 *            the next optimisation belongs.
 *
 * One row per (brain, project, date, call_type). A busy client is a handful of
 * rows a day, not one per message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_brain_usage', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('brain_id');
            $table->unsignedBigInteger('project_id')->nullable();

            // Stored as a date string rather than a timestamp: every read of this
            // table groups by day, and a DATE makes that an index scan instead of
            // arithmetic on every row.
            $table->date('usage_date');

            // route | reply | capture | summary | other
            $table->string('call_type', 20)->default('other');

            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->unsignedInteger('calls')->default(0);

            // Calls that never produced tokens — a dead key, an exhausted quota,
            // a timeout. Counted here because a brain silently failing looks
            // exactly like a brain nobody used, and those need opposite responses.
            $table->unsignedInteger('failures')->default(0);

            $table->integer('updated_at')->nullable();

            $table->unique(['brain_id', 'project_id', 'usage_date', 'call_type'], 'ai_brain_usage_unq');
            $table->index(['project_id', 'usage_date'], 'ai_brain_usage_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_brain_usage');
    }
};
