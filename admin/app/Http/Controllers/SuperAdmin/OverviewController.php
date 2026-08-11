<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Models\User;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Platform-wide overview: counts + health pings + at-a-glance graphs.
 *
 * The aggregation block iterates every active project once, switching
 * the tenant DB connection per pass, accumulating into shared buckets
 * (per-day series, channel split, funnel, top clients, etc.). One
 * cross-tenant pass powers all six dashboard charts plus the KPIs.
 *
 * For deeper / longer time-window analytics see AnalyticsController.
 */
class OverviewController extends Controller
{
    public function index(Request $request, TenantManager $tenants): View
    {
        $title = 'Overview';

        // ── Master-DB counts (cheap) ──────────────────────────────
        $stats = [
            'users'           => User::count(),
            'super_admins'    => User::where('is_super_admin', true)->count(),
            'clients'         => Client::count(),
            'clients_active'  => Client::where('is_active', 'Yes')->count(),
            'projects'        => Project::count(),
            'projects_active' => Project::where('is_active', 'Yes')->count(),
            'audit_today'     => AuditLog::where('created_at', '>=', strtotime('today'))->count(),
        ];

        // Marketing-site traffic. Wrapped because the visitors tables arrive in
        // a later migration than the rest of this dashboard — an un-migrated
        // deploy should lose one tile, not the whole overview.
        try {
            $stats['visitors']       = \App\Models\Visitor::humans()->count();
            $stats['visitors_today'] = \App\Models\Visitor::humans()
                ->where('last_seen_at', '>=', now()->startOfDay())->count();
        } catch (Throwable $e) {
            $stats['visitors'] = $stats['visitors_today'] = 0;
        }

        // ── Chart-data buckets ────────────────────────────────────
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
        $leadFunnel       = ['new' => 0, 'contacted' => 0, 'qualified' => 0, 'converted' => 0, 'disqualified' => 0];
        $totals           = ['sessions' => 0, 'leads' => 0, 'voice_msgs' => 0];

        $perClient = [];                          // client_id => totals
        $provisioning = ['provisioned' => 0, 'pending' => 0];

        // ── One cross-tenant pass ─────────────────────────────────
        $projects = Project::orderBy('id')->get(['id', 'name', 'client_id', 'is_active']);
        $clients  = Client::whereIn('id', $projects->pluck('client_id')->unique())
            ->get(['id', 'name'])->keyBy('id');

        foreach ($projects as $p) {
            $provisioning[$p->is_active === 'Yes' ? 'provisioned' : 'pending']++;
            if ($p->is_active !== 'Yes') continue;

            $tenants->useFor($p);
            try {
                $sCount = Session::count();
                $lCount = Lead::count();
                $vCount = Message::whereNotNull('audio_url')->count();
                $totals['sessions']   += $sCount;
                $totals['leads']      += $lCount;
                $totals['voice_msgs'] += $vCount;

                foreach (array_keys($channelBreakdown) as $ch) {
                    $channelBreakdown[$ch] += Session::where('channel', $ch)->count();
                }
                foreach (array_keys($leadFunnel) as $st) {
                    $leadFunnel[$st] += Lead::where('status', $st)->count();
                }

                // Per-day windows
                foreach (
                    Session::where('started_at', '>=', $startOfWindow)
                        ->where('started_at', '<=', $now)
                        ->get(['started_at'])
                        ->groupBy(fn ($r) => date('Y-m-d', (int) $r->started_at))
                    as $d => $g
                ) { if (isset($sessionsPerDay[$d])) $sessionsPerDay[$d] += $g->count(); }

                foreach (
                    Lead::where('created_at', '>=', $startOfWindow)
                        ->where('created_at', '<=', $now)
                        ->get(['created_at'])
                        ->groupBy(fn ($r) => date('Y-m-d', (int) $r->created_at))
                    as $d => $g
                ) { if (isset($leadsPerDay[$d])) $leadsPerDay[$d] += $g->count(); }

                foreach (
                    Message::whereNotNull('audio_url')
                        ->where('created_at', '>=', $startOfWindow)
                        ->where('created_at', '<=', $now)
                        ->get(['created_at'])
                        ->groupBy(fn ($r) => date('Y-m-d', (int) $r->created_at))
                    as $d => $g
                ) { if (isset($voicePerDay[$d])) $voicePerDay[$d] += $g->count(); }

                // Per-client roll-up (for the leaderboard chart)
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
            } catch (Throwable $e) {
                // Unreachable tenant — skip; user-side will see the
                // failure on the projects page with a "Re-provision"
                // button.
            }
        }

        uasort($perClient, fn ($a, $b) => ($b['sessions'] + $b['leads']) <=> ($a['sessions'] + $a['leads']));
        $topClients = array_slice(array_values($perClient), 0, 5);

        // ── Recent activity (master-DB; cheap) ────────────────────
        $recentClients = Client::orderByDesc('id')->limit(6)->get(['id', 'name', 'slug', 'is_active', 'created_at']);
        $recentUsers   = User::orderByDesc('id')->limit(6)->get(['id', 'name', 'email', 'created_at']);
        $recentAudit   = AuditLog::orderByDesc('id')->limit(6)->get();

        // ── Health pings ──────────────────────────────────────────
        $health = [
            'master_db'   => $this->pingMasterDb(),
            'tenant_host' => $this->pingTenantHost($tenants),
            'voice'       => $this->pingVoiceEngine(),
            'twilio'      => $this->pingTwilio(),
        ];

        return view('ops.overview', compact(
            'title',
            'stats', 'totals',
            'sessionsPerDay', 'leadsPerDay', 'voicePerDay',
            'channelBreakdown', 'leadFunnel', 'topClients', 'provisioning',
            'health',
            'recentClients', 'recentUsers', 'recentAudit',
        ));
    }

    // ── Health pings ──────────────────────────────────────────────
    private function pingMasterDb(): array
    {
        try {
            DB::connection('mysql')->select('SELECT 1');
            return ['ok' => true, 'msg' => 'reachable'];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => substr($e->getMessage(), 0, 80)];
        }
    }

    private function pingTenantHost(TenantManager $tenants): array
    {
        try {
            $conn = $tenants->rootConnection();
            DB::connection($conn)->select('SELECT 1');
            return ['ok' => true, 'msg' => 'tenant host reachable'];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => substr($e->getMessage(), 0, 80)];
        }
    }

    private function pingVoiceEngine(): array
    {
        $url = (string) config('services.python.ws_url', '');
        if (!$url) return ['ok' => false, 'msg' => 'PYTHON_WS_URL not set'];
        $httpUrl = preg_replace('#^ws(s?)://#', 'http$1://', $url);
        $httpUrl = preg_replace('#/ws/.*$#', '/admin/diag', $httpUrl);
        try {
            $ch = curl_init($httpUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 500
                ? ['ok' => true, 'msg' => "voice-engine HTTP {$code}"]
                : ['ok' => false, 'msg' => "voice-engine HTTP {$code}"];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => 'unreachable'];
        }
    }

    private function pingTwilio(): array
    {
        $sid   = (string) config('services.twilio.account_sid');
        $token = (string) config('services.twilio.auth_token');
        if (!$sid || !$token) return ['ok' => false, 'msg' => 'creds not set'];
        try {
            $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => $sid . ':' . $token,
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_TIMEOUT        => 3,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code === 200
                ? ['ok' => true, 'msg' => 'Twilio auth ok']
                : ['ok' => false, 'msg' => "Twilio HTTP {$code}"];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => 'unreachable'];
        }
    }
}
