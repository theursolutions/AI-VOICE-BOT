<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything Meta hands back during onboarding, persisted the moment it
 * arrives — before we try to do anything with it.
 *
 * Why: the expensive, user-visible part of onboarding is the trip to
 * Facebook (consent, picking pages, verifying a number). If our own import
 * then fails — Graph rate limit, a missing scope, a DB hiccup — the old
 * flow had nothing to resume from and the customer had to walk the whole
 * Meta consent journey again. Now the callback writes the code, both
 * tokens and the raw discovery response here first, so a retry replays our
 * side only.
 *
 * Timing matters: an OAuth `code` is single-use and dies in ~10 minutes,
 * so it is useless as a retry anchor. The long-lived token is good for ~60
 * days, which is why the callback exchanges for it immediately rather than
 * lazily — that exchange is what makes retry meaningfully possible at all.
 *
 * Everything token-shaped is encrypted at rest (see the model's casts) and
 * purged once consumed or expired.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('channel_onboarding_payloads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('log_id')->nullable()->index();

            $table->string('provider', 32);                  // whatsapp | instagram | facebook_page
            $table->string('method', 24)->default('redirect'); // redirect | embedded_signup

            // Raw material from Meta, in the order it becomes available.
            $table->text('auth_code')->nullable();           // encrypted; single-use, ~10 min
            // Must be replayed byte-identical on the token exchange — Meta
            // rejects a mismatch — so it is stored rather than recomputed.
            $table->string('redirect_uri', 500)->nullable();
            $table->text('short_lived_token')->nullable();   // encrypted; ~1-2 h
            $table->text('long_lived_token')->nullable();    // encrypted; ~60 d — the retry anchor
            $table->timestamp('token_expires_at')->nullable();
            $table->json('token_scopes')->nullable();

            // Embedded Signup hands these straight to the browser.
            $table->string('waba_id', 64)->nullable();
            $table->string('phone_number_id', 64)->nullable();

            // Raw Graph discovery response. Encrypted: page/IG entries carry
            // their own access tokens.
            $table->text('discovery')->nullable();

            // received → tokenized → discovered → imported (terminal) | failed
            $table->string('status', 16)->default('received')->index();
            $table->string('error_code', 64)->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            // When the stored credentials stop being usable — after this a
            // retry genuinely does need the customer to revisit Meta.
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_onboarding_payloads');
    }
};
