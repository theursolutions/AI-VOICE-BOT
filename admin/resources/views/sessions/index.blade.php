@extends('layouts.master')

@section('content')
<style>
    .tva-dt-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-dt-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }

    .tva-stat-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-bottom:18px; }
    .tva-stat {
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        padding:14px 16px; cursor:pointer; transition: all .15s;
        display:flex; align-items:center; gap:12px;
    }
    .tva-stat:hover { border-color:#c7d2fe; transform: translateY(-1px); box-shadow:0 4px 10px -4px rgba(99,102,241,.25); }
    .tva-stat.is-active { border-color:#6366f1; background:linear-gradient(135deg,#eef2ff,#fff); }
    .tva-stat__icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .tva-stat__label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .tva-stat__value { font-size:22px; font-weight:700; color:#0f172a; line-height:1.2; }

    .tva-dt-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
    .tva-dt-toolbar {
        padding:16px 20px; border-bottom:1px solid #e2e8f0;
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        background: #fafbff;
    }
    .tva-dt-search { position:relative; flex:1; min-width:240px; max-width:380px; }
    .tva-dt-search input {
        width:100%; padding:9px 12px 9px 36px;
        border:1px solid #e2e8f0; border-radius:8px; background:#fff;
        font-size:13px; transition: border-color .15s;
    }
    .tva-dt-search input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
    .tva-dt-search > i,
    .tva-dt-search > svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; width:16px; height:16px; pointer-events:none; }
    .tva-dt-search__clear { position:absolute; right:8px; top:50%; transform:translateY(-50%); background:none; border:none; color:#94a3b8; cursor:pointer; padding:4px; }
    .tva-dt-search__clear:hover { color:#0f172a; }

    .tva-dt-toolbar select {
        border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px;
        font-size:12px; background:#fff; color:#0f172a; min-width: 130px;
    }
    .tva-dt-toolbar label.lbl { font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }

    .tva-dt-table { width:100%; }
    .tva-dt-table thead th {
        background:#f8fafc; color:#475569;
        font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700;
        padding:12px 16px; text-align:left; border-bottom:1px solid #e2e8f0;
    }
    .tva-dt-table tbody td {
        padding:14px 16px; border-bottom:1px solid #f1f5f9; font-size:13px; color:#1e293b; vertical-align: middle;
    }
    .tva-dt-table tbody tr:hover { background:#fafbff; }
    .tva-dt-table tbody tr:last-child td { border-bottom:none; }

    .tva-channel-chip {
        display:inline-flex; align-items:center; gap:5px;
        padding:3px 10px; border-radius:999px;
        font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.04em;
    }
    .tva-channel-chip.is-web   { background:#dbeafe; color:#1e40af; }
    .tva-channel-chip.is-voice { background:#fef3c7; color:#92400e; }
    .tva-channel-chip.is-phone { background:#dcfce7; color:#15803d; }
    .tva-channel-chip.is-sms   { background:#ede9fe; color:#7c3aed; }
    .tva-channel-chip.is-internal { background:#e0e7ff; color:#4338ca; }
    .tva-internal-tag {
        display:inline-flex; align-items:center; gap:4px; margin-left:6px;
        padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700;
        text-transform:uppercase; letter-spacing:.04em; background:#eef2ff; color:#4338ca; border:1px solid #c7d2fe;
    }

    .tva-status {
        display:inline-flex; align-items:center; gap:6px;
        padding:3px 10px; border-radius:999px;
        font-size:11px; font-weight:600; text-transform:capitalize;
    }
    .tva-status::before { content:''; width:6px; height:6px; border-radius:50%; }
    .tva-status.is-active     { background:#dcfce7; color:#15803d; }
    .tva-status.is-active::before     { background:#10b981; animation: pulse 1.5s infinite; }
    .tva-status.is-ended      { background:#f1f5f9; color:#475569; }
    .tva-status.is-ended::before      { background:#94a3b8; }
    .tva-status.is-abandoned  { background:#fee2e2; color:#b91c1c; }
    .tva-status.is-abandoned::before  { background:#ef4444; }
    @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:.4;} }

    .tva-dt-footer {
        padding:12px 20px; border-top:1px solid #e2e8f0;
        display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;
        background:#fafbff;
    }
    .tva-dt-footer__info { font-size:12px; color:#64748b; }
    .tva-pag { display:flex; gap:4px; align-items:center; }
    .tva-pag a, .tva-pag span {
        display:inline-flex; align-items:center; justify-content:center;
        min-width:32px; height:32px; padding:0 8px; border-radius:8px;
        font-size:12px; font-weight:600; color:#475569;
        border:1px solid #e2e8f0; background:#fff; text-decoration:none;
    }
    .tva-pag a:hover { border-color:#c7d2fe; color:#3730a3; background:#eef2ff; }
    .tva-pag .is-current { background:var(--tva-gradient); color:#fff; border-color:transparent; }
    .tva-pag .is-disabled { opacity:.4; cursor:not-allowed; }

    html.dark .tva-stat { background:#1e293b; border-color:#334155; }
    html.dark .tva-stat.is-active { background:linear-gradient(135deg,#312e81,#1e293b); border-color:#6366f1; }
    html.dark .tva-stat__value { color:#f1f5f9; }
    html.dark .tva-dt-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-dt-toolbar, html.dark .tva-dt-footer { background:#0f172a; border-color:#334155; }
    html.dark .tva-dt-toolbar select, html.dark .tva-dt-search input { background:#1e293b; color:#f1f5f9; border-color:#334155; }
    html.dark .tva-dt-table thead th { background:#0f172a; color:#cbd5e1; border-bottom-color:#334155; }
    html.dark .tva-dt-table tbody td { color:#e2e8f0; border-bottom-color:#334155; }
    html.dark .tva-dt-table tbody tr:hover { background:#0f172a; }
    html.dark .tva-pag a, html.dark .tva-pag span { background:#1e293b; color:#cbd5e1; border-color:#334155; }
    html.dark .tva-pag a:hover { background:#312e81; color:#c7d2fe; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">💬</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Conversations</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Every chat and call your AI handled — drill in to see full transcripts and extracted leads.
            </div>
        </div>
        @if ($project)
            <div style="background:rgba(255,255,255,.15); padding:8px 14px; border-radius:10px;">
                <div style="font-size:10px; opacity:.85; text-transform:uppercase; letter-spacing:.05em;">Project</div>
                <div style="font-size:14px; font-weight:600;">{{ $project->name }}</div>
            </div>
        @endif
    </div>

    @php
        $base = ['project_id' => $projectId, 'q' => $search, 'per_page' => $perPage, 'channel' => $channel];
        $linkAll       = route('sessions.index', ['client' => $client->slug]) . '?' . http_build_query($base);
        $linkActive    = route('sessions.index', ['client' => $client->slug]) . '?' . http_build_query($base + ['status' => 'active']);
        $linkEnded     = route('sessions.index', ['client' => $client->slug]) . '?' . http_build_query($base + ['status' => 'ended']);
        $linkAbandoned = route('sessions.index', ['client' => $client->slug]) . '?' . http_build_query($base + ['status' => 'abandoned']);
    @endphp
    <div class="tva-stat-grid">
        <a href="{{ $linkAll }}" class="tva-stat {{ !$status ? 'is-active' : '' }}" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#f1f5f9; color:#475569;"><i data-lucide="message-square" class="w-4 h-4"></i></div>
            <div><div class="tva-stat__label">Total sessions</div><div class="tva-stat__value">{{ number_format($counts['total']) }}</div></div>
        </a>
        <a href="{{ $linkActive }}" class="tva-stat {{ $status === 'active' ? 'is-active' : '' }}" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#dcfce7; color:#15803d;"><i data-lucide="zap" class="w-4 h-4"></i></div>
            <div><div class="tva-stat__label">Active now</div><div class="tva-stat__value">{{ number_format($counts['active']) }}</div></div>
        </a>
        <a href="{{ $linkEnded }}" class="tva-stat {{ $status === 'ended' ? 'is-active' : '' }}" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#f1f5f9; color:#475569;"><i data-lucide="check" class="w-4 h-4"></i></div>
            <div><div class="tva-stat__label">Ended</div><div class="tva-stat__value">{{ number_format($counts['ended']) }}</div></div>
        </a>
        <a href="{{ $linkAbandoned }}" class="tva-stat {{ $status === 'abandoned' ? 'is-active' : '' }}" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#fee2e2; color:#b91c1c;"><i data-lucide="x-circle" class="w-4 h-4"></i></div>
            <div><div class="tva-stat__label">Abandoned</div><div class="tva-stat__value">{{ number_format($counts['abandoned']) }}</div></div>
        </a>
    </div>

    <div class="tva-dt-card">
        <form method="GET" class="tva-dt-toolbar" id="tva-sessions-toolbar">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search name, email, phone, call SID, ID…" autocomplete="off">
                @if ($search !== '')
                    <button type="button" class="tva-dt-search__clear" data-tva-search-clear><i data-lucide="x" class="w-3 h-3"></i></button>
                @endif
            </div>

            <select name="project_id" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>

            <select name="channel" onchange="this.form.submit()">
                <option value="">All channels</option>
                @foreach (['web','voice','phone','sms','whatsapp','instagram','facebook','internal'] as $ch)
                    <option value="{{ $ch }}" @selected($channel === $ch)>{{ $ch === 'internal' ? 'Internal (Ask AI)' : ucfirst($ch) }}</option>
                @endforeach
            </select>

            <select name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (['active','ended','abandoned'] as $st)
                    <option value="{{ $st }}" @selected($status === $st)>{{ ucfirst($st) }}</option>
                @endforeach
            </select>

            <div class="ml-auto flex items-center gap-2">
                <label class="lbl">Show</label>
                <select name="per_page" onchange="this.form.submit()">
                    @foreach ([10, 25, 50, 100] as $pp)
                        <option value="{{ $pp }}" @selected($perPage === $pp)>{{ $pp }}</option>
                    @endforeach
                </select>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="tva-dt-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Last activity</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($sessions ?? [] as $sess)
                    @php
                        $name = $sess->customer_name ?: 'Anonymous';
                        $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'A', 0, 2));
                        $started  = $sess->started_at ? date('M j, Y · H:i', $sess->started_at) : '—';
                        $last     = $sess->last_activity_at ? date('M j · H:i', $sess->last_activity_at) : '—';
                        $rel = '';
                        if ($sess->last_activity_at) {
                            $diff = time() - $sess->last_activity_at;
                            if ($diff < 60)     $rel = $diff . 's ago';
                            elseif ($diff < 3600) $rel = floor($diff/60) . 'm ago';
                            elseif ($diff < 86400) $rel = floor($diff/3600) . 'h ago';
                            else $rel = floor($diff/86400) . 'd ago';
                        }
                    @endphp
                    <tr>
                        <td data-label="ID" style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $sess->id }}</td>
                        <td data-label="Customer">
                            <div class="flex items-center gap-3">
                                <div style="width:32px; height:32px; border-radius:50%; background:var(--tva-gradient); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:11px; flex-shrink:0;">{{ $initials }}</div>
                                <div>
                                    <div style="font-weight:600;">
                                        {{ $name }}
                                        @if ($sess->channel === 'internal')
                                            <span class="tva-internal-tag" title="Internal chat — a team member talking to the AI assistant"><i data-lucide="bot" style="width:11px;height:11px"></i> Ask AI</span>
                                        @endif
                                    </div>
                                    @if ($sess->channel === 'internal')
                                        <div style="font-size:11px; color:#94a3b8;">Internal user · {{ $sess->customer_email ?: 'staff' }}</div>
                                    @elseif ($sess->customer_email)
                                        <div style="font-size:11px; color:#94a3b8;">{{ $sess->customer_email }}</div>
                                    @elseif ($sess->customer_phone)
                                        <div style="font-size:11px; color:#94a3b8;">{{ $sess->customer_phone }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td data-label="Channel"><span class="tva-channel-chip is-{{ $sess->channel }}">{{ $sess->channel === 'internal' ? 'Ask AI' : $sess->channel }}</span></td>
                        <td data-label="Status"><span class="tva-status is-{{ $sess->status }}">{{ $sess->status }}</span></td>
                        <td data-label="Started" style="font-size:12px; color:#475569;">{{ $started }}</td>
                        <td data-label="Last activity" style="font-size:12px; color:#475569;">
                            {{ $last }}
                            @if ($rel) <div style="font-size:10px; color:#94a3b8;">{{ $rel }}</div> @endif
                        </td>
                        <td data-label="Open" style="text-align:right;">
                            <a href="{{ route('sessions.show', ['client' => $client->slug, 'id' => $sess->id]) }}?project_id={{ hashid($projectId) }}"
                               class="inline-flex items-center justify-center w-8 h-8 rounded-lg" style="color:#6366f1; background:#eef2ff;"
                               title="View transcript">
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                        <i data-lucide="message-square-off" class="w-10 h-10 inline mb-2"></i>
                        <div style="font-size:14px; font-weight:500;">
                            @if ($search !== '') No sessions match "{{ $search }}". @else No sessions yet. @endif
                        </div>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if ($sessions && $sessions->total() > 0)
            <div class="tva-dt-footer">
                <div class="tva-dt-footer__info">
                    Showing <b>{{ $sessions->firstItem() }}</b>–<b>{{ $sessions->lastItem() }}</b> of <b>{{ number_format($sessions->total()) }}</b> sessions
                </div>
                @include('partials.pagination', ['paginator' => $sessions])
            </div>
        @endif
    </div>
</div>

<script>
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}

    (function () {
        var form = document.getElementById('tva-sessions-toolbar');
        if (!form) return;
        var input = form.querySelector('input[name="q"]');
        var timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { form.submit(); }, 350);
        });
        var clearBtn = form.querySelector('[data-tva-search-clear]');
        if (clearBtn) clearBtn.addEventListener('click', function () {
            input.value = ''; form.submit();
        });
    })();
</script>
@endsection
