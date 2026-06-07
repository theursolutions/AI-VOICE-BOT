<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BotAgent;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Session;
use App\Models\Skill;
use App\Models\Voice;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Generic destroy / restore for any per-tenant resource. One route
 * pair instead of five — type tag drives the model lookup.
 *
 *   Supported types: session, lead, voice, agent (bot-agent), skill
 *
 * Customer-side queries respect the IntSoftDeletes global scope, so
 * a deleted row stops being returned to the project owner immediately
 * on their next page load.
 */
class TenantResourceController extends Controller
{
    private const MAP = [
        'session' => Session::class,
        'lead'    => Lead::class,
        'voice'   => Voice::class,
        'agent'   => BotAgent::class,   // path label matches the tenant table name
        'skill'   => Skill::class,
    ];

    public function __construct(private TenantManager $tenants) {}

    public function destroy(Request $request, string $type, int $projectId, int $id): RedirectResponse
    {
        [$project, $model] = $this->resolve($type, $projectId, $id, true);
        $model->softDelete();

        AuditLog::record("{$type}.soft_delete", [
            'target_type' => $type,
            'target_id'   => $model->id,
            'payload'     => ['project_id' => $project->id, 'project_name' => $project->name],
        ]);

        return back()->with('success', ucfirst($type) . " #{$model->id} deleted (recoverable).");
    }

    public function restore(Request $request, string $type, int $projectId, int $id): RedirectResponse
    {
        [$project, $model] = $this->resolve($type, $projectId, $id, true);
        $model->restoreSoft();

        AuditLog::record("{$type}.restore", [
            'target_type' => $type,
            'target_id'   => $model->id,
            'payload'     => ['project_id' => $project->id, 'project_name' => $project->name],
        ]);

        return back()->with('success', ucfirst($type) . " #{$model->id} restored.");
    }

    /**
     * Resolve tenant connection + model row, returning [project, row].
     * $includeTrashed lets us operate on soft-deleted rows (e.g. for
     * restore).
     */
    private function resolve(string $type, int $projectId, int $id, bool $includeTrashed): array
    {
        abort_unless(isset(self::MAP[$type]), 404);

        $project = Project::query()->withTrashedRows()->findOrFail($projectId);
        $this->tenants->useFor($project);

        $class = self::MAP[$type];
        $q = $class::query();
        if ($includeTrashed) $q->withTrashedRows();

        $row = $q->findOrFail($id);
        abort_unless((int) $row->project_id === $projectId, 404);

        return [$project, $row];
    }
}
