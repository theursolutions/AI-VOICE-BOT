<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace invitations. Cross-tenant configuration that lives on the master DB
 * (alongside `clients`, `users`, `project_users`).
 *
 * Lifecycle is encoded entirely via dates:
 *   pending   → accepted_at IS NULL AND revoked_at IS NULL AND expires_at > now
 *   accepted  → accepted_at IS NOT NULL
 *   revoked   → revoked_at  IS NOT NULL
 *   expired   → accepted_at IS NULL AND revoked_at IS NULL AND expires_at <= now
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('client_id');
            $table->integer('invited_by');
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->integer('expires_at');
            $table->integer('accepted_at')->nullable();
            $table->integer('accepted_by_user_id')->nullable();
            $table->integer('revoked_at')->nullable();

            // Project-wide audit columns
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->nullable()->default('Yes');

            // Composite index for the "is this still pending?" question
            // — the lifecycle is encoded via accepted_at/revoked_at/expires_at,
            // so the index covers the typical "list pending invites for client" lookup.
            $table->index(['client_id', 'accepted_at', 'revoked_at', 'expires_at'], 'invitations_client_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
