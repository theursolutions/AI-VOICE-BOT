<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend sessions.channel ENUM to include 'phone' for Twilio /
 * Media Streams calls. Existing values stay; old data isn't touched.
 *
 * This migration lives under database/migrations/tenant/ which is
 * applied to every per-project tenant DB (the sessions table is
 * tenant-scoped).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::connection('tenant')->statement("
            ALTER TABLE `sessions`
            MODIFY COLUMN `channel` ENUM(
                'web', 'voice', 'phone', 'sms',
                'whatsapp', 'twilio', 'plivo', 'api'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('tenant')->statement("
            UPDATE `sessions` SET `channel` = 'web' WHERE `channel` IN ('phone', 'voice', 'sms')
        ");
        DB::connection('tenant')->statement("
            ALTER TABLE `sessions`
            MODIFY COLUMN `channel` ENUM(
                'web', 'whatsapp', 'twilio', 'plivo', 'api'
            ) NOT NULL
        ");
    }
};
