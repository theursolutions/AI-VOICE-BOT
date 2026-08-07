<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC foundation: per-client (agency) roles with a module permission set,
 * and a role_id on the project_users membership pivot.
 *
 * Backfill is critical: every EXISTING client gets an "Owner" all-access
 * role and every existing member is assigned it, so nobody is locked out
 * when route/sidebar enforcement turns on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('client_id')->index();
                $table->string('name', 80);
                $table->json('modules')->nullable();      // allowed module keys, or ["*"]
                $table->boolean('is_owner')->default(false); // all-access system role
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }

        if (!Schema::hasColumn('project_users', 'role_id')) {
            Schema::table('project_users', function (Blueprint $table) {
                $table->integer('role_id')->nullable()->after('user_id');
            });
        }

        // Backfill: one Owner role per existing client, assigned to all its
        // current members (they had full access before roles existed).
        $now = time();
        foreach (DB::table('clients')->pluck('id') as $clientId) {
            $exists = DB::table('roles')->where('client_id', $clientId)->where('is_owner', true)->first();
            $roleId = $exists->id ?? DB::table('roles')->insertGetId([
                'client_id'  => $clientId,
                'name'       => 'Owner',
                'modules'    => json_encode(['*']),
                'is_owner'   => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('project_users')
                ->where('client_id', $clientId)
                ->whereNull('role_id')
                ->update(['role_id' => $roleId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_users', 'role_id')) {
            Schema::table('project_users', function (Blueprint $table) {
                $table->dropColumn('role_id');
            });
        }
        Schema::dropIfExists('roles');
    }
};
