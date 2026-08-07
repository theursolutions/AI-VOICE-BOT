<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend sessions.channel ENUM with the Meta chat channels
 * ('instagram', 'facebook', 'messenger') so Instagram / Facebook Page
 * conversations can be stored alongside WhatsApp and web. Existing values
 * are preserved; old rows are untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::connection('tenant')->statement("
            ALTER TABLE `sessions`
            MODIFY COLUMN `channel` ENUM(
                'web', 'voice', 'phone', 'sms',
                'whatsapp', 'twilio', 'plivo', 'api',
                'instagram', 'facebook', 'messenger'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('tenant')->statement("
            UPDATE `sessions` SET `channel` = 'web'
            WHERE `channel` IN ('instagram', 'facebook', 'messenger')
        ");
        DB::connection('tenant')->statement("
            ALTER TABLE `sessions`
            MODIFY COLUMN `channel` ENUM(
                'web', 'voice', 'phone', 'sms',
                'whatsapp', 'twilio', 'plivo', 'api'
            ) NOT NULL
        ");
    }
};
