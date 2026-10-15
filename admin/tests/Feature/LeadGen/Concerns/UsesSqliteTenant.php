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
        (require database_path('migrations/tenant/2026_10_16_100000_add_lead_id_to_lg_prospects.php'))->up();
        app(TenantManager::class)->reset();
    }

    /** The CRM tables ContactResolver and Lead use, shaped like the tenant migrations. */
    protected function createCrmTables(): void
    {
        $s = \Illuminate\Support\Facades\Schema::connection('tenant');
        $s->create('contacts', function ($t) {
            $t->id(); $t->integer('project_id'); $t->string('name')->nullable(); $t->string('email')->nullable();
            $t->string('phone')->nullable(); $t->string('avatar')->nullable(); $t->text('notes')->nullable(); $t->json('metadata')->nullable();
            $t->integer('first_seen_at')->nullable(); $t->integer('last_seen_at')->nullable();
            $t->integer('created_at')->nullable(); $t->integer('update_at')->nullable(); $t->integer('deleted_at')->nullable();
        });
        $s->create('contact_identities', function ($t) {
            $t->id(); $t->integer('project_id'); $t->unsignedBigInteger('contact_id'); $t->string('channel'); $t->string('external_id');
            $t->string('channel_account')->nullable(); $t->integer('created_at')->nullable();
            $t->unique(['project_id', 'channel', 'external_id']);
        });
        $s->create('sessions', function ($t) { $t->id(); $t->integer('project_id')->nullable(); $t->unsignedBigInteger('contact_id')->nullable(); });
        $s->create('leads', function ($t) {
            $t->id(); $t->unsignedBigInteger('session_id')->nullable(); $t->integer('project_id'); $t->unsignedBigInteger('contact_id')->nullable();
            $t->json('fields')->nullable(); $t->float('confidence')->nullable(); $t->string('status')->default('new');
            $t->integer('assigned_to')->nullable(); $t->text('notes')->nullable(); $t->integer('created_at')->nullable();
            $t->integer('update_at')->nullable(); $t->integer('created_by')->nullable(); $t->integer('deleted_at')->nullable();
        });
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
