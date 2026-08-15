<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each project's own Twilio credentials.
 *
 * Every customer brings their own Twilio account and buys their own number
 * there, so the platform credentials in .env are NOT the general case — they
 * belong to the "Serve AI" demo project and the landing-page demo call, and
 * nothing else.
 *
 * ── Why an auth token needs care ─────────────────────────────────────────
 *
 * A Twilio auth token can spend the customer's money and read every call
 * recording and message on their account. So it is encrypted at rest via
 * Laravel's `encrypted` cast (APP_KEY), never rendered back to the browser,
 * and never logged. `auth_token_hint` holds the last four characters so the
 * UI can show "…f4c1" for recognition without decrypting anything.
 *
 * Losing APP_KEY makes these unreadable — that is the intended trade, but it
 * means APP_KEY belongs in your backup and rotation plan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_twilio_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->unique();

            // Indexed: every inbound webhook looks the account up by this to
            // find the token that signed it.
            $table->string('account_sid', 40)->index();
            $table->text('auth_token')->nullable();          // encrypted
            $table->string('auth_token_hint', 8)->nullable(); // display only

            // Read back from Twilio when the credentials are saved, so the UI
            // can warn about a trial account (which can only reach verified
            // numbers) before the customer finds out on a live call.
            $table->string('friendly_name', 120)->nullable();
            $table->string('account_type', 20)->nullable();   // Trial | Full

            $table->string('status', 20)->default('connected'); // connected | invalid
            $table->string('last_error', 255)->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_twilio_accounts');
    }
};
