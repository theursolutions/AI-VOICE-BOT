@extends('layouts.ops')

@section('content')
<style>
    .ops-chart-grid { display:grid; grid-template-columns: 2fr 1fr; gap:18px; margin-bottom:18px; }
    .ops-chart-grid--3 { grid-template-columns: 1fr 1fr 1fr; }
    .ops-chart-grid--2 { grid-template-columns: 1fr 1fr; }
    @media (max-width: 980px) {
        .ops-chart-grid, .ops-chart-grid--3, .ops-chart-grid--2 { grid-template-columns: 1fr; }
    }
    .ops-chart-wrap { position: relative; height: 240px; }
    @media (max-width: 540px) { .ops-chart-wrap { height: 190px; } }
    .ops-card-title {
        display:flex; align-items:center; gap:8px;
        font-size:13px; font-weight:600; color:#0f172a;
        padding-bottom:12px; border-bottom:1px solid #e2e8f0; margin-bottom:14px;
    }
    .ops-card-title .badge {
        margin-left:auto; font-family: ui-monospace, monospace; font-size:9.5px;
        background:#fef3c7; color:#92400e; padding:3px 9px; border-radius:999px;
        text-transform:uppercase; letter-spacing:.06em; font-weight:700;
    }
    html.dark .ops-card-title { color:#f1f5f9; border-bottom-color:#334155; }

    /* Lead funnel bars */
    .funnel-bar { display:flex; align-items:center; gap:10px; margin-bottom:9px; }
    .funnel-bar__label { width:96px; font-size:11.5px; font-weight:600; text-transform:capitalize; color:#334155; }
    .funnel-bar__track { flex:1; height:12px; background:#f1f5f9; border-radius:6px; overflow:hidden; min-width:50px; }
    .funnel-bar__fill { height:100%; border-radius:6px; transition: width .6s ease; }
    .funnel-bar__count { width:32px; text-align:right; font-size:12px; font-weight:700; color:#0f172a; font-family: ui-monospace, monospace; }
    .funnel-bar--new          .funnel-bar__fill { background: linear-gradient(90deg,#3b82f6,#60a5fa); }
    .funnel-bar--contacted    .funnel-bar__fill { background: linear-gradient(90deg,#f59e0b,#fbbf24); }
    .funnel-bar--qualified    .funnel-bar__fill { background: linear-gradient(90deg,#8b5cf6,#a78bfa); }
    .funnel-bar--converted    .funnel-bar__fill { background: linear-gradient(90deg,#10b981,#34d399); }
    .funnel-bar--disqualified .funnel-bar__fill { background: linear-gradient(90deg,#ef4444,#f87171); }
    html.dark .funnel-bar__label { color:#cbd5e1; }
    html.dark .funnel-bar__track { background:#0f172a; }
    html.dark .funnel-bar__count { color:#f1f5f9; }
</style>

<div class="content">
    {{-- Hero --}}
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🛰️</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Mission control</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Platform-wide snapshot · {{ $stats['clients_active'] }} of {{ $stats['clients'] }} workspaces active ·
                {{ $stats['projects_active'] }} of {{ $stats['projects'] }} projects provisioned.
            </div>
        </div>
        <a href="{{ route('ops.analytics.index') }}" style="background:rgba(255,255,255,.18); padding:10px 16px; border-radius:10px; color:#fff; font-weight:600; font-size:13px; text-decoration:none;">
            <i data-lucide="bar-chart-3" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Deep analytics →
        </a>
    </div>

    {{-- KPIs --}}
    <div class="tva-stat-grid">
        <div class="tva-stat">
            <div class="tva-stat__icon" style="background:#dbeafe; color:#1e40af;"><i data-lucide="users" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Users</div>
                <div class="tva-stat__value">{{ number_format($stats['users']) }}</div>
                <div style="font-size:11px; color:#94a3b8;">{{ $stats['super_admins'] }} super-admin{{ $stats['super_admins'] === 1 ? '' : 's' }}</div>
            </div>
        </div>
        <a href="{{ route('ops.visitors.index') }}" class="tva-stat" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#ccfbf1; color:#0f766e;"><i data-lucide="globe" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Site visitors</div>
                <div class="tva-stat__value">{{ number_format($stats['visitors']) }}</div>
                <div style="font-size:11px; color:#94a3b8;">{{ number_format($stats['visitors_today']) }} today</div>
            </div>
        </a>
        <div class="tva-stat">
            <div class="tva-stat__icon" style="background:#dcfce7; color:#15803d;"><i data-lucide="briefcase" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Workspaces</div>
                <div class="tva-stat__value">{{ number_format($stats['clients']) }}</div>
                <div style="font-size:11px; color:#94a3b8;">{{ $stats['clients_active'] }} active</div>
            </div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__icon" style="background:#ede9fe; color:#7c3aed;"><i data-lucide="message-square" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Conversations</div>
                <div class="tva-stat__value">{{ number_format($totals['sessions']) }}</div>
                <div style="font-size:11px; color:#94a3b8;">{{ number_format($totals['leads']) }} leads captured</div>
            </div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__icon" style="background:#fef3c7; color:#92400e;"><i data-lucide="mic" class="w-4 h-4"></i></div>
            <div>
                <div class="tva-stat__label">Voice replies</div>
                <div class="tva-stat__value">{{ number_format($totals['voice_msgs']) }}</div>
                <div style="font-size:11px; color:#94a3b8;">across all tenants</div>
            </div>
        </div>
    </div>

    {{-- Row 1: Activity 14d (wide) + Channel donut --}}
    <div class="ops-chart-grid">
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="trending-up" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Activity — sessions + leads (14 days)
                <span class="badge">live</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="dashActivity"></canvas></div>
        </div>
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="radio" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Channels
                <span class="badge">all-time</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="dashChannels"></canvas></div>
        </div>
    </div>

    {{-- Row 2: Voice usage bar (wide) + Provisioning donut --}}
    <div class="ops-chart-grid">
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="audio-waveform" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Voice replies per day
                <span class="badge">14 days</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="dashVoice"></canvas></div>
        </div>
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="folder" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Project provisioning
                <span class="badge">live</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="dashProv"></canvas></div>
        </div>
    </div>

    {{-- Row 3: Top clients bar (wide) + Lead funnel --}}
    <div class="ops-chart-grid">
        <div class="intro-y box p-5">
            <div class="ops-card-title">
                <i data-lucide="award" class="w-4 h-4" style="color: var(--tva-accent);"></i>
                Top workspaces — sessions + leads
                <span class="badge">top 5</span>
            </div>
            <div class="ops-chart-wrap"><canvas id="dashTopClients"></canvas></div>
        </div>
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
                    <div class="funnel-bar__track"><div class="funnel-bar__fill" style="width: {{ ($count / $funnelMax) * 100 }}%"></div></div>
                    <div class="funnel-bar__count">{{ $count }}</div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- System health --}}
    <div class="intro-y box p-5 mb-5">
        <h2 class="font-medium text-base mb-3"><i data-lucide="activity" class="w-4 h-4 inline -mt-0.5 mr-1"></i> System health</h2>
        <div class="tva-stat-grid" style="margin:0;">
            @foreach ([
                'master_db'   => ['Master DB',     'database'],
                'tenant_host' => ['Tenant DB host','server'],
                'voice'       => ['Voice engine',  'mic'],
                'twilio'      => ['Twilio',        'phone-call'],
            ] as $key => [$label, $icon])
                @php $h = $health[$key]; @endphp
                <div class="tva-stat">
                    <div class="tva-stat__icon" style="background:{{ $h['ok'] ? '#dcfce7' : '#fee2e2' }}; color:{{ $h['ok'] ? '#15803d' : '#b91c1c' }};">
                        <i data-lucide="{{ $icon }}" class="w-4 h-4"></i>
                    </div>
                    <div style="min-width:0; flex:1;">
                        <div class="tva-stat__label">{{ $label }}</div>
                        <div style="margin-top:4px;">
                            @if ($h['ok'])
                                <span class="tva-status is-active">Online</span>
                            @else
                                <span class="tva-status is-abandoned">Offline</span>
                            @endif
                        </div>
                        <div style="font-family: ui-monospace, monospace; font-size:10px; color:#94a3b8; margin-top:6px; word-break:break-all;">{{ $h['msg'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Recent activity (workspaces + users) --}}
    <div class="grid grid-cols-12 gap-5">
        <div class="intro-y box p-5 col-span-12 lg:col-span-6">
            <div class="flex items-center mb-3">
                <h2 class="font-medium text-base"><i data-lucide="briefcase" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Recent workspaces</h2>
                <a href="{{ route('ops.clients.index') }}" class="ml-auto text-xs" style="color: var(--tva-accent);">View all →</a>
            </div>
            <table class="tva-dt-table">
                <thead><tr><th>ID</th><th>Name</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($recentClients as $c)
                    <tr>
                        <td style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $c->id }}</td>
                        <td>
                            <div style="font-weight:600;">{{ $c->name }}</div>
                            <div style="font-size:11px; color:#94a3b8; font-family: ui-monospace, monospace;">/{{ $c->slug }}</div>
                        </td>
                        <td>
                            @if (($c->is_active ?? 'Yes') === 'Yes')
                                <span class="tva-status is-active">Active</span>
                            @else
                                <span class="tva-status is-suspended">Suspended</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="text-align:center; padding:24px; color:#94a3b8;">No workspaces yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="intro-y box p-5 col-span-12 lg:col-span-6">
            <div class="flex items-center mb-3">
                <h2 class="font-medium text-base"><i data-lucide="users" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Recent users</h2>
                <a href="{{ route('ops.users.index') }}" class="ml-auto text-xs" style="color: var(--tva-accent);">View all →</a>
            </div>
            <table class="tva-dt-table">
                <thead><tr><th>ID</th><th>Name</th><th>Email</th></tr></thead>
                <tbody>
                @forelse ($recentUsers as $u)
                    <tr>
                        <td style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $u->id }}</td>
                        <td style="font-weight:600;">{{ $u->name }}</td>
                        <td style="font-family: ui-monospace, monospace; font-size:11.5px; color:#64748b;">{{ $u->email }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="text-align:center; padding:24px; color:#94a3b8;">No users yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="intro-y box p-5 mt-5">
        <div class="flex items-center mb-3">
            <h2 class="font-medium text-base"><i data-lucide="file-text" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Recent audit events</h2>
            <a href="{{ route('ops.audit.index') }}" class="ml-auto text-xs" style="color: var(--tva-accent);">View all →</a>
        </div>
        <table class="tva-dt-table">
            <thead><tr><th>When</th><th>Action</th><th>Target</th></tr></thead>
            <tbody>
            @forelse ($recentAudit as $a)
                <tr>
                    <td style="font-family: ui-monospace, monospace; color:#64748b;">{{ date('M j H:i:s', $a->created_at) }}</td>
                    <td style="font-family: ui-monospace, monospace; color: var(--tva-accent); font-weight:600;">{{ $a->action }}</td>
                    <td style="font-family: ui-monospace, monospace; color:#94a3b8;">{{ $a->target_type ? $a->target_type.'#'.$a->target_id : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="text-align:center; padding:24px; color:#94a3b8;">No audit events yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();

    function dashRender() {
        if (typeof Chart === 'undefined') return setTimeout(dashRender, 100);
        var dark = document.documentElement.classList.contains('dark');
        var gridC = dark ? 'rgba(148,163,184,.18)' : 'rgba(148,163,184,.25)';
        var tickC = dark ? '#94a3b8' : '#64748b';
        var common = {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: tickC, font: { size: 11 } } } },
            scales: {
                x: { grid: { color: gridC }, ticks: { color: tickC, font: { size: 10 } } },
                y: { grid: { color: gridC }, ticks: { color: tickC, font: { size: 10 }, precision: 0 }, beginAtZero: true }
            }
        };

        var days   = @json(array_keys($sessionsPerDay));
        var pretty = days.map(function (d) { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { month:'short', day:'numeric' }); });

        // Activity dual line
        new Chart(document.getElementById('dashActivity'), {
            type: 'line',
            data: {
                labels: pretty,
                datasets: [
                    { label:'Sessions', data: @json(array_values($sessionsPerDay)),
                      borderColor:'#ffb800', backgroundColor:'rgba(255,184,0,.18)',
                      fill:true, tension:.35, pointRadius:3, borderWidth:2 },
                    { label:'Leads', data: @json(array_values($leadsPerDay)),
                      borderColor:'#7c3aed', backgroundColor:'rgba(124,58,237,.12)',
                      fill:true, tension:.35, pointRadius:3, borderWidth:2 },
                ]
            },
            options: common
        });

        // Channel doughnut
        var ch = @json($channelBreakdown);
        new Chart(document.getElementById('dashChannels'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(ch).map(function (k) { return k.charAt(0).toUpperCase() + k.slice(1); }),
                datasets: [{
                    data: Object.values(ch),
                    backgroundColor: ['#3b82f6','#f59e0b','#10b981','#7c3aed'],
                    borderWidth: 0,
                }]
            },
            options: { responsive:true, maintainAspectRatio:false,
                plugins: { legend: { position:'bottom', labels: { color: tickC, font: { size: 11 } } } },
                cutout: '60%' }
        });

        // Voice replies bar
        new Chart(document.getElementById('dashVoice'), {
            type: 'bar',
            data: {
                labels: pretty,
                datasets: [{
                    label: 'Voice replies',
                    data: @json(array_values($voicePerDay)),
                    backgroundColor: 'rgba(124,58,237,.55)',
                    borderRadius: 6,
                }]
            },
            options: { ...common, plugins: { legend: { display:false } } }
        });

        // Provisioning doughnut
        var pv = @json($provisioning);
        new Chart(document.getElementById('dashProv'), {
            type: 'doughnut',
            data: {
                labels: ['Provisioned', 'Pending'],
                datasets: [{
                    data: [pv.provisioned, pv.pending],
                    backgroundColor: ['#10b981', '#f59e0b'],
                    borderWidth: 0,
                }]
            },
            options: { responsive:true, maintainAspectRatio:false,
                plugins: { legend: { position:'bottom', labels: { color: tickC, font: { size: 11 } } } },
                cutout: '65%' }
        });

        // Top clients horizontal bar
        var top = @json($topClients);
        new Chart(document.getElementById('dashTopClients'), {
            type: 'bar',
            data: {
                labels: top.map(function (c) { return c.name; }),
                datasets: [
                    { label:'Sessions', data: top.map(function (c) { return c.sessions; }),
                      backgroundColor: 'rgba(255,184,0,.6)', borderRadius: 4, stack:'a' },
                    { label:'Leads', data: top.map(function (c) { return c.leads; }),
                      backgroundColor: 'rgba(124,58,237,.6)', borderRadius: 4, stack:'a' },
                ]
            },
            options: {
                responsive:true, maintainAspectRatio:false, indexAxis: 'y',
                plugins: { legend: { position:'bottom', labels: { color: tickC, font: { size: 11 } } } },
                scales: {
                    x: { grid: { color: gridC }, ticks: { color: tickC, font: { size: 10 }, precision: 0 }, beginAtZero:true, stacked:true },
                    y: { grid: { color: gridC }, ticks: { color: tickC, font: { size: 11 } }, stacked:true }
                }
            }
        });
    }
    dashRender();
</script>
@endsection
