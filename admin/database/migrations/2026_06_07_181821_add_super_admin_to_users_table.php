<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two new columns on `users`:
     *   - is_super_admin: hard gate for /admin/* ops console
     *   - role:           forward-compat for future staff tiers
     *                     (admin / support / finance). NULL = customer.
     * Both default to safe values so existing customer accounts are
     * unchanged.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_super_admin')) {
                $table->boolean('is_super_admin')->default(false)->after('email');
            }
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->nullable()->after('is_super_admin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_super_admin')) {
                $table->dropColumn('is_super_admin');
            }
            if (Schema::hasColumn('users', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};
