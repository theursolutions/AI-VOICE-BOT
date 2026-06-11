<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotAgent;
use App\Models\Client;
use App\Models\Flow;
use App\Models\Project;
use App\Models\Skill;
use App\Services\Telephony\WelcomeAudioService;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-project telephony settings — multiple phone numbers per project,
 * each routed to either a pool of specific agents or to a whole skill.
 *
 * Storage shape (projects.json_data['telephony']):
 *
 *   {
 *     "numbers": [
 *       {
 *         "phone_number":  "+12346352160",
 *         "enabled":       true,
 *         "welcome_voice": "Polly.Matthew",     // Polly fallback
 *         "routing_type":  "agents" | "skill",
 *         "agent_ids":     [1, 3],              // used when routing_type=agents
 *         "skill_id":      5                    // used when routing_type=skill
 *       },
 *       ...
 *     ]
 *   }
 */
class TelephonyWebController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name', 'json_data']);

        // Build agents + skills lookup per project so the modals can
        // populate their dropdowns without N extra queries client-side.
        $perProject = [];
        foreach ($projects as $p) {
            $this->tenants->useFor($p);
            $perProject[$p->id] = [
                'agents' => BotAgent::where('project_id', $p->id)
                    ->where('status', BotAgent::STATUS_ACTIVE)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'skills' => Skill::where('project_id', $p->id)
                    ->where('status', Skill::STATUS_ACTIVE)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                // Only active flows are bindable — drafts/archived ones
                // can't answer real calls anyway, no point exposing them.
                'flows'  => Flow::where('project_id', $p->id)
                    ->where('status', Flow::STATUS_ACTIVE)
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->get(['id', 'name', 'language']),
            ];
        }

        $base = rtrim((string) config('services.twilio.webhook_base', ''), '/');
        $webhookUrls = [
            'voice'  => $base ? $base . '/api/telephony/twilio/voice'  : null,
            'status' => $base ? $base . '/api/telephony/twilio/status' : null,
        ];

        return view('telephony.index', [
            'client'      => $client,
            'projects'    => $projects,
            'perProject'  => $perProject,
            'webhookUrls' => $webhookUrls,
            'envDefault'  => trim((string) config('services.twilio.phone_number', '')),
        ]);
    }

    /**
     * POST /telephony/numbers — add OR update a number on a project.
     * The form sends `number_index` of `__new__` for additions, or a
     * numeric index for edits.
     */
    public function saveNumber(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'    => 'required|integer',
            'number_index'  => 'required|string',        // numeric or "__new__"
            'phone_number'  => 'required|string|max:32',
            'enabled'       => 'nullable|boolean',
            'welcome_voice' => 'nullable|string|max:60',
            'routing_type'  => 'required|in:agents,skill,flow',
            'agent_ids'     => 'nullable|array',
            'agent_ids.*'   => 'integer',
            'skill_id'      => 'nullable|integer',
            'flow_id'       => 'nullable|integer',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        // Normalise phone number to E.164-ish.
        $number = preg_replace('/\s+/', '', $data['phone_number']);
        if (!str_starts_with($number, '+')) {
            $number = '+' . ltrim($number, '0');
        }

        $json = is_array($project->json_data) ? $project->json_data : [];
        $numbers = (array) data_get($json, 'telephony.numbers', []);

        // Conflict check — refuse to assign the same E.164 number twice
        // within the workspace.
        foreach (Project::where('client_id', $client->id)->get(['id', 'name', 'json_data']) as $otherP) {
            foreach ((array) data_get($otherP->json_data, 'telephony.numbers', []) as $idx => $n) {
                $sameProj = ($otherP->id === $project->id);
                $sameIdx  = ($data['number_index'] !== '__new__' && (int) $data['number_index'] === $idx);
                if ($sameProj && $sameIdx) continue;
                if (($n['phone_number'] ?? '') === $number) {
                    return back()->withErrors([
                        'phone_number' => "Number {$number} is already assigned to {$otherP->name}.",
                    ])->withInput();
                }
            }
        }

        $entry = [
            'phone_number'  => $number,
            'enabled'       => $request->boolean('enabled'),
            'welcome_voice' => $data['welcome_voice'] ?: 'Polly.Matthew',
            'routing_type'  => $data['routing_type'],
            'agent_ids'     => array_values($data['agent_ids'] ?? []),
            'skill_id'      => $data['routing_type'] === 'skill' ? (int) ($data['skill_id'] ?? 0) ?: null : null,
            'flow_id'       => $data['routing_type'] === 'flow'  ? (int) ($data['flow_id']  ?? 0) ?: null : null,
        ];

        if ($data['number_index'] === '__new__') {
            $numbers[] = $entry;
        } else {
            $idx = (int) $data['number_index'];
            if (isset($numbers[$idx])) {
                $numbers[$idx] = $entry;
            } else {
                $numbers[] = $entry;
            }
        }

        $json['telephony'] = array_merge((array) ($json['telephony'] ?? []), [
            'numbers' => array_values($numbers),
        ]);
        $project->json_data = $json;
        $project->save();

        // Wipe cached welcome wav — fallback Polly voice may have changed.
        try { app(WelcomeAudioService::class)->invalidateForProject($project->id); } catch (\Throwable $e) {}

        return redirect()
            ->route('telephony.index', ['client' => $client->slug])
            ->with('success', "Number {$number} saved for {$project->name}.");
    }

    public function deleteNumber(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'number_index' => 'required|integer',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        $json = is_array($project->json_data) ? $project->json_data : [];
        $numbers = (array) data_get($json, 'telephony.numbers', []);
        $idx = (int) $data['number_index'];
        if (!isset($numbers[$idx])) {
            return back();
        }
        $removed = $numbers[$idx]['phone_number'] ?? '';
        unset($numbers[$idx]);

        $json['telephony'] = array_merge((array) ($json['telephony'] ?? []), [
            'numbers' => array_values($numbers),
        ]);
        $project->json_data = $json;
        $project->save();

        return redirect()
            ->route('telephony.index', ['client' => $client->slug])
            ->with('success', "Number {$removed} removed.");
    }

    private function guard(Client $client, int $projectId): Project
    {
        return Project::where('client_id', $client->id)
            ->where('id', $projectId)
            ->firstOrFail();
    }
}
