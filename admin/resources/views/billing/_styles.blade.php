{{-- Shared styling for every billing screen (overview, plans, checkout,
     invoice). Kept in one partial so the four pages can't drift apart.
     Light theme with html.dark overrides, matching the rest of the admin. --}}
<style>
    /* ── Cards ─────────────────────────────────────────────────────── */
    .bl-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:24px; }
    .bl-card + .bl-card { margin-top:20px; }
    .bl-card__head {
        display:flex; align-items:center; gap:10px; margin-bottom:18px;
        padding-bottom:14px; border-bottom:1px solid #e2e8f0;
    }
    .bl-card__title {
        font-size:12px; font-weight:800; color:#0f172a;
        text-transform:uppercase; letter-spacing:.07em;
    }
    .bl-card__action { margin-left:auto; }

    /* ── Current plan panel ────────────────────────────────────────────
       Restrained on purpose. A full-bleed purple gradient reads as a
       marketing banner; this is an account screen, so the surface stays
       white and the accent is a single hairline rail plus one very soft
       corner wash. Hierarchy comes from type and space, not saturation. */
    .bl-plan {
        position:relative; overflow:hidden; background:#fff;
        border:1px solid #e6eaf2; border-radius:18px; padding:30px 32px 26px;
        box-shadow:0 1px 2px rgba(16,24,40,.04), 0 12px 32px -24px rgba(16,24,40,.28);
    }
    .bl-plan::before {   /* the accent rail */
        content:''; position:absolute; left:0; top:0; bottom:0; width:4px;
        background:linear-gradient(180deg,#6366f1,#8b5cf6 55%,#a855f7);
    }
    .bl-plan::after {    /* barely-there wash, keeps it from feeling flat */
        content:''; position:absolute; right:-120px; top:-140px; width:340px; height:340px;
        border-radius:50%; pointer-events:none;
        background:radial-gradient(circle,rgba(99,102,241,.07),transparent 66%);
    }
    .bl-plan__inner { position:relative; z-index:1; }

    .bl-plan__top { display:flex; gap:26px; flex-wrap:wrap; align-items:flex-start; }
    .bl-plan__eyebrow {
        display:inline-flex; align-items:center; gap:7px;
        font-size:10px; font-weight:800; letter-spacing:.13em; text-transform:uppercase;
        color:#8b93a7; margin-bottom:9px;
    }
    .bl-plan__eyebrow::before { content:''; width:14px; height:1.5px; background:#c7cbd8; border-radius:2px; }
    .bl-plan__name {
        font-size:32px; font-weight:800; line-height:1.05; letter-spacing:-.025em; color:#0b1220;
        display:flex; align-items:center; gap:11px; flex-wrap:wrap;
    }
    .bl-plan__tagline { font-size:13.5px; color:#64748b; margin-top:8px; max-width:440px; line-height:1.6; }

    .bl-plan__price { margin-left:auto; text-align:right; padding-left:14px; }
    .bl-plan__amount {
        font-size:36px; font-weight:800; line-height:1; letter-spacing:-.03em; color:#0b1220;
        font-variant-numeric:tabular-nums;
    }
    .bl-plan__per { font-size:12px; font-weight:650; color:#8b93a7; margin-top:6px; letter-spacing:.01em; }
    .bl-plan__local { font-size:12px; color:#6366f1; margin-top:4px; font-variant-numeric:tabular-nums; }

    /* Stat strip: hairline dividers instead of boxes — reads as one object. */
    .bl-plan__stats {
        display:grid; gap:0; margin-top:26px; padding-top:22px; border-top:1px solid #eef1f6;
        grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
    }
    .bl-plan__stat { padding:2px 20px; border-left:1px solid #eef1f6; }
    .bl-plan__stat:first-child { padding-left:0; border-left:0; }
    .bl-plan__stat dt {
        font-size:9.5px; font-weight:800; letter-spacing:.11em; text-transform:uppercase;
        color:#9aa2b5; margin-bottom:6px;
    }
    .bl-plan__stat dd {
        margin:0; font-size:14px; font-weight:650; color:#0b1220;
        font-variant-numeric:tabular-nums; display:flex; align-items:center; gap:7px;
    }
    .bl-plan__stat dd small { font-weight:500; color:#8b93a7; font-size:12px; }

    .bl-plan__cta { display:flex; gap:10px; margin-top:24px; flex-wrap:wrap; }

    @media (max-width:620px) {
        .bl-plan { padding:24px 20px 22px; }
        .bl-plan__name { font-size:26px; }
        .bl-plan__amount { font-size:30px; }
        .bl-plan__price { margin-left:0; text-align:left; padding-left:0; margin-top:14px; }
        .bl-plan__stat { padding:10px 0; border-left:0; border-top:1px solid #eef1f6; }
        .bl-plan__stat:first-child { border-top:0; padding-top:0; }
    }

    /* Status pill sized for the headline row. */
    .bl-pill {
        display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:750;
        letter-spacing:.03em; padding:5px 11px; border-radius:999px; white-space:nowrap;
        border:1px solid transparent;
    }
    .bl-pill--live    { background:#ecfdf3; color:#067647; border-color:#abefc6; }
    .bl-pill--trial   { background:#eff6ff; color:#1849a9; border-color:#b2ddff; }
    .bl-pill--warn    { background:#fffaeb; color:#b54708; border-color:#fedf89; }
    .bl-pill--stopped { background:#fef3f2; color:#b42318; border-color:#fecdca; }
    .bl-pill--muted   { background:#f8fafc; color:#475467; border-color:#e4e7ec; }
    .bl-pill__dot { width:6px; height:6px; border-radius:50%; background:currentColor; }

    /* ── Badges ────────────────────────────────────────────────────── */
    .bl-badge {
        display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:800;
        letter-spacing:.06em; text-transform:uppercase; padding:4px 10px; border-radius:999px;
    }
    .bl-badge--green { background:#dcfce7; color:#15803d; }
    .bl-badge--blue  { background:#dbeafe; color:#1d4ed8; }
    .bl-badge--amber { background:#fef3c7; color:#b45309; }
    .bl-badge--red   { background:#fee2e2; color:#b91c1c; }
    .bl-badge--slate { background:#f1f5f9; color:#475569; }
    .bl-badge--onhero { background:rgba(255,255,255,.22); color:#fff; }

    /* ── Buttons ───────────────────────────────────────────────────── */
    .bl-btn {
        display:inline-flex; align-items:center; justify-content:center; gap:7px;
        font-size:13.5px; font-weight:650; padding:10px 18px; border-radius:11px;
        text-decoration:none; cursor:pointer; border:1px solid transparent;
        transition:filter .15s, transform .05s; white-space:nowrap;
    }
    .bl-btn:hover { filter:brightness(1.06); }
    .bl-btn:active { transform:translateY(1px); }
    .bl-btn--primary { background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); color:#fff; }
    .bl-btn--ghost   { background:#fff; border-color:#e2e8f0; color:#334155; }
    .bl-btn--danger  { background:#fff; border-color:#fecaca; color:#b91c1c; }
    .bl-btn--onhero  { background:#fff; color:#4f46e5; font-weight:700; }
    .bl-btn--onhero-ghost { background:rgba(255,255,255,.14); color:#fff; border-color:rgba(255,255,255,.35); }
    .bl-btn[disabled] { opacity:.55; cursor:not-allowed; }
    .bl-btn--sm { font-size:12px; padding:7px 12px; border-radius:9px; }

    /* ── Usage meters ──────────────────────────────────────────────── */
    .bl-meters { display:grid; gap:20px; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); }
    .bl-meter__top { display:flex; align-items:baseline; gap:8px; margin-bottom:8px; }
    .bl-meter__label { font-size:13px; font-weight:650; color:#334155; }
    .bl-meter__pct { margin-left:auto; font-size:12.5px; font-weight:700; color:#475569; font-variant-numeric:tabular-nums; }
    .bl-meter__bar { height:9px; border-radius:999px; background:#f1f5f9; overflow:hidden; }
    .bl-meter__fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#6366f1,#8b5cf6); transition:width .5s ease; }
    .bl-meter__fill--warn { background:linear-gradient(90deg,#f59e0b,#f97316); }
    .bl-meter__fill--over { background:linear-gradient(90deg,#ef4444,#dc2626); }
    .bl-meter__foot {
        display:flex; align-items:center; gap:6px; margin-top:7px;
        font-size:11.5px; color:#94a3b8; font-variant-numeric:tabular-nums;
    }
    .bl-meter__state { margin-left:auto; display:inline-flex; align-items:center; gap:4px; font-weight:700; }
    .bl-meter__state--warn { color:#b45309; }
    .bl-meter__state--over { color:#b91c1c; }
    .bl-meter__state--ok   { color:#15803d; }
    .bl-meter--unlimited .bl-meter__bar { background:repeating-linear-gradient(90deg,#eef2ff 0 8px,#f8fafc 8px 16px); }

    /* ── Included features ─────────────────────────────────────────── */
    .bl-incl { display:grid; gap:22px; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); }
    .bl-incl__group {
        font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase;
        color:#6366f1; margin-bottom:10px;
    }
    .bl-incl ul { list-style:none; margin:0; padding:0; }
    .bl-incl li { display:flex; gap:8px; font-size:13px; color:#475569; margin-bottom:8px; line-height:1.5; }
    .bl-incl .tick { color:#16a34a; flex:none; }

    /* ── Cards on file ─────────────────────────────────────────────── */
    .bl-pm {
        display:flex; align-items:center; gap:14px; padding:14px 16px;
        border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px; background:#fff;
    }
    .bl-pm--default { border-color:#6366f1; background:#eef2ff; }
    .bl-pm--expired { border-color:#fecaca; background:#fef2f2; }
    .bl-pm__brand {
        width:44px; height:30px; border-radius:6px; flex:none; display:flex;
        align-items:center; justify-content:center; background:#0f172a; color:#fff;
        font-size:9.5px; font-weight:800; letter-spacing:.03em; text-transform:uppercase;
    }
    .bl-pm__num { font-size:13.5px; font-weight:650; color:#0f172a; font-variant-numeric:tabular-nums; }
    .bl-pm__exp { font-size:11.5px; color:#94a3b8; margin-top:2px; }
    .bl-pm__actions { margin-left:auto; display:flex; gap:7px; align-items:center; flex-wrap:wrap; }

    /* ── Tables ────────────────────────────────────────────────────── */
    .bl-table { width:100%; border-collapse:collapse; font-size:13px; }
    .bl-table th, .bl-table td { text-align:left; padding:11px 12px; border-bottom:1px solid #f1f5f9; color:#475569; }
    .bl-table th { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; font-weight:800; }
    .bl-table td:last-child, .bl-table th:last-child { text-align:right; }
    .bl-table tbody tr:hover { background:#f8fafc; }
    .bl-amt { font-variant-numeric:tabular-nums; font-weight:650; color:#0f172a; }

    .bl-empty { text-align:center; padding:26px 10px; color:#94a3b8; font-size:13px; }
    .bl-empty i { display:block; margin:0 auto 8px; color:#cbd5e1; }
    .bl-note { font-size:11.5px; color:#94a3b8; line-height:1.6; margin-top:12px; }

    /* ── Alerts ────────────────────────────────────────────────────── */
    .bl-alert { border-radius:12px; padding:14px 16px; font-size:13.5px; display:flex; gap:11px; margin-bottom:20px; line-height:1.55; }
    .bl-alert--warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .bl-alert--info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; }
    .bl-alert--err  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .bl-alert--ok   { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    .bl-alert strong { display:block; margin-bottom:2px; }

    .bl-grid { display:grid; gap:20px; grid-template-columns:1fr; }
    @media (min-width:1180px) { .bl-grid { grid-template-columns:1.45fr 1fr; align-items:start; } }

    /* ── Dark mode ─────────────────────────────────────────────────── */
    html.dark .bl-card { background:#1e293b; border-color:#334155; }
    html.dark .bl-card__head { border-color:#334155; }
    html.dark .bl-card__title, html.dark .bl-amt, html.dark .bl-pm__num { color:#f1f5f9; }
    html.dark .bl-table th, html.dark .bl-table td { border-color:#334155; }
    html.dark .bl-table tbody tr:hover { background:#0f172a; }
    html.dark .bl-btn--ghost { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .bl-meter__bar { background:#334155; }
    html.dark .bl-meter__label { color:#cbd5e1; }
    html.dark .bl-pm { background:#0f172a; border-color:#334155; }
    html.dark .bl-pm--default { background:#312e81; border-color:#6366f1; }
    html.dark .bl-incl li { color:#94a3b8; }

    html.dark .bl-plan { background:#1e293b; border-color:#334155; box-shadow:none; }
    html.dark .bl-plan__name, html.dark .bl-plan__amount, html.dark .bl-plan__stat dd { color:#f8fafc; }
    html.dark .bl-plan__stats, html.dark .bl-plan__stat { border-color:#334155; }
    html.dark .bl-pill--live    { background:rgba(6,118,71,.18);  color:#6ee7b7; border-color:rgba(110,231,183,.3); }
    html.dark .bl-pill--trial   { background:rgba(24,73,169,.22);  color:#93c5fd; border-color:rgba(147,197,253,.3); }
    html.dark .bl-pill--warn    { background:rgba(181,71,8,.2);    color:#fdba74; border-color:rgba(253,186,116,.3); }
    html.dark .bl-pill--stopped { background:rgba(180,35,24,.2);   color:#fca5a5; border-color:rgba(252,165,165,.3); }
    html.dark .bl-pill--muted   { background:#0f172a; color:#cbd5e1; border-color:#334155; }
</style>
