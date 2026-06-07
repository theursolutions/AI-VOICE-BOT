<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Twilio numbers are stored on the Project itself, in
 * json_data.telephony.numbers[]. No tenant DB query needed — single
 * pass over Projects in the master DB.
 */
class OpsTelephonyController extends Controller
{
    public function index(Request $request): View
    {
        $title = 'All Twilio numbers';
        $perPage = 25;

        $projects = Project::with([])->get(['id', 'name', 'client_id', 'json_data', 'is_active']);
        $clients  = Client::whereIn('id', $projects->pluck('client_id')->unique())->get(['id', 'name', 'slug'])->keyBy('id');

        $projectFilter = (int) $request->query('project_id', 0);
        $search        = trim((string) $request->query('q', ''));

        $rows = collect();
        foreach ($projects as $p) {
            if ($projectFilter > 0 && $p->id !== $projectFilter) continue;
            $numbers = (array) data_get($p->json_data, 'telephony.numbers', []);
            $c = $clients[$p->client_id] ?? null;
            foreach ($numbers as $idx => $n) {
                $number = (string) ($n['phone_number'] ?? '');
                if ($search !== '' && stripos($number, $search) === false &&
                    stripos((string)$p->name, $search) === false &&
                    stripos((string) $c?->name, $search) === false) continue;

                $rows->push([
                    'phone'         => $number,
                    'enabled'       => (bool) ($n['enabled'] ?? false),
                    'routing_type'  => $n['routing_type']  ?? 'agents',
                    'welcome_voice' => $n['welcome_voice'] ?? null,
                    'agent_ids'     => (array) ($n['agent_ids'] ?? []),
                    'skill_id'      => $n['skill_id']      ?? null,
                    'index'         => $idx,
                    'project_id'    => $p->id,
                    'project_name'  => $p->name,
                    'client_name'   => $c?->name,
                    'client_slug'   => $c?->slug,
                    'is_active'     => $p->is_active,
                ]);
            }
        }

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $projectList = $projects->where('is_active', 'Yes')->sortBy('name')->values();

        return view('ops.telephony.index', compact(
            'title', 'paginator', 'projectList', 'clients',
            'projectFilter', 'search'
        ));
    }
}
