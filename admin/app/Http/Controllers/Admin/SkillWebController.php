<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Skill;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Single-page CRUD for skills (call-center routing categories) via
 * modal dialogs. Project-scoped: each project has its own skill list.
 */
class SkillWebController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        $skills = collect();
        if ($project) {
            $this->tenants->useFor($project);
            $skills = Skill::where('project_id', $project->id)
                ->withCount('agents')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get();
        }

        return view('skills.index', compact(
            'client', 'projects', 'project', 'projectId', 'skills'
        ));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'  => 'required|integer',
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'sla_seconds' => 'nullable|integer|min:0',
            'is_default'  => 'nullable|boolean',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        // Only one default skill per project.
        if (!empty($data['is_default'])) {
            Skill::where('project_id', $project->id)->update(['is_default' => false]);
        }

        $now = time();
        Skill::create([
            'project_id'  => $project->id,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'sla_seconds' => $data['sla_seconds'] ?? null,
            'is_default'  => !empty($data['is_default']),
            'status'      => Skill::STATUS_ACTIVE,
            'created_at'  => $now,
            'update_at'   => $now,
        ]);

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "Skill \"{$data['name']}\" added.");
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate([
            'project_id'  => 'required|integer',
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'sla_seconds' => 'nullable|integer|min:0',
            'is_default'  => 'nullable|boolean',
            'status'      => 'required|in:active,archived',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        $skill = Skill::findOrFail($id);
        abort_unless((int) $skill->project_id === $project->id, 404);

        if (!empty($data['is_default'])) {
            Skill::where('project_id', $project->id)
                 ->where('id', '!=', $skill->id)
                 ->update(['is_default' => false]);
        }

        $skill->update([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'sla_seconds' => $data['sla_seconds'] ?? null,
            'is_default'  => !empty($data['is_default']),
            'status'      => $data['status'],
            'update_at'   => time(),
        ]);

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "Skill \"{$skill->name}\" updated.");
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);
        $project = $this->guard($client, (int) $data['project_id']);

        $skill = Skill::findOrFail($id);
        abort_unless((int) $skill->project_id === $project->id, 404);

        $name = $skill->name;
        $skill->delete();

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "Skill \"{$name}\" deleted.");
    }

    private function guard(Client $client, int $projectId): Project
    {
        $project = Project::where('client_id', $client->id)
            ->where('id', $projectId)
            ->firstOrFail();
        $this->tenants->useFor($project);
        return $project;
    }
}
