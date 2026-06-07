<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI agents — each is a persona with its own cloned voice, system
 * prompt, and one-or-more skills it handles. Multiple agents can
 * serve the same skill (load distribution + persona variety).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('agents', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->integer('project_id');
            $table->string('name', 120);

            // Voice this agent speaks with. Nullable so an agent can
            // exist without a cloned voice (uses project default then).
            $table->unsignedBigInteger('voice_id')->nullable();

            // Free-text persona / system-prompt fragment. Injected into
            // the LLM messages before the conversation so the bot
            // behaves consistently as this character.
            $table->text('persona')->nullable();

            // Used by the widget when the project has more than one
            // agent but no explicit routing — falls back to the default.
            $table->boolean('is_default')->default(false);

            // active = available to pick    archived = retired
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('update_at')->nullable();
            $table->integer('deleted_at')->nullable();
            $table->enum('is_active', ['Yes', 'No'])->default('Yes');

            $table->index(['project_id', 'status']);
            $table->index('voice_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('agents');
    }
};
