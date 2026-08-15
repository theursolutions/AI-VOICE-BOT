<?php

namespace App\Http\Controllers\Admin;

use App\Flow\NodeCatalog;
use App\Http\Controllers\Controller;
use App\Models\BotAgent;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Flow;
use App\Models\Project;
use App\Models\Session;
use App\Services\Flow\FlowPlanner;
use App\Services\Flow\FlowValidator;
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
 *   POST   /c/{slug}/flows/ai/plan           JSON — draft a graph from a description
 *   POST   /c/{slug}/flows/ai/create         create a flow from a description
 *
 * Definition format is the React-Flow shape (nodes[] + edges[] + settings).
 * No server-side schema validation beyond "is it an array" — the editor
 * is the source of truth for the graph shape. Reduces churn while
 * we're iterating on node types.
 *
 * The AI routes are the exception: anything a model writes is checked against
 * App\Flow\NodeCatalog by FlowValidator before it can be persisted, because a
 * generated graph has no human in the loop to notice that a branch points at
 * a handle which doesn't exist.
 */
class FlowWebController extends Controller
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
        if ($refusal = $this->refuseUnlessWithinQuota(
            $client, 'flow_builder', \App\Models\Flow::whereIn('project_id',
                \App\Models\Project::where('client_id', $client->id)->pluck('id'))->count(), 'flow')) {
            return $refusal;
        }

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

        // project_id is NOT optional here. Flows live in the project's own
        // tenant DB, so the editor cannot look one up without knowing which
        // project to connect to. Omitting it (as this redirect used to) sent
        // the user straight from "create" into a 404, while the same flow
        // opened fine from the index — whose link appends project_id.
        return redirect()
            ->route('flows.editor', [
                'client'     => $client->slug,
                'id'         => $flow->id,
                'project_id' => $project->id,
            ])
            ->with('success', "Flow \"{$flow->name}\" created.");
    }

    public function editor(Request $request, Client $client, int $id): View
    {
        $projectId = $this->resolveProjectId($request, $client);
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
        $projectId = $this->resolveProjectId($request, $client);
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
    /** Returns JSON for the in-editor Activate button, a redirect for the list form. */
    public function update(Request $request, Client $client, int $id): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'nullable|string|max:160',
            'language'   => 'nullable|string|max:16',
            'status'     => 'nullable|in:draft,active,archived',
        ]);
        $flow = $this->loadFlow($client, $id, (int) $data['project_id']);

        // Going live is the one transition that can hurt: an active flow
        // intercepts real conversations, so a broken graph silently swallows
        // customer traffic instead of failing visibly. Refuse, and say why.
        if (($data['status'] ?? null) === Flow::STATUS_ACTIVE && $flow->status !== Flow::STATUS_ACTIVE) {
            $errors = $flow->activationErrors();
            if ($errors !== []) {
                $message = "Can't activate \"{$flow->name}\": " . implode(' ', $errors);

                if ($request->expectsJson()) {
                    return response()->json(['ok' => false, 'errors' => $errors, 'message' => $message], 422);
                }

                return back()->withErrors(['status' => $message]);
            }
        }

        if (!empty($data['name']))     $flow->name = $data['name'];
        if (!empty($data['language'])) $flow->language = $data['language'];
        if (!empty($data['status']))   $flow->status = $data['status'];
        $flow->update_at = time();
        $flow->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $flow->status, 'message' => "Flow \"{$flow->name}\" updated."]);
        }

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
    /**
     * Which project's tenant DB should we open for this request?
     *
     * The GET screens take project_id from the query string. A bare
     * `(int) $request->query('project_id')` turns a missing param into 0,
     * which then fails the ownership lookup as an opaque 404 — the symptom
     * when a link is bookmarked, shared, or built without it.
     *
     * When the client owns exactly one project the answer isn't ambiguous, so
     * fall back to it (the same thing index() already does). With none or
     * several we genuinely cannot guess: flows are stored per-project, so
     * picking one at random would show the wrong data or 404 anyway.
     */
    private function resolveProjectId(Request $request, Client $client): int
    {
        $projectId = (int) $request->query('project_id');
        if ($projectId > 0) {
            return $projectId;
        }

        $projects = Project::where('client_id', $client->id)->pluck('id');

        return $projects->count() === 1 ? (int) $projects->first() : 0;
    }

    // ── AI flow builder ──────────────────────────────────────────────

    /**
     * POST /c/{slug}/flows/ai/plan
     *
     * Draft a graph from a plain-language brief. Deliberately does NOT save:
     * the customer reads the summary and the gap report first, then either
     * creates the flow or reworks the brief. `flow_id` revises an existing
     * flow instead of starting fresh.
     */
    public function aiPlan(Request $request, Client $client, FlowPlanner $planner): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'brief'      => 'required|string|min:10|max:4000',
            'channel'    => 'nullable|in:voice,chat',
            'flow_id'    => 'nullable|integer',
            // The graph currently on the editor canvas, which may hold
            // unsaved edits.
            'definition' => 'nullable|array',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);
        $channel = $data['channel'] ?? NodeCatalog::CHANNEL_CHAT;

        // Revising: hand the planner the current graph so it edits rather
        // than replaces, and the customer keeps the work already done.
        //
        // The canvas wins over the stored row when both are available. In the
        // editor the two routinely differ — the customer has dragged nodes and
        // typed prompts without saving — and revising the saved version would
        // silently throw that work away.
        $existing = null;
        if (is_array($data['definition'] ?? null) && ! empty($data['definition']['nodes'])) {
            $existing = $data['definition'];
        } elseif (! empty($data['flow_id'])) {
            $flow = Flow::where('id', (int) $data['flow_id'])
                ->where('project_id', $project->id)
                ->first();
            $existing = is_array($flow?->definition) ? $flow->definition : null;
        }

        $plan = $planner->plan($project, $data['brief'], $channel, $existing);

        return response()->json($plan, $plan['ok'] ? 200 : 422);
    }

    /**
     * POST /c/{slug}/flows/ai/create
     *
     * Plan and, if the graph is valid, persist it as a draft so the customer
     * lands in the normal editor with it on the canvas. Always a draft:
     * activation stays a deliberate human act (see Flow::activationErrors).
     */
    public function aiCreate(
        Request $request,
        Client $client,
        FlowPlanner $planner,
        FlowValidator $validator,
    ): JsonResponse {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'required|string|max:160',
            'brief'      => 'required|string|min:10|max:4000',
            'channel'    => 'nullable|in:voice,chat',
            'language'   => 'nullable|string|max:16',
            // The graph the customer just previewed and approved.
            'definition' => 'nullable|array',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);
        $channel = $data['channel'] ?? NodeCatalog::CHANNEL_CHAT;

        if (is_array($data['definition'] ?? null)) {
            // Save what they approved. Re-planning here would quietly produce
            // a DIFFERENT flow from the one shown in the preview — same brief,
            // new generation. Re-validated because it arrived over the wire.
            $check = $validator->validate($data['definition'], [
                'channel'    => $channel,
                'source_ids' => DataSource::where('project_id', $project->id)->pluck('id')->all(),
                'agent_ids'  => BotAgent::where('project_id', $project->id)->pluck('id')->all(),
            ]);

            $plan = [
                'ok'       => $check['ok'],
                'definition' => $check['definition'],
                'summary'  => (string) $request->input('summary', ''),
                'steps'    => [], 'gaps' => [], 'assumptions' => [],
                'warnings' => $check['warnings'],
                'errors'   => $check['errors'],
                'repaired' => false,
            ];
        } else {
            $plan = $planner->plan($project, $data['brief'], $channel);
        }

        if (! $plan['ok'] || ! is_array($plan['definition'])) {
            return response()->json($plan, 422);
        }

        $now  = time();
        $lang = $data['language'] ?? 'en';

        $definition = $plan['definition'];
        $definition['settings']['language'] = $lang;

        $flow = Flow::create([
            'project_id' => $project->id,
            'name'       => $data['name'],
            'slug'       => Flow::generateSlug($data['name'], $project->id),
            'status'     => Flow::STATUS_DRAFT,
            'language'   => $lang,
            'definition' => $definition,
            'version'    => 1,
            'description'=> mb_substr($plan['summary'] ?: $data['brief'], 0, 500),
            'created_at' => $now,
            'update_at'  => $now,
        ]);

        return response()->json($plan + [
            'flow_id'     => $flow->id,
            'editor_url'  => route('flows.editor', [
                'client'     => $client->slug,
                'id'         => $flow->id,
                'project_id' => $project->id,
            ]),
        ]);
    }

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
