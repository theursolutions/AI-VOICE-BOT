<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-hosted query agents (Tier 3b).
 *
 *   enrollment_token  — one-time bearer used by `query-agent enroll` to
 *                       exchange for the long-lived auth token.
 *   token_hash        — SHA-256 hash of the long-lived bearer token the
 *                       agent uses for all subsequent /agent/* calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');
            $table->string('name');
            $table->string('agent_uid', 64)->unique();
            $table->string('enrollment_token', 80)->nullable()->unique();
            $table->integer('enrollment_token_expires_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->integer('enrolled_at')->nullable();
            $table->integer('last_seen_at')->nullable();
            $table->string('client_version', 32)->nullable();
            $table->enum('status', ['pending', 'active', 'revoked'])->default('pending');
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->nullable()->default('Yes');

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
