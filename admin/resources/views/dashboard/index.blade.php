@extends('layouts.master')

@section('content')
<style>
    /* ── The rail drops below the work area's corner ────────────────────
       Dashboard is the FIRST item in the sidebar, and that position is the
       whole problem. The rail's first menu item begins 113px down — 16px of
       logo padding, a ~28px lockup, then the divider's 24px margins either
       side — and the wedge that curves the top of an active item sits 30px
       above it, spanning 83-113. The work area's rounded corner spans 88-118.

       So on this page, and only this page, both draw a curve in the same band,
       20px apart and facing opposite ways, and what is caught between them
       reads as a splinter in the corner.

       Neither is worth giving up: the corner is what every other page shows,
       and ::before is what gives the tab the same curve on top that ::after
       gives it underneath. Dropping the rail 40px moves the tab clear of 118
       with a few pixels to spare, so both keep their shape.

       Scoped here on purpose — no other page has an active item at that
       height, so no other rail needs moving. The trade is that the menu sits
       40px lower on the Dashboard than elsewhere. */
    html:not(.dark) .side-nav > ul { margin-top: 40px; }

    /* Nudges the top wedge 5px right so its edge meets the tab instead of
       leaving a sliver. Scaling or rotating it was the wrong lever — the wedge
       is not the wrong size or angle, it is simply parked 5px short.

       Light mode only: the gap comes from the 40px rail offset above, which is
       itself light-only, so in dark the wedge is already where the theme put
       it and moving it would introduce the very gap this closes. */
    html:not(.dark) .side-nav > ul > li > .side-menu.side-menu--active::before {
        right: -5px;
    }

    /* ── KPI cards ─────────────────────────────────────────────────── */
    .tva-dash-hero {
        background: var(--tva-gradient);
        color: #fff;
        border-radius: 14px;
        padding: 22px 26px;
        box-shadow: 0 12px 32px -14px rgba(0,0,0,.4);
        margin-bottom: 22px;
    }
    .tva-dash-hero h2 { font-size: 22px; font-weight: 700; }
    .tva-dash-hero p  { opacity: .9; font-size: 14px; margin-top: 4px; }

    .tva-kpis { display:grid; gap:14px; grid-template-columns: repeat(2,1fr); margin-bottom:24px; }
    @media (min-width: 900px) { .tva-kpis { grid-template-columns: repeat(4,1fr); } }
    @media (max-width: 540px) {
        .tva-kpis { grid-template-columns: 1fr; gap:10px; }
        .tva-kpi { min-height: 0; padding: 14px; }
        .tva-kpi__icon { width: 36px; height: 36px; }
        .tva-kpi__value { font-size: 20px; }
    }

    .tva-kpi {
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        padding: 18px;
        display:flex; align-items:flex-start; gap:14px;
        min-height: 96px;
    }
    .tva-kpi__icon {
        width:42px; height:42px; border-radius:10px;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0;
    }
    .tva-kpi--blue   .tva-kpi__icon { background:#dbeafe; color:#1d4ed8; }
    .tva-kpi--green  .tva-kpi__icon { background:#d1fae5; color:#047857; }
    .tva-kpi--amber  .tva-kpi__icon { background:#fef3c7; color:#b45309; }
    .tva-kpi--purple .tva-kpi__icon { background:#ede9fe; color:#7c3aed; }

    .tva-kpi__label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .tva-kpi__value { font-size:24px; font-weight:700; color:#0f172a; margin-top:4px; line-height:1.1; }
    .tva-kpi__sub   { font-size:11px; color:#94a3b8; font-weight:500; margin-top:4px; }

    /* ── Charts row ────────────────────────────────────────────────── */
    .tva-row { display:grid; gap:22px; grid-template-columns: 1fr; }
    @media (min-width: 1100px) { .tva-row { grid-template-columns: 2fr 1fr; } }

    .tva-card {
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        padding: 18px 22px;
    }
    .tva-card__title {
        font-size:14px; font-weight:600; color:#0f172a;
        display:flex; align-items:center; gap:8px;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
    }

    /* ── Lead funnel bars ──────────────────────────────────────────── */
    .tva-funnel-bar { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .tva-funnel-bar__label { width: 110px; font-size:12px; font-weight:600; text-transform:capitalize; color:#334155; }
    .tva-funnel-bar__track { flex:1; height:14px; background:#f1f5f9; border-radius:7px; overflow:hidden; min-width: 60px; }
    .tva-funnel-bar__fill  { height:100%; border-radius:7px; transition: width .6s ease; }
    .tva-funnel-bar__count { width:36px; text-align:right; font-size:13px; font-weight:600; color:#0f172a; }
    @media (max-width: 540px) {
        .tva-funnel-bar { gap: 8px; }
        .tva-funnel-bar__label { width: 84px; font-size: 11px; }
        .tva-funnel-bar__count { width: 28px; font-size: 12px; }
    }

    /* Activity chart wrapper — give the canvas a real height. */
    .tva-chart-wrap { position: relative; height: 260px; }
    @media (max-width: 540px) { .tva-chart-wrap { height: 200px; } }

    .tva-funnel-bar--new          .tva-funnel-bar__fill { background: linear-gradient(90deg,#3b82f6,#60a5fa); }
    .tva-funnel-bar--contacted    .tva-funnel-bar__fill { background: linear-gradient(90deg,#f59e0b,#fbbf24); }
    .tva-funnel-bar--qualified    .tva-funnel-bar__fill { background: linear-gradient(90deg,#8b5cf6,#a78bfa); }
    .tva-funnel-bar--converted    .tva-funnel-bar__fill { background: linear-gradient(90deg,#10b981,#34d399); }
    .tva-funnel-bar--disqualified .tva-funnel-bar__fill { background: linear-gradient(90deg,#ef4444,#f87171); }

    /* ── Channel chips ─────────────────────────────────────────────── */
    .tva-channels { display:grid; gap:10px; grid-template-columns: repeat(4, 1fr); }
    @media (max-width: 900px) { .tva-channels { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 420px) { .tva-channels { grid-template-columns: 1fr; } }
    .tva-channel-chip {
        background:#f8fafc; border:1px solid #e2e8f0;
        border-radius: 10px; padding: 12px;
        display:flex; align-items:center; gap:10px;
    }
    .tva-channel-chip__icon { width:34px; height:34px; border-radius:8px; display:flex; align-items:center; justify-content:center; }
    .tva-channel-chip__label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
    .tva-channel-chip__value { font-size:16px; font-weight:700; color:#0f172a; }

    /* ── Recent lists ──────────────────────────────────────────────── */
    .tva-list-row {
        display:flex; align-items:center; gap:12px;
        padding: 10px 0;
        border-bottom: 1px dashed #e2e8f0;
        text-decoration: none;
        color: inherit;
    }
    .tva-list-row:last-child { border-bottom: none; }
    .tva-list-row:hover { background: #f8fafc; padding-left: 8px; padding-right: 8px; border-radius: 8px; }
    .tva-list-row__avatar {
        width:34px; height:34px; border-radius:50%;
        background:#dbeafe; color:#1d4ed8;
        font-weight:700; font-size:12px;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0;
    }
    .tva-list-row__main { flex:1; min-width:0; }
    .tva-list-row__title { font-size:13px; font-weight:600; color:#0f172a; }
    .tva-list-row__sub   { font-size:11px; color:#94a3b8; margin-top:2px; }
    .tva-list-row__chip {
        font-size:10px; padding:3px 9px; border-radius:999px;
        background:#e2e8f0; color:#475569; font-weight:600;
    }

    .tva-empty-mini { text-align:center; color:#94a3b8; font-size:13px; padding: 20px 0; }

    /* ── DARK MODE (.dark on <html>) ───────────────────────────────── */
    html.dark .tva-kpi { background:#1e293b; border-color:#334155; }
    html.dark .tva-kpi__label { color:#94a3b8; }
    html.dark .tva-kpi__value { color:#f1f5f9; }
    html.dark .tva-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-card__title { color:#f1f5f9; border-bottom-color:#334155; }
    html.dark .tva-funnel-bar__label { color:#cbd5e1; }
    html.dark .tva-funnel-bar__track { background:#0f172a; }
    html.dark .tva-funnel-bar__count { color:#f1f5f9; }
    html.dark .tva-channel-chip { background:#0f172a; border-color:#334155; }
    html.dark .tva-channel-chip__label { color:#94a3b8; }
    html.dark .tva-channel-chip__value { color:#f1f5f9; }
    html.dark .tva-list-row { border-bottom-color:#334155; color:#e2e8f0; }
    html.dark .tva-list-row:hover { background:#283449; }
    html.dark .tva-list-row__title { color:#f1f5f9; }
    html.dark .tva-list-row__chip { background:#334155; color:#cbd5e1; }
</style>

<div class="content">
    @php
        $custName = auth()->user()?->name ?? 'there';
        $clientName = $client?->name ?? 'your workspace';
        $totalChannels = max(1, array_sum($channelBreakdown));
    @endphp

    {{-- ── Hero ─────────────────────────────────────────────────────── --}}
    <div class="tva-dash-hero mt-6">
        <h2>Welcome back, {{ explode(' ', $custName)[0] }} 👋</h2>
        <p>Here's what's happening in <b>{{ $clientName }}</b> across {{ $projects->count() }} project{{ $projects->count() === 1 ? '' : 's' }}.</p>
    </div>

    {{-- ── KPI row ──────────────────────────────────────────────────── --}}
    <div class="tva-kpis">
        <div class="tva-kpi tva-kpi--blue">
            <div class="tva-kpi__icon"><i data-lucide="message-square" class="w-5 h-5"></i></div>
            <div>
                <div class="tva-kpi__label">Conversations</div>
                <div class="tva-kpi__value">{{ number_format($totals['sessions']) }}</div>
                <div class="tva-kpi__sub">{{ number_format($totals['messages']) }} total messages</div>
            </div>
        </div>
        <div class="tva-kpi tva-kpi--green">
            <div class="tva-kpi__icon"><i data-lucide="user-check" class="w-5 h-5"></i></div>
            <div>
                <div class="tva-kpi__label">Leads captured</div>
                <div class="tva-kpi__value">{{ number_format($totals['leads']) }}</div>
                <div class="tva-kpi__sub">{{ $totals['conversions'] }} converted</div>
            </div>
        </div>
        <div class="tva-kpi tva-kpi--amber">
            <div class="tva-kpi__icon"><i data-lucide="trending-up" class="w-5 h-5"></i></div>
            <div>
                <div class="tva-kpi__label">Conversion rate</div>
                <div class="tva-kpi__value">{{ $conversionRate }}%</div>
                <div class="tva-kpi__sub">leads → converted</div>
            </div>
        </div>
        <div class="tva-kpi tva-kpi--purple">
            <div class="tva-kpi__icon"><i data-lucide="mic" class="w-5 h-5"></i></div>
            <div>
                <div class="tva-kpi__label">Voice replies</div>
                <div class="tva-kpi__value">{{ number_format($totals['voice_msgs']) }}</div>
                <div class="tva-kpi__sub">spoken responses delivered</div>
            </div>
        </div>
    </div>

    {{-- ── Activity chart + Funnel ───────────────────────────────────── --}}
    <div class="tva-row" style="margin-bottom: 22px;">
        <div class="tva-card">
            <div class="tva-card__title">
                <i data-lucide="bar-chart-2" class="w-4 h-4"></i> Activity — last 14 days
            </div>
            <div class="tva-chart-wrap">
                <canvas id="tvaActivityChart"></canvas>
            </div>
        </div>

        <div class="tva-card">
            <div class="tva-card__title">
                <i data-lucide="filter" class="w-4 h-4"></i> Lead funnel
            </div>
            @php $funnelMax = max(1, max($leadFunnel)); @endphp
            @foreach ($leadFunnel as $stage => $count)
                <div class="tva-funnel-bar tva-funnel-bar--{{ $stage }}">
                    <div class="tva-funnel-bar__label">{{ $stage }}</div>
                    <div class="tva-funnel-bar__track">
                        <div class="tva-funnel-bar__fill" style="width: {{ ($count / $funnelMax) * 100 }}%"></div>
                    </div>
                    <div class="tva-funnel-bar__count">{{ $count }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── Channel breakdown ─────────────────────────────────────────── --}}
    <div class="tva-card" style="margin-bottom:22px;">
        <div class="tva-card__title">
            <i data-lucide="radio" class="w-4 h-4"></i> Channel breakdown
        </div>
        <div class="tva-channels">
            @php
                $channelIcons = [
                    'web'   => ['globe',         '#1d4ed8', '#dbeafe'],
                    'voice' => ['mic',           '#047857', '#d1fae5'],
                    'phone' => ['phone',         '#b45309', '#fef3c7'],
                    'sms'   => ['message-circle','#7c3aed', '#ede9fe'],
                ];
            @endphp
            @foreach ($channelBreakdown as $ch => $count)
                @php [$icon, $fg, $bg] = $channelIcons[$ch]; @endphp
                <div class="tva-channel-chip">
                    <div class="tva-channel-chip__icon" style="background: {{ $bg }}; color: {{ $fg }};">
                        <i data-lucide="{{ $icon }}" class="w-4 h-4"></i>
                    </div>
                    <div>
                        <div class="tva-channel-chip__label">{{ ucfirst($ch) }}</div>
                        <div class="tva-channel-chip__value">{{ $count }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── Recent sessions + leads ───────────────────────────────────── --}}
    <div class="tva-row">
        <div class="tva-card">
            <div class="tva-card__title">
                <i data-lucide="message-circle" class="w-4 h-4"></i> Recent conversations
                @if ($primaryProject)
                <a href="{{ route('sessions.index') }}?project_id={{ hashid($primaryProject->id) }}"
                   class="ml-auto text-xs text-primary" style="margin-left:auto">View all →</a>
                @endif
            </div>
            @forelse ($recentSessions as $s)
                @php
                    $name = $s->customer_name ?: 'Anonymous';
                    $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'A', 0, 2));
                @endphp
                <a href="{{ route('sessions.show', ['id' => $s->id]) }}?project_id={{ hashid($s->project_id) }}"
                   class="tva-list-row">
                    <div class="tva-list-row__avatar">{{ $initials }}</div>
                    <div class="tva-list-row__main">
                        <div class="tva-list-row__title">{{ $name }}</div>
                        <div class="tva-list-row__sub">
                            {{ $s->_project_name ?? '' }} · #{{ $s->id }} ·
                            {{ $s->last_activity_at ? date('M d H:i', $s->last_activity_at) : '—' }}
                        </div>
                    </div>
                    <span class="tva-list-row__chip">{{ $s->channel }}</span>
                </a>
            @empty
                <div class="tva-empty-mini">No conversations yet.</div>
            @endforelse
        </div>

        <div class="tva-card">
            <div class="tva-card__title">
                <i data-lucide="user-plus" class="w-4 h-4"></i> Recent leads
                @if ($primaryProject)
                <a href="{{ route('leads.index') }}?project_id={{ hashid($primaryProject->id) }}"
                   class="ml-auto text-xs text-primary" style="margin-left:auto">View all →</a>
                @endif
            </div>
            @forelse ($recentLeads as $lead)
                @php
                    $lf = $lead->fields ?? [];
                    $name = $lf['name'] ?? 'Unnamed';
                    $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'L', 0, 2));
                @endphp
                <a href="{{ route('leads.show', ['id' => $lead->id]) }}?project_id={{ hashid($lead->project_id) }}"
                   class="tva-list-row">
                    <div class="tva-list-row__avatar" style="background:#d1fae5; color:#047857;">{{ $initials }}</div>
                    <div class="tva-list-row__main">
                        <div class="tva-list-row__title">{{ $name }}</div>
                        <div class="tva-list-row__sub">
                            {{ $lead->_project_name ?? '' }} · #{{ $lead->id }} ·
                            {{ number_format(($lead->confidence ?? 0) * 100, 0) }}% confidence
                        </div>
                    </div>
                    <span class="tva-list-row__chip">{{ $lead->status }}</span>
                </a>
            @empty
                <div class="tva-empty-mini">No leads yet.</div>
            @endforelse
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script>
    // Re-render lucide icons
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }

    // 14-day activity line chart — waits for Chart.js to load if the CDN
    // hasn't resolved yet (the `defer` script above runs after DOMContentLoaded).
    function tvaRenderActivityChart() {
        var ctx = document.getElementById('tvaActivityChart');
        if (!ctx) return;
        if (typeof Chart === 'undefined') {
            return setTimeout(tvaRenderActivityChart, 100);
        }

        var labels = @json(array_keys($sessionsPerDay));
        var data   = @json(array_values($sessionsPerDay));

        // Short labels: "Jun 04" not "2026-06-04"
        var pretty = labels.map(function (d) {
            var dt = new Date(d + 'T00:00:00');
            return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        });

        var dark = document.documentElement.classList.contains('dark');
        var grid = dark ? 'rgba(148,163,184,.18)' : 'rgba(148,163,184,.25)';
        var tick = dark ? '#94a3b8' : '#64748b';

        var gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
        gradient.addColorStop(0, 'rgba(99,102,241,.45)');
        gradient.addColorStop(1, 'rgba(99,102,241,0)');

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: pretty,
                datasets: [{
                    label: 'Sessions',
                    data: data,
                    borderColor: '#6366f1',
                    backgroundColor: gradient,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#6366f1',
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: grid }, ticks: { color: tick, font: { size: 10 } } },
                    y: { grid: { color: grid }, ticks: { color: tick, font: { size: 10 }, precision: 0 }, beginAtZero: true }
                }
            }
        });
    }
    tvaRenderActivityChart();
</script>
@endsection
