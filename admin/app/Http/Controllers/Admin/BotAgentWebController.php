<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotAgent;
use App\Models\Client;
use App\Models\Project;
use App\Models\Skill;
use App\Models\Voice;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Single-page CRUD for AI agents (bot personas). One row per agent
 * with edit + delete via modals. Each agent has a voice, a persona
 * prompt, and a set of skills it handles.
 */
class BotAgentWebController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        $agents = collect();
        $skills = collect();
        $voices = collect();
        if ($project) {
            $this->tenants->useFor($project);
            $agents = BotAgent::with(['voice', 'skills'])
                ->where('project_id', $project->id)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get();
            $skills = Skill::where('project_id', $project->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
            $voices = Voice::where('project_id', $project->id)
                ->where('status', 'ready')
                ->orderBy('name')
                ->get();
        }

        return view('bot-agents.index', compact(
            'client', 'projects', 'project', 'projectId',
            'agents', 'skills', 'voices'
        ));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validateInput($request);
        $project = $this->guard($client, (int) $data['project_id']);

        if (!empty($data['is_default'])) {
            BotAgent::where('project_id', $project->id)->update(['is_default' => false]);
        }

        $now = time();
        $agent = BotAgent::create([
            'project_id' => $project->id,
            'name'       => $data['name'],
            'voice_id'   => $data['voice_id'] ?? null,
            'persona'    => $data['persona']  ?? null,
            'is_default' => !empty($data['is_default']),
            'status'     => BotAgent::STATUS_ACTIVE,
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        if (!empty($data['skill_ids'])) {
            $agent->skills()->sync(array_fill_keys($data['skill_ids'], ['created_at' => $now]));
        }

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "Agent \"{$agent->name}\" created.");
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $this->validateInput($request, $forUpdate = true);
        $project = $this->guard($client, (int) $data['project_id']);

        $agent = BotAgent::findOrFail($id);
        abort_unless((int) $agent->project_id === $project->id, 404);

        if (!empty($data['is_default'])) {
            BotAgent::where('project_id', $project->id)
                ->where('id', '!=', $agent->id)
                ->update(['is_default' => false]);
        }

        $agent->update([
            'name'       => $data['name'],
            'voice_id'   => $data['voice_id'] ?? null,
            'persona'    => $data['persona']  ?? null,
            'is_default' => !empty($data['is_default']),
            'status'     => $data['status'] ?? BotAgent::STATUS_ACTIVE,
            'update_at'  => time(),
        ]);

        $agent->skills()->sync(array_fill_keys($data['skill_ids'] ?? [], ['created_at' => time()]));

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "Agent \"{$agent->name}\" updated.");
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);
        $project = $this->guard($client, (int) $data['project_id']);

        $agent = BotAgent::findOrFail($id);
        abort_unless((int) $agent->project_id === $project->id, 404);

        $name = $agent->name;
        $agent->skills()->detach();
        $agent->delete();

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "Agent \"{$name}\" deleted.");
    }

    private function validateInput(Request $request, bool $forUpdate = false): array
    {
        $rules = [
            'project_id' => 'required|integer',
            'name'       => 'required|string|max:120',
            'voice_id'   => 'nullable|integer',
            'persona'    => 'nullable|string|max:4000',
            'skill_ids'  => 'nullable|array',
            'skill_ids.*'=> 'integer',
            'is_default' => 'nullable|boolean',
        ];
        if ($forUpdate) {
            $rules['status'] = 'required|in:active,archived';
        }
        return $request->validate($rules);
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
