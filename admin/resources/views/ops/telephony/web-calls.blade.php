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
    html.dark .tva-wcs__card { background:#1e293b; border-color:#334155; }
    html.dark .tva-wcs__n { color:#f1f5f9; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🌐</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Website calls</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Who actually tried a "Call us" button — on serveai.com.pk and on every client's website.
            </div>
        </div>
        <a href="{{ route('ops.telephony.sip') }}" class="btn btn-secondary btn-sm" style="background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3);">🛰️ SIP server</a>
    </div>

    <form method="GET" class="mb-4 flex items-center gap-2">
        <select name="project_id" class="form-select" style="max-width:340px" onchange="this.form.submit()">
            <option value="0">All projects</option>
            @foreach ($projects as $p)
                <option value="{{ $p->id }}" @selected($projectId === $p->id)>{{ $p->name }} · {{ $clients[$p->client_id]->name ?? '—' }}</option>
            @endforeach
        </select>
    </form>

    <div class="tva-wcs">
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['calls']) }}</div><div class="tva-wcs__l">Calls that connected</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['visitors']) }}</div><div class="tva-wcs__l">Different visitors (IP)</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['today']) }}</div><div class="tva-wcs__l">Today</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['week']) }}</div><div class="tva-wcs__l">Last 7 days</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ $stats['avg_secs'] !== null ? $stats['avg_secs'] . ' s' : '—' }}</div><div class="tva-wcs__l">Average call length</div></div>
        <div class="tva-wcs__card"><div class="tva-wcs__n">{{ number_format($stats['no_connect']) }}</div><div class="tva-wcs__l">Clicked but never connected</div></div>
    </div>

    <div class="tva-dt-card" style="padding:16px 18px; margin-bottom:18px;">
        <div style="font-weight:600; margin-bottom:6px;">Calls per day — last 14 days</div>
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
                        <td>{{ $p?->name ?? '—' }} <span class="text-slate-500 text-xs">· {{ $p ? ($clients[$p->client_id]->name ?? '—') : '—' }}</span></td>
                        <td><code>{{ $r->ip }}</code></td>
                        <td class="text-xs" style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $r->page_url ?: $r->origin }}</td>
                        <td>{{ $r->ended_at ? (int) $r->started_at->diffInSeconds($r->ended_at, true) . ' s' : 'in progress' }}</td>
                        <td>
                            @if ($r->session_id)
                                <a href="{{ route('ops.sessions.show', ['projectId' => $r->project_id, 'id' => $r->session_id]) }}">Conversation →</a>
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
