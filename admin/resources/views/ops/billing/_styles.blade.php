{{--
    Shared styling for every Super Admin → Billing screen.

    Written to match the rest of the ops console rather than the customer app:
    the amber accent from layouts/ops (--tva-accent: #ffb800), the same card
    metrics as ops/seo, and the same field pattern. The earlier version used the
    customer theme's indigo gradient and its own input sizes, which is why these
    pages looked like a different product bolted on.

    THE ALIGNMENT RULE: every interactive control — input, select, button — is
    exactly --ops-h tall and vertically centred. Previously inputs were sized by
    padding and buttons by their own padding, so a control sitting next to
    another was a few pixels taller and everything in the row looked crooked.
    One variable now governs all of them.
--}}
<style>
    :root { --ops-h: 38px; --ops-h-sm: 32px; }

    /* ── Cards ─────────────────────────────────────────────────────── */
    .ob-card {
        background:#fff; border:1px solid #e2e8f0; border-radius:14px;
        padding:22px 24px; margin-bottom:16px;
    }
    .ob-card__head {
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
        margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0;
    }
    .ob-card__title {
        font-size:15px; font-weight:700; color:#0f172a;
        display:flex; align-items:center; gap:8px;
    }
    .ob-card__sub { font-size:12px; color:#64748b; margin-top:3px; }
    .ob-card__actions { margin-left:auto; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

    /* ── Fields ────────────────────────────────────────────────────── */
    .ob-field { margin-bottom:14px; min-width:0; }
    .ob-field > label {
        display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:6px;
    }
    .ob-field .hint { font-size:11px; color:#94a3b8; font-weight:400; margin-left:6px; }
    .ob-help { font-size:11px; color:#94a3b8; margin-top:5px; line-height:1.55; }

    .ob-input, .ob-select, .ob-textarea {
        width:100%; height:var(--ops-h); padding:0 12px;
        border:1px solid #e2e8f0; border-radius:9px; background:#fff;
        font-size:13px; color:#0f172a; font-family:inherit;
        transition:border-color .12s, box-shadow .12s;
    }
    .ob-textarea { height:auto; min-height:88px; padding:10px 12px; resize:vertical; line-height:1.55; }
    .ob-input:focus, .ob-select:focus, .ob-textarea:focus {
        outline:none; border-color:var(--tva-accent); box-shadow:0 0 0 3px rgba(255,184,0,.15);
    }
    .ob-input:disabled, .ob-select:disabled { background:#f8fafc; color:#94a3b8; }
    .ob-input--sm, .ob-select--sm { height:var(--ops-h-sm); font-size:12.5px; padding:0 10px; }
    .ob-input--num { text-align:right; font-variant-numeric:tabular-nums; }

    /* Prefix affordance for money inputs: "$" sits inside the control so the
       number lines up with every other number in the column. */
    .ob-money { position:relative; }
    .ob-money > span {
        position:absolute; left:11px; top:0; height:100%; display:flex; align-items:center;
        font-size:13px; color:#94a3b8; pointer-events:none; font-weight:600;
    }
    .ob-money .ob-input { padding-left:24px; }

    /* ── Buttons: same height as inputs, so rows never look crooked ── */
    .ob-btn {
        display:inline-flex; align-items:center; justify-content:center; gap:7px;
        height:var(--ops-h); padding:0 15px; border-radius:9px; cursor:pointer;
        font-size:13px; font-weight:600; line-height:1; white-space:nowrap;
        border:1px solid #e2e8f0; background:#fff; color:#334155;
        text-decoration:none; transition:filter .12s, border-color .12s, background .12s;
    }
    .ob-btn:hover { border-color:#cbd5e1; background:#f8fafc; }
    .ob-btn--primary {
        background:var(--tva-gradient); border-color:transparent; color:#fff;
    }
    .ob-btn--primary:hover { filter:brightness(1.06); background:var(--tva-gradient); }
    .ob-btn--warn   { background:#fffbeb; border-color:#fde68a; color:#b45309; }
    .ob-btn--warn:hover { background:#fef3c7; }
    .ob-btn--danger { background:#fff; border-color:#fecaca; color:#dc2626; }
    .ob-btn--danger:hover { background:#fef2f2; }
    .ob-btn--sm { height:var(--ops-h-sm); padding:0 11px; font-size:12px; gap:5px; }
    .ob-btn--icon { width:var(--ops-h-sm); height:var(--ops-h-sm); padding:0; }
    .ob-btn[disabled] { opacity:.5; cursor:not-allowed; }

    /* A form used inline in a row must not introduce its own block layout. */
    .ob-inline { display:inline-flex; align-items:center; gap:8px; margin:0; }

    /* ── Pills ─────────────────────────────────────────────────────── */
    .ob-pill {
        display:inline-flex; align-items:center; gap:5px; font-size:10px; font-weight:800;
        letter-spacing:.06em; text-transform:uppercase; padding:3px 8px; border-radius:999px;
        white-space:nowrap; border:1px solid transparent;
    }
    .ob-pill--on      { background:#ecfdf3; color:#067647; border-color:#abefc6; }
    .ob-pill--off     { background:#fef3f2; color:#b42318; border-color:#fecdca; }
    .ob-pill--muted   { background:#f8fafc; color:#475467; border-color:#e4e7ec; }
    .ob-pill--accent  { background:var(--tva-gradient); color:#fff; }
    .ob-pill--info    { background:#eff6ff; color:#1849a9; border-color:#b2ddff; }
    .ob-pill--warn    { background:#fffaeb; color:#b54708; border-color:#fedf89; }
    .ob-pill--purple  { background:#f4f3ff; color:#5925dc; border-color:#d9d6fe; }

    /* Colour-named aliases, used by the subscriptions table where the meaning
       varies per column. Same swatches as the semantic names above so the two
       vocabularies can't drift into different greens. */
    .ob-pill--green { background:#ecfdf3; color:#067647; border-color:#abefc6; }
    .ob-pill--red   { background:#fef3f2; color:#b42318; border-color:#fecdca; }
    .ob-pill--amber { background:#fffaeb; color:#b54708; border-color:#fedf89; }
    .ob-pill--blue  { background:#eff6ff; color:#1849a9; border-color:#b2ddff; }
    .ob-pill--slate { background:#f8fafc; color:#475467; border-color:#e4e7ec; }

    /* Stripe event outcomes — named after the status column they render. */
    .ob-pill--processed { background:#ecfdf3; color:#067647; border-color:#abefc6; }
    .ob-pill--skipped   { background:#f8fafc; color:#475467; border-color:#e4e7ec; }
    .ob-pill--failed    { background:#fef3f2; color:#b42318; border-color:#fecdca; }
    .ob-pill--pending   { background:#fffaeb; color:#b54708; border-color:#fedf89; }
    .ob-pill--test      { background:#f4f3ff; color:#5925dc; border-color:#d9d6fe; }

    /* Standalone label above a filter control (no .ob-field wrapper). */
    .ob-field-label {
        display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:6px;
    }
    html.dark .ob-field-label { color:#cbd5e1; }

    /* ── Tables ────────────────────────────────────────────────────── */
    .ob-tablewrap { overflow-x:auto; }
    .ob-table { width:100%; border-collapse:collapse; font-size:13px; }
    .ob-table th, .ob-table td {
        text-align:left; padding:11px 12px; border-bottom:1px solid #f1f5f9;
        color:#475569; vertical-align:middle;
    }
    .ob-table th {
        font-size:10.5px; text-transform:uppercase; letter-spacing:.06em;
        color:#94a3b8; font-weight:800; white-space:nowrap;
    }
    .ob-table tbody tr:hover { background:#fcfcfd; }
    .ob-table td:first-child, .ob-table th:first-child { padding-left:0; }
    .ob-table td:last-child,  .ob-table th:last-child  { padding-right:0; text-align:right; }
    .ob-table .is-dim { opacity:.55; }
    .ob-amt { font-variant-numeric:tabular-nums; font-weight:700; color:#0f172a; }
    .ob-ref { font-family:ui-monospace,monospace; font-size:10.5px; color:#94a3b8; word-break:break-all; }

    /* Right-hand action column: one row, consistent gaps, never wrapping
       mid-control. */
    .ob-rowactions { display:flex; gap:7px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }

    /* ── Banners ───────────────────────────────────────────────────── */
    .ob-note {
        display:flex; gap:11px; border-radius:11px; padding:13px 15px;
        font-size:13px; line-height:1.6; margin-bottom:16px;
    }
    .ob-note i { flex:none; margin-top:1px; }
    .ob-note--info { background:#f0f9ff; border:1px solid #bae6fd; color:#075985; }
    .ob-note--warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .ob-note--err  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .ob-note code {
        font-family:ui-monospace,monospace; font-size:12px;
        background:rgba(0,0,0,.05); padding:1px 5px; border-radius:4px;
    }

    .ob-empty { text-align:center; padding:34px 16px; color:#94a3b8; font-size:13px; }
    .ob-empty i { display:block; margin:0 auto 10px; color:#cbd5e1; }

    /* ── Grids ─────────────────────────────────────────────────────── */
    .ob-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .ob-grid3 { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
    .ob-grid4 { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; }
    @media (max-width:900px){ .ob-grid3, .ob-grid4 { grid-template-columns:repeat(2,1fr); } }
    @media (max-width:640px){ .ob-grid2, .ob-grid3, .ob-grid4 { grid-template-columns:1fr; } }

    /* Checkbox rows sized so they line up with a field of the same height. */
    .ob-check {
        display:flex; align-items:flex-start; gap:9px; font-size:13px; color:#334155;
        line-height:1.5; cursor:pointer;
    }
    .ob-check input { width:16px; height:16px; margin-top:2px; flex:none; accent-color:#c97a00; }
    .ob-check b { font-weight:600; color:#0f172a; }
    .ob-check span.sub { display:block; font-size:11px; color:#94a3b8; margin-top:2px; }
    /* Sits a checkbox group at the bottom of a grid cell whose siblings are
       label-over-input fields, so their baselines agree. */
    .ob-field--checks { display:flex; flex-direction:column; justify-content:flex-end; gap:9px; }

    html.dark .ob-card { background:#1e293b; border-color:#334155; }
    html.dark .ob-card__head { border-color:#334155; }
    html.dark .ob-card__title, html.dark .ob-amt, html.dark .ob-check b { color:#f1f5f9; }
    html.dark .ob-field > label, html.dark .ob-check { color:#cbd5e1; }
    html.dark .ob-input, html.dark .ob-select, html.dark .ob-textarea {
        background:#0f172a; border-color:#334155; color:#f1f5f9;
    }
    html.dark .ob-btn { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .ob-btn:hover { background:#162032; }
    html.dark .ob-table th, html.dark .ob-table td { border-color:#334155; }
    html.dark .ob-table tbody tr:hover { background:#0f172a; }
</style>
