<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platform-wide analytics — same chart shapes the customer admin
 * dashboard uses, but aggregated across every tenant DB. Built up
 * by iterating Projects, switching the tenant connection, and
 * accumulating into a series of buckets.
 */
class AnalyticsController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request): View
    {
        $title = 'Analytics';

        // ── Cross-tenant aggregation buckets ──────────────────────
        $now           = time();
        $dayCount      = 14;
        $startOfWindow = strtotime(date('Y-m-d', $now - ($dayCount - 1) * 86400));

        $sessionsPerDay = [];
        $leadsPerDay    = [];
        $voicePerDay    = [];
        for ($i = 0; $i < $dayCount; $i++) {
            $d = date('Y-m-d', $startOfWindow + $i * 86400);
            $sessionsPerDay[$d] = 0;
            $leadsPerDay[$d]    = 0;
            $voicePerDay[$d]    = 0;
        }

        $channelBreakdown = ['web' => 0, 'voice' => 0, 'phone' => 0, 'sms' => 0];
        $leadFunnel       = ['new'=>0,'contacted'=>0,'qualified'=>0,'converted'=>0,'disqualified'=>0];
        $statusBreakdown  = ['active'=>0,'ended'=>0,'abandoned'=>0];

        $totals = ['sessions'=>0,'leads'=>0,'messages'=>0,'voice_msgs'=>0,'conversions'=>0];

        // Per-client volumes (for the leaderboard chart)
        $perClient = [];     // client_id => ['name'=>..., 'sessions'=>n, 'leads'=>n]

        $projects = Project::orderBy('id')->get(['id', 'name', 'client_id', 'is_active']);
        $clients  = Client::whereIn('id', $projects->pluck('client_id')->unique())->get(['id', 'name'])->keyBy('id');

        $provisioning = ['provisioned'=>0, 'pending'=>0];

        foreach ($projects as $p) {
            $provisioning[$p->is_active === 'Yes' ? 'provisioned' : 'pending']++;
            if ($p->is_active !== 'Yes') continue;

            $this->tenants->useFor($p);
            try {
                $sCount = Session::count();
                $lCount = Lead::count();
                $mCount = Message::count();
                $vCount = Message::whereNotNull('audio_url')->count();
                $cCount = Lead::where('status','converted')->count();

                $totals['sessions']    += $sCount;
                $totals['leads']       += $lCount;
                $totals['messages']    += $mCount;
                $totals['voice_msgs']  += $vCount;
                $totals['conversions'] += $cCount;

                foreach (array_keys($channelBreakdown) as $ch)
                    $channelBreakdown[$ch] += Session::where('channel', $ch)->count();
                foreach (array_keys($leadFunnel) as $st)
                    $leadFunnel[$st] += Lead::where('status', $st)->count();
                foreach (array_keys($statusBreakdown) as $st)
                    $statusBreakdown[$st] += Session::where('status', $st)->count();

                // Sessions per day
                $rows = Session::where('started_at','>=',$startOfWindow)->where('started_at','<=',$now)->get(['started_at']);
                foreach ($rows->groupBy(fn($r) => date('Y-m-d', (int) $r->started_at)) as $d => $g) {
                    if (isset($sessionsPerDay[$d])) $sessionsPerDay[$d] += $g->count();
                }
                // Leads per day
                $rows = Lead::where('created_at','>=',$startOfWindow)->where('created_at','<=',$now)->get(['created_at']);
                foreach ($rows->groupBy(fn($r) => date('Y-m-d', (int) $r->created_at)) as $d => $g) {
                    if (isset($leadsPerDay[$d])) $leadsPerDay[$d] += $g->count();
                }
                // Voice replies per day (proxy: messages with audio_url)
                $rows = Message::whereNotNull('audio_url')
                    ->where('created_at','>=',$startOfWindow)
                    ->where('created_at','<=',$now)
                    ->get(['created_at']);
                foreach ($rows->groupBy(fn($r) => date('Y-m-d', (int) $r->created_at)) as $d => $g) {
                    if (isset($voicePerDay[$d])) $voicePerDay[$d] += $g->count();
                }

                // Per-client roll-up
                $cid = $p->client_id;
                if (!isset($perClient[$cid])) {
                    $perClient[$cid] = [
                        'name'     => $clients[$cid]?->name ?? "Client #{$cid}",
                        'sessions' => 0,
                        'leads'    => 0,
                    ];
                }
                $perClient[$cid]['sessions'] += $sCount;
                $perClient[$cid]['leads']    += $lCount;
            } catch (\Throwable $e) {
                // Skip unreachable tenants
            }
        }

        // Sort per-client by total volume (sessions + leads) for the leaderboard.
        uasort($perClient, fn($a, $b) => ($b['sessions'] + $b['leads']) <=> ($a['sessions'] + $a['leads']));
        $topClients = array_slice(array_values($perClient), 0, 8);

        $conversionRate = $totals['leads'] > 0
            ? round(($totals['conversions'] / $totals['leads']) * 100)
            : 0;

        return view('ops.analytics', compact(
            'title',
            'totals', 'conversionRate',
            'sessionsPerDay', 'leadsPerDay', 'voicePerDay',
            'channelBreakdown', 'leadFunnel', 'statusBreakdown',
            'provisioning', 'topClients',
        ));
    }
}
