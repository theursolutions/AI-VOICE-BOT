<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DataSource;
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

        $skills       = collect();
        $webhookTools = collect();
        $skillActions = [];   // skill_id => int[] linked data_source ids
        if ($project) {
            $this->tenants->useFor($project);
            $skills = Skill::where('project_id', $project->id)
                ->withCount('agents')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get();

            // Webhook "action" tools available in this project (app DB).
            $webhookTools = DataSource::where('project_id', $project->id)
                ->where('type', DataSource::TYPE_WEBHOOK)
                ->orderBy('name')
                ->get(['id', 'name', 'status']);

            // Pre-select the actions each skill already grants.
            foreach ($skills as $sk) {
                $skillActions[$sk->id] = $sk->actionIds();
            }
        }

        $toolTemplates = config('tool_templates', []);

        return view('skills.index', compact(
            'client', 'projects', 'project', 'projectId',
            'skills', 'webhookTools', 'skillActions', 'toolTemplates'
        ));
    }

    /**
     * Create a webhook tool from a prebuilt library template
     * (config/tool_templates.php) and link it to this skill in one step.
     * The template supplies name/intent/method/args; the user supplies
     * only the endpoint URL + auth.
     */
    public function addActionFromTemplate(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'template_key' => 'required|string',
            'name'         => 'nullable|string|max:120',
            'url'          => 'required|url|max:2048',
            'auth_type'    => 'nullable|in:none,bearer,basic,api_key,header',
            'auth_value'   => 'nullable|string|max:1024',
            'auth_header'  => 'nullable|string|max:120',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        $skill = Skill::findOrFail($id);
        abort_unless((int) $skill->project_id === $project->id, 404);

        $template = collect(config('tool_templates', []))
            ->firstWhere('key', $data['template_key']);
        abort_unless($template, 404, 'Unknown tool template.');

        $authValue = $data['auth_value'] ?? null;
        if ($authValue !== null && $authValue !== '') {
            $authValue = \Illuminate\Support\Facades\Crypt::encryptString($authValue);
        }

        $now    = time();
        $source = DataSource::create([
            'project_id' => $project->id,
            'type'       => DataSource::TYPE_WEBHOOK,
            'name'       => $data['name'] ?: $template['name'],
            'config'     => [
                'url'         => $data['url'],
                'method'      => strtoupper($template['method'] ?? 'GET'),
                'when_to_use' => $template['when_to_use'] ?? '',
                'auth_type'   => $data['auth_type'] ?? ($template['auth_type'] ?? 'none'),
                'auth_value'  => $authValue,
                'auth_header' => $data['auth_header'] ?? null,
                'args'        => is_array($template['args'] ?? null) ? $template['args'] : [],
                'from_template' => $template['key'],
            ],
            'status'     => DataSource::STATUS_ACTIVE,
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        // Append to the skill's existing actions (don't clobber them).
        $skill->syncActions(array_merge($skill->actionIds(), [$source->id]));

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "Added \"{$source->name}\" to skill \"{$skill->name}\".");
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'  => 'required|integer',
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'sla_seconds' => 'nullable|integer|min:0',
            'is_default'  => 'nullable|boolean',
            'action_ids'   => 'nullable|array',
            'action_ids.*' => 'integer',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        // Only one default skill per project.
        if (!empty($data['is_default'])) {
            Skill::where('project_id', $project->id)->update(['is_default' => false]);
        }

        $now = time();
        $skill = Skill::create([
            'project_id'  => $project->id,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'sla_seconds' => $data['sla_seconds'] ?? null,
            'is_default'  => !empty($data['is_default']),
            'status'      => Skill::STATUS_ACTIVE,
            'created_at'  => $now,
            'update_at'   => $now,
        ]);

        // Actions (webhook tools) this skill grants.
        $skill->syncActions($this->validActionIds($project->id, $data['action_ids'] ?? []));

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
            'action_ids'   => 'nullable|array',
            'action_ids.*' => 'integer',
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

        $skill->syncActions($this->validActionIds($project->id, $data['action_ids'] ?? []));

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

    /**
     * Keep only IDs that are genuinely webhook tools in this project —
     * stops a tampered form from linking another project's data source.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    private function validActionIds(int $projectId, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return DataSource::where('project_id', $projectId)
            ->where('type', DataSource::TYPE_WEBHOOK)
            ->whereIn('id', array_map('intval', $ids))
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
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
