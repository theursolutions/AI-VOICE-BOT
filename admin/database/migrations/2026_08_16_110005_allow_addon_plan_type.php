<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `plans.type` to accept 'addon'.
 *
 * The column was created as an ENUM of the four plan kinds that existed at the
 * time. MySQL doesn't reject an unknown enum value with a clear error — it
 * raises a *truncation warning* and stores an empty string, so without this
 * the add-on migration fails with "Data truncated for column 'type'" rather
 * than anything that names the real problem.
 *
 * Raw ALTER rather than a Blueprint change(): modifying an enum needs
 * doctrine/dbal, which this project doesn't install, and dbal historically
 * mangles enums into varchars anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        DB::statement(
            "ALTER TABLE `plans`
             MODIFY `type` ENUM('free','standard','enterprise','custom','addon')
             NOT NULL DEFAULT 'standard'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }

        // Anything left on the removed value would be truncated to '' by the
        // ALTER, so retire those rows first rather than corrupting them.
        DB::table('plans')->where('type', 'addon')->update(['is_active' => false, 'type' => 'custom']);

        DB::statement(
            "ALTER TABLE `plans`
             MODIFY `type` ENUM('free','standard','enterprise','custom')
             NOT NULL DEFAULT 'standard'"
        );
    }
};
