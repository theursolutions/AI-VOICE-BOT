<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use App\Services\Tenant\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectsController extends Controller
{
    public function index(Request $request, TenantManager $tenants): View
    {
        $title = 'Projects';
        $search       = trim((string) $request->query('q', ''));
        $withDeleted  = (bool) $request->query('with_deleted');
        $perPage = 25;

        $q = Project::query();
        if ($withDeleted) $q->withTrashedRows();
        $q->orderByDesc('id');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('url', 'like', "%{$search}%");
                if (ctype_digit($search)) $w->orWhere('id', (int) $search);
            });
        }

        $projects = $q->paginate($perPage)->withQueryString();

        $clientsById = Client::query()->withTrashedRows()
            ->whereIn('id', $projects->pluck('client_id')->unique())
            ->get(['id', 'name', 'slug', 'deleted_at'])->keyBy('id');

        $dbNames = [];
        foreach ($projects as $p) $dbNames[$p->id] = $tenants->databaseNameFor($p);

        return view('ops.projects.index', compact('title', 'projects', 'search', 'clientsById', 'dbNames', 'withDeleted'));
    }

    public function reprovision(Request $request, int $id, TenantProvisioner $prov): RedirectResponse
    {
        $project = Project::query()->withTrashedRows()->findOrFail($id);
        $ok = $prov->provision($project);

        AuditLog::record('project.reprovision', [
            'target_type' => 'project', 'target_id' => $project->id,
            'payload' => ['name' => $project->name, 'ok' => $ok],
        ]);

        return back()->with('success', $ok
            ? "Re-provisioned \"{$project->name}\"."
            : "Re-provisioning failed — see logs.");
    }

    public function disable(Request $request, int $id): RedirectResponse
    {
        $project = Project::query()->withTrashedRows()->findOrFail($id);
        $project->is_active = 'No';
        $project->save();

        AuditLog::record('project.disable', [
            'target_type' => 'project', 'target_id' => $project->id,
            'payload' => ['name' => $project->name],
        ]);

        return back()->with('success', "Disabled \"{$project->name}\".");
    }

    public function enable(Request $request, int $id): RedirectResponse
    {
        $project = Project::query()->withTrashedRows()->findOrFail($id);
        $project->is_active = 'Yes';
        $project->save();

        AuditLog::record('project.enable', [
            'target_type' => 'project', 'target_id' => $project->id,
            'payload' => ['name' => $project->name],
        ]);

        return back()->with('success', "Enabled \"{$project->name}\".");
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $project = Project::query()->withTrashedRows()->findOrFail($id);
        $project->softDelete();

        AuditLog::record('project.soft_delete', [
            'target_type' => 'project', 'target_id' => $project->id,
            'payload' => ['name' => $project->name],
        ]);

        return back()->with('success', "Deleted \"{$project->name}\" (recoverable). Tenant DB preserved.");
    }

    public function recover(Request $request, int $id): RedirectResponse
    {
        $project = Project::query()->withTrashedRows()->findOrFail($id);
        $project->restoreSoft();

        AuditLog::record('project.recover', [
            'target_type' => 'project', 'target_id' => $project->id,
            'payload' => ['name' => $project->name],
        ]);

        return back()->with('success', "Recovered \"{$project->name}\".");
    }
}
