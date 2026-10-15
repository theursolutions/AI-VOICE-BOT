<?php

namespace App\Services\LeadGen;

use App\Models\Client;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Which project a Lead Generation request is about, checked once, here.
 *
 * Every leadgen controller goes through this instead of reading
 * `?project_id` itself, so the ownership rules can't drift between pages:
 *
 *   - only the client's projects the member is assigned to are candidates
 *     (Project::accessibleBy) — a member scoped to one project can't reach
 *     another by editing the query string
 *   - an explicitly requested project that isn't a candidate is a 404, not
 *     a silent fallback to the first project (that would show the wrong
 *     project's data under the requested one's name)
 *   - the tenant connection is switched before anything is returned, so
 *     the lg_* models' project scope always has a project to bind to
 */
class LeadGenContext
{
    public function __construct(private TenantManager $tenants) {}

    /** Projects this member may use lead generation in. */
    public function projects(Request $request, Client $client): Collection
    {
        return Project::where('client_id', $client->id)
            ->accessibleBy($request->user(), $client->id)
            ->orderBy('name')
            ->get(['id', 'name', 'client_id']);
    }

    /**
     * Resolve and switch to the request's project. Null when the member has
     * no project at all (the page shows an empty state).
     */
    public function resolve(Request $request, Client $client): ?Project
    {
        $projects  = $this->projects($request, $client);
        $requested = $request->input('project_id');

        if ($requested !== null && $requested !== '') {
            $project = $projects->firstWhere('id', (int) $requested);
            abort_unless($project, 404);
        } else {
            $project = $projects->first();
        }

        if ($project) {
            $this->tenants->useFor($project);
        }
        return $project;
    }

    /**
     * Resolve the project for a Lead Generation page that needs its data.
     *
     *   - no project the member may use     → 404
     *   - project not migrated for lead-gen → redirect to the Overview, which
     *     explains it's being set up (a bare 404 here read as "this page
     *     doesn't exist" when the real fix is `php artisan tenant:migrate`)
     *
     * @return array{0: Project, 1: Collection}
     */
    public function open(Request $request, Client $client): array
    {
        $projects = $this->projects($request, $client);
        $project  = $this->resolve($request, $client);
        abort_unless($project, 404);

        if (!self::tablesReady()) {
            \Illuminate\Support\Facades\Log::warning('leadgen.tables_missing', [
                'project_id' => $project->id,
                'fix'        => 'php artisan tenant:migrate ' . $project->id,
            ]);
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                redirect()->route('leadgen.index', ['client' => $client->slug, 'project_id' => $project->id])
            );
        }

        return [$project, $projects];
    }

    /** Have this project's lg_* tables been migrated (`php artisan tenant:migrate`)? */
    public static function tablesReady(): bool
    {
        try {
            return Schema::connection('tenant')->hasTable('lg_prospects');
        } catch (\Throwable) {
            return false;
        }
    }
}
