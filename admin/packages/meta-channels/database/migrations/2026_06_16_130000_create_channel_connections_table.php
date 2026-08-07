<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connected Meta channels per project — the onboarding records behind the
 * Channels settings page. One row per WhatsApp number / Instagram account /
 * Facebook page. Inbound webhooks resolve the owning project (and whether
 * it's enabled) by matching `external_id`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('channel_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('provider', 32);             // whatsapp | instagram | facebook_page | messenger
            $table->string('external_id', 191)->nullable();   // phone_number_id / ig id / page id
            $table->string('name', 191)->nullable();
            $table->text('access_token')->nullable();   // encrypted at rest (cast)
            $table->string('status', 16)->default('enabled');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider', 'external_id']);
            $table->unique(['project_id', 'provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_connections');
    }
};
