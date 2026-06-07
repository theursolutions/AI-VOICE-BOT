<?php

namespace App\Services\Tenant;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Resolves per-project DB connections.
 *
 *   tenant  → our chat DB, named `<prefix><project_id>`, hosted on
 *             our infra (TENANT_DB_* env vars). Holds sessions,
 *             messages, leads, voices, session_summaries.
 *
 *   client  → the customer's own CRM DB, credentials stored in
 *             projects.db_*. Used by BotChatController to run
 *             AI-generated SQL queries.
 */
class TenantManager
{
    private static ?int $currentProjectId = null;

    public function useFor(Project $project): void
    {
        $cfg = config('services.tenant');

        config(['database.connections.tenant' => [
            'driver'    => 'mysql',
            'host'      => $cfg['host'],
            'port'      => $cfg['port'],
            'database'  => $this->databaseNameFor($project),
            'username'  => $cfg['username'],
            'password'  => (string) $cfg['password'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
        ]]);
        DB::purge('tenant');

        if ($project->db_host && $project->db_name && $project->db_user) {
            config(['database.connections.client' => [
                'driver'    => $project->db_type ?: 'mysql',
                'host'      => $project->db_host,
                'port'      => $project->db_port ?: '3306',
                'database'  => $project->db_name,
                'username'  => $project->db_user,
                'password'  => (string) ($project->db_password ?? ''),
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
            ]]);
            DB::purge('client');
        }

        self::$currentProjectId = $project->id;
    }

    public function useForProjectId(int $projectId): Project
    {
        $project = Project::findOrFail($projectId);
        $this->useFor($project);
        return $project;
    }

    public function databaseNameFor(Project $project): string
    {
        $prefix = config('services.tenant.name_prefix', 'ai-crm-client-');
        return $prefix . $project->id;
    }

    /**
     * Open a privileged connection to the tenant DB host with no
     * default database — used by `tenant:provision` to run
     * CREATE DATABASE. Not for request-time use.
     */
    public function rootConnection(): string
    {
        $cfg = config('services.tenant');

        config(['database.connections.tenant_root' => [
            'driver'    => 'mysql',
            'host'      => $cfg['host'],
            'port'      => $cfg['port'],
            'database'  => null,
            'username'  => $cfg['username'],
            'password'  => (string) $cfg['password'],
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => false,
        ]]);
        DB::purge('tenant_root');

        return 'tenant_root';
    }

    public function currentProjectId(): ?int
    {
        return self::$currentProjectId;
    }

    public function reset(): void
    {
        self::$currentProjectId = null;
        DB::purge('tenant');
        DB::purge('client');
    }
}
