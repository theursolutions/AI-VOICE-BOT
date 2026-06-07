<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Endpoints called by the customer-hosted query-agent.
 *
 *   POST /api/v1/agent/enroll  — one-shot. Exchange a one-time
 *                                enrollment_token for a long-lived bearer.
 *   GET  /api/v1/agent/poll    — long-poll for work (up to ~25s).
 *   POST /api/v1/agent/result  — submit query result.
 *
 * Auth model: enrollment uses the enrollment_token in the body;
 * poll/result use Bearer <long-lived token>. We compare hashes only
 * (the plaintext token is never stored).
 */
class AgentApiController extends Controller
{
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_token' => 'required|string|size:48',
            'client_version'   => 'nullable|string|max:32',
        ]);

        $agent = Agent::where('enrollment_token', $data['enrollment_token'])->first();

        if (!$agent
            || $agent->status === Agent::STATUS_REVOKED
            || ($agent->enrollment_token_expires_at && $agent->enrollment_token_expires_at < time())) {
            return response()->json(['error' => 'Invalid or expired enrollment token'], 401);
        }

        $bearer = Str::random(64);

        $agent->update([
            'token_hash'                  => hash('sha256', $bearer),
            'enrollment_token'            => null,
            'enrollment_token_expires_at' => null,
            'enrolled_at'                 => time(),
            'last_seen_at'                => time(),
            'status'                      => Agent::STATUS_ACTIVE,
            'client_version'              => $data['client_version'] ?? null,
            'update_at'                   => time(),
        ]);

        return response()->json([
            'agent_uid' => $agent->agent_uid,
            'token'     => $bearer,
            'poll_url'  => url('/api/v1/agent/poll'),
            'result_url'=> url('/api/v1/agent/result'),
        ], 201);
    }

    public function poll(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $waitSeconds = min(25, max(1, (int) $request->query('wait', 25)));
        $intervalMs  = 250;
        $deadline    = microtime(true) + $waitSeconds;

        do {
            $picked = DB::transaction(function () use ($agent) {
                $row = AgentQuery::where('agent_id', $agent->id)
                    ->where('status', AgentQuery::STATUS_PENDING)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (!$row) {
                    return null;
                }

                $row->update([
                    'status'    => AgentQuery::STATUS_IN_PROGRESS,
                    'picked_at' => time(),
                ]);

                return $row;
            });

            if ($picked) {
                $agent->update(['last_seen_at' => time(), 'update_at' => time()]);
                return response()->json([
                    'work' => [
                        'request_id' => $picked->request_id,
                        'sql'        => $picked->sql,
                        'params'     => $picked->params ?? [],
                        'max_rows'   => $picked->max_rows,
                    ],
                ]);
            }

            usleep($intervalMs * 1000);
        } while (microtime(true) < $deadline);

        $agent->update(['last_seen_at' => time(), 'update_at' => time()]);
        return response()->json(['work' => null]);
    }

    public function result(Request $request): JsonResponse
    {
        $agent = $this->authenticate($request);
        if ($agent instanceof JsonResponse) {
            return $agent;
        }

        $data = $request->validate([
            'request_id' => 'required|string|max:64',
            'status'     => 'required|in:done,failed',
            'rows'       => 'nullable|array',
            'error'      => 'nullable|string',
        ]);

        $row = AgentQuery::where('request_id', $data['request_id'])
            ->where('agent_id', $agent->id)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'Unknown request_id'], 404);
        }

        $row->update([
            'status'       => $data['status'] === 'done' ? AgentQuery::STATUS_DONE : AgentQuery::STATUS_FAILED,
            'result'       => $data['status'] === 'done' ? ($data['rows'] ?? []) : null,
            'error'        => $data['error'] ?? null,
            'completed_at' => time(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function authenticate(Request $request): Agent|JsonResponse
    {
        $header = $request->header('Authorization', '');
        $token  = preg_replace('/^Bearer\s+/i', '', (string) $header);

        if (!$token) {
            return response()->json(['error' => 'Missing token'], 401);
        }

        $agent = Agent::where('token_hash', hash('sha256', $token))
            ->where('status', Agent::STATUS_ACTIVE)
            ->first();

        if (!$agent) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        return $agent;
    }
}
