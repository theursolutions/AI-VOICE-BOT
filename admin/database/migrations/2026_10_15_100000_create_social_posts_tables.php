<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social Posts: content a project publishes to its connected Facebook Pages,
 * Instagram accounts and YouTube channels, now or on a schedule.
 *
 * Lives in the app DB next to channel_connections (not the tenant DB) so the
 * every-minute publisher can sweep every project's due posts in one query
 * without switching connections.
 *
 * One post fans out to one target row per account. Each target succeeds or
 * fails on its own — Instagram rejecting an aspect ratio must not hide that
 * the Facebook copy went out.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Accounts on platforms outside Meta (YouTube now, TikTok later).
        // Meta accounts stay in channel_connections, which the inbox shares;
        // these have nothing to do with messaging and their tokens refresh on
        // a different OAuth, so they get their own table rather than a fake
        // "provider" inside the Meta package's.
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('provider', 32);                     // youtube
            $table->string('external_id', 191);                 // YouTube channel id
            $table->string('name', 191)->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->text('access_token')->nullable();           // encrypted at rest (cast)
            $table->text('refresh_token')->nullable();          // encrypted at rest (cast)
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->string('status', 16)->default('enabled');   // enabled | reconnect
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'provider', 'external_id']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('format', 16)->default('post');     // post | reel | story
            $table->string('title', 100)->nullable();           // YouTube video title
            $table->text('caption')->nullable();
            $table->string('link', 500)->nullable();            // Facebook link preview
            $table->json('media')->nullable();                  // [{path, type, mime, size}]
            $table->json('options')->nullable();                // {youtube_privacy}
            $table->string('status', 16)->default('draft');     // draft | scheduled | publishing | published | partial | failed
            $table->timestamp('scheduled_at')->nullable();      // UTC
            $table->timestamp('published_at')->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['project_id', 'id']);
        });

        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            // Exactly one of these is set: a Meta account or another platform's.
            $table->unsignedBigInteger('channel_connection_id')->nullable()->index();
            $table->unsignedBigInteger('social_account_id')->nullable()->index();
            $table->string('provider', 32);                     // facebook_page | instagram | youtube
            $table->string('account_name', 191)->nullable();    // kept if the account is removed later
            $table->string('status', 16)->default('pending');   // pending | processing | published | failed
            $table->json('state')->nullable();                  // Instagram container ids between ticks; notes
            $table->string('remote_id', 191)->nullable();
            $table->string('permalink', 500)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
    }
};
