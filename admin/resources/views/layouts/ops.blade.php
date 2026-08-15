<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ tva_theme_class('admin') }}">
    @include('layouts.head')

    @include('partials.theme')
    {{-- Override the customer's neon-green palette with amber so the
         ops console is visually unmistakable. Same UI primitives
         (sidebar, topbar, .tva-* card classes) — different accent. --}}
    <style>
        :root {
            --tva-primary: #c97a00;
            --tva-accent:  #ffb800;
            --tva-gradient: linear-gradient(135deg, #c97a00 0%, #ffb800 100%);
        }
        /* Amber pulse on the active sidebar item — matches customer feel. */
        .side-menu--active { background-color: rgba(255, 184, 0, .12) !important; }
        .side-menu--active .side-menu__title { color: #ffd54a !important; }
        .ops-internal-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #ffb800; color: #1c1300;
            font-family: ui-monospace, monospace; font-size: 10px; font-weight: 800;
            padding: 4px 10px; border-radius: 6px;
            text-transform: uppercase; letter-spacing: .12em;
            box-shadow: 0 0 12px rgba(255, 184, 0, .35);
        }

        /* ── Shared card primitives (mirrors what customer pages inline).
              Lifted here so every ops view inherits them without redefinition. */
        .tva-dt-hero {
            background: var(--tva-gradient); color:#fff;
            border-radius:14px; padding:22px 26px; margin-bottom:22px;
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
            padding:14px 16px; display:flex; align-items:center; gap:12px;
        }
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
        .tva-dt-search input:focus { outline:none; border-color: var(--tva-accent); box-shadow:0 0 0 3px rgba(255,184,0,.12); }
        /* Match both the initial <i data-lucide="..."> and the <svg> Lucide
           swaps in on render — otherwise the icon escapes the input box. */
        .tva-dt-search > i,
        .tva-dt-search > svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8; width:16px; height:16px; pointer-events:none; }

        .tva-dt-toolbar select {
            border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px;
            font-size:12px; background:#fff; color:#0f172a; min-width:130px;
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

        .tva-status, .tva-channel-chip {
            display:inline-flex; align-items:center; gap:6px;
            padding:3px 10px; border-radius:999px;
            font-size:11px; font-weight:600; text-transform:capitalize;
        }
        .tva-status::before { content:''; width:6px; height:6px; border-radius:50%; }
        .tva-status.is-active     { background:#dcfce7; color:#15803d; }
        .tva-status.is-active::before     { background:#10b981; }
        .tva-status.is-ended      { background:#f1f5f9; color:#475569; }
        .tva-status.is-ended::before      { background:#94a3b8; }
        .tva-status.is-abandoned  { background:#fee2e2; color:#b91c1c; }
        .tva-status.is-abandoned::before  { background:#ef4444; }
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
        .tva-status.is-suspended { background:#fee2e2; color:#b91c1c; }
        .tva-status.is-suspended::before { background:#ef4444; }

        .tva-channel-chip { text-transform:uppercase; letter-spacing:.04em; }
        .tva-channel-chip.is-web   { background:#dbeafe; color:#1e40af; }
        .tva-channel-chip.is-voice { background:#fef3c7; color:#92400e; }
        .tva-channel-chip.is-phone { background:#dcfce7; color:#15803d; }
        .tva-channel-chip.is-sms   { background:#ede9fe; color:#7c3aed; }

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
        .tva-pag a:hover { border-color: var(--tva-accent); color:#92400e; background:#fef3c7; }
        .tva-pag .is-current { background: var(--tva-gradient); color:#fff; border-color:transparent; }
        .tva-pag .is-disabled { opacity:.4; cursor:not-allowed; }

        /* Dark mode */
        html.dark .tva-stat { background:#1e293b; border-color:#334155; }
        html.dark .tva-stat__value { color:#f1f5f9; }
        html.dark .tva-dt-card { background:#1e293b; border-color:#334155; }
        html.dark .tva-dt-toolbar, html.dark .tva-dt-footer { background:#0f172a; border-color:#334155; }
        html.dark .tva-dt-toolbar select, html.dark .tva-dt-search input { background:#1e293b; color:#f1f5f9; border-color:#334155; }
        html.dark .tva-dt-table thead th { background:#0f172a; color:#cbd5e1; border-bottom-color:#334155; }
        html.dark .tva-dt-table tbody td { color:#e2e8f0; border-bottom-color:#334155; }
        html.dark .tva-dt-table tbody tr:hover { background:#0f172a; }
        html.dark .tva-pag a, html.dark .tva-pag span { background:#1e293b; color:#cbd5e1; border-color:#334155; }
        html.dark .tva-pag a:hover { background:#7c2d12; color:#fcd34d; }
    </style>

    <body class="py-5">
        @include('partials.impersonation-banner')
        @include('layouts.ops-mobile-menu')
        <div class="flex mt-[4.7rem] md:mt-0">
            @include('layouts.ops-sidebar')
            <div class="content">
                @include('layouts.ops-topbar')
                @yield('content')
            </div>
        </div>
        @include('layouts.footer')
    </body>
</html>
