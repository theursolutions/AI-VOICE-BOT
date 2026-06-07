<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many pivot: which skills each agent handles, with an
 * optional priority knob for tie-breaking when multiple agents in a
 * skill are available simultaneously.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->create('agent_skills', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('skill_id');
            $table->tinyInteger('priority')->default(0);    // higher = picked first within the pool
            $table->integer('created_at')->nullable();

            $table->primary(['agent_id', 'skill_id']);
            $table->index('skill_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('agent_skills');
    }
};
