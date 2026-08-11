<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metered usage per workspace per billing period.
 *
 * Metric keys come from config/billing.php `metrics`. The two voice meters
 * are deliberately separate:
 *
 *   telephony_minutes — Twilio number rental + carrier per-minute. Real money.
 *                       ZERO on the free plan.
 *   voice_messages    — a mic message in the web widget, served by local
 *                       Whisper + XTTS. Near-zero marginal cost, so the free
 *                       plan can include it.
 *
 * Collapsing those into one "voice" number would have forced the free plan to
 * choose between no microphone at all and giving away phone calls.
 *
 * `period_start`/`period_end` mirror the subscription's current period so a
 * quota resets exactly when the customer is re-billed, not on the 1st of the
 * month. Absolute metrics (storage, indexed pages) use a NULL period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('client_id')->index();
            // Optional attribution; quotas are enforced at the client level.
            $table->unsignedBigInteger('project_id')->nullable()->index();

            $table->string('metric', 60)->index();

            // NULL period = an absolute/high-water metric that never resets.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->unsignedBigInteger('used')->default(0);

            // Usage recorded ABOVE the plan allowance, kept separately so it
            // can be reported to Stripe as metered overage without having to
            // recompute the split at invoice time.
            $table->unsignedBigInteger('overage')->default(0);

            $table->timestamp('last_recorded_at')->nullable();
            $table->timestamps();

            // The upsert target for increments. A composite unique makes
            // "increment or create" a single atomic statement.
            $table->unique(['client_id', 'metric', 'period_start'], 'usage_counters_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
