@extends('layouts.master')

@section('content')
<style>
    /* ── Hero ──────────────────────────────────────────────────────── */
    .tva-session-hero {
        background: var(--tva-gradient);
        color: #fff;
        border-radius: 14px;
        padding: 24px 28px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.4);
    }
    .tva-hero-grid { display: grid; grid-template-columns: auto 1fr auto; gap: 22px; align-items: center; }
    @media (max-width: 640px) { .tva-hero-grid { grid-template-columns: auto 1fr; } .tva-hero-side { grid-column: 1/-1; text-align: left; } }

    .tva-avatar {
        width: 64px; height: 64px; border-radius: 50%;
        background: rgba(255,255,255,.18); color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 22px;
        border: 2px solid rgba(255,255,255,.35);
    }
    .tva-hero-label { font-size: 11px; text-transform: uppercase; letter-spacing: .08em; opacity: .85; font-weight: 600; }
    .tva-hero-name  { font-size: 22px; font-weight: 700; margin-top: 4px; line-height: 1.2; }
    .tva-hero-meta  { font-size: 13px; opacity: .92; margin-top: 6px; display:flex; flex-wrap:wrap; gap: 14px; }

    .tva-pill {
        display: inline-flex; align-items: center;
        padding: 5px 14px; border-radius: 999px;
        font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .06em;
        background: rgba(255,255,255,.22);
        color: #fff; border: 1px solid rgba(255,255,255,.2);
    }
    .tva-pill-dot { width:7px; height:7px; border-radius:50%; margin-right:7px; }

    /* ── Stat cards ────────────────────────────────────────────────── */
    .tva-stats { display: grid; gap: 14px; grid-template-columns: repeat(2, 1fr); margin-bottom: 24px; }
    @media (min-width: 768px) { .tva-stats { grid-template-columns: repeat(4, 1fr); } }

    .tva-stat {
        background: #fff; border-radius: 12px; padding: 16px 18px;
        border: 1px solid #e2e8f0;
        min-height: 84px;
    }
    .tva-stat__label { font-size: 11px; color:#64748b; text-transform: uppercase; letter-spacing:.06em; font-weight:600; }
    .tva-stat__value { font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 6px; line-height: 1.2; }
    .tva-stat__sub   { font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 4px; }

    /* ── Details rows ──────────────────────────────────────────────── */
    .tva-meta-row {
        display:flex; align-items:center; padding: 12px 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    .tva-meta-row:first-child { padding-top: 0; }
    .tva-meta-row:last-child  { border-bottom: none; padding-bottom: 0; }
    .tva-meta-icon {
        width: 36px; height: 36px; border-radius: 8px;
        background: #eef2ff; color: #6366f1;
        display:flex; align-items:center; justify-content:center;
        margin-right: 14px; flex-shrink:0;
    }
    .tva-meta-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; font-weight:600; }
    .tva-meta-value { font-size:14px; color:#0f172a; font-weight:500; margin-top: 2px; }

    /* ── Conversation thread ───────────────────────────────────────── */
    .tva-conv-head {
        display:flex; align-items:center; justify-content:space-between;
        gap: 16px; margin-bottom: 18px;
        padding-bottom: 12px; border-bottom: 1px solid #e2e8f0;
    }
    .tva-conv-title { font-size: 15px; font-weight: 600; color:#0f172a; display:flex; align-items:center; gap: 8px; }
    .tva-conv-count {
        font-size: 11px; color:#64748b; background:#f1f5f9;
        padding: 4px 10px; border-radius: 999px; font-weight: 600;
    }

    .tva-msg { display:flex; gap:12px; margin-bottom: 18px; }
    .tva-msg__avatar {
        width: 38px; height: 38px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 13px; flex-shrink:0;
    }
    .tva-msg--user  .tva-msg__avatar { background:#dbeafe; color:#1d4ed8; }
    .tva-msg--bot   .tva-msg__avatar { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; }
    .tva-msg__bubble {
        flex:1; min-width: 0;
        background:#f8fafc; border:1px solid #e2e8f0;
        border-radius: 14px; padding: 12px 16px;
    }
    .tva-msg--user .tva-msg__bubble  { background:#eff6ff; border-color:#bfdbfe; }
    .tva-msg--bot  .tva-msg__bubble  { background:#fafaff; border-color:#e0e7ff; }
    .tva-json-block { margin-top:10px; border:1px solid #e2e8f0; border-radius:8px; background:#fff; overflow:hidden; }
    .tva-json-block > summary { cursor:pointer; padding:7px 11px; font-size:12px; font-weight:600; color:#4338ca; background:#f8fafc; list-style:none; user-select:none; }
    .tva-json-block > summary::-webkit-details-marker { display:none; }
    .tva-json-block[open] > summary { border-bottom:1px solid #e2e8f0; }
    .tva-mini-table { width:100%; border-collapse:collapse; font-size:11.5px; }
    .tva-mini-table th, .tva-mini-table td { border:1px solid #eef2f7; padding:5px 9px; text-align:left; white-space:nowrap; }
    .tva-mini-table th { background:#f1f5f9; color:#334155; font-weight:600; }
    .tva-json-pre { margin:0; padding:11px; font-size:11px; line-height:1.5; color:#0f172a; background:#0b11200a; overflow-x:auto; max-height:340px; }
    .tva-json-note { padding:6px 11px; font-size:11px; color:#94a3b8; }
    html.dark .tva-json-block { background:#0f172a; border-color:#1e293b; }
    html.dark .tva-json-block > summary { background:#111827; color:#a5b4fc; }
    html.dark .tva-mini-table th { background:#1e293b; color:#cbd5e1; }
    html.dark .tva-mini-table th, html.dark .tva-mini-table td { border-color:#1e293b; }
    html.dark .tva-json-pre { color:#e2e8f0; background:#0b1120; }
    .tva-msg__head {
        display:flex; align-items:center; flex-wrap: wrap; gap:10px;
        font-size:12px; color:#64748b; margin-bottom:8px;
    }
    .tva-msg__role { font-weight:700; color:#1e293b; font-size: 13px; }
    .tva-msg__time { color:#64748b; font-weight: 500; }
    .tva-msg__body { white-space: pre-wrap; color:#1e293b; line-height:1.6; font-size: 14px; }
    .tva-msg__chip {
        display:inline-flex; align-items:center;
        font-size:10px; padding:3px 9px; border-radius:999px;
        background:#e2e8f0; color:#475569; font-weight:600;
        letter-spacing: .02em;
    }
    .tva-msg__chip--danger { background:#fee2e2; color:#b91c1c; }
    .tva-msg__chip--ok     { background:#dcfce7; color:#15803d; }

    .tva-empty {
        text-align: center; padding: 48px 16px;
        color:#94a3b8; font-size: 14px;
    }
    .tva-empty i { width: 40px; height: 40px; margin-bottom: 10px; opacity: .5; }

    /* ── Lead card (left column) ──────────────────────────────────── */
    .tva-lead-card {
        background: linear-gradient(135deg,#fefce8 0%,#fef3c7 100%);
        border: 1px solid #fde68a;
        border-radius: 12px; padding: 18px;
    }
    .tva-lead-card__title { font-weight:700; color:#92400e; font-size:12px; text-transform:uppercase; letter-spacing:.06em; }
    .tva-lead-card .text-slate-700 { color: #78350f; }
    .tva-lead-card a.text-primary  { color: #b45309; font-weight: 600; }

    /* ── DARK MODE (.dark on <html>) ───────────────────────────────── */
    html.dark .tva-stat        { background:#1e293b; border-color:#334155; }
    html.dark .tva-stat__label { color:#94a3b8; }
    html.dark .tva-stat__value { color:#f1f5f9; }
    html.dark .tva-stat__sub   { color:#94a3b8; }

    html.dark .tva-meta-row    { border-bottom-color: #334155; }
    html.dark .tva-meta-icon   { background:#312e81; color:#a5b4fc; }
    html.dark .tva-meta-label  { color:#94a3b8; }
    html.dark .tva-meta-value  { color:#f1f5f9; }

    html.dark .tva-conv-head   { border-bottom-color:#334155; }
    html.dark .tva-conv-title  { color:#f1f5f9; }
    html.dark .tva-conv-count  { background:#334155; color:#cbd5e1; }

    html.dark .tva-msg--user .tva-msg__bubble { background:#172554; border-color:#1d4ed8; }
    html.dark .tva-msg--bot  .tva-msg__bubble { background:#1e1b4b; border-color:#4338ca; }
    html.dark .tva-msg__role { color:#f8fafc; }
    html.dark .tva-msg__time { color:#cbd5e1; }
    html.dark .tva-msg__body { color:#e2e8f0; }
    html.dark .tva-msg__chip { background:#334155; color:#e2e8f0; }
    html.dark .tva-msg__chip--danger { background:#7f1d1d; color:#fecaca; }
    html.dark .tva-msg__chip--ok     { background:#14532d; color:#bbf7d0; }

    html.dark .tva-empty { color:#64748b; }

    html.dark .tva-lead-card { background: linear-gradient(135deg,#422006 0%,#451a03 100%); border-color:#92400e; }
    html.dark .tva-lead-card__title { color:#fcd34d; }
    html.dark .tva-lead-card .text-slate-700 { color:#fde68a; }
    html.dark .tva-lead-card a.text-primary  { color:#fbbf24; }
</style>

<div class="content">
    @php
        $statusMap = [
            'active'    => ['#16a34a', 'Active'],
            'ended'     => ['#64748b', 'Ended'],
            'abandoned' => ['#dc2626', 'Abandoned'],
        ];
        [$statusColor, $statusLabel] = $statusMap[$session->status] ?? ['#64748b', ucfirst($session->status ?? 'unknown')];

        $custName = $session->customer_name ?: 'Anonymous';
        $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $custName) ?: 'A', 0, 2));

        $durationSecs = ($session->last_activity_at && $session->started_at)
            ? max(0, $session->last_activity_at - $session->started_at) : 0;
        $durFmt = $durationSecs >= 60
            ? intdiv($durationSecs, 60) . 'm ' . ($durationSecs % 60) . 's'
            : $durationSecs . 's';

        $userCount = $messages->where('role', 'user')->count();
        $botCount  = $messages->where('role', 'assistant')->count();
    @endphp

    {{-- ── Top bar ──────────────────────────────────────────────────── --}}
    <div class="intro-y flex items-center mt-6 mb-4">
        <h2 class="text-lg font-medium mr-auto">
            <a href="{{ route('sessions.index', ['client' => $client->slug]) }}?project_id={{ hashid($projectId) }}"
               class="text-slate-400 hover:text-primary">
                <i data-lucide="chevron-left" class="w-4 h-4 inline -mt-1"></i> Conversations
            </a>
            <span class="text-slate-400 mx-1">/</span>
            <span>Session #{{ $session->id }}</span>
        </h2>
    </div>

    @if ($session->channel === 'internal')
        <div class="intro-y" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;border-radius:10px;background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;font-size:13px;">
            <i data-lucide="bot" class="w-4 h-4"></i>
            <span><strong>Internal “Ask AI” chat.</strong> This is a team member ({{ $session->customer_name ?: 'staff' }}@if(!empty($session->metadata['user_email'])) · {{ $session->metadata['user_email'] }}@endif) talking to the AI assistant — not a customer conversation.</span>
        </div>
    @endif

    {{-- ── Hero card ────────────────────────────────────────────────── --}}
    <div class="tva-session-hero mb-6">
        <div class="tva-hero-grid">
            <div class="tva-avatar">{{ $initials }}</div>
            <div>
                <div class="tva-hero-label">Customer</div>
                <div class="tva-hero-name">{{ $custName }}</div>
                @if ($session->customer_email || $session->customer_phone)
                    <div class="tva-hero-meta">
                        @if ($session->customer_email)
                            <span><i data-lucide="mail" class="w-3 h-3 inline -mt-0.5"></i> {{ $session->customer_email }}</span>
                        @endif
                        @if ($session->customer_phone)
                            <span><i data-lucide="phone" class="w-3 h-3 inline -mt-0.5"></i> {{ $session->customer_phone }}</span>
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

    {{-- ── Stat row ─────────────────────────────────────────────────── --}}
    <div class="tva-stats">
        <div class="tva-stat">
            <div class="tva-stat__label">Channel</div>
            <div class="tva-stat__value">{{ ucfirst($session->channel) }}</div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Duration</div>
            <div class="tva-stat__value">{{ $durFmt }}</div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Messages</div>
            <div class="tva-stat__value">{{ $messages->count() }}</div>
            <div class="tva-stat__sub">{{ $userCount }} user · {{ $botCount }} assistant</div>
        </div>
        <div class="tva-stat">
            <div class="tva-stat__label">Lead</div>
            @if ($lead)
                <div class="tva-stat__value">{{ number_format(($lead->confidence ?? 0) * 100, 0) }}%</div>
                <div class="tva-stat__sub">{{ ucfirst($lead->status) }}</div>
            @else
                <div class="tva-stat__value">—</div>
                <div class="tva-stat__sub">Not extracted</div>
            @endif
        </div>
    </div>

    {{-- ── Two columns ──────────────────────────────────────────────── --}}
    <div class="grid grid-cols-12 gap-6">
        {{-- Left: meta + lead --}}
        <div class="col-span-12 lg:col-span-4">
            <div class="intro-y box p-5 mb-4">
                <h3 class="font-medium text-base mb-2 flex items-center">
                    <i data-lucide="info" class="w-4 h-4 mr-2"></i> Details
                </h3>
                <div class="tva-meta-row">
                    <div class="tva-meta-icon"><i data-lucide="folder" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Project</div>
                        <div class="tva-meta-value">{{ $project->name }}</div>
                    </div>
                </div>
                <div class="tva-meta-row">
                    <div class="tva-meta-icon"><i data-lucide="radio" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Channel</div>
                        <div class="tva-meta-value">{{ ucfirst($session->channel) }}</div>
                    </div>
                </div>
                @if ($session->voice)
                <div class="tva-meta-row">
                    <div class="tva-meta-icon"><i data-lucide="mic" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Voice</div>
                        <div class="tva-meta-value">{{ $session->voice->name }}</div>
                    </div>
                </div>
                @endif
                <div class="tva-meta-row">
                    <div class="tva-meta-icon"><i data-lucide="play-circle" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Started</div>
                        <div class="tva-meta-value">{{ $session->started_at ? date('M d, Y · H:i', $session->started_at) : '—' }}</div>
                    </div>
                </div>
                <div class="tva-meta-row">
                    <div class="tva-meta-icon"><i data-lucide="activity" class="w-4 h-4"></i></div>
                    <div class="flex-1">
                        <div class="tva-meta-label">Last activity</div>
                        <div class="tva-meta-value">{{ $session->last_activity_at ? date('M d, Y · H:i', $session->last_activity_at) : '—' }}</div>
                    </div>
                </div>
            </div>

            @if ($lead)
                <div class="intro-y tva-lead-card">
                    <div class="flex items-center justify-between mb-3">
                        <div class="tva-lead-card__title flex items-center">
                            <i data-lucide="user-check" class="w-4 h-4 mr-2"></i> Lead extracted
                        </div>
                        <a href="{{ route('leads.show', ['client' => $client->slug, 'id' => $lead->id]) }}?project_id={{ hashid($projectId) }}"
                           class="text-xs text-primary font-semibold">Open →</a>
                    </div>
                    @php $lf = $lead->fields ?? []; @endphp
                    <div class="text-sm text-slate-700 space-y-1">
                        @if (!empty($lf['name']))   <div><b>Name:</b> {{ $lf['name'] }}</div> @endif
                        @if (!empty($lf['email']))  <div><b>Email:</b> {{ $lf['email'] }}</div> @endif
                        @if (!empty($lf['phone']))  <div><b>Phone:</b> {{ $lf['phone'] }}</div> @endif
                        @if (!empty($lf['intent'])) <div><b>Intent:</b> {{ $lf['intent'] }}</div> @endif
                    </div>
                    <div class="text-xs text-slate-500 mt-3">
                        Confidence {{ number_format(($lead->confidence ?? 0) * 100, 0) }}% · Status {{ $lead->status }}
                    </div>
                </div>
            @else
                <div class="intro-y box tva-empty">
                    <i data-lucide="user-x"></i>
                    <div style="font-weight:600;">No lead extracted yet</div>
                    <div class="text-xs mt-1" style="opacity:.7;">
                        A lead appears here once the bot collects enough info.
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: conversation thread --}}
        <div class="col-span-12 lg:col-span-8">
            <div class="intro-y box p-5">
                <div class="tva-conv-head">
                    <div class="tva-conv-title">
                        <i data-lucide="message-circle" class="w-4 h-4"></i>
                        Conversation
                    </div>
                    <span class="tva-conv-count">{{ $messages->count() }} messages</span>
                </div>

                @forelse ($messages as $msg)
                    @php
                        $isUser = $msg->role === 'user';
                        $msgClass = $isUser ? 'tva-msg--user' : 'tva-msg--bot';
                        $when = $msg->created_at ? date('H:i:s', $msg->created_at) : '';
                        $whenFull = $msg->created_at ? date('M d, Y · H:i:s', $msg->created_at) : '';
                        $avatarLabel = $isUser
                            ? strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $custName) ?: 'U', 0, 1))
                            : 'AI';
                    @endphp
                    <div class="tva-msg {{ $msgClass }}">
                        <div class="tva-msg__avatar">{{ $avatarLabel }}</div>
                        <div class="tva-msg__bubble">
                            <div class="tva-msg__head">
                                <span class="tva-msg__role">{{ $isUser ? $custName : 'Assistant' }}</span>
                                @if ($when)
                                    <span class="tva-msg__time" title="{{ $whenFull }}">{{ $when }}</span>
                                @endif
                                @if ($msg->latency_ms)
                                    <span class="tva-msg__chip">{{ $msg->latency_ms }}ms</span>
                                @endif
                                @if ($msg->model_used)
                                    <span class="tva-msg__chip">{{ $msg->model_used }}</span>
                                @endif
                                @if (!empty($msg->metadata['cancelled']))
                                    <span class="tva-msg__chip tva-msg__chip--danger">stopped</span>
                                @endif
                                @if ($msg->audio_url)
                                    <span class="tva-msg__chip tva-msg__chip--ok"><i data-lucide="volume-2" class="w-3 h-3 mr-1 inline"></i> voice</span>
                                @endif
                            </div>
                            @if ($msg->content)
                                <div class="tva-msg__body">{{ $msg->content }}</div>
                            @endif

                            {{-- Structured response payload (internal Ask AI turns store
                                 the tables + sources the bot returned, as JSON). --}}
                            @php
                                $mTables  = $msg->metadata['tables']  ?? null;
                                $mSources = $msg->metadata['sources'] ?? null;
                            @endphp
                            @if (!empty($mTables) && is_array($mTables))
                                @foreach ($mTables as $tbl)
                                    @php $cols = $tbl['columns'] ?? []; $rows = $tbl['rows'] ?? []; @endphp
                                    <details class="tva-json-block">
                                        <summary><i data-lucide="table" class="w-3 h-3 inline mr-1"></i> {{ $tbl['title'] ?? 'Data' }} · {{ count($rows) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }}</summary>
                                        @if ($cols)
                                            <div style="overflow-x:auto;">
                                                <table class="tva-mini-table">
                                                    <thead><tr>@foreach ($cols as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                                                    <tbody>
                                                    @foreach (array_slice($rows, 0, 50) as $r)
                                                        <tr>@foreach ($cols as $c)<td>{{ is_array($r[$c] ?? null) ? json_encode($r[$c]) : ($r[$c] ?? '') }}</td>@endforeach</tr>
                                                    @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                            @if (count($rows) > 50)<div class="tva-json-note">Showing first 50 of {{ count($rows) }} rows.</div>@endif
                                        @endif
                                    </details>
                                @endforeach
                            @endif
                            @if (!empty($mTables) || !empty($mSources))
                                <details class="tva-json-block">
                                    <summary><i data-lucide="code" class="w-3 h-3 inline mr-1"></i> Response JSON</summary>
                                    <pre class="tva-json-pre">{{ json_encode(['tables' => $mTables ?: [], 'sources' => $mSources ?: []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            @endif
                            @if ($msg->audio_url)
                                <audio controls preload="none" class="mt-3 w-full max-w-md rounded">
                                    <source src="{{ $msg->audio_url }}" type="audio/wav">
                                </audio>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="tva-empty">
                        <i data-lucide="inbox"></i>
                        <div>No messages in this session</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<script>
    // Re-render lucide icons after Blade injects new ones.
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
</script>
@endsection
