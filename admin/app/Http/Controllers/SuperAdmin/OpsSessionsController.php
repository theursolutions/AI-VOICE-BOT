<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class OpsSessionsController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request): View
    {
        $title = 'All conversations';
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
        $channel       = (string) $request->query('channel', '');
        $status        = (string) $request->query('status', '');
        $search        = trim((string) $request->query('q', ''));
        $withDeleted   = (bool) $request->query('with_deleted');

        $decorate = function (Session $s, Project $p) use ($clients): array {
            $c = $clients[$p->client_id] ?? null;
            return [
                'id'              => $s->id,
                'project_id'      => $p->id,
                'project_name'    => $p->name,
                'client_id'       => $p->client_id,
                'client_name'     => $c?->name,
                'client_slug'     => $c?->slug,
                'channel'         => $s->channel,
                'status'          => $s->status,
                'customer_name'   => $s->customer_name,
                'customer_email'  => $s->customer_email,
                'customer_phone'  => $s->customer_phone,
                'external_id'     => $s->external_id,
                'started_at'      => $s->started_at,
                'last_activity_at'=> $s->last_activity_at,
                'deleted_at'      => $s->deleted_at,
            ];
        };

        $rows = collect();

        $build = function () use ($withDeleted) {
            $q = Session::query();
            if ($withDeleted) $q->withTrashedRows();
            return $q;
        };

        $applyFilters = function ($q) use ($channel, $status, $search) {
            if ($channel) $q->where('channel', $channel);
            if ($status)  $q->where('status', $status);
            if ($search !== '') {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like, $search) {
                    $w->where('customer_name',  'like', $like)
                      ->orWhere('customer_email','like', $like)
                      ->orWhere('customer_phone','like', $like)
                      ->orWhere('external_id',   'like', $like);
                    if (ctype_digit($search)) $w->orWhere('id', (int) $search);
                });
            }
            return $q;
        };

        if ($projectFilter > 0) {
            $p = $projects->firstWhere('id', $projectFilter);
            if ($p) {
                $this->tenants->useFor(Project::query()->withTrashedRows()->find($p->id));
                $sessions = $applyFilters($build()->where('project_id', $p->id))
                    ->orderByDesc('last_activity_at')
                    ->limit(5000)->get();
                foreach ($sessions as $s) $rows->push($decorate($s, $p));
            }
        } else {
            foreach ($projects as $p) {
                $this->tenants->useFor(Project::query()->withTrashedRows()->find($p->id));
                try {
                    $sessions = $applyFilters($build()->where('project_id', $p->id))
                        ->orderByDesc('last_activity_at')
                        ->limit($perTenantCap)
                        ->get();
                    foreach ($sessions as $s) $rows->push($decorate($s, $p));
                } catch (\Throwable $e) {}
            }
        }

        $rows = $rows->sortByDesc('last_activity_at')->values();

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('ops.sessions.index', compact(
            'title', 'paginator', 'projects', 'clients',
            'projectFilter', 'channel', 'status', 'search', 'withDeleted'
        ));
    }

    public function show(Request $request, int $projectId, int $id): View
    {
        $project = Project::query()->withTrashedRows()->findOrFail($projectId);
        $client  = Client::query()->withTrashedRows()->find($project->client_id);

        $this->tenants->useFor($project);
        $session = Session::query()->withTrashedRows()->findOrFail($id);
        abort_unless((int) $session->project_id === $projectId, 404);

        $messages = Message::where('session_id', $session->id)
            ->orderBy('created_at')->get();

        $title = "Conversation #{$session->id}";
        return view('ops.sessions.show', compact('title', 'project', 'client', 'session', 'messages'));
    }
}
