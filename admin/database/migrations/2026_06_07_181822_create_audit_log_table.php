<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only record of super-admin actions. Every sensitive
     * operation in /admin/* writes a row here. Never updated, never
     * deleted by app code.
     *
     *   action       : machine code (impersonate.start, client.suspend, …)
     *   actor_id     : the super-admin user who ran it
     *   target_type  : 'user' | 'client' | 'project' | null
     *   target_id    : FK to the affected entity (loose ref — kept as int)
     *   payload      : free-form JSON for context (search query, IP, etc)
     *   ip / user_agent : forensic trail
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('action', 80);
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('target_type', 32)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unsignedInteger('created_at')->index();

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
