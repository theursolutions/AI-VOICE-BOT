{{-- Shared styles for Lead Generation pages (tva-lg-* prefix, light + dark). --}}
<style>
    .tva-lg-stats { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:16px; margin-top:20px; }
    @media (max-width: 1100px) { .tva-lg-stats { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    @media (max-width: 600px)  { .tva-lg-stats { grid-template-columns:1fr; gap:12px; } }
    .tva-lg-stat { display:flex; align-items:center; gap:14px;
                   background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; }
    html.dark .tva-lg-stat { background:#1e293b; border-color:#334155; }
    .tva-lg-stat__ico { width:42px; height:42px; border-radius:11px; flex-shrink:0;
                        display:flex; align-items:center; justify-content:center; }
    .tva-lg-stat__ico svg { width:19px !important; height:19px !important; }
    .tva-lg-stat__n { font-size:23px; font-weight:700; line-height:1.15; color:#0f172a; font-variant-numeric:tabular-nums; }
    html.dark .tva-lg-stat__n { color:#f1f5f9; }
    .tva-lg-stat__l { font-size:11.5px; color:#94a3b8; margin-top:3px; }
    .tva-lg-panel { background:#fff; border:1px solid #e2e8f0; border-radius:14px; margin-top:18px; padding:18px 20px; }
    html.dark .tva-lg-panel { background:#1e293b; border-color:#334155; }
    .tva-lg-panel--flush { padding:16px 4px; overflow-x:auto; }
    .tva-lg-h { font-size:13px; font-weight:700; color:#0f172a; margin-bottom:12px; }
    html.dark .tva-lg-h { color:#f1f5f9; }
    .tva-lg-row { display:flex; align-items:flex-start; gap:12px; padding:12px 0; border-top:1px solid #e2e8f0; }
    .tva-lg-row:first-of-type { border-top:0; padding-top:0; }
    html.dark .tva-lg-row { border-top-color:#334155; }
    .tva-lg-row__t { font-weight:600; font-size:12.5px; color:#334155; }
    html.dark .tva-lg-row__t { color:#e2e8f0; }
    .tva-lg-row__d { font-size:11.5px; color:#94a3b8; margin-top:2px; line-height:1.55; }
    .tva-lg-pill { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:3px 10px 3px 8px;
                   border-radius:999px; white-space:nowrap; line-height:1.5; border:1px solid transparent; }
    .tva-lg-pill::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
    .tva-lg-row .tva-lg-pill { margin-left:auto; }
    .tva-lg-pill--ok    { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
    .tva-lg-pill--off   { background:#f8fafc; color:#64748b; border-color:#e2e8f0; }
    .tva-lg-pill--run   { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .tva-lg-pill--warn  { background:#fffbeb; color:#b45309; border-color:#fde68a; }
    .tva-lg-pill--bad   { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
    html.dark .tva-lg-pill--off { background:#334155; color:#cbd5e1; }
    .tva-lg-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    @media (max-width: 900px) { .tva-lg-grid { grid-template-columns:1fr; } }
    .tva-lg-tabs { display:flex; gap:4px; margin-top:14px; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; }
    html.dark .tva-lg-tabs { border-bottom-color:#334155; }
    .tva-lg-tab { padding:8px 14px; font-size:12.5px; font-weight:600; color:#64748b; border-bottom:2px solid transparent; margin-bottom:-1px; }
    .tva-lg-tab:hover { color:#0f172a; }
    html.dark .tva-lg-tab:hover { color:#f1f5f9; }
    .tva-lg-tab--on { color:#2563eb; border-bottom-color:#2563eb; }
    .tva-lg-tbl { width:100%; border-collapse:collapse; }
    .tva-lg-tbl th { font-size:9.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
                     color:#94a3b8; text-align:left; padding:0 12px 8px; white-space:nowrap; }
    .tva-lg-tbl td { padding:10px 12px; border-top:1px solid #e2e8f0; font-size:12.5px; color:#334155; vertical-align:top; }
    html.dark .tva-lg-tbl td { border-top-color:#334155; color:#cbd5e1; }
    .tva-lg-tbl th:first-child, .tva-lg-tbl td:first-child { padding-left:20px; }
    .tva-lg-tbl th:last-child,  .tva-lg-tbl td:last-child  { padding-right:20px; }
    .tva-lg-sub { font-size:11px; color:#94a3b8; }
    .tva-lg-name { font-weight:600; color:#0f172a; }
    html.dark .tva-lg-name { color:#f1f5f9; }
    .tva-lg-bar { height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden; }
    html.dark .tva-lg-bar { background:#334155; }
    .tva-lg-bar > div { height:100%; background:#2563eb; transition:width .4s; }
    .tva-lg-empty { text-align:center; padding:44px 20px; color:#94a3b8; font-size:12.5px; line-height:1.7; }
    .tva-lg-field { margin-top:14px; }
    .tva-lg-field > label { display:block; font-size:12px; font-weight:600; margin-bottom:6px; }
    .tva-lg-hint { font-size:11px; color:#94a3b8; margin-top:4px; line-height:1.5; }
    .tva-lg-chips { display:flex; flex-wrap:wrap; gap:6px; max-height:260px; overflow:auto; }
    .tva-lg-chip { display:inline-flex; align-items:center; gap:6px; border:1px solid #e2e8f0; border-radius:999px;
                   padding:4px 10px; font-size:12px; cursor:pointer; user-select:none; }
    html.dark .tva-lg-chip { border-color:#334155; }
    .tva-lg-chip input { margin:0; }
    .tva-lg-chip--on { border-color:#2563eb; background:#eff6ff; }
    html.dark .tva-lg-chip--on { background:#1e3a8a55; }
    .tva-lg-match { display:block; width:100%; text-align:left; border:1px solid #e2e8f0; border-radius:10px;
                    padding:8px 12px; margin-top:6px; font-size:12.5px; }
    html.dark .tva-lg-match { border-color:#334155; }
    .tva-lg-match:hover { border-color:#2563eb; }
    .tva-lg-match[disabled] { opacity:.5; cursor:not-allowed; }

    /* ── Form fields ───────────────────────────────────────────────────────
       The theme's .form-control leans on Tailwind's form base, which only
       styles inputs with an explicit type — so plain <input> rendered bare.
       These rules are only loaded on Lead Generation pages. */
    .form-control, .form-select {
        display:block; width:100%; min-height:40px; padding:9px 12px; font-size:13px; line-height:1.4;
        color:#0f172a; background-color:#fff; border:1px solid #cbd5e1; border-radius:10px;
        box-shadow:0 1px 2px rgba(15,23,42,.04); transition:border-color .15s, box-shadow .15s;
    }
    .form-control::placeholder { color:#94a3b8; }
    .form-control:focus, .form-select:focus {
        outline:none; border-color:rgb(var(--color-primary)); box-shadow:0 0 0 3px rgb(var(--color-primary) / .16);
    }
    .form-control:disabled, .form-select:disabled { background-color:#f8fafc; color:#94a3b8; cursor:not-allowed; }
    textarea.form-control { min-height:auto; line-height:1.55; resize:vertical; }
    .form-select {
        appearance:none; -webkit-appearance:none; padding-right:36px; cursor:pointer;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:right 11px center; background-size:16px;
    }
    .form-control-sm, .form-select-sm { min-height:34px; padding-top:6px; padding-bottom:6px; font-size:12.5px; border-radius:8px; }
    .form-select-sm { padding-right:32px; background-position:right 9px center; }
    .form-check-input { width:16px; height:16px; accent-color:rgb(var(--color-primary)); cursor:pointer; flex-shrink:0; }
    input[type=file].form-control { padding:7px 10px; }
    html.dark .form-control, html.dark .form-select { background-color:#0f172a; border-color:#334155; color:#e2e8f0; }
    html.dark .form-control:disabled, html.dark .form-select:disabled { background-color:#1e293b; color:#64748b; }
    html.dark .form-select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); }
    .tva-lg-field > label, label.text-xs.font-semibold { color:#334155; }
    html.dark .tva-lg-field > label, html.dark label.text-xs.font-semibold { color:#cbd5e1; }

    /* ── Wizard ────────────────────────────────────────────────────────── */
    .tva-wz-steps { display:flex; align-items:center; gap:0; margin-top:18px; background:#fff; border:1px solid #e2e8f0;
                    border-radius:14px; padding:14px 18px; overflow-x:auto; }
    html.dark .tva-wz-steps { background:#1e293b; border-color:#334155; }
    .tva-wz-step { display:flex; align-items:center; gap:10px; flex-shrink:0; cursor:pointer; background:none; border:0; padding:0; text-align:left; }
    .tva-wz-step[disabled] { cursor:not-allowed; }
    .tva-wz-dot { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                  border:2px solid #cbd5e1; color:#94a3b8; background:#fff; transition:.2s; }
    html.dark .tva-wz-dot { background:#0f172a; border-color:#475569; }
    .tva-wz-dot svg { width:16px !important; height:16px !important; }
    .tva-wz-label { font-size:12.5px; font-weight:700; color:#64748b; line-height:1.2; }
    .tva-wz-label small { display:block; font-weight:500; font-size:11px; color:#94a3b8; }
    .tva-wz-line { flex:1; min-width:28px; height:2px; background:#e2e8f0; margin:0 14px; border-radius:2px; }
    html.dark .tva-wz-line { background:#334155; }
    .tva-wz-step--on .tva-wz-dot { border-color:rgb(var(--color-primary)); background:rgb(var(--color-primary)); color:#fff;
                                    box-shadow:0 0 0 4px rgb(var(--color-primary) / .15); }
    .tva-wz-step--on .tva-wz-label { color:#0f172a; }
    html.dark .tva-wz-step--on .tva-wz-label { color:#f1f5f9; }
    .tva-wz-step--done .tva-wz-dot { border-color:#10b981; background:#10b981; color:#fff; }
    .tva-wz-line--done { background:#10b981; }
    .tva-wz-pane { display:none; }
    .tva-wz-pane--on { display:block; animation:tvaWzIn .2s ease-out; }
    @keyframes tvaWzIn { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }
    .tva-wz-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:24px; margin-top:18px; }
    html.dark .tva-wz-card { background:#1e293b; border-color:#334155; }
    .tva-wz-title { font-size:16px; font-weight:800; color:#0f172a; }
    html.dark .tva-wz-title { color:#f1f5f9; }
    .tva-wz-sub { font-size:12.5px; color:#64748b; margin-top:4px; line-height:1.55; }
    .tva-wz-nav { display:flex; align-items:center; gap:10px; margin-top:22px; padding-top:18px; border-top:1px solid #f1f5f9; }
    html.dark .tva-wz-nav { border-top-color:#334155; }
    .tva-wz-nav .btn { display:inline-flex; align-items:center; gap:6px; }
    .tva-wz-nav svg { width:16px !important; height:16px !important; }
    .tva-wz-seg { display:inline-flex; background:#f1f5f9; border-radius:12px; padding:4px; gap:4px; }
    html.dark .tva-wz-seg { background:#0f172a; }
    .tva-wz-seg label { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:9px; font-size:12.5px;
                        font-weight:600; color:#475569; cursor:pointer; }
    .tva-wz-seg input { position:absolute; opacity:0; pointer-events:none; }
    .tva-wz-seg svg { width:15px !important; height:15px !important; }
    .tva-wz-seg label:has(input:checked) { background:#fff; color:rgb(var(--color-primary)); box-shadow:0 1px 3px rgba(15,23,42,.1); }
    html.dark .tva-wz-seg label:has(input:checked) { background:#1e293b; color:#93c5fd; }
    .tva-wz-chosen { display:flex; align-items:center; gap:12px; margin-top:16px; padding:12px 14px; border-radius:12px;
                     background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; font-size:13px; font-weight:600; }
    html.dark .tva-wz-chosen { background:#064e3b55; border-color:#065f46; color:#6ee7b7; }
    .tva-wz-chosen svg { width:18px !important; height:18px !important; }
    .tva-wz-matches { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; margin-top:12px; }
    .tva-wz-match { display:flex; align-items:center; gap:10px; text-align:left; border:1px solid #e2e8f0; border-radius:12px;
                    padding:10px 12px; background:#fff; transition:.15s; }
    html.dark .tva-wz-match { background:#0f172a; border-color:#334155; }
    .tva-wz-match:hover:not([disabled]) { border-color:rgb(var(--color-primary)); box-shadow:0 4px 12px -6px rgb(var(--color-primary) / .4); }
    .tva-wz-match[disabled] { opacity:.5; cursor:not-allowed; }
    .tva-wz-match b { display:block; font-size:12.5px; color:#0f172a; }
    html.dark .tva-wz-match b { color:#f1f5f9; }
    .tva-wz-match span { font-size:11px; color:#94a3b8; }
    .tva-wz-toggles { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:12px; }
    .tva-wz-toggle { position:relative; display:flex; align-items:center; gap:12px; border:1.5px solid #e2e8f0; border-radius:14px;
                     padding:14px; cursor:pointer; transition:.15s; background:#fff; }
    html.dark .tva-wz-toggle { background:#0f172a; border-color:#334155; }
    .tva-wz-toggle input { position:absolute; opacity:0; pointer-events:none; }
    .tva-wz-toggle b { display:block; font-size:12.5px; color:#0f172a; }
    html.dark .tva-wz-toggle b { color:#f1f5f9; }
    .tva-wz-toggle small { font-size:11px; color:#94a3b8; }
    .tva-wz-toggle:has(input:checked) { border-color:rgb(var(--color-primary)); background:rgb(var(--color-primary) / .05);
                                        box-shadow:0 0 0 3px rgb(var(--color-primary) / .1); }
    .tva-wz-check { margin-left:auto; width:20px; height:20px; border-radius:50%; border:2px solid #cbd5e1; flex-shrink:0; }
    .tva-wz-toggle:has(input:checked) .tva-wz-check { border-color:rgb(var(--color-primary)); background:rgb(var(--color-primary));
        box-shadow:inset 0 0 0 3px #fff; }
    html.dark .tva-wz-toggle:has(input:checked) .tva-wz-check { box-shadow:inset 0 0 0 3px #0f172a; }
    .tva-wz-sum { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; }
    @media (max-width: 760px) { .tva-wz-sum { grid-template-columns:1fr; } .tva-wz-card { padding:18px; } }
    .tva-wz-sum > div { border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; }
    html.dark .tva-wz-sum > div { border-color:#334155; }
    .tva-wz-sum dt { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
    .tva-wz-sum dd { font-size:13px; color:#0f172a; margin-top:4px; line-height:1.5; }
    html.dark .tva-wz-sum dd { color:#e2e8f0; }
    .tva-wz-estimate { display:flex; align-items:center; gap:12px; padding:14px 16px; border-radius:12px; margin-top:16px;
                       background:rgb(var(--color-primary) / .06); border:1px solid rgb(var(--color-primary) / .2); }
    .tva-wz-estimate b { font-size:22px; font-weight:800; color:rgb(var(--color-primary)); }
    .tva-wz-estimate.is-busy .tva-tone { animation:tvaBob 1.2s ease-in-out infinite; }
    .tva-wz-estimate.is-busy b { background:linear-gradient(90deg, rgb(var(--color-primary)), #7c3aed, rgb(var(--color-primary)));
        background-size:200% 100%; -webkit-background-clip:text; background-clip:text; color:transparent; animation:tvaShimmer 1.2s linear infinite; }
    html.dark .tva-wz-estimate b { color:#93c5fd; }
    .tva-spin { width:16px; height:16px; border-radius:50%; border:2px solid currentColor; border-right-color:transparent;
                display:inline-block; animation:tvaSpin .7s linear infinite; vertical-align:-3px; }
    @keyframes tvaSpin { to { transform:rotate(360deg); } }

    /* ── Buttons (content area only — sidebar/topbar untouched) ─────────── */
    .content .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; font-weight:600; font-size:13px;
        border-radius:10px; padding:8px 16px; min-height:38px; line-height:1.2; border:1px solid transparent; white-space:nowrap;
        transition:background-color .15s, border-color .15s, box-shadow .15s, transform .1s, color .15s; cursor:pointer; }
    .content .btn:active:not(:disabled) { transform:translateY(1px); }
    .content .btn:disabled { opacity:.55; cursor:not-allowed; }
    .content .btn svg { width:15px !important; height:15px !important; }
    .content .btn-sm { min-height:32px; padding:5px 12px; font-size:12.5px; border-radius:9px; }
    .content .btn-primary { color:#fff; background:linear-gradient(135deg, rgb(var(--color-primary)), #4f46e5);
        box-shadow:0 4px 12px -4px rgb(var(--color-primary) / .55); border-color:transparent; }
    .content .btn-primary:hover:not(:disabled) { box-shadow:0 6px 16px -4px rgb(var(--color-primary) / .7); filter:brightness(1.05); }
    .content .btn-secondary { color:#1e293b; background:#f1f5f9; border-color:#e2e8f0; }
    .content .btn-secondary:hover:not(:disabled) { background:#e2e8f0; }
    .content .btn-outline-secondary { color:#334155; background:#fff; border-color:#cbd5e1; box-shadow:0 1px 2px rgba(15,23,42,.05); }
    .content .btn-outline-secondary:hover:not(:disabled) { border-color:rgb(var(--color-primary) / .5); color:rgb(var(--color-primary)); background:rgb(var(--color-primary) / .04); }
    .content .btn-outline-danger { color:#be123c; background:#fff; border-color:#fecdd3; }
    .content .btn-outline-danger:hover:not(:disabled) { background:#fff1f2; border-color:#fda4af; }
    .content .btn-success { color:#fff; background:linear-gradient(135deg, #10b981, #059669); box-shadow:0 4px 12px -4px rgba(16,185,129,.55); }
    html.dark .content .btn-secondary { background:#334155; border-color:#475569; color:#e2e8f0; }
    html.dark .content .btn-outline-secondary { background:#1e293b; border-color:#475569; color:#e2e8f0; }
    html.dark .content .btn-outline-danger { background:#1e293b; border-color:#9f1239; color:#fda4af; }

    /* ── Status, tag and score chips ───────────────────────────────────── */
    .tva-st { display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700; padding:3px 10px 3px 8px;
              border-radius:999px; white-space:nowrap; line-height:1.5; border:1px solid transparent; }
    .tva-st::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; flex-shrink:0; }
    .tva-st--new        { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .tva-st--reviewing  { background:#fffbeb; color:#b45309; border-color:#fde68a; }
    .tva-st--approved   { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
    .tva-st--rejected   { background:#fff1f2; color:#be123c; border-color:#fecdd3; }
    .tva-st--in_crm     { background:#f0fdfa; color:#0f766e; border-color:#99f6e4; }
    .tva-st--blocked    { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
    .tva-st--dup        { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
    .tva-st--muted      { background:#f8fafc; color:#64748b; border-color:#e2e8f0; }
    html.dark .tva-st--new { background:#1e3a8a44; color:#93c5fd; border-color:#1e40af; }
    html.dark .tva-st--reviewing { background:#78350f44; color:#fcd34d; border-color:#92400e; }
    html.dark .tva-st--approved { background:#064e3b44; color:#6ee7b7; border-color:#065f46; }
    html.dark .tva-st--rejected { background:#88133744; color:#fda4af; border-color:#9f1239; }
    html.dark .tva-st--in_crm { background:#134e4a44; color:#5eead4; border-color:#115e59; }
    html.dark .tva-st--blocked { background:#7f1d1d44; color:#fca5a5; border-color:#991b1b; }
    html.dark .tva-st--dup { background:#7c2d1244; color:#fdba74; border-color:#9a3412; }
    html.dark .tva-st--muted { background:#33415544; color:#cbd5e1; border-color:#475569; }
    .tva-tag { display:inline-flex; align-items:center; gap:3px; font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:6px;
               background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe; white-space:nowrap; }
    .tva-tag::before { content:'#'; opacity:.55; }
    .tva-tag--1 { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .tva-tag--2 { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
    .tva-tag--3 { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }
    .tva-tag--4 { background:#fdf2f8; color:#be185d; border-color:#fbcfe8; }
    .tva-tag--5 { background:#f0fdfa; color:#0f766e; border-color:#99f6e4; }
    html.dark .tva-tag { filter:saturate(.9); background:#1e293b; }
    .tva-score { display:inline-flex; align-items:center; gap:8px; }
    .tva-score__n { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                    font-size:12px; font-weight:800; color:#fff; flex-shrink:0; }
    .tva-score--high .tva-score__n { background:linear-gradient(135deg, #10b981, #059669); }
    .tva-score--medium .tva-score__n { background:linear-gradient(135deg, #3b82f6, #4f46e5); }
    .tva-score--low .tva-score__n { background:linear-gradient(135deg, #94a3b8, #64748b); }
    .tva-score--needs_review .tva-score__n { background:linear-gradient(135deg, #f59e0b, #d97706); }
    .tva-score__l { font-size:11px; font-weight:700; color:#475569; line-height:1.25; }
    html.dark .tva-score__l { color:#cbd5e1; }

    /* ── Tables ────────────────────────────────────────────────────────── */
    .tva-lg-tbl thead th { background:#f8fafc; padding-top:11px; padding-bottom:11px; border-bottom:1px solid #e2e8f0; }
    .tva-lg-tbl thead th:first-child { border-top-left-radius:10px; }
    .tva-lg-tbl thead th:last-child { border-top-right-radius:10px; }
    html.dark .tva-lg-tbl thead th { background:#0f172a; border-bottom-color:#334155; }
    .tva-lg-tbl td { vertical-align:middle; }
    .tva-lg-tbl tbody tr { transition:background-color .12s; }
    .tva-lg-tbl tbody tr:hover { background:#f8fafc; }
    html.dark .tva-lg-tbl tbody tr:hover { background:#0f172a88; }
    .tva-lg-tbl tbody tr.is-selected { background:rgb(var(--color-primary) / .06); }
    .tva-lg-panel--flush { padding:0 0 4px; }
    .tva-lg-panel--flush > .tva-lg-tbl { margin-top:0; }
    .tva-av { width:36px; height:36px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0;
              font-size:13px; font-weight:800; color:#fff; letter-spacing:.02em; }
    .tva-av--0 { background:linear-gradient(135deg,#6366f1,#8b5cf6); } .tva-av--1 { background:linear-gradient(135deg,#0ea5e9,#2563eb); }
    .tva-av--2 { background:linear-gradient(135deg,#10b981,#0d9488); } .tva-av--3 { background:linear-gradient(135deg,#f59e0b,#ea580c); }
    .tva-av--4 { background:linear-gradient(135deg,#ec4899,#db2777); } .tva-av--5 { background:linear-gradient(135deg,#14b8a6,#0891b2); }
    .tva-who { display:flex; align-items:center; gap:12px; min-width:0; }
    .tva-who__n { font-weight:700; color:#0f172a; font-size:13px; }
    html.dark .tva-who__n { color:#f1f5f9; }
    a.tva-who__n:hover { color:rgb(var(--color-primary)); }
    .tva-who__s { font-size:11.5px; color:#94a3b8; margin-top:1px; display:flex; flex-wrap:wrap; gap:4px 6px; align-items:center; }
    .tva-cell-ico { display:inline-flex; align-items:center; gap:6px; color:#334155; font-size:12.5px; }
    html.dark .tva-cell-ico { color:#cbd5e1; }
    .tva-cell-ico svg { width:14px !important; height:14px !important; color:#94a3b8; flex-shrink:0; }
    .tva-empty-dash { color:#cbd5e1; }

    /* ── Toolbar, bulk bar, pager ──────────────────────────────────────── */
    .tva-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:#fff; border:1px solid #e2e8f0;
                   border-radius:14px; padding:12px; margin-top:16px; }
    html.dark .tva-toolbar { background:#1e293b; border-color:#334155; }
    .tva-toolbar .form-control, .tva-toolbar .form-select { width:auto; }
    .tva-search { position:relative; flex:1; min-width:220px; max-width:340px; }
    .tva-search svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:15px !important; height:15px !important; color:#94a3b8; }
    .tva-search .form-control { padding-left:34px; width:100%; }
    .tva-bulkbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:12px 16px; border-bottom:1px solid #e2e8f0;
                   border-top-left-radius:14px; border-top-right-radius:14px; transition:background-color .2s; }
    html.dark .tva-bulkbar { border-bottom-color:#334155; }
    .tva-bulkbar.has-selection { background:rgb(var(--color-primary) / .07); }
    .tva-bulkbar__count { font-size:12.5px; font-weight:700; color:#64748b; min-width:90px; }
    .tva-bulkbar.has-selection .tva-bulkbar__count { color:rgb(var(--color-primary)); }
    .tva-pager { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 18px 10px; }
    .tva-pager__info { font-size:12px; color:#64748b; }
    .tva-pager__info b { color:#0f172a; }
    html.dark .tva-pager__info b { color:#f1f5f9; }
    .tva-pag { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
    .tva-pag a, .tva-pag span { display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 9px;
        border-radius:9px; font-size:12.5px; font-weight:600; color:#475569; border:1px solid #e2e8f0; background:#fff; text-decoration:none; transition:.12s; }
    .tva-pag a:hover { border-color:rgb(var(--color-primary) / .4); color:rgb(var(--color-primary)); background:rgb(var(--color-primary) / .05); }
    .tva-pag .is-current { background:linear-gradient(135deg, rgb(var(--color-primary)), #4f46e5); color:#fff; border-color:transparent;
        box-shadow:0 4px 10px -4px rgb(var(--color-primary) / .6); }
    .tva-pag .is-disabled { opacity:.4; cursor:not-allowed; }
    .tva-pag svg { width:15px !important; height:15px !important; }
    html.dark .tva-pag a, html.dark .tva-pag span { background:#1e293b; color:#cbd5e1; border-color:#334155; }
    html.dark .tva-pag .is-current { color:#fff; }

    /* These components set their own display, which beats Tailwind's .hidden
       (this stylesheet loads after the theme's) — so hide them explicitly. */
    .tva-wz-chosen.hidden, .form-control.hidden, .form-select.hidden, .tva-wz-matches.hidden,
    .tva-load.hidden, .tva-lg-chips.hidden { display:none !important; }

    /* ── Loaders: make the wait feel like progress ─────────────────────── */
    .tva-load { text-align:center; padding:30px 16px 26px; margin-top:16px; border-radius:16px;
                background:linear-gradient(180deg, rgb(var(--color-primary) / .05), transparent 70%);
                border:1px dashed rgb(var(--color-primary) / .25); animation:tvaWzIn .25s ease-out; }
    .tva-load__orb { position:relative; width:76px; height:76px; margin:0 auto; }
    .tva-load__orb::before, .tva-load__orb::after { content:''; position:absolute; inset:0; border-radius:50%;
        background:rgb(var(--color-primary) / .35); animation:tvaPulse 2s ease-out infinite; }
    .tva-load__orb::after { animation-delay:1s; }
    .tva-load__core { position:absolute; inset:12px; border-radius:50%; z-index:1; display:flex; align-items:center; justify-content:center;
        background:linear-gradient(135deg, rgb(var(--color-primary)), #7c3aed); color:#fff;
        box-shadow:0 8px 24px -6px rgb(var(--color-primary) / .6); animation:tvaBob 2.4s ease-in-out infinite; }
    .tva-load__core svg { width:24px; height:24px; }
    @keyframes tvaPulse { 0% { transform:scale(.7); opacity:.8; } 100% { transform:scale(1.9); opacity:0; } }
    @keyframes tvaBob { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-4px); } }
    .tva-load__t { margin-top:18px; font-size:15px; font-weight:800; color:#0f172a; }
    html.dark .tva-load__t { color:#f1f5f9; }
    .tva-load__s { margin-top:4px; font-size:12.5px; color:#64748b; line-height:1.55; max-width:460px; margin-left:auto; margin-right:auto; }
    html.dark .tva-load__s { color:#94a3b8; }
    .tva-load__bar { position:relative; overflow:hidden; height:6px; max-width:320px; margin:16px auto 0; border-radius:99px; background:rgb(var(--color-primary) / .12); }
    .tva-load__bar::after { content:''; position:absolute; top:0; bottom:0; width:40%; border-radius:99px;
        background:linear-gradient(90deg, transparent, rgb(var(--color-primary)), #7c3aed, transparent); animation:tvaSlide 1.4s ease-in-out infinite; }
    @keyframes tvaSlide { 0% { left:-40%; } 100% { left:100%; } }
    .tva-load__meta { display:flex; justify-content:center; gap:8px; align-items:center; margin-top:12px; font-size:11.5px; color:#94a3b8; }
    .tva-load__time { font-variant-numeric:tabular-nums; font-weight:700; color:rgb(var(--color-primary)); }
    html.dark .tva-load__time { color:#93c5fd; }
    .tva-load__tip { margin:14px auto 0; max-width:440px; font-size:12px; color:#475569; background:#fff; border:1px solid #e2e8f0;
        border-radius:10px; padding:8px 12px; min-height:36px; transition:opacity .35s; }
    html.dark .tva-load__tip { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    .tva-load--sm { padding:18px 12px; }
    .tva-load--sm .tva-load__orb { width:56px; height:56px; }
    .tva-load--sm .tva-load__core { inset:9px; }
    .tva-load--sm .tva-load__core svg { width:18px; height:18px; }

    /* Input + button while working */
    .tva-input-busy { background-image:linear-gradient(90deg, transparent 0%, rgb(var(--color-primary) / .10) 50%, transparent 100%) !important;
        background-size:200% 100% !important; animation:tvaShimmer 1.2s linear infinite; border-color:rgb(var(--color-primary)) !important;
        box-shadow:0 0 0 3px rgb(var(--color-primary) / .14) !important; }
    @keyframes tvaShimmer { 0% { background-position:150% 0; } 100% { background-position:-50% 0; } }
    .tva-btn-prime { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:40px; padding:0 18px;
        border-radius:10px; font-weight:700; font-size:13px; color:#fff; border:0; white-space:nowrap;
        background:linear-gradient(135deg, rgb(var(--color-primary)), #4f46e5);
        box-shadow:0 6px 16px -6px rgb(var(--color-primary) / .6); transition:transform .1s, box-shadow .15s, opacity .15s; }
    .tva-btn-prime:hover:not(:disabled) { box-shadow:0 8px 20px -6px rgb(var(--color-primary) / .75); transform:translateY(-1px); }
    .tva-btn-prime:disabled { opacity:.75; cursor:progress; }
    .tva-btn-prime svg { width:16px !important; height:16px !important; }

    /* ── Header: title, help line, icon tabs ───────────────────────────── */
    .tva-lg-head { display:flex; align-items:center; gap:12px; margin-top:18px; flex-wrap:wrap; }
    .tva-lg-head__ico { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center;
                        background:linear-gradient(135deg, rgb(var(--color-primary)), #6366f1); color:#fff; flex-shrink:0;
                        box-shadow:0 6px 16px -6px rgb(var(--color-primary) / .55); }
    .tva-lg-head__ico svg { width:20px !important; height:20px !important; }
    .tva-lg-head__t { font-size:18px; font-weight:700; color:#0f172a; line-height:1.2; }
    html.dark .tva-lg-head__t { color:#f1f5f9; }
    .tva-lg-help { display:flex; align-items:flex-start; gap:8px; margin-top:12px; padding:10px 14px; border-radius:12px;
                   background:#f8fafc; border:1px solid #e2e8f0; font-size:12.5px; color:#475569; line-height:1.55; }
    html.dark .tva-lg-help { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    .tva-lg-help svg { width:16px !important; height:16px !important; color:rgb(var(--color-primary)); flex-shrink:0; margin-top:1px; }
    .tva-lg-tabs2 { display:flex; gap:6px; margin-top:16px; overflow-x:auto; padding-bottom:2px; scrollbar-width:thin; }
    .tva-lg-tab2 { display:inline-flex; align-items:center; gap:7px; padding:8px 13px; border-radius:10px; font-size:12.5px;
                   font-weight:600; color:#475569; white-space:nowrap; border:1px solid transparent; transition:.15s; }
    .tva-lg-tab2 svg { width:15px !important; height:15px !important; }
    .tva-lg-tab2:hover { background:#f1f5f9; color:#0f172a; }
    html.dark .tva-lg-tab2 { color:#94a3b8; }
    html.dark .tva-lg-tab2:hover { background:#1e293b; color:#f1f5f9; }
    .tva-lg-tab2--on { background:#fff; color:rgb(var(--color-primary)); border-color:#e2e8f0; box-shadow:0 1px 3px rgba(15,23,42,.08); }
    html.dark .tva-lg-tab2--on { background:#1e293b; border-color:#334155; color:#93c5fd; }

    /* ── Colour tones for icon chips ───────────────────────────────────── */
    .tva-tone { display:inline-flex; align-items:center; justify-content:center; border-radius:11px; flex-shrink:0; }
    .tva-tone svg { width:18px !important; height:18px !important; }
    .tva-tone--blue    { background:#dbeafe; color:#1d4ed8; }  html.dark .tva-tone--blue    { background:#1e3a8a66; color:#93c5fd; }
    .tva-tone--indigo  { background:#e0e7ff; color:#4338ca; }  html.dark .tva-tone--indigo  { background:#312e8166; color:#a5b4fc; }
    .tva-tone--emerald { background:#d1fae5; color:#047857; }  html.dark .tva-tone--emerald { background:#064e3b66; color:#6ee7b7; }
    .tva-tone--amber   { background:#fef3c7; color:#b45309; }  html.dark .tva-tone--amber   { background:#78350f66; color:#fcd34d; }
    .tva-tone--rose    { background:#ffe4e6; color:#be123c; }  html.dark .tva-tone--rose    { background:#88133766; color:#fda4af; }
    .tva-tone--teal    { background:#ccfbf1; color:#0f766e; }  html.dark .tva-tone--teal    { background:#134e4a66; color:#5eead4; }
    .tva-tone--violet  { background:#ede9fe; color:#6d28d9; }  html.dark .tva-tone--violet  { background:#4c1d9566; color:#c4b5fd; }
    .tva-tone--slate   { background:#f1f5f9; color:#475569; }  html.dark .tva-tone--slate   { background:#33415588; color:#cbd5e1; }
    .tva-tone--green   { background:#dcfce7; color:#15803d; }  html.dark .tva-tone--green   { background:#14532d66; color:#86efac; }

    /* ── Overview: hero, steps, cards ──────────────────────────────────── */
    .tva-lg-hero { position:relative; overflow:hidden; margin-top:18px; border-radius:18px; padding:26px 28px; color:#fff;
                   background:radial-gradient(circle at 85% 20%, rgba(255,255,255,.18), transparent 40%),
                              linear-gradient(120deg, rgb(var(--color-primary)) 0%, #4f46e5 55%, #7c3aed 100%); }
    .tva-lg-hero h1 { font-size:22px; font-weight:800; line-height:1.25; }
    .tva-lg-hero p { margin-top:6px; font-size:13.5px; opacity:.92; max-width:620px; line-height:1.6; }
    .tva-lg-hero__row { display:flex; gap:24px; align-items:center; flex-wrap:wrap; }
    .tva-lg-hero__btns { display:flex; gap:10px; margin-top:18px; flex-wrap:wrap; }
    .tva-lg-btn-white { display:inline-flex; align-items:center; gap:8px; background:#fff; color:#1e293b; font-weight:700;
                        font-size:13px; padding:9px 16px; border-radius:10px; box-shadow:0 4px 14px rgba(15,23,42,.18); }
    .tva-lg-btn-white:hover { background:#f8fafc; }
    .tva-lg-btn-ghost { display:inline-flex; align-items:center; gap:8px; color:#fff; font-weight:600; font-size:13px;
                        padding:9px 16px; border-radius:10px; border:1px solid rgba(255,255,255,.45); }
    .tva-lg-btn-ghost:hover { background:rgba(255,255,255,.12); }
    .tva-lg-btn-white svg, .tva-lg-btn-ghost svg { width:16px !important; height:16px !important; }
    .tva-lg-ring { margin-left:auto; display:flex; align-items:center; gap:14px; background:rgba(255,255,255,.12);
                   border:1px solid rgba(255,255,255,.25); border-radius:14px; padding:14px 18px; }
    .tva-lg-ring__n { font-size:26px; font-weight:800; line-height:1; }
    .tva-lg-ring__l { font-size:11.5px; opacity:.85; margin-top:4px; }
    @media (max-width: 720px) { .tva-lg-ring { margin-left:0; } .tva-lg-hero { padding:22px 20px; } }

    .tva-lg-steps { display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:14px; margin-top:18px; }
    @media (max-width: 1280px) { .tva-lg-steps { grid-template-columns:repeat(3, minmax(0,1fr)); } }
    @media (max-width: 760px)  { .tva-lg-steps { grid-template-columns:1fr; } }
    .tva-lg-step { position:relative; background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; display:flex;
                   flex-direction:column; gap:8px; transition:.15s; }
    .tva-lg-step:hover { border-color:#cbd5e1; box-shadow:0 6px 18px -10px rgba(15,23,42,.25); }
    html.dark .tva-lg-step { background:#1e293b; border-color:#334155; }
    .tva-lg-step--done { border-color:#bbf7d0; }
    html.dark .tva-lg-step--done { border-color:#14532d; }
    .tva-lg-step__num { position:absolute; top:14px; right:14px; font-size:11px; font-weight:800; color:#94a3b8; }
    .tva-lg-step__t { font-size:13.5px; font-weight:700; color:#0f172a; }
    html.dark .tva-lg-step__t { color:#f1f5f9; }
    .tva-lg-step__d { font-size:12px; color:#64748b; line-height:1.55; flex:1; }
    html.dark .tva-lg-step__d { color:#94a3b8; }
    .tva-lg-step__cta { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; color:rgb(var(--color-primary)); }
    html.dark .tva-lg-step__cta { color:#93c5fd; }
    .tva-lg-step__cta svg, .tva-lg-step__ok svg { width:14px !important; height:14px !important; }
    .tva-lg-step__ok { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; color:#15803d; }
    html.dark .tva-lg-step__ok { color:#86efac; }

    .tva-lg-kpis { display:grid; grid-template-columns:repeat(6, minmax(0,1fr)); gap:14px; margin-top:18px; }
    @media (max-width: 1280px) { .tva-lg-kpis { grid-template-columns:repeat(3, minmax(0,1fr)); } }
    @media (max-width: 640px)  { .tva-lg-kpis { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    .tva-lg-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:10px; }
    html.dark .tva-lg-kpi { background:#1e293b; border-color:#334155; }
    .tva-lg-kpi__n { font-size:24px; font-weight:800; color:#0f172a; line-height:1; font-variant-numeric:tabular-nums; }
    html.dark .tva-lg-kpi__n { color:#f1f5f9; }
    .tva-lg-kpi__l { font-size:12px; color:#64748b; }
    a.tva-lg-kpi:hover { border-color:#cbd5e1; }

    .tva-lg-card-h { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
    .tva-lg-card-h b { font-size:13.5px; color:#0f172a; }
    html.dark .tva-lg-card-h b { color:#f1f5f9; }
    .tva-lg-card-h a { margin-left:auto; font-size:12px; font-weight:600; color:rgb(var(--color-primary)); }
    .tva-lg-item { display:flex; align-items:center; gap:12px; padding:11px 0; border-top:1px solid #f1f5f9; }
    .tva-lg-item:first-of-type { border-top:0; padding-top:2px; }
    html.dark .tva-lg-item { border-top-color:#334155; }
    .tva-lg-item__t { font-size:12.5px; font-weight:600; color:#1e293b; }
    html.dark .tva-lg-item__t { color:#e2e8f0; }
    .tva-lg-item__d { font-size:11.5px; color:#94a3b8; margin-top:1px; line-height:1.5; }
    .tva-lg-trust { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px; }
    @media (max-width: 1000px) { .tva-lg-trust { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    @media (max-width: 560px)  { .tva-lg-trust { grid-template-columns:1fr; } }
    .tva-lg-trust div { display:flex; gap:10px; align-items:flex-start; font-size:12px; color:#475569; line-height:1.5; }
    html.dark .tva-lg-trust div { color:#cbd5e1; }
</style>
