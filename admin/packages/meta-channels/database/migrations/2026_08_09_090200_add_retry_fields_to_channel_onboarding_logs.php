<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes an onboarding attempt retryable and diagnosable at a glance.
 *
 *   method       how it was started (redirect / embedded_signup / qr_handoff)
 *                — the three flows fail in different places, and "which one
 *                was this?" was previously unanswerable after the fact
 *   payload_id   the stored Meta response this attempt can be replayed from
 *   retry_of_id  chains a retry back to the attempt it re-ran
 *   attempt      1 for the original, 2+ for retries
 *   error_code   machine-readable failure class, so the UI can show the user
 *                what to actually DO rather than a raw Graph error string
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('channel_onboarding_logs', function (Blueprint $table) {
            $table->string('method', 24)->default('redirect')->after('provider');
            $table->unsignedBigInteger('payload_id')->nullable()->index()->after('method');
            $table->unsignedBigInteger('retry_of_id')->nullable()->index()->after('payload_id');
            $table->unsignedTinyInteger('attempt')->default(1)->after('retry_of_id');
            $table->string('error_code', 64)->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('channel_onboarding_logs', function (Blueprint $table) {
            $table->dropIndex(['payload_id']);
            $table->dropIndex(['retry_of_id']);
            $table->dropColumn(['method', 'payload_id', 'retry_of_id', 'attempt', 'error_code']);
        });
    }
};
