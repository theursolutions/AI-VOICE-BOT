<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Voice;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class OpsVoicesController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request): View
    {
        $title = 'All voices';
        $perPage = 25;

        $projects = Project::query()->withTrashedRows()
            ->where('is_active', 'Yes')->orderBy('name')->get(['id', 'name', 'client_id']);
        $clients  = Client::query()->withTrashedRows()
            ->whereIn('id', $projects->pluck('client_id')->unique())
            ->get(['id', 'name', 'slug'])->keyBy('id');

        $projectFilter = (int) $request->query('project_id', 0);
        $search        = trim((string) $request->query('q', ''));
        $withDeleted   = (bool) $request->query('with_deleted');

        $rows = collect();
        foreach ($projects as $p) {
            if ($projectFilter > 0 && $p->id !== $projectFilter) continue;
            $this->tenants->useFor(Project::query()->withTrashedRows()->find($p->id));
            try {
                $q = Voice::query();
                if ($withDeleted) $q->withTrashedRows();
                $q->where('project_id', $p->id);
                if ($search !== '') {
                    $like = '%' . $search . '%';
                    $q->where(function ($w) use ($like) {
                        $w->where('name','like',$like)->orWhere('language','like',$like)->orWhere('provider','like',$like);
                    });
                }
                $voices = $q->orderByDesc('id')->limit(500)->get();
                $c = $clients[$p->client_id] ?? null;
                foreach ($voices as $v) {
                    $rows->push([
                        'id'           => $v->id,
                        'project_id'   => $p->id,
                        'project_name' => $p->name,
                        'client_name'  => $c?->name,
                        'client_slug'  => $c?->slug,
                        'name'         => $v->name,
                        'provider'     => $v->provider,
                        'language'     => $v->language,
                        'status'       => $v->status,
                        'external_id'  => $v->external_id,
                        'created_at'   => $v->created_at,
                        'deleted_at'   => $v->deleted_at,
                    ]);
                }
            } catch (\Throwable $e) {}
        }

        $rows = $rows->sortByDesc('id')->values();
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('ops.voices.index', compact(
            'title', 'paginator', 'projects', 'clients',
            'projectFilter', 'search', 'withDeleted'
        ));
    }
}
