<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend voices.provider ENUM with 'google': ready-made Google voices
 * (Gemini text-to-speech) live beside cloned ones. For a Google voice,
 * `external_id` holds the Google voice name ("Kore") and there is no
 * reference audio file.
 *
 * Lives under database/migrations/tenant/ — applied to every per-project
 * tenant DB by `php artisan tenant:migrate`.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::connection('tenant')->statement("
            ALTER TABLE `voices`
            MODIFY COLUMN `provider` ENUM('coqui', 'elevenlabs', 'google') NOT NULL
        ");
    }

    public function down(): void
    {
        DB::connection('tenant')->statement("DELETE FROM `voices` WHERE `provider` = 'google'");
        DB::connection('tenant')->statement("
            ALTER TABLE `voices`
            MODIFY COLUMN `provider` ENUM('coqui', 'elevenlabs') NOT NULL
        ");
    }
};
