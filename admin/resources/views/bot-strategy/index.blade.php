@extends('layouts.master')

@section('content')
<style>
    .tva-tier-card {
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        padding: 18px 20px; display:flex; align-items:flex-start; gap:14px;
        transition: all .15s;
    }
    .tva-tier-card.is-on  { border-width: 2px; }
    .tva-tier-card.is-off { opacity: .65; }
    .tva-tier-icon {
        width:46px; height:46px; border-radius:10px;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0;
    }
    .tva-tier-body { flex:1; }
    .tva-tier-name {
        font-size:14px; font-weight:600; color:#0f172a;
        display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    }
    .tva-tier-badge {
        font-size: 9px; padding:2px 7px; border-radius:999px;
        font-weight: 700; letter-spacing:.05em;
    }
    .tva-tier-count {
        font-size: 10px; padding:2px 8px; border-radius:999px;
        background:#f1f5f9; color:#475569; font-weight: 600;
    }
    .tva-tier-desc { font-size: 12px; color:#64748b; margin-top: 4px; line-height: 1.55; }

    /* iOS-style switch */
    .tva-switch {
        position: relative; flex-shrink:0;
        width: 46px; height: 26px;
    }
    .tva-switch input { display: none; }
    .tva-switch__slider {
        position:absolute; inset:0;
        background:#cbd5e1; border-radius:999px;
        cursor: pointer; transition: background .15s;
    }
    .tva-switch__slider::before {
        content:''; position:absolute; top:3px; left:3px;
        width:20px; height:20px; background:#fff; border-radius:50%;
        box-shadow: 0 1px 3px rgba(0,0,0,.25);
        transition: left .15s;
    }
    .tva-switch input:checked + .tva-switch__slider { background:#10b981; }
    .tva-switch input:checked + .tva-switch__slider::before { left:23px; }

    html.dark .tva-tier-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-tier-name { color:#f1f5f9; }
    html.dark .tva-tier-desc { color:#94a3b8; }
    html.dark .tva-tier-count { background:#334155; color:#cbd5e1; }
</style>

<div class="content">
    <div class="intro-y flex items-center mt-6 mb-4">
        <h2 class="text-lg font-medium mr-auto">
            Bot knowledge strategy — {{ $client?->name }}
        </h2>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
            {{ session('success') }}
        </div>
    @endif

    {{-- Explainer banner --}}
    <div class="intro-y box p-5 mb-5"
         style="background: linear-gradient(135deg,#eef2ff 0%,#f5f3ff 100%); border:1px solid #c7d2fe;">
        <h3 class="font-medium text-base mb-2 flex items-center" style="color:#3730a3;">
            <i data-lucide="layers" class="w-4 h-4 mr-2"></i>
            Which tiers should the bot consult?
        </h3>
        <p class="text-xs" style="color:#475569; line-height:1.6;">
            By default the bot fans out to <b>every active data source</b> in parallel and lets the
            LLM blend the results. Use the toggles below to silence tiers you don't want to expose
            for this project — e.g. <b>turn off Live database</b> if you don't trust the bot to write SQL,
            or <b>turn off Webhook tools</b> while you stabilise an endpoint.
        </p>
    </div>

    {{-- Project picker --}}
    <div class="intro-y box p-3 mb-5">
        <form method="GET">
            <label class="text-xs text-slate-500 font-semibold uppercase tracking-wider">Project</label>
            <select name="project_id" class="form-select w-full md:w-1/3 mt-1" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <form method="POST" action="{{ route('bot-strategy.update', ['client' => $client->slug]) }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="project_id" value="{{ $projectId }}">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach ($tierMeta as $type => $meta)
                @php
                    $on   = (bool) ($strategy[$type] ?? true);
                    $cnt  = (int)  ($counts[$type]   ?? 0);
                @endphp
                <label class="tva-tier-card {{ $on ? 'is-on' : 'is-off' }}"
                       style="{{ $on ? 'border-color:' . $meta['color'] . ';' : '' }}">
                    <div class="tva-tier-icon"
                         style="background:{{ $meta['bg'] }}; color:{{ $meta['color'] }};">
                        <i data-lucide="{{ $meta['icon'] }}" class="w-5 h-5"></i>
                    </div>
                    <div class="tva-tier-body">
                        <div class="tva-tier-name">
                            {{ $meta['label'] }}
                            <span class="tva-tier-badge"
                                  style="background:{{ $meta['bg'] }}; color:{{ $meta['color'] }};">{{ $meta['tier'] }}</span>
                            <span class="tva-tier-count">{{ $cnt }} active</span>
                        </div>
                        <div class="tva-tier-desc">{{ $meta['desc'] }}</div>
                    </div>
                    <div class="tva-switch">
                        <input type="checkbox" name="strategy[{{ $type }}]" value="1" {{ $on ? 'checked' : '' }}>
                        <span class="tva-switch__slider"></span>
                    </div>
                </label>
            @endforeach
        </div>

        <div class="mt-5 flex items-center gap-3">
            <button type="submit" class="btn btn-primary shadow-md">
                <i data-lucide="save" class="w-4 h-4 mr-2 inline"></i> Save strategy
            </button>
            <a href="{{ route('data-sources.index') }}" class="text-xs text-slate-500 hover:text-primary">
                Manage data sources →
            </a>
        </div>
    </form>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
</script>
@endsection
