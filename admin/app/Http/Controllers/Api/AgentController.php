<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin endpoints to manage customer-hosted agents.
 *
 * Authenticates via X-CLIENT-API-KEY (project.apikey middleware) —
 * mounted under the project-scoped /api/v1/ group.
 */
class AgentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $project = $request->attributes->get('project');

        $agents = Agent::where('project_id', $project->id)
            ->orderByDesc('id')
            ->get(['id','name','agent_uid','status','enrolled_at','last_seen_at','client_version']);

        return response()->json(['data' => $agents]);
    }

    public function store(Request $request): JsonResponse
    {
        $project = $request->attributes->get('project');

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $now = time();
        $enrollmentToken = Str::random(48);

        $agent = Agent::create([
            'project_id'                  => $project->id,
            'name'                        => $data['name'],
            'agent_uid'                   => (string) Str::uuid(),
            'enrollment_token'            => $enrollmentToken,
            'enrollment_token_expires_at' => $now + 86400, // 24h
            'status'                      => Agent::STATUS_PENDING,
            'created_at'                  => $now,
            'update_at'                   => $now,
        ]);

        return response()->json([
            'agent'            => $agent->only(['id','name','agent_uid','status']),
            'enrollment_token' => $enrollmentToken,
            'enroll_url'       => url('/api/v1/agent/enroll'),
            'expires_at'       => $agent->enrollment_token_expires_at,
            'docker_run'       => "docker run -d --name aicrm-agent \\\n".
                "  -e ENROLLMENT_TOKEN={$enrollmentToken} \\\n".
                "  -e LARAVEL_BASE_URL=".rtrim(config('app.url'), '/')." \\\n".
                "  -e DB_HOST=<your-db-host> \\\n".
                "  -e DB_PORT=3306 \\\n".
                "  -e DB_NAME=<your-db> \\\n".
                "  -e DB_USER=<readonly-user> \\\n".
                "  -e DB_PASSWORD=<password> \\\n".
                "  aicrm/query-agent:latest",
        ], 201);
    }

    public function regenerate(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $agent = Agent::where('id', $id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $enrollmentToken = Str::random(48);
        $agent->update([
            'enrollment_token'            => $enrollmentToken,
            'enrollment_token_expires_at' => time() + 86400,
            'token_hash'                  => null,
            'status'                      => Agent::STATUS_PENDING,
            'enrolled_at'                 => null,
            'update_at'                   => time(),
        ]);

        return response()->json([
            'enrollment_token' => $enrollmentToken,
            'expires_at'       => $agent->enrollment_token_expires_at,
        ]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $project = $request->attributes->get('project');

        $agent = Agent::where('id', $id)
            ->where('project_id', $project->id)
            ->firstOrFail();

        $agent->update([
            'status'    => Agent::STATUS_REVOKED,
            'is_active' => 'No',
            'update_at' => time(),
        ]);

        return response()->json(['revoked' => true]);
    }
}
