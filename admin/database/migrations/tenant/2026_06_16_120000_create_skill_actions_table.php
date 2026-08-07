<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many pivot turning a skill into a *capability bundle*: it links
 * a skill to the webhook "action" tools it grants. An agent assigned the
 * skill can invoke exactly the actions linked here.
 *
 * `data_source_id` references `data_sources.id`, which lives in the SHARED
 * (app) DB — not the tenant DB — so there is intentionally NO foreign-key
 * constraint here. The link is logical; ToolPicker / WebhookResolver
 * resolve it within a project context (both tables are project-scoped).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('skill_actions', function (Blueprint $table) {
            $table->unsignedBigInteger('skill_id');
            $table->unsignedBigInteger('data_source_id'); // -> app DB data_sources.id (a webhook tool)
            $table->integer('created_at')->nullable();

            $table->primary(['skill_id', 'data_source_id']);
            $table->index('data_source_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('skill_actions');
    }
};
