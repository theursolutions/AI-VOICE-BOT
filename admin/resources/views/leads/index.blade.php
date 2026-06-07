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
    .tva-stat__icon {
        width:38px; height:38px; border-radius:10px;
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .tva-stat__label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .tva-stat__value { font-size:22px; font-weight:700; color:#0f172a; line-height:1.2; }

    .tva-dt-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; }
    .tva-dt-toolbar {
        padding:16px 20px; border-bottom:1px solid #e2e8f0;
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
        background: #fafbff;
    }
    .tva-dt-search {
        position:relative; flex:1; min-width:240px; max-width:380px;
    }
    .tva-dt-search input {
        width:100%; padding:9px 12px 9px 36px;
        border:1px solid #e2e8f0; border-radius:8px; background:#fff;
        font-size:13px; transition: border-color .15s;
    }
    .tva-dt-search input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.1); }
    .tva-dt-search > i,
    .tva-dt-search > svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; width:16px; height:16px; pointer-events:none; }
    .tva-dt-search__clear {
        position:absolute; right:8px; top:50%; transform:translateY(-50%);
        background:none; border:none; color:#94a3b8; cursor:pointer; padding:4px;
    }
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

    .tva-status {
        display:inline-flex; align-items:center; gap:6px;
        padding:3px 10px; border-radius:999px;
        font-size:11px; font-weight:600; text-transform:capitalize;
    }
    .tva-status::before { content:''; width:6px; height:6px; border-radius:50%; }
    .tva-status.is-new          { background:#dbeafe; color:#1e40af; }
    .tva-status.is-new::before          { background:#3b82f6; }
    .tva-status.is-contacted    { background:#fef3c7; color:#92400e; }
    .tva-status.is-contacted::before    { background:#f59e0b; }
    .tva-status.is-qualified    { background:#e0e7ff; color:#3730a3; }
    .tva-status.is-qualified::before    { background:#6366f1; }
    .tva-status.is-converted    { background:#dcfce7; color:#15803d; }
    .tva-status.is-converted::before    { background:#10b981; }
    .tva-status.is-disqualified { background:#f1f5f9; color:#64748b; }
    .tva-status.is-disqualified::before { background:#94a3b8; }

    .tva-confidence-bar {
        width:60px; height:6px; background:#e2e8f0; border-radius:999px; overflow:hidden;
        display:inline-block; vertical-align:middle; margin-right:8px;
    }
    .tva-confidence-bar > span { display:block; height:100%; background:linear-gradient(90deg,#6366f1,#8b5cf6); }

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
        <div class="tva-dt-hero__icon">🎯</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Leads</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                People extracted from chat & call conversations — qualify them and push them through your funnel.
            </div>
        </div>
        @if ($project)
            <div style="background:rgba(255,255,255,.15); padding:8px 14px; border-radius:10px;">
                <div style="font-size:10px; opacity:.85; text-transform:uppercase; letter-spacing:.05em;">Project</div>
                <div style="font-size:14px; font-weight:600;">{{ $project->name }}</div>
            </div>
        @endif
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif

    {{-- Status pills as stat cards (clickable filters) --}}
    @php
        $base = ['project_id' => $projectId, 'q' => $search, 'per_page' => $perPage];
        $linkAll        = route('leads.index', ['client' => $client->slug]) . '?' . http_build_query($base);
        $linkNew        = route('leads.index', ['client' => $client->slug]) . '?' . http_build_query($base + ['status' => 'new']);
        $linkQualified  = route('leads.index', ['client' => $client->slug]) . '?' . http_build_query($base + ['status' => 'qualified']);
        $linkConverted  = route('leads.index', ['client' => $client->slug]) . '?' . http_build_query($base + ['status' => 'converted']);
    @endphp
    <div class="tva-stat-grid">
        <a href="{{ $linkAll }}" class="tva-stat {{ !$status ? 'is-active' : '' }}" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#f1f5f9; color:#475569;"><i data-lucide="users" class="w-4 h-4"></i></div>
            <div><div class="tva-stat__label">Total leads</div><div class="tva-stat__value">{{ number_format($counts['total']) }}</div></div>
        </a>
        <a href="{{ $linkNew }}" class="tva-stat {{ $status === 'new' ? 'is-active' : '' }}" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#dbeafe; color:#1e40af;"><i data-lucide="sparkles" class="w-4 h-4"></i></div>
            <div><div class="tva-stat__label">New</div><div class="tva-stat__value">{{ number_format($counts['new']) }}</div></div>
        </a>
        <a href="{{ $linkQualified }}" class="tva-stat {{ $status === 'qualified' ? 'is-active' : '' }}" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#e0e7ff; color:#3730a3;"><i data-lucide="check-circle-2" class="w-4 h-4"></i></div>
            <div><div class="tva-stat__label">Qualified</div><div class="tva-stat__value">{{ number_format($counts['qualified']) }}</div></div>
        </a>
        <a href="{{ $linkConverted }}" class="tva-stat {{ $status === 'converted' ? 'is-active' : '' }}" style="text-decoration:none;">
            <div class="tva-stat__icon" style="background:#dcfce7; color:#15803d;"><i data-lucide="trophy" class="w-4 h-4"></i></div>
            <div><div class="tva-stat__label">Converted</div><div class="tva-stat__value">{{ number_format($counts['converted']) }}</div></div>
        </a>
    </div>

    <div class="tva-dt-card">
        {{-- Toolbar --}}
        <form method="GET" class="tva-dt-toolbar" id="tva-leads-toolbar">
            <div class="tva-dt-search">
                <i data-lucide="search" class="w-4 h-4"></i>
                <input type="text" name="q" value="{{ $search }}" placeholder="Search name, email, phone, notes, ID…" autocomplete="off">
                @if ($search !== '')
                    <button type="button" class="tva-dt-search__clear" data-tva-search-clear><i data-lucide="x" class="w-3 h-3"></i></button>
                @endif
            </div>

            <select name="project_id" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>

            <select name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                @foreach (['new','contacted','qualified','converted','disqualified'] as $st)
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

        {{-- Table --}}
        <div class="overflow-x-auto">
            <table class="tva-dt-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Lead</th>
                        <th>Contact</th>
                        <th>Intent</th>
                        <th>Confidence</th>
                        <th>Status</th>
                        <th>Session</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($leads ?? [] as $lead)
                    @php
                        $f = $lead->fields ?? [];
                        $conf = (int) round(($lead->confidence ?? 0) * 100);
                        $name = $f['name'] ?? 'Anonymous';
                        $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'A', 0, 2));
                    @endphp
                    <tr>
                        <td data-label="ID" style="color:#94a3b8; font-family: ui-monospace, monospace;">#{{ $lead->id }}</td>
                        <td data-label="Lead">
                            <div class="flex items-center gap-3">
                                <div style="width:32px; height:32px; border-radius:50%; background:var(--tva-gradient); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:11px; flex-shrink:0;">{{ $initials }}</div>
                                <div>
                                    <div style="font-weight:600;">{{ $name }}</div>
                                    @if (!empty($f['company']))
                                        <div style="font-size:11px; color:#94a3b8;">{{ $f['company'] }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td data-label="Contact" style="font-size:12px;">
                            @if (!empty($f['email']))
                                <div><i data-lucide="mail" class="w-3 h-3 inline -mt-0.5"></i> {{ $f['email'] }}</div>
                            @endif
                            @if (!empty($f['phone']))
                                <div><i data-lucide="phone" class="w-3 h-3 inline -mt-0.5"></i> {{ $f['phone'] }}</div>
                            @endif
                            @if (empty($f['email']) && empty($f['phone']))
                                <span style="color:#cbd5e1;">—</span>
                            @endif
                        </td>
                        <td data-label="Intent" style="font-size:12px; color:#64748b; max-width:200px;">
                            <div class="truncate" title="{{ $f['intent'] ?? '' }}">{{ $f['intent'] ?? '—' }}</div>
                        </td>
                        <td data-label="Confidence">
                            <span class="tva-confidence-bar"><span style="width: {{ $conf }}%;"></span></span>
                            <span style="font-size:12px; font-weight:600; color:#475569;">{{ $conf }}%</span>
                        </td>
                        <td data-label="Status"><span class="tva-status is-{{ $lead->status }}">{{ $lead->status }}</span></td>
                        <td data-label="Session">
                            @if ($lead->session_id)
                                <a href="{{ route('sessions.show', ['client' => $client->slug, 'id' => $lead->session_id]) }}?project_id={{ $projectId }}"
                                   style="color:#6366f1; text-decoration:none; font-family: ui-monospace, monospace; font-size:12px;">
                                    #{{ $lead->session_id }}
                                </a>
                            @else <span style="color:#cbd5e1;">—</span> @endif
                        </td>
                        <td data-label="Open" style="text-align:right;">
                            <a href="{{ route('leads.show', ['client' => $client->slug, 'id' => $lead->id]) }}?project_id={{ $projectId }}"
                               class="inline-flex items-center justify-center w-8 h-8 rounded-lg" style="color:#6366f1; background:#eef2ff;"
                               title="View lead">
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="text-align:center; padding:60px 20px; color:#94a3b8;">
                        <i data-lucide="inbox" class="w-10 h-10 inline mb-2"></i>
                        <div style="font-size:14px; font-weight:500;">
                            @if ($search !== '') No leads match "{{ $search }}". @else No leads yet. @endif
                        </div>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        {{-- Footer with pagination --}}
        @if ($leads && $leads->total() > 0)
            <div class="tva-dt-footer">
                <div class="tva-dt-footer__info">
                    Showing <b>{{ $leads->firstItem() }}</b>–<b>{{ $leads->lastItem() }}</b> of <b>{{ number_format($leads->total()) }}</b> leads
                </div>
                @include('partials.pagination', ['paginator' => $leads])
            </div>
        @endif
    </div>
</div>

<script>
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}

    // Debounced search submit (300ms after typing stops).
    (function () {
        var form = document.getElementById('tva-leads-toolbar');
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
