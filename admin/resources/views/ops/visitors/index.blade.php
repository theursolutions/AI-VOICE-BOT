@extends('layouts.ops')

@section('content')
<style>
    .vz-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:18px; }
    .vz-two  { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px; }
    @media (max-width:900px){ .vz-two{ grid-template-columns:1fr; } }

    .vz-panel { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px 18px; }
    .vz-panel__title { font-size:13px; font-weight:700; color:#0f172a; margin-bottom:12px; display:flex; align-items:center; gap:7px; }

    .vz-bar { display:flex; align-items:center; gap:10px; margin-bottom:8px; font-size:12.5px; }
    .vz-bar__label { flex:0 0 42%; color:#334155; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .vz-bar__track { flex:1; height:7px; background:#f1f5f9; border-radius:99px; overflow:hidden; }
    .vz-bar__fill  { height:100%; background:var(--tva-gradient,#f59e0b); border-radius:99px; }
    .vz-bar__n { flex:0 0 58px; text-align:right; font-family:ui-monospace,monospace; color:#64748b; font-size:11.5px; }

    .vz-ip { font-family:ui-monospace,monospace; font-size:12px; color:#0f172a; font-weight:600; }
    .vz-sub { font-size:11px; color:#94a3b8; }
    .vz-chip { display:inline-block; font-size:10.5px; font-weight:700; padding:2px 7px; border-radius:999px; background:#f1f5f9; color:#475569; }
    .vz-chip--lead { background:#dcfce7; color:#15803d; }
    .vz-chip--bot  { background:#fee2e2; color:#b91c1c; }
    .vz-flag { font-size:15px; line-height:1; }
    .vz-note { font-size:12px; color:#64748b; background:#f8fafc; border:1px solid #e2e8f0;
               border-radius:10px; padding:10px 14px; margin-bottom:16px; }

    html.dark .vz-panel { background:#1e293b; border-color:#334155; }
    html.dark .vz-panel__title, html.dark .vz-ip { color:#f1f5f9; }
    html.dark .vz-bar__label { color:#cbd5e1; }
    html.dark .vz-bar__track { background:#334155; }
    html.dark .vz-chip { background:#334155; color:#cbd5e1; }
    html.dark .vz-note { background:#0f172a; border-color:#334155; color:#94a3b8; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🌍</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Visitors</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Every page open on the public site, with location and device worked out from the request itself.
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif

    {{-- ── Headline counts ─────────────────────────────────────────── --}}
    <div class="vz-grid">
        @php
            $tiles = [
                ['Visitors',   number_format($stats['visitors']),   'users',        '#dbeafe', '#1e40af', 'all time, humans'],
                ['Today',      number_format($stats['today']),      'sunrise',      '#fef3c7', '#92400e', 'seen since midnight'],
                ['Last 7 days',number_format($stats['week']),       'calendar',     '#e0e7ff', '#3730a3', 'unique visitors'],
                ['Page opens', number_format($stats['page_views']), 'mouse-pointer-click', '#dcfce7', '#15803d', 'total clicks in'],
                ['Countries',  number_format($stats['countries']),  'globe',        '#ccfbf1', '#0f766e', 'distinct'],
                ['Became leads',number_format($stats['leads']),     'user-check',   '#fce7f3', '#9d174d', 'left contact details'],
                ['Bots',       number_format($stats['bots']),       'bot',          '#f1f5f9', '#475569', 'crawlers & scripts'],
            ];
        @endphp
        @foreach ($tiles as [$label, $value, $icon, $bg, $fg, $hint])
            <div class="tva-stat">
                <div class="tva-stat__icon" style="background:{{ $bg }}; color:{{ $fg }};"><i data-lucide="{{ $icon }}" class="w-4 h-4"></i></div>
                <div>
                    <div class="tva-stat__label">{{ $label }}</div>
                    <div class="tva-stat__value">{{ $value }}</div>
                    <div style="font-size:11px; color:#94a3b8;">{{ $hint }}</div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($pendingGeo > 0 && ! $geoInstant)
        <div class="vz-note">
            <i data-lucide="map-pin" class="w-3.5 h-3.5 inline -mt-0.5"></i>
            <strong>{{ number_format($pendingGeo) }}</strong> address(es) have no location yet.
            There's no local GeoLite2 database on this server, so lookups use a rate-limited public
            endpoint and are never run while a visitor is waiting.
            <form method="POST" action="{{ route('ops.visitors.geo') }}" class="inline">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" style="margin-left:6px;">
                    Resolve next {{ (int) config('visitors.geo.batch_size', 25) }}
                </button>
            </form>
        </div>
    @endif

    {{-- ── Top pages + countries ───────────────────────────────────── --}}
    <div class="vz-two">
        <div class="vz-panel">
            <div class="vz-panel__title">📄 Most-opened pages</div>
            @php $maxOpens = max(1, (int) ($topPages->max('opens') ?? 1)); @endphp
            @forelse ($topPages as $p)
                <div class="vz-bar">
                    <span class="vz-bar__label" title="{{ $p->path }}">{{ $p->path }}</span>
                    <span class="vz-bar__track"><span class="vz-bar__fill" style="width:{{ round($p->opens / $maxOpens * 100) }}%"></span></span>
                    <span class="vz-bar__n">{{ number_format($p->opens) }} · {{ number_format($p->visitors) }}v</span>
                </div>
            @empty
                <div class="vz-sub">No page opens recorded yet.</div>
            @endforelse
        </div>

        <div class="vz-panel">
            <div class="vz-panel__title">🌍 Top countries</div>
            @php $maxGeo = max(1, (int) ($topGeo->max('visitors') ?? 1)); @endphp
            @forelse ($topGeo as $g)
                <div class="vz-bar">
                    <span class="vz-bar__label">{{ $g->country }}</span>
                    <span class="vz-bar__track"><span class="vz-bar__fill" style="width:{{ round($g->visitors / $maxGeo * 100) }}%"></span></span>
                    <span class="vz-bar__n">{{ number_format($g->visitors) }}</span>
                </div>
            @empty
                <div class="vz-sub">No locations resolved yet.</div>
            @endforelse
        </div>
    </div>

    {{-- ── Visitor table ───────────────────────────────────────────── --}}
    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar">
            <input type="text" name="q" value="{{ $search }}" placeholder="Search IP, city, country, ISP, browser, page…" style="min-width:260px;">
            <select name="device" onchange="this.form.submit()"
                    style="font-size:13px;padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;color:#334155;">
                <option value="">All devices</option>
                @foreach (['desktop' => 'Desktop', 'mobile' => 'Mobile', 'tablet' => 'Tablet', 'bot' => 'Bot'] as $k => $v)
                    <option value="{{ $k }}" @selected($device === $k)>{{ $v }}</option>
                @endforeach
            </select>
            <select name="days" onchange="this.form.submit()"
                    style="font-size:13px;padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;background:#fff;color:#334155;">
                @foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 0 => 'All time'] as $k => $v)
                    <option value="{{ $k }}" @selected($days === $k)>{{ $v }}</option>
                @endforeach
            </select>
            <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#475569;">
                <input type="checkbox" name="bots" value="1" onchange="this.form.submit()" @checked($bots)> Include bots
            </label>
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            @if ($search !== '' || $device !== '' || $bots)
                <a href="{{ route('ops.visitors.index') }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
            <div class="ml-auto" style="color:#64748b; font-size:12px;">{{ number_format($visitors->total()) }} visitor(s)</div>
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table">
                <thead>
                    <tr>
                        <th>IP / visitor</th>
                        <th>Location</th>
                        <th>Network</th>
                        <th>Device</th>
                        <th>Lang</th>
                        <th>Came from</th>
                        <th style="text-align:right;">Pages</th>
                        <th>Last page</th>
                        <th>First seen</th>
                        <th>Last seen</th>
                        <th style="text-align:right;"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($visitors as $v)
                    <tr>
                        <td data-label="IP / visitor">
                            <div class="vz-ip">{{ $v->ip ?: '—' }}</div>
                            <div class="vz-sub">{{ substr($v->visitor_key, 0, 10) }}</div>
                            @if (($leadKeys[$v->visitor_key] ?? 0) > 0)
                                <span class="vz-chip vz-chip--lead">lead</span>
                            @endif
                            @if ($v->is_bot)
                                <span class="vz-chip vz-chip--bot">bot</span>
                            @endif
                        </td>
                        <td data-label="Location">
                            @if ($v->location)
                                <span class="vz-flag">{{ $v->flag }}</span> {{ $v->location }}
                                @if ($v->postal)<div class="vz-sub">{{ $v->postal }}</div>@endif
                            @elseif ($v->geo_status === 'private')
                                <span class="vz-sub">Local / private IP</span>
                            @elseif ($v->geo_status === 'pending')
                                <span class="vz-sub">not resolved yet</span>
                            @else
                                <span class="vz-sub">unknown</span>
                            @endif
                        </td>
                        <td data-label="Network" style="max-width:170px;">
                            <div style="font-size:12px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $v->org }}">{{ $v->org ?: '—' }}</div>
                            @if ($v->asn)<div class="vz-sub">{{ $v->asn }}{{ $v->connection_type ? ' · '.$v->connection_type : '' }}</div>@endif
                        </td>
                        <td data-label="Device">
                            <div style="font-size:12px;color:#475569;">{{ $v->browser ?: '—' }} {{ $v->browser_version }}</div>
                            <div class="vz-sub">{{ $v->os }}{{ $v->device_type ? ' · '.$v->device_type : '' }}</div>
                        </td>
                        <td data-label="Lang"><span class="vz-sub">{{ $v->language ?: '—' }}</span></td>
                        <td data-label="Came from" style="max-width:150px;">
                            <div style="font-size:12px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $v->referrer }}">
                                {{ $v->referrer_host ?: 'direct' }}
                            </div>
                            @if ($v->utm_source)<div class="vz-sub">{{ $v->utm_source }}{{ $v->utm_campaign ? ' / '.$v->utm_campaign : '' }}</div>@endif
                        </td>
                        <td data-label="Pages" style="text-align:right;font-family:ui-monospace,monospace;font-weight:700;color:#0f172a;">{{ number_format($v->page_views) }}</td>
                        <td data-label="Last page" style="max-width:170px;">
                            <div style="font-size:12px;color:#475569;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $v->last_path }}">{{ $v->last_path ?: '—' }}</div>
                            @if ($v->landing_path && $v->landing_path !== $v->last_path)
                                <div class="vz-sub">landed on {{ $v->landing_path }}</div>
                            @endif
                        </td>
                        <td data-label="First seen" style="white-space:nowrap;font-size:11.5px;color:#64748b;">
                            {{ $v->first_seen_at?->format('M j, H:i') ?: '—' }}
                        </td>
                        <td data-label="Last seen" style="white-space:nowrap;font-size:11.5px;color:#64748b;">
                            {{ $v->last_seen_at?->diffForHumans(null, true) ?: '—' }} ago
                        </td>
                        <td data-label="" style="text-align:right;">
                            <a href="{{ route('ops.visitors.show', $v->id) }}" class="btn btn-secondary btn-sm">
                                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" style="text-align:center;color:#94a3b8;padding:40px 0;">
                            No visitors recorded in this window yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($visitors->hasPages())
            <div style="padding:14px 16px;">{{ $visitors->links() }}</div>
        @endif
    </div>

    <div class="vz-note" style="margin-top:16px;">
        <i data-lucide="info" class="w-3.5 h-3.5 inline -mt-0.5"></i>
        All of this comes from data the browser sends with every request — IP, User-Agent,
        Accept-Language and Referer — plus the location derived from the IP. No tracking script runs
        on the visitor's device and no cookie is set. An email address cannot be obtained this way;
        it only appears when a visitor volunteers one, at which point their lead is joined to this
        record and marked <span class="vz-chip vz-chip--lead">lead</span>.
        Note that an IP is personal data under GDPR/UK-GDPR — see <code>config/visitors.php</code>
        for the retention and IP-anonymisation switches.
    </div>
</div>

<script>if (window.lucide?.createIcons) window.lucide.createIcons();</script>
@endsection
