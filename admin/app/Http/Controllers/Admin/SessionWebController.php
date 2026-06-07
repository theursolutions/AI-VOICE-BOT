<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Workspace-scoped browser controller for browsing chat sessions and
 * their message threads. All session/message rows live in the
 * per-project tenant DB, so we switch the `tenant` connection via
 * TenantManager before each query.
 */
class SessionWebController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $channel   = $request->query('channel');
        $status    = $request->query('status');
        $search    = trim((string) $request->query('q', ''));
        $perPage   = (int) ($request->query('per_page', 25));
        if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 25;

        $sessions = null;
        $project = null;
        $counts  = [
            'total' => 0, 'active' => 0, 'ended' => 0, 'abandoned' => 0,
        ];

        if ($projectId) {
            $project = $projects->firstWhere('id', $projectId);
            if ($project) {
                $this->tenants->useFor($project);

                // Pill counters — full project totals so they don't
                // shift while the user filters the table below.
                $counts['total']     = Session::where('project_id', $projectId)->count();
                $counts['active']    = Session::where('project_id', $projectId)->where('status', 'active')->count();
                $counts['ended']     = Session::where('project_id', $projectId)->where('status', 'ended')->count();
                $counts['abandoned'] = Session::where('project_id', $projectId)->where('status', 'abandoned')->count();

                $q = Session::query()->where('project_id', $projectId);
                if ($channel) $q->where('channel', $channel);
                if ($status)  $q->where('status', $status);
                if ($search !== '') {
                    $like = '%' . $search . '%';
                    $q->where(function ($w) use ($like, $search) {
                        $w->where('customer_name', 'like', $like)
                          ->orWhere('customer_email', 'like', $like)
                          ->orWhere('customer_phone', 'like', $like)
                          ->orWhere('external_id', 'like', $like);
                        if (ctype_digit($search)) {
                            $w->orWhere('id', (int) $search);
                        }
                    });
                }

                $sessions = $q->orderByDesc('last_activity_at')
                    ->paginate($perPage)
                    ->withQueryString();
            }
        }

        return view('sessions.index', compact(
            'client', 'projects', 'project', 'projectId',
            'sessions', 'channel', 'status', 'search', 'perPage', 'counts'
        ));
    }

    public function show(Request $request, Client $client, int $id): View
    {
        $projectId = (int) $request->query('project_id');
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        if (!$projectId) {
            $projectId = (int) optional($projects->first())->id;
        }
        $project = $projects->firstWhere('id', $projectId);
        abort_unless($project, 404, 'Project not found for this workspace.');

        $this->tenants->useFor($project);

        $session = Session::with('voice')->findOrFail($id);
        abort_unless((int) $session->project_id === $projectId, 404);

        $messages = Message::where('session_id', $session->id)
            ->orderBy('created_at')
            ->get();

        $lead = $session->lead;

        return view('sessions.show', compact(
            'client', 'project', 'projectId', 'session', 'messages', 'lead'
        ));
    }
}
