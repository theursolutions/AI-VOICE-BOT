@extends('layouts.master')

@section('content')
<div class="content">
    <div class="intro-y flex items-center mt-8">
        <h2 class="text-lg font-medium mr-auto">Add Data Source</h2>
        <a href="{{ route('data-sources.index') }}" class="btn btn-secondary">
            <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Back
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger-soft show mt-4" role="alert">
            <ul class="mb-0">
                @foreach ($errors->all() as $msg)
                    <li>{{ $msg }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($projects->isEmpty())
        <div class="intro-y box p-5 mt-5 bg-warning/10">
            <p class="text-warning font-medium">
                No projects yet for this workspace. Create a project via Onboarding before adding data sources.
            </p>
        </div>
    @else

    {{-- ── Database-access strategy framing (compact, theme-aware) ──── --}}
    <style>
        .tva-tier-strip { display:flex; flex-wrap:wrap; gap:8px; margin-top: 18px; margin-bottom: 4px; }
        .tva-tier-chip {
            flex:1; min-width: 220px;
            display:flex; align-items:center; gap:10px;
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 10px;
            padding: 10px 12px;
            text-decoration: none;
            transition: background .15s, border-color .15s;
        }
        .tva-tier-chip:hover { background: rgba(255,255,255,.08); }
        .tva-tier-chip__badge {
            font-size: 9px; font-weight: 700; letter-spacing: .05em;
            padding: 2px 8px; border-radius: 999px;
            flex-shrink: 0;
        }
        .tva-tier-chip__title { font-size: 12px; font-weight: 600; color: #f1f5f9; line-height: 1.2; }
        .tva-tier-chip__desc  { font-size: 11px; color: #94a3b8; margin-top: 2px; line-height: 1.4; }
        /* Light-mode fallback (no .dark on html) */
        html:not(.dark) .tva-tier-chip { background: rgba(15,23,42,.03); border-color: rgba(15,23,42,.08); }
        html:not(.dark) .tva-tier-chip:hover { background: rgba(15,23,42,.06); }
        html:not(.dark) .tva-tier-chip__title { color: #0f172a; }
        html:not(.dark) .tva-tier-chip__desc  { color: #64748b; }
    </style>
    <div class="intro-y mt-5">
        <div class="text-xs uppercase tracking-wider opacity-70" style="font-weight:600;">
            <i data-lucide="shield-check" class="w-3 h-3 inline -mt-0.5"></i>
            Three ways the bot can read your business data
        </div>
        <div class="tva-tier-strip">
            <a href="#card-snapshot" class="tva-tier-chip">
                <span class="tva-tier-chip__badge" style="background:#dcfce7; color:#15803d;">B</span>
                <div>
                    <div class="tva-tier-chip__title">Data snapshot · safest</div>
                    <div class="tva-tier-chip__desc">CSV/JSON exports indexed. No live DB.</div>
                </div>
            </a>
            <a href="#card-webhook" class="tva-tier-chip">
                <span class="tva-tier-chip__badge" style="background:#fef3c7; color:#b45309;">C</span>
                <div>
                    <div class="tva-tier-chip__title">Webhook tools · medium</div>
                    <div class="tva-tier-chip__desc">Bot calls your endpoint on demand.</div>
                </div>
            </a>
            <a href="#card-database" class="tva-tier-chip">
                <span class="tva-tier-chip__badge" style="background:#fee2e2; color:#b91c1c;">A</span>
                <div>
                    <div class="tva-tier-chip__title">Live database · advanced</div>
                    <div class="tva-tier-chip__desc">Read-only SQL. Most powerful.</div>
                </div>
            </a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 mt-5">

        {{-- ── Website URL ───────────────────────────────────────── --}}
        <div class="intro-y col-span-12 md:col-span-6 box p-5">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 rounded-full bg-primary/20 text-primary
                            flex items-center justify-center mr-3">
                    <i data-lucide="globe" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="font-medium text-base">Website URL</h3>
                    <p class="text-xs text-slate-500">Crawl a public website for content.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('data-sources.store.website') }}">
                @csrf
                <div class="grid grid-cols-12 gap-3 mb-3">
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Project <span class="text-danger">*</span></label>
                        <select name="project_id" required class="form-select">
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" required value="{{ old('name') }}"
                               class="form-control" placeholder="Acme marketing site">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">URL <span class="text-danger">*</span></label>
                    <input type="url" name="url" required value="{{ old('url') }}"
                           class="form-control" placeholder="https://example.com">
                </div>
                <button type="submit" class="btn btn-primary w-full">
                    <i data-lucide="download-cloud" class="w-4 h-4 mr-2"></i> Crawl & index
                </button>
            </form>
        </div>

        {{-- ── Document Upload ────────────────────────────────────── --}}
        <div class="intro-y col-span-12 md:col-span-6 box p-5">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 rounded-full bg-success/20 text-success
                            flex items-center justify-center mr-3">
                    <i data-lucide="file-text" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="font-medium text-base">Knowledge documents</h3>
                    <p class="text-xs text-slate-500">PDF, DOCX, TXT — articles, FAQs, policies. Up to 20 MB each.</p>
                </div>
            </div>
            <form method="POST" enctype="multipart/form-data"
                  action="{{ route('data-sources.store.documents') }}">
                @csrf
                <input type="hidden" name="kind" value="document">
                <div class="grid grid-cols-12 gap-3 mb-3">
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Project <span class="text-danger">*</span></label>
                        <select name="project_id" required class="form-select">
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Collection name <span class="text-danger">*</span></label>
                        <input type="text" name="name" required
                               class="form-control" placeholder="Help center articles">
                    </div>
                    <div class="col-span-12">
                        <label class="form-label">Files <span class="text-danger">*</span></label>
                        <input type="file" name="files[]" multiple required
                               accept=".pdf,.txt,.docx"
                               class="form-control">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-full">
                    <i data-lucide="upload" class="w-4 h-4 mr-2"></i> Upload & index
                </button>
            </form>
        </div>

        {{-- ── B · Data Snapshot (CSV / JSON) ─────────────────────── --}}
        <div id="card-snapshot" class="intro-y col-span-12 md:col-span-6 box p-5"
             style="border:2px solid #86efac;">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 rounded-full flex items-center justify-center mr-3"
                     style="background:#dcfce7; color:#15803d;">
                    <i data-lucide="database" class="w-5 h-5"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-medium text-base flex items-center gap-2">
                        Data snapshot
                        <span style="background:#dcfce7; color:#15803d; font-size:9px; padding:2px 7px; border-radius:999px; font-weight:700; letter-spacing:.05em;">TIER B · SAFEST</span>
                    </h3>
                    <p class="text-xs text-slate-500">CSV, JSON, XLSX — product catalog, customer list, pricing tables.</p>
                </div>
            </div>
            <form method="POST" enctype="multipart/form-data"
                  action="{{ route('data-sources.store.documents') }}">
                @csrf
                <input type="hidden" name="kind" value="data_snapshot">
                <div class="grid grid-cols-12 gap-3 mb-3">
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Project <span class="text-danger">*</span></label>
                        <select name="project_id" required class="form-select">
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Snapshot name <span class="text-danger">*</span></label>
                        <input type="text" name="name" required
                               class="form-control" placeholder="Product catalog">
                    </div>
                    <div class="col-span-12">
                        <label class="form-label">Data file <span class="text-danger">*</span></label>
                        <input type="file" name="files[]" multiple required
                               accept=".csv,.json"
                               class="form-control">
                        <small class="text-slate-500 text-xs block mt-1">
                            Each row becomes a searchable chunk. Re-upload anytime to refresh.
                        </small>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-full">
                    <i data-lucide="upload-cloud" class="w-4 h-4 mr-2"></i> Upload snapshot
                </button>
            </form>
        </div>

        {{-- ── C · Webhook tools ──────────────────────────────────── --}}
        <div id="card-webhook" class="intro-y col-span-12 md:col-span-6 box p-5"
             style="border:2px solid #fbbf24;">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 rounded-full flex items-center justify-center mr-3"
                     style="background:#fef3c7; color:#b45309;">
                    <i data-lucide="zap" class="w-5 h-5"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-medium text-base flex items-center gap-2">
                        Webhook tool
                        <span style="background:#fef3c7; color:#b45309; font-size:9px; padding:2px 7px; border-radius:999px; font-weight:700; letter-spacing:.05em;">TIER C · MEDIUM</span>
                    </h3>
                    <p class="text-xs text-slate-500">Bot calls your HTTP endpoint when intent matches.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('data-sources.store.webhook') }}">
                @csrf
                <div class="grid grid-cols-12 gap-3 mb-3">
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Project <span class="text-danger">*</span></label>
                        <select name="project_id" required class="form-select">
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Tool name <span class="text-danger">*</span></label>
                        <input type="text" name="name" required maxlength="120"
                               class="form-control" placeholder="Order status lookup">
                    </div>

                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Method <span class="text-danger">*</span></label>
                        <select name="method" required class="form-select">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Endpoint URL <span class="text-danger">*</span></label>
                        <input type="url" name="url" required maxlength="2048"
                               class="form-control"
                               placeholder="https://yoursite.com/api/order-status">
                    </div>

                    <div class="col-span-12">
                        <label class="form-label">When should the bot call this? <span class="text-danger">*</span></label>
                        <textarea name="when_to_use" required rows="2" maxlength="500" class="form-control"
                                  placeholder="User asks about an order status, refund, shipment, or tracking number."></textarea>
                        <small class="text-slate-500 text-xs">Plain-English description. The bot uses this to decide when to invoke the tool.</small>
                    </div>

                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Auth</label>
                        <select name="auth_type" class="form-select" id="webhook_auth_type" onchange="tvaWebhookAuthChange(this.value)">
                            <option value="none">None</option>
                            <option value="bearer">Bearer token</option>
                            <option value="basic">Basic auth</option>
                            <option value="api_key">API key (header)</option>
                            <option value="header">Custom header</option>
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-6" id="webhook_auth_value_wrap" style="display:none;">
                        <label class="form-label">Auth value</label>
                        <input type="password" name="auth_value" class="form-control" placeholder="••••••••" autocomplete="off">
                    </div>
                    <div class="col-span-12 md:col-span-6" id="webhook_auth_header_wrap" style="display:none;">
                        <label class="form-label">Header name</label>
                        <input type="text" name="auth_header" class="form-control" placeholder="X-API-Key">
                    </div>

                    <div class="col-span-12">
                        <label class="form-label">Expected arguments (JSON schema)</label>
                        <textarea name="args_json" rows="3" maxlength="4000"
                                  class="form-control"
                                  style="font-family: ui-monospace, Consolas, monospace; font-size: 12px;"
                                  placeholder='{"order_id": "string", "email": "email"}'></textarea>
                        <small class="text-slate-500 text-xs">Object of arg name → type hint. Bot extracts these from the conversation before calling.</small>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i data-lucide="zap" class="w-4 h-4 mr-2"></i> Register webhook
                </button>
            </form>
        </div>

        {{-- ── HubSpot OAuth ──────────────────────────────────────── --}}
        <div class="intro-y col-span-12 md:col-span-6 box p-5">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 rounded-full bg-info/20 text-info
                            flex items-center justify-center mr-3">
                    <i data-lucide="link-2" class="w-5 h-5"></i>
                </div>
                <div>
                    <h3 class="font-medium text-base">HubSpot CRM</h3>
                    <p class="text-xs text-slate-500">Pull contacts, deals, and notes via OAuth.</p>
                </div>
            </div>
            @if ($projects->count() === 1)
                <a href="{{ route('oauth.hubspot.start', ['project_id' => $project->id]) }}"
                   class="btn btn-primary w-full">
                    <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>
                    Connect HubSpot for “{{ $project->name }}”
                </a>
            @else
                <form method="GET" action="{{ route('oauth.hubspot.start') }}">
                    <div class="mb-3">
                        <label class="form-label">Project <span class="text-danger">*</span></label>
                        <select name="project_id" required class="form-select">
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-full">
                        <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>
                        Connect HubSpot
                    </button>
                </form>
            @endif
        </div>

        {{-- ── A · Direct DB Credentials (Live SQL) ──────────────────── --}}
        <div id="card-database" class="intro-y col-span-12 md:col-span-6 box p-5"
             style="border:2px solid #fca5a5;">
            <div class="flex items-center mb-4">
                <div class="w-10 h-10 rounded-full flex items-center justify-center mr-3"
                     style="background:#fee2e2; color:#b91c1c;">
                    <i data-lucide="database" class="w-5 h-5"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-medium text-base flex items-center gap-2">
                        Live database (SQL)
                        <span style="background:#fee2e2; color:#b91c1c; font-size:9px; padding:2px 7px; border-radius:999px; font-weight:700; letter-spacing:.05em;">TIER A · ADVANCED</span>
                    </h3>
                    <p class="text-xs text-slate-500">On save we connect once, auto-introspect your tables, and enforce SELECT-only at query time. <b>Use a read-only DB user.</b></p>
                </div>
            </div>
            <form method="POST" action="{{ route('data-sources.store.database') }}">
                @csrf
                <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Project <span class="text-danger">*</span></label>
                        <select name="project_id" required class="form-select">
                            @foreach ($projects as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" required class="form-control"
                               placeholder="Production read-replica">
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Host <span class="text-danger">*</span></label>
                        <input type="text" name="host" required class="form-control"
                               placeholder="127.0.0.1">
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Port <span class="text-danger">*</span></label>
                        <input type="number" name="port" required value="3306" class="form-control">
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">Database name <span class="text-danger">*</span></label>
                        <input type="text" name="db_name" required class="form-control"
                               placeholder="my_app">
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">User <span class="text-danger">*</span></label>
                        <input type="text" name="user" required class="form-control"
                               placeholder="readonly_bot">
                    </div>
                    <div class="col-span-12 md:col-span-6">
                        <label class="form-label">
                            Password
                            <span class="text-xs text-slate-500" style="font-weight:400;">— optional</span>
                        </label>
                        <input type="password" name="password" class="form-control" autocomplete="off"
                               placeholder="leave blank for local DBs">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-full mt-4">
                    <i data-lucide="save" class="w-4 h-4 mr-2"></i> Save credentials
                </button>
            </form>
        </div>

    </div>
    @endif
</div>

<script>
    // Show/hide the auth-value + header-name fields based on auth_type.
    function tvaWebhookAuthChange(v) {
        var valWrap = document.getElementById('webhook_auth_value_wrap');
        var hdrWrap = document.getElementById('webhook_auth_header_wrap');
        if (!valWrap || !hdrWrap) return;
        valWrap.style.display = (v && v !== 'none') ? '' : 'none';
        hdrWrap.style.display = (v === 'api_key' || v === 'header') ? '' : 'none';
    }
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
</script>
@endsection
