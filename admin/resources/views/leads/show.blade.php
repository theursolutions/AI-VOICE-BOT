@extends('layouts.master')

@section('content')
<style>
    /* ── Hero ──────────────────────────────────────────────────────── */
    .tva-lead-hero {
        background: var(--tva-gradient);
        color: #fff;
        border-radius: 14px;
        padding: 24px 28px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.4);
    }
    .tva-hero-grid { display:grid; grid-template-columns:auto 1fr auto; gap:22px; align-items:center; }
    @media (max-width: 640px) { .tva-hero-grid { grid-template-columns:auto 1fr; } .tva-hero-side { grid-column:1/-1; text-align:left; } }

    .tva-avatar {
        width: 64px; height: 64px; border-radius: 50%;
        background: rgba(255,255,255,.18); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 22px;
        border: 2px solid rgba(255,255,255,.35);
    }
    .tva-hero-label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .85; font-weight: 600; }
    .tva-hero-name  { font-size: 22px; font-weight: 700; margin-top: 4px; line-height: 1.2; }
    .tva-hero-meta  { font-size: 13px; opacity: .92; margin-top: 6px; display:flex; flex-wrap:wrap; gap:14px; }

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
        border: 1px solid #e2e8f0;
        min-height: 84px;
    }
    .tva-stat__label { font-size: 11px; color:#64748b; text-transform: uppercase; letter-spacing:.06em; font-weight: 600; }
    .tva-stat__value { font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 6px; line-height: 1.2; }
    .tva-stat__sub   { font-size: 11px; color:#94a3b8; font-weight: 500; margin-top: 4px; }

    /* ── 6/6 row (force side-by-side at md+) ───────────────────────── */
    .tva-cols { display:grid; gap:24px; grid-template-columns: 1fr; }
    @media (min-width: 900px) { .tva-cols { grid-template-columns: 1fr 1fr; } }

    /* ── Detail rows ───────────────────────────────────────────────── */
    .tva-meta-row { display:flex; align-items:center; padding:12px 0; border-bottom:1px dashed #e2e8f0; }
    .tva-meta-row:first-child { padding-top: 0; }
    .tva-meta-row:last-child  { border-bottom: none; padding-bottom: 0; }
    .tva-meta-icon {
        width: 36px; height: 36px; border-radius: 8px;
        background: #ecfdf5; color: #059669;
        display:flex; align-items:center; justify-content:center;
        margin-right: 14px; flex-shrink:0;
    }
    .tva-meta-icon--mail   { background:#eff6ff; color:#2563eb; }
    .tva-meta-icon--phone  { background:#fef3c7; color:#b45309; }
    .tva-meta-icon--intent { background:#ede9fe; color:#7c3aed; }
    .tva-meta-icon--budget { background:#fce7f3; color:#be185d; }
    .tva-meta-icon--time   { background:#e0e7ff; color:#4338ca; }

    .tva-meta-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .tva-meta-value { font-size:14px; color:#0f172a; font-weight:500; margin-top:2px; }
    .tva-meta-value--empty { color:#cbd5e1; font-style:italic; font-weight:400; }

    /* ── Status funnel ─────────────────────────────────────────────── */
    .tva-funnel { display:flex; align-items:center; gap:6px; margin:8px 0 0; }
    .tva-funnel__step {
        flex:1; text-align:center;
        padding:10px 4px; border-radius:8px;
        background:#f1f5f9; color:#94a3b8;
        font-size:11px; font-weight:600; text-transform:uppercase;
        letter-spacing:.04em; border: 1px solid #e2e8f0;
    }
    .tva-funnel__step--active {
        background: linear-gradient(135deg,#059669,#0891b2);
        color:#fff; border-color: transparent;
        box-shadow: 0 4px 12px -2px rgba(13,148,136,.35);
    }
    .tva-funnel__step--past { background:#d1fae5; color:#065f46; border-color:#a7f3d0; }

    /* ── Custom fields ─────────────────────────────────────────────── */
    .tva-kv {
        display:flex; justify-content:space-between;
        padding:10px 14px; border-radius:8px;
        background:#f8fafc; margin-bottom:6px;
        font-size:13px;
    }
    .tva-kv__key { color:#64748b; font-weight:500; }
    .tva-kv__val { color:#0f172a; font-weight:600; }

    /* ── Confidence bar ────────────────────────────────────────────── */
    .tva-conf-bar { background:#f1f5f9; height:8px; border-radius:4px; overflow:hidden; }
    .tva-conf-fill { height:100%; border-radius:4px; background: linear-gradient(90deg,#10b981,#0891b2); }
    .tva-conf-fill--low { background: linear-gradient(90deg,#f59e0b,#ef4444); }

    .tva-form-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; font-weight:600; margin-bottom:6px; display:block; }

    /* ── Source-conversation banner ────────────────────────────────── */
    .tva-source-banner {
        display:flex; align-items:center; gap:14px;
        padding:14px 18px;
        border-radius:12px;
        background: linear-gradient(135deg,#eff6ff 0%,#f5f3ff 100%);
        border: 1px solid #c7d2fe;
        margin-top: 16px;
        transition: transform .15s, box-shadow .15s;
    }
    .tva-source-banner:hover { transform: translateY(-1px); box-shadow: 0 8px 20px -10px rgba(99,102,241,.4); }

    /* ── Status radio cards (manage form) ──────────────────────────── */
    .tva-status-card {
        display:flex; align-items:center; gap:12px;
        padding:12px 14px;
        border-radius:10px;
        background:#f8fafc;
        border: 1px solid #e2e8f0;
        cursor: pointer;
        transition: all .15s;
        margin-bottom: 8px;
    }
    .tva-status-card:hover { background:#f1f5f9; }
    .tva-status-card input { margin: 0; flex-shrink: 0; }
    .tva-status-card__icon {
        width: 30px; height: 30px; border-radius:8px;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0;
    }
    .tva-status-card__title { font-size: 13px; font-weight: 600; color:#0f172a; }
    .tva-status-card__hint  { font-size: 11px; color:#64748b; margin-top: 1px; }

    .tva-status-card--blue   .tva-status-card__icon { background:#dbeafe; color:#1d4ed8; }
    .tva-status-card--amber  .tva-status-card__icon { background:#fef3c7; color:#b45309; }
    .tva-status-card--purple .tva-status-card__icon { background:#ede9fe; color:#7c3aed; }
    .tva-status-card--green  .tva-status-card__icon { background:#d1fae5; color:#047857; }
    .tva-status-card--red    .tva-status-card__icon { background:#fee2e2; color:#b91c1c; }

    .tva-status-card.is-selected { border-width: 2px; }
    .tva-status-card--blue.is-selected   { background:#eff6ff; border-color:#60a5fa; }
    .tva-status-card--amber.is-selected  { background:#fffbeb; border-color:#fbbf24; }
    .tva-status-card--purple.is-selected { background:#faf5ff; border-color:#a78bfa; }
    .tva-status-card--green.is-selected  { background:#ecfdf5; border-color:#34d399; }
    .tva-status-card--red.is-selected    { background:#fef2f2; border-color:#f87171; }

    /* ── DARK MODE (.dark on <html>) ───────────────────────────────── */
    html.dark .tva-stat        { background:#1e293b; border-color:#334155; }
    html.dark .tva-stat__label { color:#94a3b8; }
    html.dark .tva-stat__value { color:#f1f5f9; }
    html.dark .tva-stat__sub   { color:#94a3b8; }

    html.dark .tva-meta-row    { border-bottom-color: #334155; }
    html.dark .tva-meta-label  { color:#94a3b8; }
    html.dark .tva-meta-value  { color:#f1f5f9; }
    html.dark .tva-meta-value--empty { color:#64748b; }

    html.dark .tva-funnel__step       { background:#1e293b; border-color:#334155; color:#64748b; }
    html.dark .tva-funnel__step--past { background:#064e3b; border-color:#10b981; color:#a7f3d0; }

    html.dark .tva-kv      { background:#1e293b; }
    html.dark .tva-kv__key { color:#94a3b8; }
    html.dark .tva-kv__val { color:#f1f5f9; }

    html.dark .tva-conf-bar { background:#1e293b; }

    html.dark .tva-form-label { color:#94a3b8; }

    html.dark .tva-source-banner {
        background: linear-gradient(135deg,#1e293b 0%,#1e1b4b 100%);
        border-color: #4338ca;
    }
    html.dark .tva-source-banner .tva-meta-label { color:#a5b4fc; }
    html.dark .tva-source-banner .tva-meta-value { color:#f1f5f9; }

    html.dark .tva-status-card { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    html.dark .tva-status-card:hover { background:#283449; }
    html.dark .tva-status-card__title { color:#f1f5f9; }
    html.dark .tva-status-card__hint  { color:#94a3b8; }
    html.dark .tva-status-card--blue.is-selected   { background:#172554; border-color:#3b82f6; }
    html.dark .tva-status-card--amber.is-selected  { background:#451a03; border-color:#f59e0b; }
    html.dark .tva-status-card--purple.is-selected { background:#2e1065; border-color:#a855f7; }
    html.dark .tva-status-card--green.is-selected  { background:#052e16; border-color:#10b981; }
    html.dark .tva-status-card--red.is-selected    { background:#450a0a; border-color:#ef4444; }
</style>

@php
    $f = $lead->fields ?? [];

    $custName = $f['name'] ?? 'Unnamed lead';
    $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $custName) ?: 'L', 0, 2));

    $statusMap = [
        'new'           => ['#3b82f6', 'New'],
        'contacted'     => ['#f59e0b', 'Contacted'],
        'qualified'     => ['#8b5cf6', 'Qualified'],
        'converted'     => ['#10b981', 'Converted'],
        'disqualified'  => ['#ef4444', 'Disqualified'],
    ];
    [$statusColor, $statusLabel] = $statusMap[$lead->status] ?? ['#64748b', ucfirst($lead->status ?? 'unknown')];

    $confPct = (int) round(($lead->confidence ?? 0) * 100);
    $confLow = $confPct < 50;

    // Funnel ordering. Order matters for past/active/future colouring.
    $funnel = ['new', 'contacted', 'qualified', 'converted'];
    $currentIdx = array_search($lead->status, $funnel);
    if ($currentIdx === false) $currentIdx = -1;
    $isDisqualified = $lead->status === 'disqualified';

    $createdAt = $lead->created_at ? date('M d, Y · H:i', $lead->created_at) : null;
@endphp

<div class="content">
    {{-- ── Breadcrumb ───────────────────────────────────────────────── --}}
    <div class="intro-y flex items-center mt-6 mb-4">
        <h2 class="text-lg font-medium mr-auto">
            <a href="{{ route('leads.index', ['client' => $client->slug]) }}?project_id={{ hashid($projectId) }}"
               class="text-slate-400 hover:text-primary">
                <i data-lucide="chevron-left" class="w-4 h-4 inline -mt-1"></i> Leads
            </a>
            <span class="text-slate-400 mx-1">/</span>
            <span>Lead #{{ $lead->id }}</span>
        </h2>
    </div>

    {{-- ── Hero card ────────────────────────────────────────────────── --}}
    <div class="tva-lead-hero mb-6">
        <div class="tva-hero-grid">
            <div class="tva-avatar">{{ $initials }}</div>
            <div>
                <div class="tva-hero-label">Lead</div>
                <div class="tva-hero-name">{{ $custName }}</div>
                @if (!empty($f['email']) || !empty($f['phone']))
                    <div class="tva-hero-meta">
                        @if (!empty($f['email']))
                            <span><i data-lucide="mail" class="w-3 h-3 inline -mt-0.5"></i> {{ $f['email'] }}</span>
                        @endif
                        @if (!empty($f['phone']))
                            <span><i data-lucide="phone" class="w-3 h-3 inline -mt-0.5"></i> {{ $f['phone'] }}</span>
                        @endif
                    </div>
                @endif
            </div>
            <div class="tva-hero-side text-right">
                <span class="tva-pill">
                    <span class="tva-pill-dot" style="background:{{ $statusColor }}"></span>
                    {{ $statusLabel }}
                </span>
                <div class="text-xs mt-2 opacity-80">{{ $project->name }}</div>
            </div>
        </div>
    </div>

    {{-- ── Status funnel ────────────────────────────────────────────── --}}
    <div class="intro-y box p-5 mb-6">
        <div class="flex items-center justify-between mb-2">
            <h3 class="font-medium text-base flex items-center">
                <i data-lucide="trending-up" class="w-4 h-4 mr-2"></i>
                Lead funnel
            </h3>
            @if ($isDisqualified)
                <span class="tva-pill" style="background:#fee2e2; color:#b91c1c;">
                    <span class="tva-pill-dot" style="background:#dc2626"></span> Disqualified
                </span>
            @endif
        </div>
        <div class="tva-funnel">
            @foreach ($funnel as $idx => $step)
                @php
                    $cls = 'tva-funnel__step';
                    if (!$isDisqualified) {
                        if ($idx === $currentIdx) $cls .= ' tva-funnel__step--active';
                        elseif ($idx < $currentIdx) $cls .= ' tva-funnel__step--past';
                    }
                @endphp
                <div class="{{ $cls }}">{{ ucfirst($step) }}</div>
            @endforeach
        </div>
    </div>

    {{-- ── Stat row ─────────────────────────────────────────────────── --}}
    <div class="tva-stats">
        <div class="tva-stat">
            <div class="tva-stat__label">Confidence</div>
            <div class="tva-stat__value">{{ $confPct }}%</div>
            <div class="tva-conf-bar mt-2">
                <div class="tva-conf-fill {{ $confLow ? 'tva-conf-fill--low' : '' }}" style="width: {{ $confPct }}%"></div>
            </div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Status</div>
            <div class="tva-stat__value">{{ $statusLabel }}</div>
            <div class="tva-stat__sub">since lead creation</div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Source</div>
            <div class="tva-stat__value">
                @if ($lead->session_id)
                    Session #{{ $lead->session_id }}
                @else
                    Manual
                @endif
            </div>
            <div class="tva-stat__sub">{{ $lead->session?->channel ?? 'web' }}</div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Created</div>
            <div class="tva-stat__value">{{ $createdAt ? date('M d', $lead->created_at) : '—' }}</div>
            <div class="tva-stat__sub">{{ $createdAt ? date('H:i', $lead->created_at) : '' }}</div>
        </div>
    </div>

    {{-- ── Two columns (6/6 side-by-side at 900px+) ────────────────── --}}
    <div class="tva-cols">
        {{-- LEFT: Extracted fields --}}
        <div>
            <div class="intro-y box p-5 mb-4">
                <h3 class="font-medium text-base mb-4 flex items-center">
                    <i data-lucide="clipboard-list" class="w-4 h-4 mr-2"></i>
                    Extracted fields
                </h3>

                <div class="tva-meta-row">
                    <div class="tva-meta-icon"><i data-lucide="user" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Name</div>
                        <div class="tva-meta-value {{ empty($f['name']) ? 'tva-meta-value--empty' : '' }}">
                            {{ $f['name'] ?? 'Not provided' }}
                        </div>
                    </div>
                </div>

                <div class="tva-meta-row">
                    <div class="tva-meta-icon tva-meta-icon--mail"><i data-lucide="mail" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Email</div>
                        <div class="tva-meta-value {{ empty($f['email']) ? 'tva-meta-value--empty' : '' }}">
                            @if (!empty($f['email']))
                                <a href="mailto:{{ $f['email'] }}" class="text-primary">{{ $f['email'] }}</a>
                            @else
                                Not provided
                            @endif
                        </div>
                    </div>
                </div>

                <div class="tva-meta-row">
                    <div class="tva-meta-icon tva-meta-icon--phone"><i data-lucide="phone" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Phone</div>
                        <div class="tva-meta-value {{ empty($f['phone']) ? 'tva-meta-value--empty' : '' }}">
                            @if (!empty($f['phone']))
                                <a href="tel:{{ $f['phone'] }}" class="text-primary">{{ $f['phone'] }}</a>
                            @else
                                Not provided
                            @endif
                        </div>
                    </div>
                </div>

                <div class="tva-meta-row">
                    <div class="tva-meta-icon tva-meta-icon--intent"><i data-lucide="target" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Intent</div>
                        <div class="tva-meta-value {{ empty($f['intent']) ? 'tva-meta-value--empty' : '' }}">
                            {{ $f['intent'] ?? 'Not detected' }}
                        </div>
                    </div>
                </div>

                <div class="tva-meta-row">
                    <div class="tva-meta-icon tva-meta-icon--budget"><i data-lucide="dollar-sign" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Budget</div>
                        <div class="tva-meta-value {{ empty($f['budget']) ? 'tva-meta-value--empty' : '' }}">
                            {{ $f['budget'] ?? 'Not provided' }}
                        </div>
                    </div>
                </div>

                <div class="tva-meta-row">
                    <div class="tva-meta-icon tva-meta-icon--time"><i data-lucide="calendar" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Timeline</div>
                        <div class="tva-meta-value {{ empty($f['timeline']) ? 'tva-meta-value--empty' : '' }}">
                            {{ $f['timeline'] ?? 'Not provided' }}
                        </div>
                    </div>
                </div>
            </div>

            @if (!empty($f['custom']) && is_array($f['custom']))
                <div class="intro-y box p-5">
                    <h3 class="font-medium text-base mb-3 flex items-center">
                        <i data-lucide="layers" class="w-4 h-4 mr-2"></i>
                        Custom fields
                    </h3>
                    @foreach ($f['custom'] as $k => $v)
                        <div class="tva-kv">
                            <span class="tva-kv__key">{{ $k }}</span>
                            <span class="tva-kv__val">{{ is_scalar($v) ? $v : json_encode($v) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- The person, not just this one opportunity. A lead is a single
                 capture; the contact is every conversation they have ever had
                 with the business, on any channel. --}}
            @if ($lead->contact_id)
                <a href="{{ route('contacts.show', ['client' => $client->slug, 'id' => $lead->contact_id, 'project_id' => hashid($projectId)]) }}"
                   class="intro-y tva-source-banner">
                    <div class="tva-meta-icon" style="background:#ede9fe; color:#6d28d9;">
                        <i data-lucide="contact" class="w-4 h-4"></i>
                    </div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Contact</div>
                        <div class="tva-meta-value">Full history across every channel</div>
                    </div>
                    <i data-lucide="external-link" class="w-5 h-5"></i>
                </a>
            @endif

            @if ($lead->session_id)
                <a href="{{ route('sessions.show', ['client' => $client->slug, 'id' => $lead->session_id]) }}?project_id={{ hashid($projectId) }}"
                   class="intro-y tva-source-banner">
                    <div class="tva-meta-icon" style="background:#dbeafe; color:#1d4ed8;">
                        <i data-lucide="message-circle" class="w-4 h-4"></i>
                    </div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Source conversation</div>
                        <div class="tva-meta-value">Session #{{ $lead->session_id }}</div>
                    </div>
                    <i data-lucide="external-link" class="w-5 h-5"></i>
                </a>
            @endif
        </div>

        {{-- RIGHT: Status management --}}
        <div>
            @if (session('success'))
                <div class="alert alert-success-soft show mb-4 flex items-center">
                    <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
                    {{ session('success') }}
                </div>
            @endif

            <div class="intro-y box p-5">
                <h3 class="font-medium text-base mb-4 flex items-center">
                    <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
                    Manage
                </h3>

                <form method="POST" action="{{ route('leads.update', ['client' => $client->slug, 'id' => $lead->id]) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="project_id" value="{{ $projectId }}">

                    <div class="mb-4">
                        <label class="tva-form-label">Status</label>
                        @foreach ([
                            'new'          => ['blue',   'circle',         'Just captured'],
                            'contacted'    => ['amber',  'phone-call',     'Reached out'],
                            'qualified'    => ['purple', 'check-circle-2', 'Good fit'],
                            'converted'    => ['green',  'trophy',         'Won — became customer'],
                            'disqualified' => ['red',    'x-circle',       'Not a fit'],
                        ] as $st => $meta)
                            @php
                                [$colour, $icon, $hint] = $meta;
                                $isCurrent = $lead->status === $st;
                            @endphp
                            <label class="tva-status-card tva-status-card--{{ $colour }} {{ $isCurrent ? 'is-selected' : '' }}">
                                <input type="radio" name="status" value="{{ $st }}" @checked($isCurrent)>
                                <div class="tva-status-card__icon"><i data-lucide="{{ $icon }}" class="w-4 h-4"></i></div>
                                <div class="flex-1">
                                    <div class="tva-status-card__title">{{ ucfirst($st) }}</div>
                                    <div class="tva-status-card__hint">{{ $hint }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <div class="mb-4">
                        <label class="tva-form-label">Notes</label>
                        <textarea name="notes" rows="6"
                                  class="form-control w-full"
                                  placeholder="Internal notes about this lead — next steps, conversation context, anything worth remembering…"
                        >{{ old('notes', $lead->notes) }}</textarea>
                    </div>

                    <button class="btn btn-primary w-full shadow-md">
                        <i data-lucide="save" class="w-4 h-4 mr-2 inline"></i> Save changes
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
</script>
@endsection
