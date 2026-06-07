<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill: for every project with the legacy `db_*` columns
 * populated, materialise a `data_sources` row of type='database'.
 * Idempotent — re-running this migration after fresh inserts is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = time();

        $projects = DB::table('projects')
            ->whereNotNull('db_host')
            ->whereNotNull('db_name')
            ->whereNotNull('db_user')
            ->get();

        foreach ($projects as $project) {
            $exists = DB::table('data_sources')
                ->where('project_id', $project->id)
                ->where('type', 'database')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('data_sources')->insert([
                'project_id'  => $project->id,
                'type'        => 'database',
                'name'        => 'Customer CRM DB',
                'config'      => json_encode([
                    'type'     => $project->db_type ?: 'mysql',
                    'host'     => $project->db_host,
                    'port'     => $project->db_port ?: '3306',
                    'name'     => $project->db_name,
                    'user'     => $project->db_user,
                    'password' => (string) ($project->db_password ?? ''),
                    'schema'   => $project->db_schema,
                ]),
                'status'     => 'active',
                'created_at' => $now,
                'update_at'  => $now,
                'is_active'  => 'Yes',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('data_sources')->where('type', 'database')->delete();
    }
};
