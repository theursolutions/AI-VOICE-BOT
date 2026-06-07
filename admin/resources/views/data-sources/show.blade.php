@extends('layouts.master')

@section('content')
<style>
    /* ── Hero ──────────────────────────────────────────────────────── */
    .tva-ds-hero {
        background: var(--tva-gradient);
        color: #fff;
        border-radius: 14px;
        padding: 24px 28px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.4);
    }
    .tva-ds-hero__grid { display:grid; grid-template-columns: auto 1fr auto; gap:22px; align-items:center; }
    @media (max-width: 640px) { .tva-ds-hero__grid { grid-template-columns: auto 1fr; } .tva-ds-hero__side { grid-column:1/-1; text-align:left; } }
    .tva-ds-icon {
        width: 64px; height: 64px; border-radius: 14px;
        background: rgba(255,255,255,.18); color: #fff;
        display: flex; align-items: center; justify-content: center;
        border: 2px solid rgba(255,255,255,.35);
    }
    .tva-ds-label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .85; font-weight: 600; }
    .tva-ds-name  { font-size: 22px; font-weight: 700; margin-top: 4px; line-height: 1.2; }
    .tva-ds-id    { font-size: 12px; opacity: .8; margin-top: 4px; font-family: ui-monospace, monospace; }

    .tva-pill {
        display: inline-flex; align-items: center;
        padding: 5px 14px; border-radius: 999px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .06em;
        background: rgba(255,255,255,.22); color:#fff;
        border: 1px solid rgba(255,255,255,.2);
    }
    .tva-pill-dot { width:7px; height:7px; border-radius:50%; margin-right:7px; }

    /* ── Stat cards ────────────────────────────────────────────────── */
    .tva-stats { display:grid; gap:14px; grid-template-columns: repeat(2,1fr); margin-bottom:24px; }
    @media (min-width: 768px) { .tva-stats { grid-template-columns: repeat(4,1fr); } }
    .tva-stat {
        background: #fff; border-radius: 12px; padding: 16px 18px;
        border: 1px solid #e2e8f0; min-height: 84px;
    }
    .tva-stat__label { font-size: 11px; color:#64748b; text-transform: uppercase; letter-spacing:.06em; font-weight: 600; }
    .tva-stat__value { font-size: 16px; font-weight: 700; color: #0f172a; margin-top: 6px; line-height: 1.3; }
    .tva-stat__sub   { font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 4px; }

    /* ── 6/6 columns ───────────────────────────────────────────────── */
    .tva-cols { display:grid; gap:24px; grid-template-columns: 1fr; }
    @media (min-width: 900px) { .tva-cols { grid-template-columns: 1fr 1fr; align-items: start; } }

    /* ── Section card ──────────────────────────────────────────────── */
    .tva-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:18px 22px; }
    .tva-card__title {
        font-size:14px; font-weight:600; color:#0f172a;
        display:flex; align-items:center; gap:8px;
        margin-bottom: 16px; padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
    }

    /* ── Meta rows ─────────────────────────────────────────────────── */
    .tva-meta-row { display:flex; align-items:center; padding:12px 0; border-bottom:1px dashed #e2e8f0; }
    .tva-meta-row:first-child { padding-top: 0; }
    .tva-meta-row:last-child  { border-bottom: none; padding-bottom: 0; }
    .tva-meta-icon {
        width: 36px; height: 36px; border-radius: 8px;
        display:flex; align-items:center; justify-content:center;
        margin-right: 14px; flex-shrink:0;
    }
    .tva-meta-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .tva-meta-value { font-size:14px; color:#0f172a; font-weight:500; margin-top:2px; }

    /* ── File list ─────────────────────────────────────────────────── */
    .tva-file-row {
        display:flex; align-items:center; gap:12px;
        padding: 10px 12px; border-radius: 10px;
        background:#f8fafc; border:1px solid #e2e8f0;
        margin-bottom: 8px;
    }
    .tva-file-icon { width: 36px; height: 36px; border-radius: 8px; display:flex; align-items:center; justify-content:center; background:#eef2ff; color:#6366f1; flex-shrink:0; }
    .tva-file-name { font-size: 13px; font-weight: 600; color:#0f172a; }
    .tva-file-meta { font-size: 11px; color:#94a3b8; margin-top:2px; }

    /* ── Code block ────────────────────────────────────────────────── */
    .tva-code {
        background:#0f172a; color:#e2e8f0;
        border-radius: 10px; padding: 14px 16px;
        font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        font-size: 12px; line-height: 1.55;
        overflow-x: auto; white-space: pre;
    }

    .tva-error-card {
        background: linear-gradient(135deg,#fef2f2 0%, #fee2e2 100%);
        border: 1px solid #fca5a5;
        border-radius: 10px; padding: 14px 16px;
        display: flex; align-items: flex-start; gap: 10px;
    }
    .tva-error-card__icon { width:30px; height:30px; border-radius:8px; background:#fee2e2; color:#b91c1c; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .tva-error-card__msg  { font-size: 12px; color:#7f1d1d; line-height: 1.5; font-family: ui-monospace, monospace; }

    /* ── Dark mode ─────────────────────────────────────────────────── */
    html.dark .tva-stat        { background:#1e293b; border-color:#334155; }
    html.dark .tva-stat__label { color:#94a3b8; }
    html.dark .tva-stat__value { color:#f1f5f9; }
    html.dark .tva-stat__sub   { color:#94a3b8; }
    html.dark .tva-card        { background:#1e293b; border-color:#334155; }
    html.dark .tva-card__title { color:#f1f5f9; border-bottom-color:#334155; }
    html.dark .tva-meta-row    { border-bottom-color:#334155; }
    html.dark .tva-meta-label  { color:#94a3b8; }
    html.dark .tva-meta-value  { color:#f1f5f9; }
    html.dark .tva-file-row    { background:#0f172a; border-color:#334155; }
    html.dark .tva-file-name   { color:#f1f5f9; }
    html.dark .tva-file-icon   { background:#312e81; color:#a5b4fc; }
</style>

@php
    // Map type → presentation (icon + colors + label + description).
    $typeMap = [
        'website'       => ['globe',         'Website crawl',     'Crawled pages from a public site'],
        'document'      => ['file-text',     'Knowledge documents','PDF/DOCX/TXT files indexed for RAG'],
        'data_snapshot' => ['database-zap',  'Data snapshot',     'CSV/JSON exports, one row per chunk'],
        'webhook'       => ['zap',           'Webhook tools',     'HTTP endpoints called on demand'],
        'crm_oauth'     => ['link-2',        'CRM (OAuth)',       'Connected CRM data via OAuth'],
        'database'      => ['database',      'Live database',     'SQL queries against a live DB'],
        'agent'         => ['server',        'Query agent',       'Customer-hosted query agent'],
    ];
    [$typeIcon, $typeLabel, $typeDesc] = $typeMap[$source->type] ?? ['box', $source->type, ''];

    $statusMap = [
        'active'   => ['#22c55e', 'Active'],
        'pending'  => ['#f59e0b', 'Pending'],
        'failed'   => ['#ef4444', 'Failed'],
        'expired'  => ['#64748b', 'Expired'],
        'disabled' => ['#94a3b8', 'Disabled'],
    ];
    [$statusColor, $statusLabel] = $statusMap[$source->status] ?? ['#64748b', ucfirst($source->status)];

    $created = $source->created_at ? \Illuminate\Support\Carbon::createFromTimestamp($source->created_at) : null;
    $synced  = $source->last_synced_at ? \Illuminate\Support\Carbon::createFromTimestamp($source->last_synced_at) : null;

    $files = $source->config['files'] ?? [];
    $isFiles = is_array($files) && !empty($files);

    function tva_filesize($bytes) {
        if (!$bytes) return null;
        $units = ['B','KB','MB','GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return number_format($bytes, $bytes < 10 && $i ? 1 : 0) . ' ' . $units[$i];
    }
@endphp

<div class="content">
    {{-- ── Breadcrumb + actions ─────────────────────────────────────── --}}
    <div class="intro-y flex items-center mt-6 mb-4 flex-wrap gap-2">
        <h2 class="text-lg font-medium mr-auto">
            <a href="{{ route('data-sources.index') }}" class="text-slate-400 hover:text-primary">
                <i data-lucide="chevron-left" class="w-4 h-4 inline -mt-1"></i> Data sources
            </a>
            <span class="text-slate-400 mx-1">/</span>
            <span>{{ $source->name }}</span>
        </h2>
        @if ($source->status !== 'disabled')
            <form method="POST" class="inline"
                  action="{{ route('data-sources.resync', ['id' => $source->id]) }}">
                @csrf
                <button type="submit" class="btn btn-warning">
                    <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i> Resync
                </button>
            </form>
            <form method="POST" class="inline"
                  action="{{ route('data-sources.destroy', ['id' => $source->id]) }}"
                  onsubmit="return confirm('Disable this data source?');">
                @csrf
                <button type="submit" class="btn btn-danger">
                    <i data-lucide="x-circle" class="w-4 h-4 mr-2"></i> Disable
                </button>
            </form>
        @endif
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
            {{ session('success') }}
        </div>
    @endif

    {{-- ── Hero card ────────────────────────────────────────────────── --}}
    <div class="tva-ds-hero mb-6">
        <div class="tva-ds-hero__grid">
            <div class="tva-ds-icon">
                <i data-lucide="{{ $typeIcon }}" class="w-7 h-7"></i>
            </div>
            <div>
                <div class="tva-ds-label">{{ $typeLabel }}</div>
                <div class="tva-ds-name">{{ $source->name }}</div>
                <div class="tva-ds-id">#{{ $source->id }} · {{ $typeDesc }}</div>
            </div>
            <div class="tva-ds-hero__side text-right">
                <span class="tva-pill">
                    <span class="tva-pill-dot" style="background:{{ $statusColor }}"></span>
                    {{ $statusLabel }}
                </span>
            </div>
        </div>
    </div>

    {{-- ── Last error banner (if any) ───────────────────────────────── --}}
    @if ($source->last_error)
        <div class="tva-error-card mb-6">
            <div class="tva-error-card__icon">
                <i data-lucide="alert-triangle" class="w-4 h-4"></i>
            </div>
            <div class="flex-1">
                <div class="font-semibold mb-1" style="color:#7f1d1d; font-size:13px;">Last sync error</div>
                <div class="tva-error-card__msg">{{ $source->last_error }}</div>
            </div>
        </div>
    @endif

    {{-- ── Stat row ─────────────────────────────────────────────────── --}}
    <div class="tva-stats">
        <div class="tva-stat">
            <div class="tva-stat__label">Status</div>
            <div class="tva-stat__value">{{ $statusLabel }}</div>
            <div class="tva-stat__sub">current sync state</div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Type</div>
            <div class="tva-stat__value">{{ $typeLabel }}</div>
            <div class="tva-stat__sub">{{ ucfirst(str_replace('_',' ', $source->type)) }}</div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Last synced</div>
            <div class="tva-stat__value">{{ $synced ? $synced->diffForHumans() : '—' }}</div>
            <div class="tva-stat__sub">{{ $synced ? $synced->format('M d, Y · H:i') : 'Never' }}</div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Created</div>
            <div class="tva-stat__value">{{ $created ? $created->format('M d') : '—' }}</div>
            <div class="tva-stat__sub">{{ $created ? $created->format('Y · H:i') : '' }}</div>
        </div>
    </div>

    {{-- ── Two-column body ──────────────────────────────────────────── --}}
    <div class="tva-cols">
        {{-- LEFT: Overview meta --}}
        <div class="tva-card">
            <div class="tva-card__title">
                <i data-lucide="info" class="w-4 h-4"></i> Details
            </div>

            <div class="tva-meta-row">
                <div class="tva-meta-icon" style="background:#eef2ff; color:#6366f1;">
                    <i data-lucide="hash" class="w-4 h-4"></i>
                </div>
                <div class="flex-1">
                    <div class="tva-meta-label">ID</div>
                    <div class="tva-meta-value" style="font-family: ui-monospace, monospace;">#{{ $source->id }}</div>
                </div>
            </div>

            <div class="tva-meta-row">
                <div class="tva-meta-icon" style="background:#ecfdf5; color:#10b981;">
                    <i data-lucide="{{ $typeIcon }}" class="w-4 h-4"></i>
                </div>
                <div class="flex-1">
                    <div class="tva-meta-label">Type</div>
                    <div class="tva-meta-value">{{ $typeLabel }}</div>
                </div>
            </div>

            <div class="tva-meta-row">
                <div class="tva-meta-icon" style="background:#fef3c7; color:#b45309;">
                    <i data-lucide="activity" class="w-4 h-4"></i>
                </div>
                <div class="flex-1">
                    <div class="tva-meta-label">Status</div>
                    <div class="tva-meta-value">
                        <span class="inline-flex items-center" style="color:{{ $statusColor }};">
                            <span class="tva-pill-dot" style="background:{{ $statusColor }};"></span>
                            {{ $statusLabel }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="tva-meta-row">
                <div class="tva-meta-icon" style="background:#fce7f3; color:#be185d;">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                </div>
                <div class="flex-1">
                    <div class="tva-meta-label">Last synced</div>
                    <div class="tva-meta-value">
                        {{ $synced ? $synced->format('M d, Y · H:i') : 'Never synced' }}
                    </div>
                </div>
            </div>

            <div class="tva-meta-row">
                <div class="tva-meta-icon" style="background:#e0e7ff; color:#4338ca;">
                    <i data-lucide="calendar" class="w-4 h-4"></i>
                </div>
                <div class="flex-1">
                    <div class="tva-meta-label">Created</div>
                    <div class="tva-meta-value">
                        {{ $created ? $created->format('M d, Y · H:i') : '—' }}
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT: Ingestion status --}}
        <div class="tva-card">
            <div class="tva-card__title">
                <i data-lucide="activity" class="w-4 h-4"></i> Ingestion status
            </div>
            @if ($remote)
                @if (isset($remote['error']))
                    <div class="tva-error-card">
                        <div class="tva-error-card__icon"><i data-lucide="alert-triangle" class="w-4 h-4"></i></div>
                        <div class="tva-error-card__msg">{{ $remote['error'] }}</div>
                    </div>
                @else
                    <div class="tva-code">{{ json_encode($remote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
                @endif
            @else
                <div class="text-center py-8" style="color:#94a3b8;">
                    <i data-lucide="inbox" class="w-10 h-10 inline mb-2 opacity-60"></i>
                    <div class="text-sm" style="font-weight:600;">No ingestion job yet</div>
                    <div class="text-xs mt-1">Click <b>Resync</b> above to queue one.</div>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Database schema preview (only for live SQL sources) ──────── --}}
    @if ($source->type === 'database')
        @php
            $dbCfg = $source->config ?? [];
            $dbSchema = $dbCfg['schema'] ?? [];
            $tableCount = is_array($dbSchema) ? count($dbSchema) : 0;
            $colCount = 0;
            if (is_array($dbSchema)) {
                foreach ($dbSchema as $cols) $colCount += is_array($cols) ? count($cols) : 0;
            }
            $queriesRun = (int) ($dbCfg['queries_run'] ?? 0);
            $maxRows    = (int) ($dbCfg['max_rows']    ?? 100);
            $timeout    = (int) ($dbCfg['timeout_sec'] ??   8);
            $lastQAt    = $dbCfg['last_query_at'] ?? null;
            $lastQMs    = $dbCfg['last_query_ms'] ?? null;
        @endphp

        {{-- Safety + stats row --}}
        <div class="tva-stats mt-6">
            <div class="tva-stat">
                <div class="tva-stat__label">Tables introspected</div>
                <div class="tva-stat__value">{{ $tableCount }}</div>
                <div class="tva-stat__sub">{{ $colCount }} column(s) total</div>
            </div>
            <div class="tva-stat">
                <div class="tva-stat__label">Queries run</div>
                <div class="tva-stat__value">{{ number_format($queriesRun) }}</div>
                <div class="tva-stat__sub">{{ $lastQAt ? 'last: ' . \Illuminate\Support\Carbon::createFromTimestamp($lastQAt)->diffForHumans() : 'never' }}</div>
            </div>
            <div class="tva-stat">
                <div class="tva-stat__label">Row cap</div>
                <div class="tva-stat__value">{{ $maxRows }}</div>
                <div class="tva-stat__sub">forced LIMIT per query</div>
            </div>
            <div class="tva-stat">
                <div class="tva-stat__label">Timeout</div>
                <div class="tva-stat__value">{{ $timeout }}s</div>
                <div class="tva-stat__sub">{{ $lastQMs !== null ? "last took {$lastQMs}ms" : '' }}</div>
            </div>
        </div>

        {{-- Schema preview --}}
        <div class="tva-card mt-6">
            <div class="tva-card__title">
                <i data-lucide="table" class="w-4 h-4"></i> Introspected schema
                <span class="ml-auto text-xs" style="color:#94a3b8; font-weight:500;">{{ $tableCount }} table(s)</span>
            </div>
            @if ($tableCount === 0)
                <div class="text-center py-6" style="color:#94a3b8;">
                    <div class="text-sm">No schema captured yet.</div>
                    <div class="text-xs mt-1">Re-save the data source to reconnect and introspect.</div>
                </div>
            @else
                @foreach ($dbSchema as $table => $cols)
                    <div style="margin-bottom: 12px;">
                        <div style="font-family: ui-monospace, monospace; font-size: 13px; font-weight: 700; color: var(--tva-primary); margin-bottom: 4px;">
                            <i data-lucide="hash" class="w-3 h-3 inline -mt-0.5"></i> {{ $table }}
                        </div>
                        <div class="tva-code" style="font-size: 11px; line-height: 1.6;">{{ is_array($cols) ? implode("\n", $cols) : (string) $cols }}</div>
                    </div>
                @endforeach
            @endif

            <div class="text-xs mt-2" style="color:#64748b;">
                <i data-lucide="shield-check" class="w-3 h-3 inline -mt-0.5" style="color:#10b981;"></i>
                Every query is validated SELECT-only, capped at <b>{{ $maxRows }}</b> rows, and times out after <b>{{ $timeout }}s</b>. Run history is logged.
            </div>
        </div>

        {{-- Test query panel (database only) --}}
        <div class="tva-card mt-6">
            <div class="tva-card__title">
                <i data-lucide="play" class="w-4 h-4"></i> Test query
                <span class="ml-auto text-xs" style="color:#94a3b8; font-weight:500;">verify the bot can answer from this DB</span>
            </div>
            <p class="text-xs mb-3" style="color:#64748b;">
                Type a plain-English question. We'll send it through the same pipeline a chat turn uses — LLM generates SQL, safety validator runs, the query hits your DB, results show below.
            </p>
            <input type="text" id="tva_db_test_input"
                   class="form-control"
                   placeholder='e.g. "how many users do we have?" or "list 5 recent orders"'
                   maxlength="1000">
            <button type="button" id="tva_db_test_btn" class="btn btn-primary mt-3">
                <i data-lucide="play" class="w-4 h-4 mr-2"></i> Run test query
            </button>
            <div id="tva_db_test_result" class="mt-3" style="display:none;"></div>
        </div>

        <script>
            (function () {
                var btn = document.getElementById('tva_db_test_btn');
                if (!btn) return;
                var url   = "{{ route('data-sources.test-query', ['id' => $source->id]) }}";
                var token = "{{ csrf_token() }}";

                function escapeHtml(s) {
                    return String(s == null ? '' : s)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;');
                }

                function renderRows(rows) {
                    if (!rows || rows.length === 0) {
                        return '<div class="text-xs" style="color:#94a3b8;">(0 rows)</div>';
                    }
                    var cols = Object.keys(rows[0]);
                    var thead = cols.map(function (c) { return '<th>' + escapeHtml(c) + '</th>'; }).join('');
                    var tbody = rows.map(function (r) {
                        return '<tr>' + cols.map(function (c) {
                            var v = r[c];
                            if (v === null) v = 'NULL';
                            else if (typeof v === 'object') v = JSON.stringify(v);
                            return '<td>' + escapeHtml(v) + '</td>';
                        }).join('') + '</tr>';
                    }).join('');
                    return '<div style="overflow-x:auto;"><table class="table table-sm" style="font-size:12px;">'
                         + '<thead><tr>' + thead + '</tr></thead><tbody>' + tbody + '</tbody></table></div>';
                }

                btn.addEventListener('click', function () {
                    var q = document.getElementById('tva_db_test_input').value.trim();
                    var panel = document.getElementById('tva_db_test_result');
                    if (!q) {
                        panel.style.display = 'block';
                        panel.innerHTML = '<div class="text-xs" style="color:#b91c1c;">Type a question first.</div>';
                        return;
                    }
                    panel.style.display = 'block';
                    panel.innerHTML = '<div class="text-xs" style="color:#64748b;">Generating SQL + running query…</div>';

                    var fd = new FormData();
                    fd.append('_token', token);
                    fd.append('query', q);

                    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var statusColor = data.ok ? '#15803d' : '#b91c1c';
                            var statusBg    = data.ok ? '#dcfce7' : '#fee2e2';
                            var statusLbl   = data.ok ? 'OK'      : 'FAILED';

                            var html = '<div style="display:flex; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap;">' +
                                       '<span style="background:' + statusBg + '; color:' + statusColor +
                                       '; font-size:10px; font-weight:700; padding:3px 10px; border-radius:999px;">' + statusLbl + '</span>';
                            if (data.row_count != null) {
                                html += '<span class="text-xs" style="color:#64748b;">' + data.row_count + ' row(s)</span>';
                            }
                            if (data.duration_ms != null) {
                                html += '<span class="text-xs" style="color:#64748b;">· ' + data.duration_ms + ' ms</span>';
                            }
                            html += '</div>';

                            if (data.sql) {
                                html += '<div class="text-xs mb-1" style="color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600;">Generated SQL</div>';
                                html += '<div class="tva-code" style="margin-bottom:10px;">' + escapeHtml(data.sql) + '</div>';
                            }
                            if (data.error) {
                                html += '<div class="tva-error-card"><div class="tva-error-card__icon">'
                                      + '<i data-lucide="alert-triangle" class="w-4 h-4"></i></div>'
                                      + '<div class="tva-error-card__msg">' + escapeHtml(data.error) + '</div></div>';
                            }
                            if (data.ok && data.rows) {
                                html += '<div class="text-xs mb-1" style="color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600;">Rows</div>';
                                html += renderRows(data.rows);
                            }
                            panel.innerHTML = html;
                            // The local lucide build sometimes requires
                            // {icons}; wrap so a thrown error doesn't
                            // bubble into the .catch() below and mask
                            // the real result.
                            try {
                                if (window.lucide && typeof window.lucide.createIcons === 'function') {
                                    try { window.lucide.createIcons(); }
                                    catch (e) {
                                        if (window.lucide.icons) {
                                            window.lucide.createIcons({ icons: window.lucide.icons });
                                        }
                                    }
                                }
                            } catch (_) {}
                        })
                        .catch(function (err) {
                            // Show the actual fetch error, not a JS error
                            // from icon rendering inside the .then().
                            var msg = (err && err.message) ? err.message : String(err);
                            panel.innerHTML = '<div class="text-xs" style="color:#b91c1c;">Request failed: ' + escapeHtml(msg) + '</div>';
                        });
                });

                document.getElementById('tva_db_test_input').addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
                });
            })();
        </script>
    @endif

    {{-- ── Webhook test panel (only for webhook tools) ──────────────── --}}
    @if ($source->type === 'webhook')
        @php
            $whCfg = $source->config ?? [];
            $whArgs = $whCfg['args'] ?? [];
            $sampleArgs = is_array($whArgs)
                ? collect($whArgs)->mapWithKeys(fn ($v, $k) => [$k => ''])->toArray()
                : [];
        @endphp
        <div class="tva-card mt-6">
            <div class="tva-card__title">
                <i data-lucide="send" class="w-4 h-4"></i> Send a test request
                <span class="ml-auto text-xs" style="color:#94a3b8; font-weight:500;">
                    {{ $whCfg['method'] ?? 'GET' }} · {{ $whCfg['url'] ?? '' }}
                </span>
            </div>
            <p class="text-xs mb-3" style="color:#64748b;">
                Fire a one-off request to your endpoint with the args below. Use this to verify
                the URL is reachable + the response shape is what the bot expects.
            </p>
            <textarea id="tva_wh_test_args" rows="3"
                      class="form-control"
                      style="font-family: ui-monospace, Consolas, monospace; font-size: 12px;"
                      placeholder='{"order_id": "1234", "email": "test@example.com"}'>{{ !empty($sampleArgs) ? json_encode($sampleArgs, JSON_PRETTY_PRINT) : '' }}</textarea>
            <button type="button" id="tva_wh_test_btn" class="btn btn-primary mt-3">
                <i data-lucide="zap" class="w-4 h-4 mr-2"></i> Send test request
            </button>

            <div id="tva_wh_test_result" class="mt-3" style="display:none;"></div>
        </div>

        <script>
            (function () {
                var btn = document.getElementById('tva_wh_test_btn');
                if (!btn) return;
                var url = "{{ route('data-sources.test-webhook', ['id' => $source->id]) }}";
                var token = "{{ csrf_token() }}";

                btn.addEventListener('click', function () {
                    var args = document.getElementById('tva_wh_test_args').value || '';
                    var panel = document.getElementById('tva_wh_test_result');
                    panel.style.display = 'block';
                    panel.innerHTML = '<div class="text-xs" style="color:#64748b;">Sending…</div>';
                    var fd = new FormData();
                    fd.append('_token', token);
                    fd.append('test_args', args);
                    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            var statusColor = data.ok ? '#15803d' : '#b91c1c';
                            var statusBg    = data.ok ? '#dcfce7' : '#fee2e2';
                            var statusLbl   = data.ok ? 'OK'      : 'FAILED';
                            var bodyText    = data.body || '';
                            try { bodyText = JSON.stringify(JSON.parse(bodyText), null, 2); } catch (_) {}
                            panel.innerHTML =
                                '<div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">' +
                                  '<span style="background:' + statusBg + '; color:' + statusColor +
                                  '; font-size:10px; font-weight:700; padding:3px 10px; border-radius:999px;">' +
                                  statusLbl + '</span>' +
                                  '<span class="text-xs" style="color:#64748b;">HTTP ' + (data.status_code || 0) +
                                  (data.error ? ' · ' + data.error : '') + '</span>' +
                                '</div>' +
                                '<div class="tva-code">' + (bodyText ? bodyText.replace(/</g, '&lt;') : '(empty)') + '</div>';
                        })
                        .catch(function (err) {
                            panel.innerHTML = '<div class="text-danger text-xs">Test failed: ' + err + '</div>';
                        });
                });
            })();
        </script>
    @endif

    {{-- ── Files block (only for file-based sources) ────────────────── --}}
    @if ($isFiles)
        <div class="tva-card mt-6">
            <div class="tva-card__title">
                <i data-lucide="paperclip" class="w-4 h-4"></i> Uploaded files
                <span class="ml-auto text-xs" style="color:#94a3b8; font-weight:500;">{{ count($files) }} file(s)</span>
            </div>
            @foreach ($files as $f)
                @php
                    $name = $f['original_name'] ?? basename($f['path'] ?? 'unknown');
                    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
                    $size = tva_filesize($f['size'] ?? null);
                @endphp
                <div class="tva-file-row">
                    <div class="tva-file-icon">
                        <i data-lucide="file" class="w-4 h-4"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="tva-file-name">{{ $name }}</div>
                        <div class="tva-file-meta">
                            <span class="uppercase">{{ $ext }}</span>
                            @if ($size) · {{ $size }} @endif
                            @if (!empty($f['mime'])) · {{ $f['mime'] }} @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── Configuration JSON ───────────────────────────────────────── --}}
    <div class="tva-card mt-6">
        <div class="tva-card__title">
            <i data-lucide="code-2" class="w-4 h-4"></i> Raw configuration
        </div>
        @php
            $safeConfig = $source->config ?? [];
            // Hide secrets — DB password (tier A) and webhook auth (tier C).
            foreach (['password', 'auth_value'] as $secret) {
                if (is_array($safeConfig) && !empty($safeConfig[$secret])) {
                    $safeConfig[$secret] = '••••••••';
                }
            }
        @endphp
        <div class="tva-code">{{ json_encode($safeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</div>
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
</script>
@endsection
