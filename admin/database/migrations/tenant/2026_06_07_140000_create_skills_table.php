<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skills (a.k.a. queues / categories) for routing calls + chats.
 *
 * Each project defines its own skills — e.g. Billing, Tech Support,
 * Sales — and assigns agents to skills (many-to-many via agent_skills).
 * Telephony / widget routing then picks an agent from a skill's pool.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('skills', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->integer('sla_seconds')->nullable();           // future use
            $table->boolean('is_default')->default(false);        // fallback skill for the project
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->default('Yes');

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('skills');
    }
};
