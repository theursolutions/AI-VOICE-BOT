<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class OpsLeadsController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request): View
    {
        $title = 'All leads';
        $perPage = 25;
        $perTenantCap = 200;

        $projects = Project::query()->withTrashedRows()
            ->where('is_active', 'Yes')
            ->orderBy('name')
            ->get(['id', 'name', 'client_id']);
        $clients  = Client::query()->withTrashedRows()
            ->whereIn('id', $projects->pluck('client_id')->unique())
            ->get(['id', 'name', 'slug'])->keyBy('id');

        $projectFilter = (int) $request->query('project_id', 0);
        $status        = (string) $request->query('status', '');
        $search        = trim((string) $request->query('q', ''));
        $withDeleted   = (bool) $request->query('with_deleted');

        $rows = collect();
        $apply = function ($q) use ($status, $search) {
            if ($status) $q->where('status', $status);
            if ($search !== '') {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like, $search) {
                    $w->where('fields', 'like', $like)
                      ->orWhere('notes', 'like', $like);
                    if (ctype_digit($search)) $w->orWhere('id', (int) $search);
                });
            }
            return $q;
        };

        $build = function () use ($withDeleted) {
            $q = Lead::query();
            if ($withDeleted) $q->withTrashedRows();
            return $q;
        };

        $iterate = function (Project $p) use ($apply, $build, $clients, $perTenantCap, &$rows) {
            $this->tenants->useFor($p);
            try {
                $leads = $apply($build()->where('project_id', $p->id))
                    ->orderByDesc('id')->limit($perTenantCap)->get();
                $c = $clients[$p->client_id] ?? null;
                foreach ($leads as $l) {
                    $rows->push([
                        'id'           => $l->id,
                        'project_id'   => $p->id,
                        'project_name' => $p->name,
                        'client_id'    => $p->client_id,
                        'client_name'  => $c?->name,
                        'client_slug'  => $c?->slug,
                        'fields'       => $l->fields ?? [],
                        'status'       => $l->status,
                        'confidence'   => $l->confidence ?? 0,
                        'session_id'   => $l->session_id,
                        'created_at'   => $l->created_at,
                        'deleted_at'   => $l->deleted_at,
                    ]);
                }
            } catch (\Throwable $e) {}
        };

        if ($projectFilter > 0) {
            $p = $projects->firstWhere('id', $projectFilter);
            if ($p) $iterate(Project::query()->withTrashedRows()->find($p->id));
        } else {
            foreach ($projects as $p) $iterate(Project::query()->withTrashedRows()->find($p->id));
        }

        $rows = $rows->sortByDesc('id')->values();

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('ops.leads.index', compact(
            'title', 'paginator', 'projects', 'clients',
            'projectFilter', 'status', 'search', 'withDeleted'
        ));
    }
}
