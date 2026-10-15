<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A lead no longer has to come from a conversation.
 *
 * Lead Generation's "Add to CRM" creates a lead for a business nobody has
 * talked to yet, so `session_id` becomes nullable. Purely widening: every
 * existing row already has a session and keeps it, and the lead views
 * already guard on `$lead->session_id` before linking to a session.
 *
 * Raw ALTER (as in the status-enum migration) because doctrine/dbal isn't
 * installed, so Blueprint::change() isn't available.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE `leads` MODIFY `session_id` BIGINT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        // Only reversible while no session-less lead exists.
        if (DB::table('leads')->whereNull('session_id')->exists()) {
            throw new RuntimeException('Leads without a session exist; delete or attach them before rolling back.');
        }
        DB::statement('ALTER TABLE `leads` MODIFY `session_id` BIGINT NOT NULL');
    }
};
