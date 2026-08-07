<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend sessions.channel ENUM with 'internal' so the in-admin "Ask AI"
 * (Team Assistant) conversations are stored alongside customer chats and
 * surface in the Conversations page — tagged distinctly so an owner can
 * audit what their team members ask the bot. Existing rows untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::connection('tenant')->statement("
            ALTER TABLE `sessions`
            MODIFY COLUMN `channel` ENUM(
                'web', 'voice', 'phone', 'sms',
                'whatsapp', 'twilio', 'plivo', 'api',
                'instagram', 'facebook', 'messenger',
                'internal'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('tenant')->statement("
            UPDATE `sessions` SET `channel` = 'web' WHERE `channel` = 'internal'
        ");
        DB::connection('tenant')->statement("
            ALTER TABLE `sessions`
            MODIFY COLUMN `channel` ENUM(
                'web', 'voice', 'phone', 'sms',
                'whatsapp', 'twilio', 'plivo', 'api',
                'instagram', 'facebook', 'messenger'
            ) NOT NULL
        ");
    }
};
