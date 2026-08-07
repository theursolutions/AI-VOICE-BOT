<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\AgentRouter;
use App\Services\Conversation\SessionTokenService;
use App\Services\Flow\WebFlowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private SessionTokenService $tokens,
        private AgentRouter $router,
        private WebFlowRunner $flowRunner,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $project = $request->attributes->get('project');

        $data = $request->validate([
            'channel'        => 'required|in:web,whatsapp,twilio,plivo,api',
            'external_id'    => 'nullable|string|max:191',
            'customer_name'  => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:32',
            'customer_email' => 'nullable|email|max:255',
            'voice_id'       => 'nullable|integer',
            'language'       => 'nullable|string|max:10',
            'metadata'       => 'nullable|array',
        ]);

        $now = time();

        // Persist the visitor's language pick (widget header dropdown) onto
        // the session metadata so SessionTokenService::mint() can bake it
        // into the JWT claims and MemoryBuilder can use it as the fallback.
        $metadata = $data['metadata'] ?? [];
        if (!empty($data['language'])) {
            $metadata['language'] = $data['language'];
        }

        $session = Session::create([
            'project_id'       => $project->id,
            'channel'          => $data['channel'],
            'external_id'      => $data['external_id']    ?? null,
            'customer_name'    => $data['customer_name']  ?? null,
            'customer_phone'   => $data['customer_phone'] ?? null,
            'customer_email'   => $data['customer_email'] ?? null,
            'voice_id'         => $data['voice_id']       ?? null,
            'status'           => 'active',
            'started_at'       => $now,
            'last_activity_at' => $now,
            'metadata'         => $metadata ?: null,
            'created_at'       => $now,
            'update_at'        => $now,
        ]);

        // Webchat sessions don't carry per-number routing — fall back
        // to the project's default agent. Sets agent_id + voice_id so
        // the LLM uses that agent's persona and TTS uses its cloned
        // voice.
        $this->router->assignToSession($project, $session);
        $session->refresh();

        $token = $this->tokens->mint($session);

        // If the widget is bound to a default Flow, walk it and return
        // the initial messages so the widget can render the first
        // bubble/menu immediately on session open. No round-trip to
        // /turn or to Python required to start the conversation.
        $flowBootstrap = $this->maybeStartFlow($project, $session, $data['channel']);

        return response()->json([
            'session_id'     => $session->id,
            'token'          => $token,
            'ws_url'         => config('services.python.ws_url'),
            'expires_in'     => config('services.python.token_ttl', 3600),
            'flow'           => $flowBootstrap, // null if no flow is bound
        ], 201);
    }

    /**
     * Webchat opening hook: if `projects.json_data.widget.default_flow_id`
     * points at an active flow, run WebFlowRunner::start() now and bake
     * the initial output into the session-start response. The widget
     * then renders messages without needing a second round-trip.
     *
     * For non-web channels we skip — phone uses the TelephonyController
     * flow path, and api/whatsapp don't have a widget runtime yet.
     */
    private function maybeStartFlow(Project $project, Session $session, string $channel): ?array
    {
        if ($channel !== 'web') return null;

        $flowId = (int) data_get($project->json_data, 'widget.default_flow_id', 0);
        if ($flowId <= 0) return null;

        $flow = Flow::where('id', $flowId)
            ->where('project_id', $project->id)
            ->where('status', Flow::STATUS_ACTIVE)
            ->whereNull('deleted_at')
            ->first();
        if (!$flow) return null;

        // Stamp the flow on the session so /widget/flow/step can find it.
        $meta = (array) ($session->metadata ?? []);
        $meta[WebFlowRunner::META_KEY] = [
            'flow_id'         => $flow->id,
            'current_node_id' => null,
        ];
        $session->metadata = $meta;
        $session->save();

        return $this->flowRunner->start($project, $session, $flow);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $session = Session::where('id', $id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        if ($session->status === 'active') {
            $session->status = 'ended';
            $session->ended_at = time();
            $session->update_at = time();
            $session->save();
        }

        return response()->json(['session_id' => $session->id, 'status' => $session->status]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $session = Session::with(['messages', 'lead', 'summary'])
            ->where('id', $id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        return response()->json($session);
    }
}
