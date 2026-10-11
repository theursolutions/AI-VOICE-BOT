@extends('layouts.master')

@section('content')
<style>
    .tva-wc-hero { background:var(--tva-gradient); color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow:0 10px 30px -10px rgba(0,0,0,.35); display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
    .tva-wc-hero__icon { width:56px; height:56px; border-radius:14px; background:rgba(255,255,255,.18); display:flex;
        align-items:center; justify-content:center; font-size:28px; border:2px solid rgba(255,255,255,.3); flex-shrink:0; }
    .tva-wc-hero a.btn { background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3); }
    .tva-wc-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px 22px; margin-bottom:18px; }
    .tva-wc-card__title { font-size:15px; font-weight:600; color:#0f172a; }
    .tva-wc-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:20px; align-items:start; }
    @media (max-width: 1100px) { .tva-wc-grid { grid-template-columns:1fr; } }
    .tva-wc-code { background:#0f172a; color:#e2e8f0; border-radius:10px; padding:12px 14px; font-size:12px; overflow-x:auto; white-space:pre; }
    .tva-wc-table { width:100%; font-size:13px; border-collapse:collapse; }
    .tva-wc-table th { text-align:left; font-size:11px; text-transform:uppercase; color:#64748b; padding:6px 8px; border-bottom:1px solid #e2e8f0; }
    .tva-wc-table td { padding:8px; border-bottom:1px solid #f1f5f9; }
    html.dark .tva-wc-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-wc-card__title { color:#f1f5f9; }
</style>

<div class="content">
    <div class="tva-wc-hero mt-6">
        <div class="tva-wc-hero__icon">🌐</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Website call button</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                A "Call us" button for your website: visitors talk to your AI agent right in the browser, with no phone and no app.
            </div>
        </div>
        <a href="{{ route('telephony.index', ['client' => $client]) }}" class="btn btn-sm">Numbers</a>
        <a href="{{ route('telephony.extensions', ['client' => $client]) }}" class="btn btn-sm">Extensions</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif
    @unless ($serverReady)
        <div class="alert alert-warning-soft show mb-4 text-sm">
            The phone server isn't set up for websites yet (it needs a secure <code>wss://</code> address). Your
            platform administrator sets this under Ops → Telephony → SIP &amp; WebRTC server.
        </div>
    @endunless

    @foreach ($projects as $project)
        @php $s = $settings[$project->id]; @endphp
        <div class="tva-wc-card">
            <div class="flex items-center gap-2 mb-3">
                <div class="tva-wc-card__title flex-1">{{ $project->name }}</div>
                <span class="text-xs px-2 py-1 rounded-full" style="background:{{ $s['enabled'] ? '#dcfce7' : '#f1f5f9' }}; color:{{ $s['enabled'] ? '#166534' : '#64748b' }}">
                    {{ $s['enabled'] ? 'On' : 'Off' }}
                </span>
            </div>
            <div class="tva-wc-grid">
                <form method="POST" action="{{ route('telephony.web-call.save', ['client' => $client]) }}" class="grid gap-3">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $project->id }}">
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1" @checked($s['enabled'])>
                        <span>Show the call button and answer calls from it</span>
                    </label>
                    <div>
                        <label class="form-label">Button text</label>
                        <input type="text" name="button_label" class="form-control" maxlength="40" value="{{ $s['button_label'] }}">
                    </div>
                    <div>
                        <label class="form-label">Websites allowed to use it</label>
                        <input type="text" name="allowed_origins" class="form-control" value="{{ implode(', ', $s['allowed_origins']) }}" placeholder="serveai.com.pk">
                        <small class="text-slate-500 text-xs">Comma-separated domains (sub-domains included). Empty = any website.</small>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="form-label">Call length</label>
                            <select name="max_seconds" class="form-select">
                                @php
                                    $lengths = [30 => '30 sec', 60 => '1 min', 120 => '2 min', 180 => '3 min', 300 => '5 min', 600 => '10 min'];
                                    // Keep a length set elsewhere (e.g. by the platform admin) instead of silently changing it on save.
                                    $lengths += [(int) $s['max_seconds'] => (int) $s['max_seconds'] . ' sec'];
                                    ksort($lengths);
                                @endphp
                                @foreach ($lengths as $sec => $lbl)
                                    <option value="{{ $sec }}" @selected((int) $s['max_seconds'] === $sec)>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Calls a day</label>
                            <input name="calls_per_day" type="number" min="1" max="50" class="form-control" value="{{ $s['calls_per_day'] }}">
                        </div>
                        <div>
                            <label class="form-label">Hours between</label>
                            <input name="gap_hours" type="number" min="0" max="24" step="0.5" class="form-control" value="{{ $s['gap_hours'] }}">
                        </div>
                    </div>
                    <small class="text-slate-500 text-xs -mt-1">Per visitor (IP address). Near the end the AI says a polite goodbye, then the call ends.</small>
                    <div><button class="btn btn-primary">Save</button></div>
                </form>

                <div>
                    <label class="form-label">Add to your website</label>
                    <div class="tva-wc-code">&lt;script src="{{ $scriptUrl }}"
        data-project-key="{{ $project->project_api_key }}" async&gt;&lt;/script&gt;</div>
                    <small class="text-slate-500 text-xs block mt-2">
                        Paste before <code>&lt;/body&gt;</code>. It adds a round call button (bottom left). Any element with
                        <code>data-tva-call</code> opens it too, e.g. <code>&lt;button data-tva-call&gt;Call our AI&lt;/button&gt;</code>.
                        The AI that answers is the agent your project uses for phone calls (Agents → <i>On calls</i>).
                    </small>
                </div>
            </div>
        </div>
    @endforeach

    <div class="tva-wc-card">
        <div class="tva-wc-card__title mb-3">Recent website calls</div>
        @if ($recent->isEmpty())
            <div class="text-slate-500 text-sm">No calls yet.</div>
        @else
            <table class="tva-wc-table">
                <thead><tr><th>When</th><th>Project</th><th>Visitor IP</th><th>Page</th><th>Length</th></tr></thead>
                <tbody>
                @foreach ($recent as $r)
                    <tr>
                        <td>{{ $r->started_at?->diffForHumans() }}</td>
                        <td>{{ $projects->firstWhere('id', $r->project_id)?->name }}</td>
                        <td><code>{{ $r->ip }}</code></td>
                        <td class="text-xs" style="max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $r->page_url ?: $r->origin }}</td>
                        <td>{{ $r->ended_at && $r->started_at ? (int) $r->started_at->diffInSeconds($r->ended_at, true) . ' s' : 'n/a' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
