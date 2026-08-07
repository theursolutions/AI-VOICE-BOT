<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations can carry the role the invitee will receive on accept,
 * so a teammate joins with the right permissions out of the gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('invitations', 'role_id')) {
            Schema::table('invitations', function (Blueprint $table) {
                $table->integer('role_id')->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invitations', 'role_id')) {
            Schema::table('invitations', function (Blueprint $table) {
                $table->dropColumn('role_id');
            });
        }
    }
};
