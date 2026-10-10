<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\WebCallSession;
use App\Services\Telephony\SipAccounts;
use App\Services\Telephony\SipServer;
use App\Services\Telephony\WebCalls;
use Illuminate\Http\RedirectResponse;
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

    /**
     * The SIP/WebRTC server every client's extensions and website call
     * buttons use: where browsers and phones reach Asterisk, and the
     * STUN/TURN servers that get browser audio through routers.
     */
    public function sip(SipServer $server, SipAccounts $accounts): View
    {
        return view('ops.telephony.sip', [
            'title'     => 'SIP & WebRTC server',
            'settings'  => $server->settings(),
            // Reachable = the voice engine answered with a list (even empty).
            'connected' => $accounts->states('zz_') !== null,
        ]);
    }

    /**
     * Who actually tried a website "Call us" button: calls that connected,
     * different visitors (IPs), how long they talked, and clicks that never
     * became a call — across every project, or one.
     */
    public function webCalls(Request $request): View
    {
        $projectId = (int) $request->query('project_id', 0);
        $base = WebCallSession::query()->when($projectId > 0, fn ($q) => $q->where('project_id', $projectId));

        $calls   = (clone $base)->whereNotNull('started_at');
        $ended   = (clone $calls)->whereNotNull('ended_at')->get(['started_at', 'ended_at']);
        $avgSecs = $ended->isEmpty() ? null
            : (int) round($ended->avg(fn ($c) => $c->started_at->diffInSeconds($c->ended_at, true)));

        $stats = [
            'calls'      => (clone $calls)->count(),
            'visitors'   => (clone $calls)->distinct()->count('ip'),
            'today'      => (clone $calls)->where('started_at', '>=', now()->startOfDay())->count(),
            'week'       => (clone $calls)->where('started_at', '>=', now()->subDays(7))->count(),
            'avg_secs'   => $avgSecs,
            'no_connect' => (clone $base)->where('status', WebCallSession::EXPIRED)->count(),
        ];

        // Calls per day, last 14 days (oldest first).
        $perDay = (clone $calls)->where('started_at', '>=', now()->subDays(13)->startOfDay())
            ->get(['started_at'])->groupBy(fn ($c) => $c->started_at->toDateString())->map->count();
        $days = collect(range(13, 0))->mapWithKeys(fn ($d) => [now()->subDays($d)->toDateString() => 0])->merge($perDay);

        $recent = (clone $calls)->orderByDesc('started_at')->limit(50)
            ->get(['id', 'project_id', 'client_id', 'ip', 'origin', 'page_url', 'status', 'counts_toward_limit', 'session_id', 'started_at', 'ended_at']);

        $projectIds = WebCallSession::distinct()->pluck('project_id');
        $projects   = Project::whereIn('id', $projectIds->merge($recent->pluck('project_id'))->unique())->get(['id', 'name', 'client_id'])->keyBy('id');
        $clients    = Client::whereIn('id', $projects->pluck('client_id')->unique())->get(['id', 'name'])->keyBy('id');

        return view('ops.telephony.web-calls', [
            'title'     => 'Website calls',
            'stats'     => $stats,
            'days'      => $days,
            'recent'    => $recent,
            'projects'  => $projects,
            'clients'   => $clients,
            'projectId' => $projectId,
        ]);
    }

    /** "Reset limit": this IP may call again now (its calls stay in the stats). */
    public function resetWebCallLimit(Request $request, WebCalls $calls): RedirectResponse
    {
        $data = $request->validate([
            'ip'         => 'required|ip',
            'project_id' => 'nullable|integer',
        ], ['ip.ip' => 'That is not a valid IP address.']);

        $projectIds = ! empty($data['project_id']) ? [(int) $data['project_id']] : null;
        $n = $calls->resetLimit($data['ip'], $projectIds);

        return back()->with('success', "Limit reset for {$data['ip']} — they can call again now"
            . ($n ? " ({$n} earlier call" . ($n === 1 ? '' : 's') . ' no longer counted).' : '.'));
    }

    public function saveSip(Request $request, SipServer $server): RedirectResponse
    {
        $data = $request->validate([
            'ws_url'          => ['required', 'string', 'max:255', 'regex:#^wss?://#i'],
            'host'            => 'required|string|max:255',
            'port'            => 'required|integer|min:1|max:65535',
            'stun'            => 'nullable|string|max:1000',
            'turn_url'        => ['nullable', 'string', 'max:500', 'regex:#^turns?:#i'],
            'turn_username'   => 'nullable|string|max:255',
            'turn_credential' => 'nullable|string|max:255',
        ], [
            'ws_url.regex'   => 'The WebSocket URL starts with wss:// (or ws:// for a local test).',
            'turn_url.regex' => 'TURN URLs start with turn: or turns:',
        ]);

        $data['stun'] = preg_split('/[\s,]+/', (string) ($data['stun'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        // An empty password field keeps the saved one (it is never echoed back).
        if (($data['turn_credential'] ?? '') === '') {
            $data['turn_credential'] = $server->settings()['turn_credential'];
        }
        $server->save($data);

        return back()->with('success', 'SIP & WebRTC server saved.');
    }
}
