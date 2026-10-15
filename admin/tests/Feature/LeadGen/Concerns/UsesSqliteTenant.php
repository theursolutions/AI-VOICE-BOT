<?php

namespace Tests\Feature\LeadGen\Concerns;

use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * Point the tenant connection at a SQLite file for the whole test — including
 * inside jobs and controllers, which call TenantManager::useFor() themselves
 * and would otherwise switch to a MySQL tenant database that doesn't exist.
 *
 * One file shared by every project, which is exactly the situation the
 * lg_* models' project scope is there to make safe.
 */
trait UsesSqliteTenant
{
    protected string $tenantFile;

    protected function setUpSqliteTenant(): void
    {
        $this->tenantFile = tempnam(sys_get_temp_dir(), 'lgt') . '.sqlite';
        touch($this->tenantFile);
        $file = $this->tenantFile;

        $this->app->singleton(TenantManager::class, fn () => new class($file) extends TenantManager {
            public function __construct(private string $file) {}

            public function useFor(Project $project): void
            {
                parent::useFor($project);
                config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => $this->file, 'prefix' => '']]);
                DB::purge('tenant');
            }
        });

        // Migrate the lg_* tables into the file.
        $p = new Project();
        $p->id = 999999;
        app(TenantManager::class)->useFor($p);
        (require database_path('migrations/tenant/2026_10_15_200000_create_leadgen_tables.php'))->up();
        app(TenantManager::class)->reset();
    }

    protected function tearDownSqliteTenant(): void
    {
        app(TenantManager::class)->reset();
        DB::purge('tenant');
        @unlink($this->tenantFile);
    }

    protected function useProject(Project|int $project): void
    {
        if (is_int($project)) {
            $id = $project;
            $project = new Project();
            $project->id = $id;
        }
        app(TenantManager::class)->useFor($project);
    }
}
