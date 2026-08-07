<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per onboarding attempt (Facebook Login / Embedded Signup). Records
 * every step so a failed onboarding can be diagnosed and retried — you can
 * see exactly where it broke (consent denied, token exchange, discovery, …).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('channel_onboarding_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();   // who started it
            $table->string('provider', 32);                      // whatsapp|instagram|facebook_page
            $table->string('status', 16)->default('started');    // started|success|failed
            $table->json('steps')->nullable();                   // [{step, ok, detail}]
            $table->json('result')->nullable();                  // onboarded channels summary
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_onboarding_logs');
    }
};
