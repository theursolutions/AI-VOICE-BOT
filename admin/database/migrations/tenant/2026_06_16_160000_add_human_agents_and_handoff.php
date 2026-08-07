<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live-agent / human-handoff support.
 *
 *  agents:
 *    - type             ai | human  (an "agent" is now either an AI persona
 *                       or a real person who takes over chats)
 *    - user_id          app users.id the human agent logs in as
 *    - presence         online | away | offline  (a human's availability —
 *                       this is the unit of human capacity)
 *    - max_active_chats how many concurrent chats this human can hold
 *
 *  sessions:
 *    - assigned_agent_id  the human agent currently handling the chat
 *    - handoff_status     bot | queued | assigned | resolved
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('tenant')->table('agents', function (Blueprint $table) {
            $table->string('type', 10)->default('ai')->after('name');
            $table->unsignedBigInteger('user_id')->nullable()->after('type');
            $table->string('presence', 10)->default('offline')->after('user_id');
            $table->integer('max_active_chats')->default(3)->after('presence');
            $table->index(['project_id', 'type', 'presence']);
        });

        Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_agent_id')->nullable()->after('channel_account');
            $table->string('handoff_status', 12)->default('bot')->after('assigned_agent_id');
            $table->index(['handoff_status']);
            $table->index('assigned_agent_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('agents', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'type', 'presence']);
            $table->dropColumn(['type', 'user_id', 'presence', 'max_active_chats']);
        });
        Schema::connection('tenant')->table('sessions', function (Blueprint $table) {
            $table->dropIndex(['handoff_status']);
            $table->dropIndex(['assigned_agent_id']);
            $table->dropColumn(['assigned_agent_id', 'handoff_status']);
        });
    }
};
