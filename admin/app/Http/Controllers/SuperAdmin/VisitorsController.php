<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactLead;
use App\Models\Visitor;
use App\Support\IpLocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Who is on the marketing site — built from request data alone (IP,
 * User-Agent, Accept-Language, Referer), with location derived from the IP.
 * See App\Http\Middleware\TrackVisitor for what gets recorded.
 */
class VisitorsController extends Controller
{
    public function index(Request $request): View
    {
        $title  = 'Visitors';
        $search = trim((string) $request->query('q', ''));
        $device = (string) $request->query('device', '');
        $days   = (int) $request->query('days', 30);
        // Bots are a large share of traffic and almost never what an operator
        // is looking at, so they're hidden unless asked for.
        $bots   = $request->boolean('bots');

        $q = Visitor::query();

        if (! $bots) {
            $q->humans();
        }
        if (in_array($device, ['desktop', 'mobile', 'tablet', 'bot'], true)) {
            $q->where('device_type', $device);
        }
        if ($days > 0) {
            $q->where('last_seen_at', '>=', now()->subDays($days));
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where(function ($w) use ($like) {
                $w->where('ip', 'like', $like)
                  ->orWhere('city', 'like', $like)
                  ->orWhere('country', 'like', $like)
                  ->orWhere('region', 'like', $like)
                  ->orWhere('org', 'like', $like)
                  ->orWhere('browser', 'like', $like)
                  ->orWhere('os', 'like', $like)
                  ->orWhere('referrer_host', 'like', $like)
                  ->orWhere('last_path', 'like', $like)
                  ->orWhere('user_agent', 'like', $like);
            });
        }

        $visitors = $q->orderByDesc('last_seen_at')->paginate(30)->withQueryString();

        // Which of these visitors turned into a lead — one query, then a
        // lookup in the view, rather than a query per row.
        $leadKeys = ContactLead::query()
            ->whereNotNull('visitor_key')
            ->whereIn('visitor_key', $visitors->pluck('visitor_key')->all())
            ->select('visitor_key', DB::raw('COUNT(*) as n'))
            ->groupBy('visitor_key')
            ->pluck('n', 'visitor_key');

        return view('ops.visitors.index', [
            'title'    => $title,
            'visitors' => $visitors,
            'leadKeys' => $leadKeys,
            'stats'    => $this->stats(),
            'topPages' => $this->topPages($days),
            'topGeo'   => $this->topCountries($days),
            'search'   => $search,
            'device'   => $device,
            'days'     => $days,
            'bots'     => $bots,
            'pendingGeo' => Visitor::needsGeo()->count(),
            'geoInstant' => app(IpLocator::class)->isInstant(),
        ]);
    }

    /** One visitor's full record plus their page-by-page trail. */
    public function show(Request $request, int $id): View
    {
        $visitor = Visitor::findOrFail($id);

        return view('ops.visitors.show', [
            'title'   => 'Visitor detail',
            'visitor' => $visitor,
            'views'   => $visitor->pageViews()->orderByDesc('created_at')->paginate(100),
            'leads'   => $visitor->leads()->orderByDesc('created_at')->get(),
        ]);
    }

    /**
     * Resolve a batch of pending IPs.
     *
     * Only needed on installs with no local GeoLite2 file — those fall back to
     * a rate-limited public endpoint, which must never run inside a visitor's
     * request. Batch size is capped in config so one click can't get the
     * server blocked by the provider.
     */
    public function resolveGeo(Request $request): RedirectResponse
    {
        $locator = app(IpLocator::class);
        $batch   = max(1, (int) config('visitors.geo.batch_size', 25));

        $rows = Visitor::needsGeo()->orderByDesc('last_seen_at')->limit($batch)->get();
        $done = 0;

        foreach ($rows as $v) {
            $geo = $locator->locate((string) $v->ip);
            $v->fill([
                'geo_status'      => $geo['status'],
                'continent'       => $geo['continent'],
                'country'         => $geo['country'],
                'country_code'    => $geo['country_code'],
                'region'          => $geo['region'],
                'city'            => $geo['city'],
                'postal'          => $geo['postal'],
                'timezone'        => $geo['timezone'],
                'org'             => $geo['org'],
                'asn'             => $geo['asn'],
                'connection_type' => $geo['connection_type'],
                'latitude'        => $geo['latitude'],
                'longitude'       => $geo['longitude'],
            ])->save();

            if ($geo['status'] === Visitor::GEO_DONE) {
                $done++;
            }
        }

        AuditLog::record('visitors.geo_resolve', ['payload' => ['attempted' => $rows->count(), 'resolved' => $done]]);

        if ($rows->isEmpty()) {
            return back()->with('success', 'Nothing pending — every visitor already has a location.');
        }

        $remaining = Visitor::needsGeo()->count();

        return back()->with('success',
            "Resolved {$done} of {$rows->count()} address(es). {$remaining} still pending.");
    }

    /** @return array<string,int> */
    private function stats(): array
    {
        $today = now()->startOfDay();

        return [
            'visitors'       => Visitor::humans()->count(),
            'today'          => Visitor::humans()->where('last_seen_at', '>=', $today)->count(),
            'week'           => Visitor::humans()->where('last_seen_at', '>=', now()->subDays(7))->count(),
            'page_views'     => (int) Visitor::humans()->sum('page_views'),
            'bots'           => Visitor::where('is_bot', true)->count(),
            'countries'      => Visitor::humans()->whereNotNull('country_code')->distinct()->count('country_code'),
            'leads'          => ContactLead::whereNotNull('visitor_key')->distinct()->count('visitor_key'),
        ];
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function topPages(int $days)
    {
        return DB::connection('mysql')->table('visitor_page_views as pv')
            ->join('visitors as v', 'v.id', '=', 'pv.visitor_id')
            ->where('v.is_bot', false)
            ->when($days > 0, fn ($q) => $q->where('pv.created_at', '>=', now()->subDays($days)))
            ->select('pv.path', DB::raw('COUNT(*) as opens'), DB::raw('COUNT(DISTINCT pv.visitor_id) as visitors'))
            ->groupBy('pv.path')
            ->orderByDesc('opens')
            ->limit(10)
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    private function topCountries(int $days)
    {
        return Visitor::humans()
            ->whereNotNull('country')
            ->when($days > 0, fn ($q) => $q->where('last_seen_at', '>=', now()->subDays($days)))
            ->select('country', 'country_code', DB::raw('COUNT(*) as visitors'))
            ->groupBy('country', 'country_code')
            ->orderByDesc('visitors')
            ->limit(10)
            ->get();
    }
}
