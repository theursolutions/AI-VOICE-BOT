@extends('layouts.master')

@section('content')
<style>
    /* Hide the master admin sidebar + topbar + mobile menu so the
       flow editor gets the entire viewport. Saves the user from
       having two competing UIs (admin nav AND the flow editor's
       own toolbox + properties panel) fighting for screen space. */
    body { padding: 0 !important; }
    .side-nav,
    .top-bar,
    .mobile-menu,
    nav.side-nav {
        display: none !important;
    }
    body > .flex { display: block !important; margin-top: 0 !important; }
    body > .flex > .content { padding: 0 !important; max-width: none !important; }

    /* Full-bleed editor — uses the whole viewport. */
    .tva-flow-editor-wrap {
        position: fixed; inset: 0;
        background: #0f172a;
    }
    .tva-flow-editor-header {
        display:flex; align-items:center; gap:14px;
        padding: 12px 18px;
        background:#0f172a; border-bottom: 1px solid #334155; color:#e6edf3;
    }
    .tva-flow-editor-header h2 { font-size:15px; font-weight:600; margin:0; }
    .tva-flow-editor-header .pill { font-size:10px; background:#334155; padding:3px 9px; border-radius:999px; font-family: ui-monospace, monospace; letter-spacing:.04em; }
    #flow-editor-root { width:100%; height: calc(100% - 50px); }
    .editor-placeholder {
        height:100%; display:flex; align-items:center; justify-content:center;
        color:#94a3b8; font-size:13px;
    }
</style>

<div class="tva-flow-editor-wrap">
    <div class="tva-flow-editor-header">
        <a href="{{ route('flows.index', ['client' => $client->slug]) }}?project_id={{ hashid($flow->project_id) }}"
           style="color:#94a3b8; text-decoration:none; font-size:13px;">
            <i data-lucide="arrow-left" class="w-4 h-4 inline -mt-0.5"></i> Back to flows
        </a>
        <h2>{{ $flow->name }}</h2>
        <span class="pill">v{{ $flow->version }}</span>
        <span class="pill">{{ $flow->language }}</span>
        <span class="pill" style="background: {{ $flow->status === 'active' ? '#15803d' : ($flow->status === 'draft' ? '#92400e' : '#475569') }};">{{ $flow->status }}</span>
        <div style="margin-left:auto; font-size:12px; color:#94a3b8;">
            Phase 1B (visual builder) lands next — for now this is the mount point.
        </div>
    </div>

    <div id="flow-editor-root"
         data-flow-id="{{ $flow->id }}"
         data-flow-status="{{ $flow->status }}"
         data-project-id="{{ $flow->project_id }}"
         data-client-slug="{{ $client->slug }}"
         data-csrf="{{ csrf_token() }}"
         data-base-url="{{ rtrim(url('/'), '/') }}"
         data-data-sources="{{ json_encode($dataSources ?? []) }}">
        <div class="editor-placeholder">
            <div style="text-align:center;">
                <div style="width: 44px; height: 44px; border: 3px solid #1e293b; border-top-color: #3b82f6; border-radius: 50%; margin: 0 auto 12px; animation: spin 1s linear infinite;"></div>
                <div style="font-size: 13px;">Booting Flow Builder…</div>
            </div>
        </div>
    </div>
</div>

<style>@keyframes spin { to { transform: rotate(360deg); } }</style>

{{-- React micro-app — bundled by Vite at resources/js/flow-editor/index.jsx --}}
@vite(['resources/js/flow-editor/index.jsx'])

<script>if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}</script>
@endsection
