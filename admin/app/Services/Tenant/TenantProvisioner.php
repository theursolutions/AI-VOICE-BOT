<?php

namespace App\Services\Tenant;

use App\Models\Project;
use Illuminate\Contracts\Console\Kernel as ArtisanKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single source of truth for "give this Project a working tenant DB".
 *
 * The job is three steps, all idempotent:
 *   1. CREATE DATABASE `ai-crm-client-{project_id}` IF NOT EXISTS
 *   2. Switch the `tenant` Eloquent connection to it
 *   3. Run every migration in database/migrations/tenant/
 *
 * Called from:
 *   - Project::created model event   (auto on first project creation)
 *   - artisan tenant:provision       (operator tool / re-run after failure)
 *   - SetupController retry button   (failsafe banner on dashboard)
 *
 * Failure model: returns false + logs. Callers decide what to do
 * (e.g. delete the Project row, surface a flash to the user). We
 * never throw past the boundary — `Project::created` must not blow
 * up the request.
 */
class TenantProvisioner
{
    public function __construct(private TenantManager $tenants) {}

    /**
     * Run the full provisioning chain for one project. Idempotent.
     * Returns true on success, false if any step failed.
     */
    public function provision(Project $project): bool
    {
        $dbName = $this->tenants->databaseNameFor($project);

        try {
            // Step 1 — make sure the DB exists. Privileged "root" connection
            // (no default DB selected) so CREATE DATABASE can run.
            $rootConn = $this->tenants->rootConnection();
            DB::connection($rootConn)->statement(
                "CREATE DATABASE IF NOT EXISTS `{$dbName}` ".
                "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );

            // Step 2 — point the `tenant` connection at the (now-existing) DB.
            $this->tenants->useFor($project);

            // Step 3 — run migrations against it. Artisan exit code 0 = ok.
            $exitCode = app(ArtisanKernel::class)->call('migrate', [
                '--database' => 'tenant',
                '--path'     => 'database/migrations/tenant',
                '--force'    => true,
            ]);

            if ($exitCode !== 0) {
                Log::error('TenantProvisioner: migration returned non-zero', [
                    'project_id' => $project->id,
                    'db_name'    => $dbName,
                    'exit'       => $exitCode,
                ]);
                return false;
            }

            // Mark the project usable. Project rows start `is_active='No'`
            // (see SetupController) and only flip after a clean migrate.
            if ($project->is_active !== 'Yes') {
                $project->forceFill(['is_active' => 'Yes'])->save();
            }

            Log::info('TenantProvisioner: ok', [
                'project_id' => $project->id, 'db_name' => $dbName,
            ]);
            return true;
        } catch (Throwable $e) {
            Log::error('TenantProvisioner: failed', [
                'project_id' => $project->id,
                'db_name'    => $dbName,
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Has this project's tenant DB actually been provisioned (db exists +
     * at least one migration ran)?  Cheap connection check.
     */
    public function isProvisioned(Project $project): bool
    {
        try {
            $this->tenants->useFor($project);
            // Probing for one of the earliest migrations is the cheapest
            // way to confirm "DB exists and is set up", without depending
            // on the migrations table format.
            return DB::connection('tenant')
                ->getSchemaBuilder()
                ->hasTable('sessions');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Best-effort teardown — used when provisioning failed and we want
     * to clean up the half-created DB. NEVER call this on a live
     * project; it drops data.
     */
    public function dropDatabase(Project $project): void
    {
        $dbName = $this->tenants->databaseNameFor($project);
        try {
            $rootConn = $this->tenants->rootConnection();
            DB::connection($rootConn)->statement("DROP DATABASE IF EXISTS `{$dbName}`");
            Log::warning('TenantProvisioner: dropped DB', ['db_name' => $dbName]);
        } catch (Throwable $e) {
            Log::error('TenantProvisioner: drop failed', [
                'db_name' => $dbName, 'error' => $e->getMessage(),
            ]);
        }
    }
}
