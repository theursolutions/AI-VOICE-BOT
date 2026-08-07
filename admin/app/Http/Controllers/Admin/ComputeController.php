<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\PythonClient;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Live "compute mesh" — real load metrics (queue depth, active conversations,
 * throughput, live voice calls) and the DESIRED fleet scale derived from
 * them. The dashboard animates worker/voice nodes scaling with load. Actual
 * process provisioning is the orchestrator's job (Supervisor / k8s).
 */
class ComputeController extends Controller
{
    private const JOBS_PER_WORKER    = 5;   // queued jobs one worker keeps up with
    private const CALLS_PER_INSTANCE = 4;   // concurrent calls one voice instance holds

    public function __construct(private TenantManager $tenants, private PythonClient $python) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)->orderBy('name')->get(['id', 'name']);
        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        return view('compute.index', compact('client', 'projects', 'project', 'projectId'));
    }

    public function metrics(Request $request, Client $client): JsonResponse
    {
        $project = Project::where('client_id', $client->id)
            ->where('id', (int) $request->query('project_id'))
            ->first();

        // ── Queue (app DB) ──
        $pending = 0;
        $failed = 0;
        try { $pending = (int) DB::table('jobs')->count(); } catch (\Throwable $e) {}
        try { $failed = (int) DB::table('failed_jobs')->count(); } catch (\Throwable $e) {}

        // ── Conversation load (tenant, selected project) ──
        $activeSessions = 0;
        $msgsPerMin = 0;
        $msgs5 = 0;
        if ($project) {
            $this->tenants->useFor($project);
            $now = time();
            $activeSessions = (int) Session::where('project_id', $project->id)->where('status', 'active')->count();
            $msgsPerMin = (int) Message::where('project_id', $project->id)->where('created_at', '>=', $now - 60)->count();
            $msgs5 = (int) Message::where('project_id', $project->id)->where('created_at', '>=', $now - 300)->count();
        }

        // ── Voice engine ──
        $ve = $this->python->metrics();
        $activeCalls = (int) ($ve['active_calls'] ?? 0);

        // ── Derived desired scale ──
        $textLoad = max($pending, $msgsPerMin);
        $desiredWorkers = max(1, (int) ceil($textLoad / self::JOBS_PER_WORKER));
        $desiredVoice = max(1, (int) ceil(max(0, $activeCalls) / self::CALLS_PER_INSTANCE));

        return response()->json([
            'queue'  => ['driver' => config('queue.default'), 'pending' => $pending, 'failed' => $failed],
            'load'   => ['active_sessions' => $activeSessions, 'msgs_per_min' => $msgsPerMin, 'msgs_5min' => $msgs5, 'active_calls' => $activeCalls],
            'scale'  => [
                'workers' => $desiredWorkers,
                'voice'   => $desiredVoice,
                'jobs_per_worker'    => self::JOBS_PER_WORKER,
                'calls_per_instance' => self::CALLS_PER_INSTANCE,
            ],
            'llm'    => [
                'provider'      => $ve['llm_provider'] ?? 'groq',
                'fallback'      => $ve['llm_fallback'] ?? null,
                'calls_per_min' => $msgsPerMin * 2,   // ToolPicker + reply (approx)
            ],
            'engine' => ['reachable' => $ve !== null, 'stt' => $ve['stt_ready'] ?? null, 'tts' => $ve['tts_ready'] ?? null],
            'ts'     => time(),
        ]);
    }
}
