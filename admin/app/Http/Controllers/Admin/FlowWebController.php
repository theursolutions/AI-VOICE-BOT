<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Flow;
use App\Models\Project;
use App\Models\Session;
use App\Services\Flow\WebFlowRunner;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-project conversation Flow CRUD + editor mount point.
 *
 *   GET    /c/{slug}/flows                   index (list + create + delete)
 *   POST   /c/{slug}/flows                   create a new flow row
 *   GET    /c/{slug}/flows/{id}/editor       Blade page that mounts the React builder
 *   GET    /c/{slug}/flows/{id}/definition   JSON — the editor fetches on load
 *   PUT    /c/{slug}/flows/{id}/definition   JSON — editor saves on save
 *   PATCH  /c/{slug}/flows/{id}              rename / change status
 *   DELETE /c/{slug}/flows/{id}              soft-delete
 *
 * Definition format is the React-Flow shape (nodes[] + edges[] + settings).
 * No server-side schema validation beyond "is it an array" — the editor
 * is the source of truth for the graph shape. Reduces churn while
 * we're iterating on node types.
 */
class FlowWebController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);
        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        $flows = collect();
        if ($project) {
            $this->tenants->useFor($project);
            $flows = Flow::where('project_id', $project->id)
                ->orderByDesc('id')
                ->get();
        }

        return view('flows.index', compact(
            'client', 'projects', 'project', 'projectId', 'flows'
        ));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'required|string|max:160',
            'language'   => 'nullable|string|max:16',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        $now = time();
        $flow = Flow::create([
            'project_id'  => $project->id,
            'name'        => $data['name'],
            'slug'        => Flow::generateSlug($data['name'], $project->id),
            'status'      => Flow::STATUS_DRAFT,
            'language'    => $data['language'] ?? 'en',
            'definition'  => Flow::emptyDefinition(),
            'version'     => 1,
            'created_at'  => $now,
            'update_at'   => $now,
        ]);

        return redirect()
            ->route('flows.editor', ['client' => $client->slug, 'id' => $flow->id])
            ->with('success', "Flow \"{$flow->name}\" created.");
    }

    public function editor(Request $request, Client $client, int $id): View
    {
        $projectId = (int) $request->query('project_id');
        $flow = $this->loadFlow($client, $id, $projectId);

        // Feed the project's data sources to the React editor (also served
        // via the /definition API on mount — see projectDataSources()).
        return view('flows.editor', [
            'client'      => $client,
            'flow'        => $flow,
            'dataSources' => $this->projectDataSources($flow->project_id),
        ]);
    }

    /** Editor fetches this on mount to populate the React Flow canvas. */
    public function definition(Request $request, Client $client, int $id): JsonResponse
    {
        $projectId = (int) $request->query('project_id');
        $flow = $this->loadFlow($client, $id, $projectId);
        return response()->json([
            'id'           => $flow->id,
            'name'         => $flow->name,
            'status'       => $flow->status,
            'language'     => $flow->language,
            'definition'   => $flow->definition ?: Flow::emptyDefinition(),
            'version'      => $flow->version,
            // Used by the Data Source + Send to Channel nodes' pickers.
            // Returned here (not just the page attribute) so the editor
            // always has fresh data regardless of HTML/asset caching.
            'data_sources' => $this->projectDataSources($flow->project_id),
        ]);
    }

    /** @return array<int,array{id:int,name:string,type:string}> */
    private function projectDataSources(int $projectId): array
    {
        return \App\Models\DataSource::where('project_id', $projectId)
            ->where('status', '!=', \App\Models\DataSource::STATUS_DISABLED)
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn ($s) => [
                'id'   => (int) $s->id,
                'name' => (string) $s->name,
                'type' => (string) $s->type,
            ])
            ->values()
            ->all();
    }

    /** Editor PUTs the whole graph back on save. */
    public function saveDefinition(Request $request, Client $client, int $id): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'definition' => 'required|array',
        ]);
        $flow = $this->loadFlow($client, $id, (int) $data['project_id']);

        $flow->definition = $data['definition'];
        $flow->version    = $flow->version + 1;
        $flow->update_at  = time();
        $flow->save();

        return response()->json(['ok' => true, 'version' => $flow->version]);
    }

    /** Rename, change language, flip status (draft/active/archived). */
    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'nullable|string|max:160',
            'language'   => 'nullable|string|max:16',
            'status'     => 'nullable|in:draft,active,archived',
        ]);
        $flow = $this->loadFlow($client, $id, (int) $data['project_id']);

        if (!empty($data['name']))     $flow->name = $data['name'];
        if (!empty($data['language'])) $flow->language = $data['language'];
        if (!empty($data['status']))   $flow->status = $data['status'];
        $flow->update_at = time();
        $flow->save();

        return back()->with('success', "Flow \"{$flow->name}\" updated.");
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);
        $flow = $this->loadFlow($client, $id, (int) $data['project_id']);
        $flow->softDelete();
        return back()->with('success', "Flow \"{$flow->name}\" deleted.");
    }

    // ── helpers ───────────────────────────────────────────────────────
    private function guard(Client $client, int $projectId): Project
    {
        $project = Project::where('client_id', $client->id)
            ->where('id', $projectId)
            ->firstOrFail();
        $this->tenants->useFor($project);
        return $project;
    }

    private function loadFlow(Client $client, int $flowId, int $projectId): Flow
    {
        $project = $this->guard($client, $projectId);
        $flow = Flow::query()
            ->where('id', $flowId)
            ->where('project_id', $project->id)
            ->firstOrFail();
        return $flow;
    }

    /**
     * POST /c/{slug}/flows/{id}/test/start
     *
     * Spins up a throwaway "test" session (channel='test') and runs the
     * flow's first walk against it. Returns the WebFlowRunner envelope
     * with execution_path[] so the editor can animate which nodes
     * lit up. The session is real (lives in the tenant DB) but tagged
     * channel='test' so admin reports can filter it out.
     */
    public function testStart(Request $request, Client $client, int $id): JsonResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);
        $flow = $this->loadFlow($client, $id, (int) $data['project_id']);
        $project = $this->guard($client, (int) $data['project_id']);

        // Sessions.channel is an enum (web|whatsapp|twilio|plivo|api). We
        // can't add 'test' without a migration, so use 'web' and tag
        // the sandbox via metadata.is_test so reports can filter out.
        $now = time();
        $session = Session::create([
            'project_id'       => $project->id,
            'channel'          => 'web',
            'status'           => 'active',
            'started_at'       => $now,
            'last_activity_at' => $now,
            'metadata'         => [
                'flow'    => ['flow_id' => $flow->id, 'current_node_id' => null],
                'is_test' => true,
            ],
            'created_at'       => $now,
            'update_at'        => $now,
        ]);

        $runner = app(WebFlowRunner::class);
        $result = $runner->start($project, $session, $flow);
        $result['test_session_id'] = $session->id;
        return response()->json($result);
    }

    /**
     * POST /c/{slug}/flows/{id}/test/step
     *
     * Advances a test session. Body must include `session_id` (returned
     * from /test/start) and either `choice_id` or `text`. Returns the
     * same envelope shape as the live widget endpoint.
     */
    public function testStep(Request $request, Client $client, int $id): JsonResponse
    {
        // IVR / DTMF choices are digits ("0","1","2"…). A request middleware
        // coerces numeric strings to integers, so choice_id arrives as int 0/3
        // and would fail a strict `string` rule. Normalise it back to a string
        // (the runner treats it as a string anyway) so the menu buttons work.
        if ($request->has('choice_id') && $request->input('choice_id') !== null) {
            $request->merge(['choice_id' => (string) $request->input('choice_id')]);
        }

        $data = $request->validate([
            'project_id' => 'required|integer',
            'session_id' => 'required|integer',
            'choice_id'  => 'nullable|string|max:64',
            'text'       => 'nullable|string|max:4000',
        ]);
        $flow = $this->loadFlow($client, $id, (int) $data['project_id']);
        $project = $this->guard($client, (int) $data['project_id']);

        // Guard via metadata.is_test rather than channel (channel is
        // 'web' to satisfy the enum). Refuses to advance any non-test
        // session so this endpoint can't pollute real conversations.
        $session = Session::where('id', (int) $data['session_id'])
            ->where('project_id', $project->id)
            ->firstOrFail();
        if (!data_get($session->metadata, 'is_test')) {
            abort(404, 'Not a test session.');
        }

        $runner = app(WebFlowRunner::class);
        $result = $runner->step($project, $session, $flow, [
            'choice_id' => (string) ($data['choice_id'] ?? ''),
            'text'      => (string) ($data['text'] ?? ''),
        ]);
        $result['test_session_id'] = $session->id;
        return response()->json($result);
    }
}
