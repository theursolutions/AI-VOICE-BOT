<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Console\Command;
use Throwable;

class TenantMigrate extends Command
{
    protected $signature = 'tenant:migrate
                            {project? : Project ID. Omit to run on every active project.}
                            {--fresh : Drop and recreate (destructive)}
                            {--rollback : Rollback the last batch}';

    protected $description = 'Run tenant chat-data migrations against one or all project DBs.';

    public function handle(TenantManager $tenants): int
    {
        $projects = $this->argument('project')
            ? Project::where('id', $this->argument('project'))->get()
            : Project::where('is_active', 'Yes')->get();

        if ($projects->isEmpty()) {
            $this->error('No matching projects.');
            return self::FAILURE;
        }

        $path = 'database/migrations/tenant';

        foreach ($projects as $project) {
            $this->line("");
            $this->info("→ Project #{$project->id} ({$project->name}) @ {$project->db_host}/{$project->db_name}");

            try {
                $tenants->useFor($project);
            } catch (Throwable $e) {
                $this->error("  Skipped: ".$e->getMessage());
                continue;
            }

            $command = match (true) {
                $this->option('fresh')    => 'migrate:fresh',
                $this->option('rollback') => 'migrate:rollback',
                default                   => 'migrate',
            };

            $exit = $this->call($command, [
                '--database' => 'tenant',
                '--path'     => $path,
                '--force'    => true,
            ]);

            if ($exit !== 0) {
                $this->error("  Migration failed for project {$project->id}");
                return self::FAILURE;
            }
        }

        $this->line('');
        $this->info('Done.');
        return self::SUCCESS;
    }
}
