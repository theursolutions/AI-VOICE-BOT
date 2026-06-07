<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(private TenantManager $tenants)
    {
        $this->middleware('auth');
    }

    public function index(Request $request): View
    {
        /** @var Client|null $client */
        $client = $request->attributes->get('client');

        $projects = $client
            ? Project::where('client_id', $client->id)->orderBy('name')->get(['id', 'name'])
            : collect();

        // Each project lives in its own tenant DB. We iterate, switch
        // the connection, run aggregate queries, and merge the totals.
        $totals = [
            'sessions'    => 0,
            'leads'       => 0,
            'messages'    => 0,
            'voice_msgs'  => 0,
            'conversions' => 0,
        ];

        // Per-day session count for the last 14 days.
        $now = time();
        $dayCount = 14;
        $startOfWindow = strtotime(date('Y-m-d', $now - ($dayCount - 1) * 86400));

        // dayKey (Y-m-d) → count
        $sessionsPerDay = [];
        for ($i = 0; $i < $dayCount; $i++) {
            $sessionsPerDay[date('Y-m-d', $startOfWindow + $i * 86400)] = 0;
        }

        $channelBreakdown = [
            'web'   => 0,
            'voice' => 0,
            'phone' => 0,
            'sms'   => 0,
        ];

        $leadFunnel = [
            'new'           => 0,
            'contacted'     => 0,
            'qualified'     => 0,
            'converted'     => 0,
            'disqualified'  => 0,
        ];

        $recentSessions = collect();
        $recentLeads    = collect();
        $primaryProject = $projects->first();

        foreach ($projects as $p) {
            $this->tenants->useFor(Project::find($p->id));

            $totals['sessions']   += Session::count();
            $totals['leads']      += Lead::count();
            $totals['messages']   += Message::count();
            $totals['voice_msgs'] += Message::whereNotNull('audio_url')->count();
            $totals['conversions'] += Lead::where('status', 'converted')->count();

            foreach (array_keys($channelBreakdown) as $ch) {
                $channelBreakdown[$ch] += Session::where('channel', $ch)->count();
            }
            foreach (array_keys($leadFunnel) as $st) {
                $leadFunnel[$st] += Lead::where('status', $st)->count();
            }

            // Sessions per day (last 14)
            $rows = Session::where('started_at', '>=', $startOfWindow)
                ->where('started_at', '<=', $now)
                ->get(['started_at'])
                ->groupBy(fn ($s) => date('Y-m-d', (int) $s->started_at));
            foreach ($rows as $day => $group) {
                if (isset($sessionsPerDay[$day])) {
                    $sessionsPerDay[$day] += $group->count();
                }
            }

            // Recent sessions/leads, tagged with their project name.
            $sessTop = Session::orderByDesc('last_activity_at')
                ->limit(5)
                ->get(['id', 'project_id', 'customer_name', 'channel', 'status', 'last_activity_at']);
            foreach ($sessTop as $s) {
                $s->_project_name = $p->name;
                $recentSessions->push($s);
            }

            $leadTop = Lead::orderByDesc('id')
                ->limit(5)
                ->get(['id', 'project_id', 'fields', 'status', 'confidence', 'session_id']);
            foreach ($leadTop as $l) {
                $l->_project_name = $p->name;
                $recentLeads->push($l);
            }
        }

        // Re-rank merged collections.
        $recentSessions = $recentSessions->sortByDesc('last_activity_at')->values()->take(5);
        $recentLeads    = $recentLeads->sortByDesc('id')->values()->take(5);

        $conversionRate = $totals['leads'] > 0
            ? round(($totals['conversions'] / $totals['leads']) * 100)
            : 0;

        return view('dashboard.index', compact(
            'client', 'projects', 'primaryProject',
            'totals', 'sessionsPerDay', 'channelBreakdown', 'leadFunnel',
            'recentSessions', 'recentLeads', 'conversionRate'
        ));
    }
}
