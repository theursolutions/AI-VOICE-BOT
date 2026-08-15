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
    use \App\Http\Controllers\Concerns\EnforcesPlanFeatures;

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
            // Every voice, not just `ready` ones. The old `where('status','ready')`
            // meant a voice whose upload failed (left at `training` with a null
            // reference_url) vanished from this dropdown while still showing on
            // the Voices page — so the picker looked empty apart from the
            // "project default" placeholder and there was no clue why. The modal
            // renders unusable ones disabled, with their status, so the reason is
            // visible instead of silent.
            $voices = Voice::where('project_id', $project->id)
                ->orderBy('name')
                ->get();
        }

        // Team users selectable as human agents.
        $users = $client->users()->get(['users.id', 'users.name', 'users.email']);

        // Channels and capabilities are configured by the workspace owner
        // only. The view hides those fields for everyone else, and
        // metadataFor() refuses to write them regardless of what is posted.
        $isOwner = (bool) auth()->user()?->isOwnerOf($client->id);

        return view('bot-agents.index', compact(
            'client', 'projects', 'project', 'projectId',
            'agents', 'skills', 'voices', 'users', 'isOwner'
        ));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        // Structural quota: an extra agent beyond the plan is not overage,
        // it is simply not in the plan, so this is a hard stop.
        if ($refusal = $this->refuseUnlessWithinQuota(
            $client, 'agents', \App\Models\BotAgent::whereIn('project_id',
                \App\Models\Project::where('client_id', $client->id)->pluck('id'))->count(), 'AI agent')) {
            return $refusal;
        }

        $data = $this->validateInput($request);
        $project = $this->guard($client, (int) $data['project_id']);

        if (!empty($data['is_default'])) {
            BotAgent::where('project_id', $project->id)->update(['is_default' => false]);
        }

        $type = ($data['type'] ?? 'ai') === 'human' ? BotAgent::TYPE_HUMAN : BotAgent::TYPE_AI;

        $now = time();
        $agent = BotAgent::create([
            'project_id'       => $project->id,
            'name'             => $data['name'],
            'type'             => $type,
            'user_id'          => $type === BotAgent::TYPE_HUMAN ? ($data['user_id'] ?? null) : null,
            'presence'         => BotAgent::PRESENCE_OFFLINE,
            'max_active_chats' => $type === BotAgent::TYPE_HUMAN ? (int) ($data['max_active_chats'] ?? 3) : 3,
            'voice_id'         => $type === BotAgent::TYPE_HUMAN ? null : ($data['voice_id'] ?? null),
            'persona'          => $type === BotAgent::TYPE_HUMAN ? null : ($data['persona'] ?? null),
            'is_default'       => !empty($data['is_default']),
            'status'           => BotAgent::STATUS_ACTIVE,
            'metadata'         => $this->metadataFor($request, $data, $client, null),
            'created_at'       => $now,
            'update_at'        => $now,
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

        $type = ($data['type'] ?? $agent->type ?? 'ai') === 'human' ? BotAgent::TYPE_HUMAN : BotAgent::TYPE_AI;

        $agent->update([
            'name'             => $data['name'],
            'type'             => $type,
            'user_id'          => $type === BotAgent::TYPE_HUMAN ? ($data['user_id'] ?? null) : null,
            'max_active_chats' => $type === BotAgent::TYPE_HUMAN ? (int) ($data['max_active_chats'] ?? 3) : $agent->max_active_chats,
            'voice_id'         => $type === BotAgent::TYPE_HUMAN ? null : ($data['voice_id'] ?? null),
            'persona'          => $type === BotAgent::TYPE_HUMAN ? null : ($data['persona'] ?? null),
            'is_default'       => !empty($data['is_default']),
            'status'           => $data['status'] ?? BotAgent::STATUS_ACTIVE,
            'metadata'         => $this->metadataFor($request, $data, $client, $agent),
            'update_at'        => time(),
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
            'project_id'       => 'required|integer',
            'name'             => 'required|string|max:120',
            'type'             => 'nullable|in:ai,human',
            'user_id'          => 'nullable|integer|required_if:type,human',
            'max_active_chats' => 'nullable|integer|min:1|max:50',
            'voice_id'         => 'nullable|integer',
            'persona'          => 'nullable|string|max:4000',
            'skill_ids'        => 'nullable|array',
            'skill_ids.*'      => 'integer',
            'is_default'       => 'nullable|boolean',
            'channels'         => 'nullable|array',
            'channels.*'       => 'string|in:' . implode(',', array_keys(BotAgent::CHANNELS)),
            'capabilities'     => 'nullable|array',
        ];
        if ($forUpdate) {
            $rules['status'] = 'required|in:active,archived';
        }
        return $request->validate($rules);
    }

    /**
     * Build the metadata blob holding channels and capabilities.
     *
     * Both are OWNER-ONLY. A non-owner editing an agent keeps whatever is
     * already stored rather than having it wiped by a form that never
     * rendered those fields — the classic way a permissions UI quietly
     * escalates everyone to full access.
     *
     * @param BotAgent|null $existing null when creating
     */
    private function metadataFor(Request $request, array $data, Client $client, ?BotAgent $existing): array
    {
        $meta = (array) ($existing->metadata ?? []);

        if (! auth()->user()?->isOwnerOf($client->id)) {
            return $meta;
        }

        // An empty selection means "every channel", which is also what an
        // unset value means — see BotAgent::channels(). Storing [] would be
        // ambiguous, so it is normalised away.
        $channels = array_values(array_filter((array) ($data['channels'] ?? [])));
        if ($channels) {
            $meta['channels'] = $channels;
        } else {
            unset($meta['channels']);
        }

        // Checkboxes only post when ticked, so every known capability is
        // written explicitly. Reading the posted keys alone would silently
        // grant everything the form happened not to render.
        $posted = (array) ($data['capabilities'] ?? []);
        $caps   = [];
        foreach (array_keys(BotAgent::CAPABILITIES) as $key) {
            $caps[$key] = (bool) ($posted[$key] ?? false);
        }
        $meta['capabilities'] = $caps;

        return $meta;
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
