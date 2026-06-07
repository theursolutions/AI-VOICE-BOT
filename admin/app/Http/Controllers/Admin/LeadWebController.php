<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeadWebController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $status    = $request->query('status');
        $search    = trim((string) $request->query('q', ''));
        $perPage   = (int) ($request->query('per_page', 25));
        if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 25;

        $leads   = null;
        $project = null;
        $counts  = [
            'total' => 0, 'new' => 0, 'qualified' => 0, 'converted' => 0,
        ];

        if ($projectId) {
            $project = $projects->firstWhere('id', $projectId);
            if ($project) {
                $this->tenants->useFor($project);

                // Status pill counters (always over the full project, not
                // the current filter — so the pill values stay stable as
                // the user toggles between filters).
                $counts['total']     = Lead::where('project_id', $projectId)->count();
                $counts['new']       = Lead::where('project_id', $projectId)->where('status', 'new')->count();
                $counts['qualified'] = Lead::where('project_id', $projectId)->where('status', 'qualified')->count();
                $counts['converted'] = Lead::where('project_id', $projectId)->where('status', 'converted')->count();

                $q = Lead::query()->where('project_id', $projectId);
                if ($status) $q->where('status', $status);
                if ($search !== '') {
                    $like = '%' . $search . '%';
                    $q->where(function ($w) use ($like, $search) {
                        $w->where('fields', 'like', $like)
                          ->orWhere('notes', 'like', $like);
                        if (ctype_digit($search)) {
                            $w->orWhere('id', (int) $search);
                            $w->orWhere('session_id', (int) $search);
                        }
                    });
                }

                $leads = $q->with('session')
                    ->orderByDesc('id')
                    ->paginate($perPage)
                    ->withQueryString();
            }
        }

        return view('leads.index', compact(
            'client', 'projects', 'project', 'projectId',
            'leads', 'status', 'search', 'perPage', 'counts'
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

        $lead = Lead::with('session')->findOrFail($id);
        abort_unless((int) $lead->project_id === $projectId, 404);

        return view('leads.show', compact(
            'client', 'project', 'projectId', 'lead'
        ));
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'status'     => 'required|in:new,contacted,qualified,converted,disqualified',
            'notes'      => 'nullable|string|max:2000',
        ]);

        $project = Project::where('client_id', $client->id)
            ->where('id', $data['project_id'])
            ->firstOrFail();
        $this->tenants->useFor($project);

        $lead = Lead::findOrFail($id);
        abort_unless((int) $lead->project_id === (int) $data['project_id'], 404);

        $lead->status = $data['status'];
        if (array_key_exists('notes', $data)) {
            $lead->notes = $data['notes'];
        }
        $lead->save();

        return redirect()
            ->route('leads.show', ['client' => $client->slug, 'id' => $lead->id])
            ->withInput(['project_id' => $project->id])
            ->with('success', 'Lead updated.');
    }
}
