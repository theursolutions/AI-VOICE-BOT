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
    .tva-lg-pill { display:inline-block; font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:999px; white-space:nowrap; }
    .tva-lg-row .tva-lg-pill { margin-left:auto; }
    .tva-lg-pill--ok    { background:#dcfce7; color:#15803d; }
    .tva-lg-pill--off   { background:#f1f5f9; color:#64748b; }
    .tva-lg-pill--run   { background:#dbeafe; color:#1d4ed8; }
    .tva-lg-pill--warn  { background:#fef3c7; color:#b45309; }
    .tva-lg-pill--bad   { background:#fee2e2; color:#b91c1c; }
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
</style>
