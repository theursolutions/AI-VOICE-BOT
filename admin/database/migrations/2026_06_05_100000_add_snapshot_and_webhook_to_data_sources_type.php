<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend data_sources.type to include the new Tier-B and Tier-C
 * options (data_snapshot for CSV/JSON uploads, webhook for live
 * customer endpoints). The original migration enumerated only the
 * four original types.
 */
return new class extends Migration {
    public function up(): void
    {
        // Raw ALTER because Laravel's Blueprint can't redefine an enum.
        DB::statement("
            ALTER TABLE `data_sources`
            MODIFY COLUMN `type` ENUM(
                'website',
                'document',
                'data_snapshot',
                'webhook',
                'crm_oauth',
                'database',
                'agent'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        // Coerce any rows using the new types back to a safe value so
        // the narrower enum doesn't reject them.
        DB::statement("
            UPDATE `data_sources` SET `type` = 'document'
            WHERE `type` IN ('data_snapshot', 'webhook')
        ");
        DB::statement("
            ALTER TABLE `data_sources`
            MODIFY COLUMN `type` ENUM(
                'website', 'document', 'crm_oauth', 'database', 'agent'
            ) NOT NULL
        ");
    }
};
