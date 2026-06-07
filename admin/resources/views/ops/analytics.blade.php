@extends('layouts.ops')

@section('content')
<style>
    .ops-chart-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
    @media (max-width: 980px) { .ops-chart-grid { grid-template-columns: 1fr; } }
    .ops-chart-wrap { position: relative; height: 280px; }
    @media (max-width: 540px) { .ops-chart-wrap { height: 220px; } }
    .ops-card-title {
        display:flex; align-items:center; gap:8px;
        font-size:14px; font-weight:600; color:#0f172a;
        padding-bottom:12px; border-bottom:1px solid #e2e8f0; margin-bottom:14px;
    }
    .ops-card-title .badge {
        margin-left:auto; font-family: ui-monospace, monospace; font-size:10px;
        background:#fef3c7; color:#92400e; padding:3px 10px; border-radius:999px;
        text-transform:uppercase; letter-spacing:.06em; font-weight:700;
    }
    html.dark .ops-card-title { color:#f1f5f9; border-bottom-color:#334155; }

    .funnel-bar { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
    .funnel-bar__label { width:110px; font-size:12px; font-weight:600; text-transform:capitalize; color:#334155; }
    .funnel-bar__track { flex:1; height:14px; background:#f1f5f9; border-radius:7px; overflow:hidden; min-width:60px; }
    .funnel-bar__fill { height:100%; border-radius:7px; transition: width .6s ease; }
    .funnel-bar__count { width:36px; text-align:right; font-size:13px; font-weight:600; color:#0f172a; }
    .funnel-bar--new          .funnel-bar__fill { background: linear-gradient(90deg,#3b82f6,#60a5fa); }
    .funnel-bar--contacted    .funnel-bar__fill { background: linear-gradient(90deg,#f59e0b,#fbbf24); }
    .funnel-bar--qualified    .funnel-bar__fill { background: linear-gradient(90deg,#8b5cf6,#a78bfa); }
    .funnel-bar--converted    .funnel-bar__fill { background: linear-gradient(90deg,#10b981,#34d399); }
    .funnel-bar--disqualified .funnel-bar__fill { background: linear-gradient(90deg,#ef4444,#f87171); }
    html.dark .funnel-bar__label { color:#cbd5e1; }
    html.dark .funnel-bar__track { background:#0f172a; }
    html.dark .funnel-bar__count { color:#f1f5f9; }

    .top-row { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px dashed #e2e8f0; }
    .top-row:last-child { border-bottom:none; }
    .top-row__rank {
        width:24px; height:24px; border-radius:6px;
        background: var(--tva-gradient); color:#fff;
        font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center;
        font-family: ui-monospace, monospace;
    }
    .top-row__name { flex:1; font-size:13px; font-weight:600; color:#0f172a; min-width:0; }
    .top-row__vals { font-size:11px; color:#64748b; font-family: ui-monospace, monospace; white-space:nowrap; }
    html.dark .top-row { border-bottom-color:#334155; }
    html.dark .top-row__name { color:#f1f5f9; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">📊</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Analytics</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Section-by-section view of every conversation, lead, voice reply, and channel — aggregated across all workspaces.
            </div>
        </div>
    </div>

    {{-- Section 1: Volume KPIs --}}
    <div class="tva-stat-grid">
        <div class="tva-stat">
            <div class="tva-stat__icon" style="background:#dbeafe; color:#1e40af;"><i data-lucide="message-square" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Conversations</div>
                <div class="tva-stat__value">{{ number_format($totals['sessions']) }}</div>
                <div style="font-size:11px; color:#94a3b8;">{{ number_format($totals['messages']) }} messages total</div>
            </div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__icon" style="background:#dcfce7; color:#15803d;"><i data-lucide="user-check" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Leads captured</div>
                <div class="tva-stat__value">{{ number_format($totals['leads']) }}</div>
                <div style="font-size:11px; color:#94a3b8;">{{ $totals['conversions'] }} converted</div>
            </div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__icon" style="background:#fef3c7; color:#92400e;"><i data-lucide="trending-up" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Conversion rate</div>
                <div class="tva-stat__value">{{ $conversionRate }}%</div>
                <div style="font-size:11px; color:#94a3b8;">leads → converted</div>
            </div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__icon" style="background:#ede9fe; color:#7c3aed;"><i data-lucide="mic" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Voice replies</div>
                <div class="tva-stat__value">{{ number_format($totals['voice_msgs']) }}</div>
                <div style="font-size:11px; color:#94a3b8;">spoken responses delivered</div>
            </div>
        </div>
    </div>

    {{-- Section 2: Activity & Voice over time --}}
    <div class="ops-chart-grid">
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="bar-chart-3" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Activity — 14 days
                <span class="badge">sessions + leads</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="chartActivity"></canvas></div>
        </div>
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="mic" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Voice replies per day
                <span class="badge">14 days</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="chartVoice"></canvas></div>
        </div>
    </div>

    {{-- Section 3: Channel + Status splits --}}
    <div class="ops-chart-grid">
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="radio" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Channel breakdown
                <span class="badge">all-time</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="chartChannels"></canvas></div>
        </div>
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="activity" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Session status
                <span class="badge">all-time</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="chartStatus"></canvas></div>
        </div>
    </div>

    {{-- Section 4: Lead funnel + Provisioning --}}
    <div class="ops-chart-grid">
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="filter" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Lead funnel
                <span class="badge">all clients</span>
            </div>
            @php $funnelMax = max(1, max($leadFunnel)); @endphp
            @foreach ($leadFunnel as $stage => $count)
                <div class="funnel-bar funnel-bar--{{ $stage }}">
                    <div class="funnel-bar__label">{{ $stage }}</div>
                    <div class="funnel-bar__track">
                        <div class="funnel-bar__fill" style="width: {{ ($count / $funnelMax) * 100 }}%"></div>
                    </div>
                    <div class="funnel-bar__count">{{ $count }}</div>
                </div>
            @endforeach
        </div>
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="folder" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Project provisioning
                <span class="badge">live</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="chartProvisioning"></canvas></div>
        </div>
    </div>

    {{-- Section 5: Top clients leaderboard --}}
    <div class="intro-y box p-5 mt-5">
        <div class="ops-card-title">
            <i data-lucide="award" class="w-4 h-4" style="color: var(--tva-accent);"></i>
            Top workspaces by volume
            <span class="badge">sessions + leads</span>
        </div>
        @forelse ($topClients as $i => $c)
            <div class="top-row">
                <div class="top-row__rank">{{ $i + 1 }}</div>
                <div class="top-row__name">{{ $c['name'] }}</div>
                <div class="top-row__vals">
                    {{ number_format($c['sessions']) }} sess · {{ number_format($c['leads']) }} leads
                </div>
            </div>
        @empty
            <div style="text-align:center; padding:30px; color:#94a3b8;">No data yet.</div>
        @endforelse
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();

    function opsRender() {
        if (typeof Chart === 'undefined') return setTimeout(opsRender, 100);
        var dark = document.documentElement.classList.contains('dark');
        var gridC = dark ? 'rgba(148,163,184,.18)' : 'rgba(148,163,184,.25)';
        var tickC = dark ? '#94a3b8' : '#64748b';
        var common = {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: tickC } } },
            scales: {
                x: { grid: { color: gridC }, ticks: { color: tickC, font: { size: 10 } } },
                y: { grid: { color: gridC }, ticks: { color: tickC, font: { size: 10 }, precision: 0 }, beginAtZero: true }
            }
        };

        var days   = @json(array_keys($sessionsPerDay));
        var pretty = days.map(function (d) { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month:'short', day:'numeric' }); });

        // Activity (dual line)
        new Chart(document.getElementById('chartActivity'), {
            type: 'line',
            data: {
                labels: pretty,
                datasets: [
                    {
                        label: 'Sessions',
                        data: @json(array_values($sessionsPerDay)),
                        borderColor: '#ffb800', backgroundColor: 'rgba(255,184,0,.18)',
                        fill: true, tension: 0.35, pointRadius: 3, borderWidth: 2,
                    },
                    {
                        label: 'Leads',
                        data: @json(array_values($leadsPerDay)),
                        borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,.12)',
                        fill: true, tension: 0.35, pointRadius: 3, borderWidth: 2,
                    },
                ]
            },
            options: common
        });

        // Voice replies (bar)
        new Chart(document.getElementById('chartVoice'), {
            type: 'bar',
            data: {
                labels: pretty,
                datasets: [{
                    label: 'Voice replies',
                    data: @json(array_values($voicePerDay)),
                    backgroundColor: 'rgba(124,58,237,.55)', borderRadius: 6,
                }]
            },
            options: { ...common, plugins: { legend: { display: false } } }
        });

        // Channel doughnut
        var ch = @json($channelBreakdown);
        new Chart(document.getElementById('chartChannels'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(ch).map(function (k) { return k.charAt(0).toUpperCase() + k.slice(1); }),
                datasets: [{
                    data: Object.values(ch),
                    backgroundColor: ['#3b82f6','#f59e0b','#10b981','#7c3aed'],
                    borderWidth: 0,
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: tickC, font: { size: 11 } } } }, cutout: '60%' }
        });

        // Status doughnut
        var st = @json($statusBreakdown);
        new Chart(document.getElementById('chartStatus'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(st).map(function (k) { return k.charAt(0).toUpperCase() + k.slice(1); }),
                datasets: [{
                    data: Object.values(st),
                    backgroundColor: ['#10b981','#94a3b8','#ef4444'],
                    borderWidth: 0,
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: tickC, font: { size: 11 } } } }, cutout: '60%' }
        });

        // Provisioning doughnut
        var pv = @json($provisioning);
        new Chart(document.getElementById('chartProvisioning'), {
            type: 'doughnut',
            data: {
                labels: ['Provisioned', 'Pending'],
                datasets: [{
                    data: [pv.provisioned, pv.pending],
                    backgroundColor: ['#10b981', '#f59e0b'],
                    borderWidth: 0,
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: tickC, font: { size: 11 } } } }, cutout: '65%' }
        });
    }
    opsRender();
</script>
@endsection
