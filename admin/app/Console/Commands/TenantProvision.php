<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Tenant\TenantManager;
use App\Services\Tenant\TenantProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Operator-facing wrapper around TenantProvisioner — same logic the
 * SetupController calls on signup. Use this to:
 *   - retry a Project whose first provision attempt failed
 *   - re-run migrations after adding a tenant migration file
 *   - drop + re-create a broken DB (with --drop, destructive)
 */
class TenantProvision extends Command
{
    protected $signature = 'tenant:provision
                            {project? : Project ID. Omit to provision every active project.}
                            {--drop : Drop the tenant DB first (destructive)}';

    protected $description = 'Create per-project chat DB and run tenant migrations.';

    public function handle(TenantManager $tenants, TenantProvisioner $prov): int
    {
        $projects = $this->argument('project')
            ? Project::where('id', $this->argument('project'))->get()
            : Project::all();

        if ($projects->isEmpty()) {
            $this->error('No matching projects.');
            return self::FAILURE;
        }

        $exit = self::SUCCESS;

        foreach ($projects as $project) {
            $dbName = $tenants->databaseNameFor($project);
            $this->line('');
            $this->info("→ Project #{$project->id} ({$project->name}) → `{$dbName}`");

            if ($this->option('drop')) {
                if (!$this->confirm("  Really drop `{$dbName}`? All chat data lost.", false)) {
                    $this->warn('  Skipped drop.');
                } else {
                    $prov->dropDatabase($project);
                    $this->warn("  Dropped `{$dbName}`");
                }
            }

            if ($prov->provision($project)) {
                $this->info("  ✓ provisioned");
            } else {
                $this->error("  ✗ failed — check logs");
                $exit = self::FAILURE;
            }
        }

        $this->line('');
        $this->info($exit === self::SUCCESS ? 'Done.' : 'Done with errors.');
        return $exit;
    }
}
