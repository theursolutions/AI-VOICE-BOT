@extends('layouts.ops')

@section('content')
<style>
    .tva-wcs { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:18px; }
    .tva-wcs__card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; }
    .tva-wcs__n { font-size:26px; font-weight:700; color:#0f172a; line-height:1.1; }
    .tva-wcs__l { font-size:12px; color:#64748b; margin-top:4px; }
    .tva-wcs-bars { display:flex; align-items:flex-end; gap:6px; height:120px; padding-top:8px; }
    .tva-wcs-bar { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; }
    .tva-wcs-bar span { width:100%; background:linear-gradient(180deg,#7c3aed,#4f46e5); border-radius:6px 6px 0 0; min-height:2px; }
    .tva-wcs-bar small { font-size:10px; color:#94a3b8; margin-top:4px; }
    .tva-wcs-bar b { font-size:10px; color:#475569; }
    .tva-wcs-demo { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)) auto; gap:12px; align-items:end; }
    .tva-wcs-demo__save { display:flex; align-items:center; gap:14px; white-space:nowrap; }
    @media (max-width: 900px) { .tva-wcs-demo { grid-template-columns:1fr; } }
    html.dark .tva-wcs__card { background:#1e293b; border-color:#334155; }
    html.dark .tva-wcs__n { color:#f1f5f9; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🌐</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Website calls</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Who actually tried a "Call us" button, on serveai.com.pk and on every client's website.
            </div>
        </div>
        <a href="{{ route('ops.telephony.sip') }}" class="btn btn-secondary btn-sm" style="background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3);">🛰️ SIP server</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <div class="tva-dt-card" style="padding:18px 20px; margin-bottom:18px;">
        <div class="flex items-center gap-2 mb-3">
            <div style="font-weight:600; flex:1;">Main website demo call</div>
            @if ($demo)
                <span class="text-xs px-2 py-1 rounded-full" style="background:{{ $demoSettings['enabled'] ? '#dcfce7' : '#f1f5f9' }}; color:{{ $demoSettings['enabled'] ? '#166534' : '#64748b' }}">
                    {{ $demoSettings['enabled'] ? 'On' : 'Off' }}
                </span>
            @endif
        </div>
        @if ($demo)
            <form method="POST" action="{{ route('ops.telephony.web-calls.demo') }}" class="tva-wcs-demo">
                @csrf
                <div>
                    <label class="form-label">Free call length <span class="text-slate-400 font-normal">(seconds)</span></label>
                    <input type="number" name="max_seconds" id="tva-demo-secs" class="form-control" min="20" max="1800" step="5" required
                           value="{{ old('max_seconds', $demoSettings['max_seconds']) }}">
                </div>
                <div>
                    <label class="form-label">Calls a day <span class="text-slate-400 font-normal">(per device)</span></label>
                    <input type="number" name="calls_per_day" class="form-control" min="1" max="50" required
                           value="{{ old('calls_per_day', $demoSettings['calls_per_day']) }}">
                </div>
                <div>
                    <label class="form-label">Hours between calls <span class="text-slate-400 font-normal">(per device)</span></label>
                    <input type="number" name="gap_hours" class="form-control" min="0" max="24" step="0.5" required
                           value="{{ old('gap_hours', $demoSettings['gap_hours']) }}">
                </div>
                <div>
                    <label class="form-label">Calls a day <span class="text-slate-400 font-normal">(per network / IP)</span></label>
                    <input type="number" name="network_calls_per_day" class="form-control" min="1" max="500" required
                           value="{{ old('network_calls_per_day', $demoSettings['network_calls_per_day']) }}">
                </div>
                <div class="tva-wcs-demo__save">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1" @checked($demoSettings['enabled'])>
                        <span>Show the call button</span>
                    </label>
                    <button class="btn btn-primary">Save</button>
                </div>
            </form>
            <small class="text-slate-500 text-xs block mt-2" id="tva-demo-hint">
                The AI greets the caller in the first 5 seconds and says the free-call goodbye 5 seconds before the end,
                whatever the length (now: goodbye at <b>{{ max(0, (int) $demoSettings['max_seconds'] - 5) }} s</b>).
                Project: {{ $demo->name }}.
            </small>
            <script>
                (function () {
                    var input = document.getElementById('tva-demo-secs'), hint = document.querySelector('#tva-demo-hint b');
                    if (!input || !hint) return;
                    input.addEventListener('input', function () { hint.textContent = Math.max(0, (parseInt(input.value, 10) || 0) - 5) + ' s'; });
                })();
            </script>
        @else
            <div class="text-slate-500 text-sm">
                Set <code>LANDING_DEMO_KEY</code> in <code>admin/.env</code> to the demo project's API key to manage the serveai.com.pk call here.
            </div>
        @endif
    </div>

    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <form method="GET">
            <select name="project_id" class="form-select" style="min-width:300px" onchange="this.form.submit()">
                <option value="0">All projects</option>
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected($projectId === $p->id)>{{ $p->name }} · {{ $clients[$p->client_id]->name ?? 'n/a' }}</option>
                @endforeach
            </select>
        </form>
        <form method="POST" action="{{ route('ops.telephony.web-calls.reset') }}" class="flex items-center gap-2 ml-auto">
            @csrf
            @if ($projectId)<input type="hidden" name="project_id" value="{{ $projectId }}">@endif
            <input type="text" name="ip" class="form-control" style="width:200px" placeholder="Visitor IP, e.g. 39.45.1.2" required>
            <button class="btn btn-secondary" title="Their calls so far stop counting toward the daily limit (they stay in these numbers)">Reset limit</button>
        </form>
    </div>

    <div class="tva-wcs">
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['calls']) }}</div><div class="tva-wcs__l">Calls that connected</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['visitors']) }}</div><div class="tva-wcs__l">Different visitors (IP)</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['today']) }}</div><div class="tva-wcs__l">Today</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['week']) }}</div><div class="tva-wcs__l">Last 7 days</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ $stats['avg_secs'] !== null ? $stats['avg_secs'] . ' s' : 'n/a' }}</div><div class="tva-wcs__l">Average call length</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['no_connect']) }}</div><div class="tva-wcs__l">Clicked but never connected</div></div>
    </div>

    <div class="tva-dt-card" style="padding:16px 18px; margin-bottom:18px;">
        <div style="font-weight:600; margin-bottom:6px;">Calls per day, last 14 days</div>
        @php $max = max(1, $days->max()); @endphp
        <div class="tva-wcs-bars">
            @foreach ($days as $day => $n)
                <div class="tva-wcs-bar" title="{{ $day }}: {{ $n }}">
                    <b>{{ $n ?: '' }}</b>
                    <span style="height:{{ max(2, round($n / $max * 100)) }}%"></span>
                    <small>{{ \Illuminate\Support\Carbon::parse($day)->format('d/m') }}</small>
                </div>
            @endforeach
        </div>
    </div>

    <div class="tva-dt-card">
        <div class="overflow-x-auto">
            <table class="tva-dt-table">
                <thead>
                    <tr><th>When</th><th>Project · Owner</th><th>Visitor IP</th><th>Page</th><th>Length</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($recent as $r)
                    @php $p = $projects[$r->project_id] ?? null; @endphp
                    <tr>
                        <td title="{{ $r->started_at }}">{{ $r->started_at?->diffForHumans() }}</td>
                        <td>{{ $p?->name ?? 'n/a' }} <span class="text-slate-500 text-xs">· {{ $p ? ($clients[$p->client_id]->name ?? 'n/a') : 'n/a' }}</span></td>
                        <td><code>{{ $r->ip }}</code></td>
                        <td class="text-xs" style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $r->page_url ?: $r->origin }}</td>
                        <td>{{ $r->ended_at ? (int) $r->started_at->diffInSeconds($r->ended_at, true) . ' s' : 'in progress' }}</td>
                        <td class="whitespace-nowrap">
                            @if ($r->session_id)
                                <a href="{{ route('ops.sessions.show', ['projectId' => $r->project_id, 'id' => $r->session_id]) }}">Conversation →</a>
                            @endif
                            @if ($r->counts_toward_limit)
                                <form method="POST" action="{{ route('ops.telephony.web-calls.reset') }}" class="inline ml-2">
                                    @csrf
                                    <input type="hidden" name="ip" value="{{ $r->ip }}">
                                    <input type="hidden" name="project_id" value="{{ $r->project_id }}">
                                    <button class="btn btn-secondary" style="padding:2px 8px; font-size:12px">Reset limit</button>
                                </form>
                            @else
                                <span class="text-slate-400 text-xs ml-2">limit reset</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-slate-500">No website calls yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
