<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen the tenant `leads.status` enum so the statuses the admin UI already
 * offers ("contacted", "disqualified") can actually be stored. The original
 * enum only allowed new/qualified/converted/rejected, so updating a lead to
 * "contacted" threw: SQLSTATE[01000] Data truncated for column 'status'.
 *
 * We keep the legacy value "rejected" in the set so existing rows that still
 * hold it are not truncated by the ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `leads` MODIFY `status` "
            . "ENUM('new','contacted','qualified','converted','disqualified','rejected') "
            . "NOT NULL DEFAULT 'new'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE `leads` MODIFY `status` "
            . "ENUM('new','qualified','converted','rejected') "
            . "NOT NULL DEFAULT 'new'"
        );
    }
};
