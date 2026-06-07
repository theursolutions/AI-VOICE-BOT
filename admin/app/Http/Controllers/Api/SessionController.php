<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\AgentRouter;
use App\Services\Conversation\SessionTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private SessionTokenService $tokens,
        private AgentRouter $router,
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
            'metadata'       => 'nullable|array',
        ]);

        $now = time();

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
            'metadata'         => $data['metadata'] ?? null,
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

        return response()->json([
            'session_id' => $session->id,
            'token'      => $token,
            'ws_url'     => config('services.python.ws_url'),
            'expires_in' => config('services.python.token_ttl', 3600),
        ], 201);
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
