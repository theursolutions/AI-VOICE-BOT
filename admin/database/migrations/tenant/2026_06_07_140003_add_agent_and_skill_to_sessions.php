<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lock each session to the agent + skill it was routed to so
 * mid-conversation handoffs are explicit and transcripts make sense
 * later when admins look back.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->nullable()->after('voice_id');
            $table->unsignedBigInteger('skill_id')->nullable()->after('agent_id');
            $table->index('agent_id');
            $table->index('skill_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
            $table->dropColumn(['agent_id', 'skill_id']);
        });
    }
};
