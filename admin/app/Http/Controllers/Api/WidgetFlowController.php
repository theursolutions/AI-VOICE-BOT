<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\Project;
use App\Models\Session;
use App\Services\Flow\WebFlowRunner;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Widget-facing Flow runtime endpoints. Mirrors the phone-side
 * /api/telephony/twilio/flow-step shape but speaks JSON (the widget
 * protocol) instead of TwiML.
 *
 *   POST /api/v1/sessions/{id}/flow/step
 *     body: { choice_id?: "1", text?: "free-form" }
 *     returns: { messages, expecting, current_node_id, handoff, ended,
 *                cost_avoided }
 *
 *   POST /api/v1/sessions/{id}/flow/restart  (rarely used — admin recovery)
 *     returns: same shape; starts the bound flow from its Start node.
 *
 * Project scoping comes from the `project.apikey` middleware which
 * stamps the matched Project on the request. The session id in the URL
 * is then constrained to that project — no cross-tenant access.
 */
class WidgetFlowController extends Controller
{
    public function __construct(
        private TenantManager $tenants,
        private WebFlowRunner $runner,
    ) {}

    public function step(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'choice_id' => 'nullable|string|max:64',
            'text'      => 'nullable|string|max:4000',
        ]);

        [$project, $session, $flow] = $this->resolve($request, $id);
        if ($flow === null) {
            return response()->json([
                'error'   => 'no_flow_bound',
                'message' => 'This session is not in flow mode.',
            ], 409);
        }

        $result = $this->runner->step($project, $session, $flow, [
            'choice_id' => (string) ($data['choice_id'] ?? ''),
            'text'      => (string) ($data['text'] ?? ''),
        ]);

        return response()->json($result);
    }

    public function restart(Request $request, int $id): JsonResponse
    {
        [$project, $session, $flow] = $this->resolve($request, $id);
        if ($flow === null) {
            return response()->json(['error' => 'no_flow_bound'], 409);
        }

        // Wipe the cursor so start() runs from the top of the graph.
        $meta = (array) ($session->metadata ?? []);
        $meta[WebFlowRunner::META_KEY] = ['flow_id' => $flow->id, 'current_node_id' => null];
        $session->metadata = $meta;
        $session->save();

        return response()->json($this->runner->start($project, $session, $flow));
    }

    /**
     * Resolve project + session + flow for the request. Returns null
     * for $flow if the session isn't flow-bound (no flow_id in
     * metadata, or the flow row no longer exists / went archived).
     */
    private function resolve(Request $request, int $sessionId): array
    {
        /** @var Project $project */
        $project = $request->attributes->get('project');
        $this->tenants->useFor($project);

        $session = Session::where('id', $sessionId)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $flowId = (int) data_get($session->metadata, WebFlowRunner::META_KEY . '.flow_id', 0);
        $flow = $flowId > 0
            ? Flow::where('id', $flowId)
                ->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->first()
            : null;

        return [$project, $session, $flow];
    }
}
