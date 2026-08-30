<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend sessions.channel ENUM with 'email' so a mailbox conversation can be
 * stored alongside WhatsApp / Instagram / Facebook / web. Existing values
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
                'instagram', 'facebook', 'messenger',
                'internal', 'email'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('tenant')->statement("
            UPDATE `sessions` SET `channel` = 'web'
            WHERE `channel` = 'email'
        ");
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
};
