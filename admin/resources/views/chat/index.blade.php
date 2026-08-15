@extends('layouts.master')

@section('content')
<style>
    /* The `hidden` attribute is enforced only by the UA stylesheet's
       `[hidden] { display:none }` — the weakest rule there is. Any class that
       sets `display` silently beats it, so .tva-fp (flex), .tva-af (flex) and
       .tva-qf__badge (inline-flex) all rendered while still reporting
       `el.hidden === true`. That is why the filter panel sat open on load and
       ignored every click: the JS toggle was working perfectly and the CSS
       was overruling it, so nothing in the toggle logic looked wrong.
       One rule fixes all of them, and any future [hidden] in this component. */
    .tva-chat [hidden] { display:none !important; }

    /* ── Shell: fixed height, only the thread scrolls ── */
    .tva-chat { display:flex; height: calc(100vh - 160px); min-height:520px; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden; background:#fff; margin-top:14px; }
    html.dark .tva-chat { background:#0f172a; border-color:#334155; }

    .tva-chat__list { width:330px; min-width:290px; border-right:1px solid #e2e8f0; display:flex; flex-direction:column; min-height:0; }
    html.dark .tva-chat__list { border-right-color:#334155; }
    .tva-chat__listhead { padding:12px 14px; border-bottom:1px solid #e2e8f0; flex:0 0 auto; position:relative; }
    html.dark .tva-chat__listhead { border-bottom-color:#334155; }
    .tva-seg { display:flex; background:#f1f5f9; border-radius:10px; padding:3px; gap:2px; }
    html.dark .tva-seg { background:#0f172a; }
    .tva-seg button { flex:1; border:none; background:transparent; font-size:12px; font-weight:600; color:#64748b; padding:6px 6px; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:5px; transition:.12s; }
    .tva-seg button.is-active { background:#fff; color:#4f46e5; box-shadow:0 1px 3px rgba(0,0,0,.12); }
    html.dark .tva-seg button.is-active { background:#334155; color:#fff; }
    .tva-seg button { position:relative; }
    /* Lucide writes its own width/height attributes onto the <svg> it swaps
       in, which beat the Tailwind w-3.5/h-3.5 classes — hence icons rendering
       far larger than the 12px label beside them. Sized here, where the
       attributes cannot win. */
    .tva-seg button svg { width:12px !important; height:12px !important; flex-shrink:0; }
    .tva-seg__n { font-size:10px; font-weight:700; opacity:.7; font-variant-numeric:tabular-nums; }
    .tva-seg__n:empty { display:none; }

    /* ── Quick filters ───────────────────────────────────────────────
       One line, no wrap. The column gives this row 302px (330px less 14px
       padding either side); four LABELLED chips wanted ~374px, which is why
       the last one used to be pushed out past the border. Three 28px icon
       toggles plus the labelled Filters button come to ~180px, so it fits
       with room to spare at any sane column width. */
    .tva-qf { display:flex; align-items:center; gap:6px; margin-top:9px; }
    /* Pins Filters right without a spacer div. */
    #filterBtn { margin-left:auto; }

    .tva-qf__ico { position:relative; width:28px; height:28px; flex-shrink:0;
                   display:inline-flex; align-items:center; justify-content:center;
                   border:1px solid #e2e8f0; background:#fff; color:#64748b;
                   border-radius:8px; cursor:pointer; transition:.12s; }
    .tva-qf__ico svg { width:14px !important; height:14px !important; }
    .tva-qf__ico:hover { border-color:#c7d2fe; color:#4f46e5; }
    .tva-qf__ico.is-on { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .tva-qf__ico--alert.is-on { background:#dc2626; border-color:#dc2626; }
    html.dark .tva-qf__ico { background:#1e293b; border-color:#334155; color:#cbd5e1; }

    /* Count rides the corner of the icon. Only rendered when non-zero, so the
       row stays quiet until it actually has news. */
    .tva-qf__ico .tva-qf__n { position:absolute; top:-5px; right:-5px; min-width:15px; height:15px;
                              padding:0 3.5px; border-radius:999px; background:#4f46e5; color:#fff;
                              font-size:9px; font-weight:700; line-height:15px; text-align:center;
                              box-shadow:0 0 0 2px #fff; }
    .tva-qf__ico--alert .tva-qf__n { background:#dc2626; }
    .tva-qf__ico.is-on .tva-qf__n { background:#0f172a; }
    html.dark .tva-qf__ico .tva-qf__n { box-shadow:0 0 0 2px #0f172a; }
    .tva-qf__chip { display:inline-flex; align-items:center; gap:5px; border:1px solid #e2e8f0;
                    background:#fff; color:#475569; font-size:11.5px; font-weight:600;
                    padding:5px 9px; border-radius:999px; cursor:pointer; transition:.12s; white-space:nowrap; }
    .tva-qf__chip:hover { border-color:#c7d2fe; color:#4f46e5; }
    .tva-qf__chip.is-on { background:#eef2ff; border-color:#a5b4fc; color:#4338ca; }
    .tva-qf__chip svg { width:12px !important; height:12px !important; flex-shrink:0; }
    html.dark .tva-qf__chip { background:#1e293b; border-color:#334155; color:#cbd5e1; }
    html.dark .tva-qf__chip.is-on { background:#312e81; border-color:#4f46e5; color:#e0e7ff; }

    /* A bare funnel glyph gave no clue what it did or that it was a menu.
       Labelled, with a chevron that turns when open. */
    .tva-qf__chip--more { padding:5px 9px; gap:4px; }
    .tva-qf__chip--more .tva-qf__caret { transition:transform .15s; opacity:.6; }
    .tva-qf__chip--more.is-open .tva-qf__caret { transform:rotate(180deg); }
    .tva-qf__chip--more.is-open { background:#eef2ff; border-color:#a5b4fc; color:#4338ca; }

    .tva-qf__n { font-size:10px; font-weight:700; opacity:.8; font-variant-numeric:tabular-nums; }
    .tva-qf__n:empty { display:none; }
    /* How many filters are on, inline in the button rather than floating off
       its corner — a notification-style dot read as an alert, when all it is
       reporting is a count. */
    .tva-qf__badge { min-width:15px; height:15px; padding:0 4px; border-radius:999px;
                     background:#4f46e5; color:#fff; font-size:9.5px; font-weight:700;
                     display:inline-flex; align-items:center; justify-content:center; }

    /* ── Active filter pills ───────────────────────────────────────── */
    .tva-af { display:flex; flex-wrap:wrap; gap:5px; margin-top:8px; align-items:center; }
    /* Outlined and neutral, not filled indigo. These are a record of what is
       already applied, so they should sit quietly behind the controls that
       change it — a row of saturated pills competed with the chips above and
       made an ordinary two-filter view look like an error state. */
    .tva-af__pill { display:inline-flex; align-items:center; gap:2px; background:#f8fafc;
                    border:1px solid #e2e8f0; color:#475569; border-radius:6px;
                    padding:2px 3px 2px 8px; font-size:10.5px; font-weight:600; line-height:1.7;
                    max-width:100%; min-width:0; overflow:hidden; white-space:nowrap; }
    .tva-af__pill em { font-style:normal; color:#94a3b8; font-weight:600; margin-right:3px; }
    .tva-af__pill button { border:none; background:transparent; color:#94a3b8; cursor:pointer; line-height:0;
                           padding:2px; border-radius:4px; display:flex; }
    .tva-af__pill button svg { width:11px !important; height:11px !important; }
    .tva-af__pill button:hover { color:#dc2626; background:#fef2f2; }
    .tva-af__clear { border:none; background:transparent; color:#64748b; font-size:10.5px; font-weight:600;
                     cursor:pointer; padding:2px 4px; border-radius:4px; }
    .tva-af__clear:hover { color:#4f46e5; background:#eef2ff; }
    html.dark .tva-af__pill { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .tva-af__pill em { color:#64748b; }

    /* ── Filter panel ──────────────────────────────────────────────── */
    /* Spans the list column exactly — left AND right pinned, no fixed width.
       It was 430px anchored to the right inside a 330px column, so it
       overhung the left edge and .tva-chat's overflow:hidden sliced it off.
       Pinning both sides makes clipping structurally impossible at any
       column width. */
    .tva-fp { position:absolute; z-index:40; left:14px; right:14px; top:100%; margin-top:4px;
              background:#fff; border:1px solid #e2e8f0; border-radius:12px;
              box-shadow:0 12px 32px rgba(15,23,42,.14); overflow:hidden;
              max-height:min(62vh, 390px); display:flex; flex-direction:column; }
    html.dark .tva-fp { background:#1e293b; border-color:#334155; }
    /* One column: at 300px there is no honest way to fit two without every
       label wrapping.
     *
     * flex:1 1 auto AND min-height:0 are both required for overflow-y to do
     * anything here. A flex item defaults to min-height:auto, which refuses to
     * shrink below its content — so the scrollbar never appeared, the groups
     * kept their full height, and the footer was pushed past .tva-fp's
     * overflow:hidden edge where Clear and Done could not be clicked. */
    .tva-fp__grid { flex:1 1 auto; min-height:0; overflow-y:auto; padding:11px 12px 3px;
                    overscroll-behavior:contain; }
    .tva-fp__group { margin-bottom:9px; min-width:0; }
    .tva-fp__group:last-child { margin-bottom:4px; }
    /* Thin scrollbar so the panel doesn't lose 15px of label width to it. */
    .tva-fp__grid::-webkit-scrollbar { width:7px; }
    .tva-fp__grid::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:99px; }
    html.dark .tva-fp__grid::-webkit-scrollbar-thumb { background:#475569; }
    .tva-fp__h { font-size:9px; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
                 color:#94a3b8; margin-bottom:5px; }
    /* min-width:0 on the container lets its flex children actually shrink;
       without it a long label (a Page name, a custom status) makes the button
       wider than the panel and it spills past the sidebar edge. */
    .tva-fp__opts { display:flex; flex-wrap:wrap; gap:4px; min-width:0; }
    .tva-fp__opts button { display:inline-flex; align-items:center; gap:5px; border:1px solid #e2e8f0;
                           background:#f8fafc; color:#475569; font-size:11px; font-weight:600;
                           padding:4px 8px; border-radius:7px; cursor:pointer; transition:.12s;
                           max-width:100%; min-width:0; overflow:hidden; }
    /* The label is the only part allowed to be truncated — the colour dot and
       the count stay whole, because a clipped number is worse than no number. */
    .tva-fp__opts button > span:not([class]) { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
    .tva-fp__opts button:hover { border-color:#c7d2fe; }
    .tva-fp__opts button.is-on { background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .tva-fp__opts button svg { width:12px !important; height:12px !important; flex-shrink:0; }
    .tva-fp__opts button b { font-weight:700; opacity:.65; font-variant-numeric:tabular-nums; }
    .tva-fp__opts button b:empty { display:none; }
    /* A zero-count option stays clickable but stops competing for attention. */
    .tva-fp__opts button.is-empty:not(.is-on) { opacity:.45; }
    html.dark .tva-fp__opts button { background:#0f172a; border-color:#334155; color:#cbd5e1; }

    .tva-empty-sm { text-align:center; font-size:11.5px; color:#94a3b8; padding:28px 18px; line-height:1.6; }
    /* Same four colours in the list rows and the Status filter, so a dot
       means exactly one thing wherever it appears. */
    .tva-st { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
    .tva-st--active { background:#22c55e; } .tva-st--expiring { background:#f59e0b; }
    .tva-st--expired { background:#ef4444; } .tva-st--closed { background:#94a3b8; }

    /* flex:0 0 auto pins the footer: it must never be the thing that shrinks
       or gets pushed out, because it holds the only two ways to leave. */
    .tva-fp__foot { flex:0 0 auto; display:flex; align-items:center; justify-content:space-between; gap:8px;
                    padding:8px 12px; border-top:1px solid #e2e8f0; background:#f8fafc; }
    html.dark .tva-fp__foot { border-top-color:#334155; background:#0f172a; }
    .tva-fp__clear { border:none; background:transparent; color:#64748b; font-size:11.5px; font-weight:600; cursor:pointer; }
    .tva-fp__clear:hover { color:#dc2626; }

    .tva-search { position:relative; margin-top:9px; }
    .tva-search > i, .tva-search > svg { position:absolute; left:11px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:#94a3b8; pointer-events:none; z-index:1; }
    .tva-search input { padding-left:34px !important; }
    /* overflow-x:hidden, not auto. A long row tag or customer name would
       otherwise mint a horizontal scrollbar across the whole list — the one
       visible under the conversations in the reported screenshot. */
    .tva-chat__convos { overflow-y:auto; overflow-x:hidden; flex:1 1 auto; min-height:0; }
    .tva-convo { display:flex; gap:10px; padding:11px 14px; cursor:pointer; border-bottom:1px solid #f1f5f9; align-items:center; }
    html.dark .tva-convo { border-bottom-color:#1e293b; }
    .tva-convo:hover { background:#f8fafc; } html.dark .tva-convo:hover { background:#1e293b; }
    .tva-convo.is-active { background:#eef2ff; } html.dark .tva-convo.is-active { background:#1e293b; }
    .tva-convo__av { width:42px; height:42px; border-radius:50%; background:var(--tva-gradient); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:600; flex-shrink:0; overflow:hidden; }
    .tva-convo__av img { width:100%; height:100%; object-fit:cover; }
    /* Initials fill the disc themselves so they can carry their own colour —
       the parent's gradient would otherwise show through behind them. */
    .tva-convo__ini { width:100%; height:100%; display:flex; align-items:center; justify-content:center;
                      font-size:14px; font-weight:600; letter-spacing:.02em; }
    .tva-convo__name { font-weight:600; font-size:13.5px; color:#0f172a; } html.dark .tva-convo__name { color:#f1f5f9; }
    .tva-convo__last { font-size:12px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:185px; }
    .tva-badge { font-size:9px; font-weight:700; padding:1px 6px; border-radius:999px; text-transform:uppercase; }
    .tva-badge--whatsapp { background:#dcfce7; color:#15803d; } .tva-badge--instagram { background:#fce7f3; color:#be185d; }
    .tva-badge--facebook,.tva-badge--messenger { background:#dbeafe; color:#1d4ed8; }
    .tva-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .tva-dot--unread { background:#6366f1; } .tva-dot--open { background:#22c55e; } .tva-dot--closed { background:#ef4444; }

    /* ── Header metrics ───────────────────────────────────────────────
       Icon + value only. Four numbers fit in a header exactly as long as
       none of them spells itself out; every chip carries its full wording in
       a title attribute instead. */
    .tva-mx { display:flex; align-items:center; gap:5px; }
    /* Label above value. The extra 8px line is what makes the strip readable
       without hovering anything — four bare numbers with only icons to tell
       them apart could not be. */
    .tva-mx__c { position:relative; overflow:hidden; display:inline-flex; align-items:center; gap:5px;
                 background:#f8fafc; border:1px solid #e8edf3; border-left-width:2px; border-radius:7px;
                 padding:3px 8px; white-space:nowrap; cursor:default; }
    .tva-mx__t { display:flex; flex-direction:column; line-height:1.15; }
    .tva-mx__lbl { font-size:8px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
                   color:#94a3b8; }
    .tva-mx__v { font-size:11.5px; font-weight:700; color:#334155; font-variant-numeric:tabular-nums; }
    .tva-mx__c svg { width:13px !important; height:13px !important; flex-shrink:0; }
    html.dark .tva-mx__c { background:#0f172a; border-color:#334155; }
    html.dark .tva-mx__v { color:#e2e8f0; }
    html.dark .tva-mx__lbl { color:#64748b; }

    /* A distinct accent per metric — the left border and the icon only, never
       the fill. Enough to tell them apart at a glance without any of them
       competing with the deadline for alarm. */
    .tva-mx__c--started { border-left-color:#94a3b8; } .tva-mx__c--started svg { color:#94a3b8; }
    .tva-mx__c--first   { border-left-color:#8b5cf6; } .tva-mx__c--first svg   { color:#8b5cf6; }
    .tva-mx__c--rate    { border-left-color:#0d9488; } .tva-mx__c--rate svg    { color:#0d9488; }
    .tva-mx__c--lead    { border-left-color:#4f46e5; } .tva-mx__c--lead svg    { color:#4f46e5; }
    .tva-mx__c--lead .tva-mx__v { text-transform:capitalize; }

    /* Only the reply-window chip fills with colour. If everything signalled
       urgency, nothing would. */
    .tva-mx__c.is-ok   { background:#f0fdf4; border-color:#bbf7d0; border-left-color:#22c55e; }
    .tva-mx__c.is-ok   svg, .tva-mx__c.is-ok .tva-mx__lbl { color:#15803d; }
    .tva-mx__c.is-ok   .tva-mx__v { color:#166534; }
    .tva-mx__c.is-warn { background:#fffbeb; border-color:#fde68a; border-left-color:#f59e0b; }
    .tva-mx__c.is-warn svg, .tva-mx__c.is-warn .tva-mx__lbl { color:#b45309; }
    .tva-mx__c.is-warn .tva-mx__v { color:#92400e; }
    .tva-mx__c.is-dead { background:#fef2f2; border-color:#fecaca; border-left-color:#ef4444; }
    .tva-mx__c.is-dead svg, .tva-mx__c.is-dead .tva-mx__lbl { color:#b91c1c; }
    .tva-mx__c.is-dead .tva-mx__v { color:#991b1b; }

    /* Fraction of the 24 hours still left — the trend, without arithmetic. */
    .tva-mx__bar { position:absolute; left:0; bottom:0; height:2px; background:currentColor; opacity:.45;
                   transition:width 1s linear; }

    /* Blinks once a second. Without it a slow-moving number is indistinguishable
       from one that failed to load — this is the cheapest possible proof the
       clock is running. */
    .tva-mx__pulse { width:5px; height:5px; border-radius:50%; background:currentColor; flex-shrink:0;
                     animation:tvaPulse 1s ease-in-out infinite; }
    @keyframes tvaPulse { 0%,100% { opacity:.25; } 50% { opacity:1; } }
    @media (prefers-reduced-motion: reduce) {
        .tva-mx__pulse { animation:none; opacity:.7; }
        .tva-mx__bar { transition:none; }
    }

    @media (max-width: 1180px) {
        /* Narrow windows keep the deadline and drop the context. */
        /* Keyed on the metric, not on its tone: the window chip is the one to
           keep, and matching "has no tone class" would also drop it in the
           moment before the first tick applies one. */
        .tva-mx__c:not(.tva-mx__c--window) { display:none; }
    }

    /* ── Conversation status control ──────────────────────────────── */
    .tva-cs { position:relative; }
    .tva-cs__btn { display:inline-flex; align-items:center; gap:6px; border:1px solid #e2e8f0; background:#fff;
                   border-radius:8px; padding:5px 9px; font-size:11.5px; font-weight:600; color:#475569;
                   cursor:pointer; transition:.12s; max-width:190px; }
    .tva-cs__btn:hover { border-color:#c7d2fe; color:#4f46e5; }
    .tva-cs__btn svg { width:12px !important; height:12px !important; opacity:.6; flex-shrink:0; }
    .tva-cs__dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
    html.dark .tva-cs__btn { background:#1e293b; border-color:#334155; color:#cbd5e1; }

    .tva-cs__menu { position:absolute; z-index:45; right:0; top:100%; margin-top:5px; min-width:216px;
                    background:#fff; border:1px solid #e2e8f0; border-radius:11px; padding:5px;
                    box-shadow:0 12px 30px rgba(15,23,42,.15); }
    html.dark .tva-cs__menu { background:#1e293b; border-color:#334155; }
    .tva-cs__opt { display:flex; align-items:center; gap:8px; width:100%; border:none; background:transparent;
                   font-size:12px; font-weight:600; color:#475569; padding:7px 9px; border-radius:7px;
                   cursor:pointer; text-align:left; }
    .tva-cs__opt:hover { background:#f1f5f9; }
    .tva-cs__opt.is-on { background:#eef2ff; color:#4338ca; }
    .tva-cs__opt--clear { color:#94a3b8; font-weight:500; }
    html.dark .tva-cs__opt { color:#cbd5e1; } html.dark .tva-cs__opt:hover { background:#0f172a; }
    .tva-cs__tag { font-size:8.5px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
                   color:#64748b; background:#e2e8f0; padding:1px 5px; border-radius:4px; }
    .tva-cs__sep { height:1px; background:#e2e8f0; margin:4px 0; }
    html.dark .tva-cs__sep { background:#334155; }
    .tva-cs__manage { display:flex; align-items:center; gap:7px; width:100%; border:none; background:transparent;
                      font-size:11.5px; font-weight:600; color:#4f46e5; padding:7px 9px; border-radius:7px; cursor:pointer; }
    .tva-cs__manage:hover { background:#eef2ff; }
    .tva-cs__manage svg { width:12px !important; height:12px !important; }

    .tva-cs__ini { width:20px; height:20px; border-radius:50%; background:#e0e7ff; color:#4338ca;
                   font-size:8.5px; font-weight:700; display:flex; align-items:center; justify-content:center;
                   flex-shrink:0; }
    .tva-cs__sub { display:flex; align-items:center; gap:4px; font-size:9.5px; font-weight:500;
                   color:#94a3b8; text-transform:capitalize; margin-top:1px; }
    .tva-cs__none { font-size:11px; color:#94a3b8; padding:10px 11px; line-height:1.6; }

    /* ── Row tags: handler · needs-a-person · status ───────────────
       Ordered by urgency, not category. "Needs a person" is the only one that
       is a call to action, so it comes first and is the only one that shouts. */
    .tva-tags { display:flex; flex-wrap:wrap; gap:4px; margin-top:5px; }
    .tva-tag { display:inline-flex; align-items:center; gap:3px; font-size:9.5px; font-weight:600;
               border:1px solid #e2e8f0; background:#f8fafc; color:#64748b;
               padding:1px 6px 1px 4px; border-radius:999px; line-height:1.6; white-space:nowrap;
               /* A long agent or status name must not widen the row past the
                  330px column and force it to scroll sideways. */
               max-width:100%; overflow:hidden; text-overflow:ellipsis; }
    .tva-tag svg { width:10px !important; height:10px !important; flex-shrink:0; }
    .tva-tag__ini { width:14px; height:14px; border-radius:50%; background:#c7d2fe; color:#3730a3;
                    font-size:7px; font-weight:700; display:flex; align-items:center; justify-content:center;
                    flex-shrink:0; }
    .tva-tag--alert { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
    .tva-tag--agent { background:#eef2ff; border-color:#c7d2fe; color:#4338ca; }
    .tva-tag--bot   { background:#f0f9ff; border-color:#bae6fd; color:#0369a1; }
    .tva-tag--wait  { background:#fffbeb; border-color:#fde68a; color:#b45309; }
    html.dark .tva-tag { background:#0f172a; border-color:#334155; color:#94a3b8; }


    /* Delivery ticks. Blue only on `read` — the one state the customer's own
       WhatsApp also shows in blue, so the meaning transfers without a legend. */
    .tva-tick { margin-left:4px; letter-spacing:-1px; opacity:.65; }
    .tva-tick--read { color:#38bdf8; opacity:1; }
    .tva-tick--fail { color:#dc2626; opacity:1; font-weight:700; letter-spacing:0;
                      margin-left:5px; cursor:help; }

    /* ── Thread events (transfers) ─────────────────────────────────
       A rule across the full width with the event floated in the middle: it
       belongs to neither party, so it sits on neither side. */
    .tva-ev { display:flex; align-items:center; justify-content:center; margin:14px 0 10px; position:relative; }
    .tva-ev::before { content:''; position:absolute; left:0; right:0; top:50%; height:1px; background:#e2e8f0; }
    html.dark .tva-ev::before { background:#334155; }
    .tva-ev__body { position:relative; display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;
                    justify-content:center; background:#f6f7fb; border:1px solid #e2e8f0; border-radius:999px;
                    padding:4px 11px; font-size:10.5px; font-weight:600; color:#64748b; }
    html.dark .tva-ev__body { background:#0b1220; border-color:#334155; color:#94a3b8; }
    .tva-ev__p { display:inline-flex; align-items:center; gap:4px; color:#334155; }
    html.dark .tva-ev__p { color:#e2e8f0; }
    /* A person gets initials, the AI a glyph — the asymmetry is what makes the
       handover direction readable without reading the words. */
    .tva-ev__ini { width:17px; height:17px; border-radius:50%; background:#c7d2fe; color:#3730a3;
                   font-size:7.5px; font-weight:700; display:flex; align-items:center; justify-content:center; }
    .tva-ev__bot { width:17px; height:17px; border-radius:50%; background:#bae6fd; color:#0369a1;
                   display:flex; align-items:center; justify-content:center; }
    .tva-ev__bot svg { width:10px !important; height:10px !important; }
    .tva-ev__arrow { width:12px !important; height:12px !important; opacity:.5; }
    .tva-ev__by { font-weight:500; opacity:.8; }
    .tva-ev__note { font-weight:500; font-style:italic; opacity:.9; }

    .tva-msg__admin { display:inline-block; background:#4f46e5; color:#fff; font-size:8px; font-weight:700;
                      letter-spacing:.05em; text-transform:uppercase; padding:1px 5px; border-radius:4px;
                      margin-right:5px; vertical-align:1px; }

    /* ── Locked composer ──────────────────────────────────────────── */
    .tva-composer-row.is-locked textarea { background:#f8fafc; color:#94a3b8; cursor:not-allowed; }
    html.dark .tva-composer-row.is-locked textarea { background:#0f172a; }
    .tva-composer-row button:disabled { opacity:.4; cursor:not-allowed; }
    .tva-policy { font-size:11px; line-height:1.5; color:#b45309; background:#fffbeb;
                  border:1px solid #fde68a; border-radius:8px; padding:6px 9px; margin-bottom:7px; }
    /* Softer when the reply still works and this is only an explanation. */
    .tva-policy.is-soft { color:#0369a1; background:#f0f9ff; border-color:#bae6fd; }
    html.dark .tva-policy { background:#1c1917; border-color:#57534e; }

    /* ── Status manager ───────────────────────────────────────────── */
    .tva-sm { max-width:400px; }
    .tva-sm__row { display:flex; align-items:center; gap:8px; padding:7px 2px; font-size:12px; color:#334155;
                   border-bottom:1px solid #f1f5f9; }
    html.dark .tva-sm__row { color:#e2e8f0; border-bottom-color:#334155; }
    .tva-sm__ico { border:none; background:transparent; color:#94a3b8; cursor:pointer; padding:3px;
                   border-radius:5px; line-height:0; }
    .tva-sm__ico svg { width:13px !important; height:13px !important; }
    .tva-sm__ico:hover { background:#f1f5f9; color:#4f46e5; }
    .tva-sm__ico--del:hover { background:#fef2f2; color:#dc2626; }
    .tva-sm__form { display:flex; flex-direction:column; gap:9px; margin-top:13px; padding-top:13px;
                    border-top:1px dashed #e2e8f0; }
    html.dark .tva-sm__form { border-top-color:#334155; }
    .tva-sm__sw { display:flex; gap:6px; flex-wrap:wrap; }
    .tva-sm__c { width:22px; height:22px; border-radius:6px; border:2px solid transparent; cursor:pointer; }
    .tva-sm__c.is-on { border-color:#0f172a; box-shadow:0 0 0 2px #fff inset; }
    html.dark .tva-sm__c.is-on { border-color:#fff; box-shadow:0 0 0 2px #1e293b inset; }
    .tva-sm__chk { display:flex; align-items:center; gap:7px; font-size:11.5px; color:#64748b; cursor:pointer; }
    /* @tailwindcss/forms sets appearance:none on [type=checkbox] and expects
       utility classes (h-4 w-4 text-indigo-600 …) to supply the box. A bare
       input therefore renders as nothing at all — which is why this option
       looked like it was missing rather than unstyled. Given the size and
       colour explicitly so it does not depend on the plugin's expectations. */
    .tva-sm__chk input[type="checkbox"] { appearance:none; -webkit-appearance:none;
        width:17px; height:17px; flex-shrink:0; margin:0; cursor:pointer;
        border:1.5px solid #cbd5e1; border-radius:5px; background:#fff; transition:.12s; }
    .tva-sm__chk input[type="checkbox"]:hover { border-color:#a5b4fc; }
    /* Tick as an inline SVG sized to 76% of the box and centred by
       background-position. The earlier version drew it from two border edges at
       fixed pixel offsets, which left it small and off-centre — geometry that
       has to be hand-tuned per box size, and was wrong at this one. */
    .tva-sm__chk input[type="checkbox"]:checked {
        background-color:#4f46e5; border-color:#4f46e5;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%23fff' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 8.5l3.2 3.2L13 5'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:center; background-size:76% 76%; }
    .tva-sm__chk input[type="checkbox"]:focus-visible { outline:2px solid #a5b4fc; outline-offset:2px; }
    html.dark .tva-sm__chk input[type="checkbox"] { background-color:#0f172a; border-color:#475569; }

    .tva-sm__err { font-size:11px; line-height:1.5; color:#b91c1c; background:#fef2f2;
                   border:1px solid #fecaca; border-radius:7px; padding:6px 9px; }
    html.dark .tva-sm__err { background:#450a0a; border-color:#7f1d1d; color:#fecaca; }

    /* ── Contact profile column ───────────────────────────────────── */
    .tva-cp { width:320px; flex:0 0 320px; border-left:1px solid #e2e8f0; display:flex; flex-direction:column;
              min-height:0; background:#fff; }
    html.dark .tva-cp { background:#0f172a; border-left-color:#334155; }
    .tva-cp__head { padding:11px 14px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center;
                    font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
                    color:#64748b; flex:0 0 auto; }
    html.dark .tva-cp__head { border-bottom-color:#334155; }
    .tva-cp__x { margin-left:auto; border:none; background:transparent; color:#94a3b8; cursor:pointer;
                 padding:3px; border-radius:5px; line-height:0; }
    .tva-cp__x:hover { background:#f1f5f9; color:#4f46e5; }
    .tva-cp__x svg { width:14px !important; height:14px !important; }
    .tva-cp__body { flex:1 1 auto; min-height:0; overflow-y:auto; padding:14px; }

    .tva-cp__id { text-align:center; margin-bottom:14px; }
    .tva-cp__av { width:66px; height:66px; border-radius:50%; margin:0 auto 9px; overflow:hidden;
                  background:var(--tva-gradient); position:relative; cursor:pointer; }
    .tva-cp__av img { width:100%; height:100%; object-fit:cover; }
    /* Camera badge — only on hover, so the photo stays a photo until you
       reach for it. */
    .tva-cp__cam { position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
                   background:rgba(15,23,42,.55); color:#fff; opacity:0; transition:opacity .14s; }
    .tva-cp__av:hover .tva-cp__cam { opacity:1; }
    .tva-cp__cam svg { width:20px !important; height:20px !important; }
    .tva-cp__name { font-size:15px; font-weight:700; color:#0f172a; }
    html.dark .tva-cp__name { color:#f1f5f9; }
    .tva-cp__sub { font-size:11px; color:#94a3b8; margin-top:2px; }

    .tva-cp__sec { margin-top:16px; }
    .tva-cp__h { font-size:9px; font-weight:700; letter-spacing:.07em; text-transform:uppercase;
                 color:#94a3b8; margin-bottom:7px; }
    .tva-cp__row { display:flex; align-items:center; gap:8px; font-size:12px; color:#334155;
                   padding:5px 0; border-bottom:1px solid #f1f5f9; }
    html.dark .tva-cp__row { color:#cbd5e1; border-bottom-color:#1e293b; }
    .tva-cp__row b { margin-left:auto; font-variant-numeric:tabular-nums; }
    .tva-cp__row svg { width:13px !important; height:13px !important; opacity:.6; flex-shrink:0; }

    /* Engagement. The bar is the number; the reasons underneath are why —
       a score an agent cannot interrogate gets over-trusted or ignored. */
    .tva-cp__score { display:flex; align-items:baseline; gap:7px; margin-bottom:6px; }
    .tva-cp__score b { font-size:22px; font-weight:800; line-height:1; }
    .tva-cp__badge { font-size:9.5px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
                     padding:2px 7px; border-radius:999px; }
    .tva-cp__badge--hot   { background:#fef2f2; color:#b91c1c; }
    .tva-cp__badge--warm  { background:#fffbeb; color:#b45309; }
    .tva-cp__badge--cold  { background:#eff6ff; color:#1d4ed8; }
    .tva-cp__badge--unqualified { background:#f1f5f9; color:#64748b; }
    .tva-cp__bar { height:5px; border-radius:99px; background:#e2e8f0; overflow:hidden; margin-bottom:8px; }
    .tva-cp__bar i { display:block; height:100%; border-radius:99px; background:#4f46e5; }
    .tva-cp__why { font-size:10.5px; color:#64748b; display:flex; gap:6px; padding:2px 0; }
    .tva-cp__why span:first-child { font-weight:700; min-width:26px; color:#334155; }
    html.dark .tva-cp__why span:first-child { color:#cbd5e1; }

    .tva-cp__pill { display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:600;
                    background:#f1f5f9; color:#475569; border-radius:999px; padding:3px 9px; margin:0 4px 4px 0; }
    html.dark .tva-cp__pill { background:#1e293b; color:#cbd5e1; }
    .tva-cp__merge { border:1px dashed #fde68a; background:#fffbeb; border-radius:8px; padding:8px 10px;
                     font-size:11px; color:#92400e; margin-bottom:6px; }
    .tva-cp__btn { border:1px solid #e2e8f0; background:#fff; color:#475569; font-size:11px; font-weight:600;
                   border-radius:7px; padding:4px 9px; cursor:pointer; }
    .tva-cp__btn:hover { border-color:#c7d2fe; color:#4f46e5; }
    .tva-cp__lead { display:flex; align-items:center; gap:7px; font-size:11.5px; padding:6px 0;
                    border-bottom:1px solid #f1f5f9; cursor:pointer; }
    .tva-cp__lead:hover { color:#4f46e5; }

    /* With the profile open the main column loses 320px, which is more than
       the header's optional content is worth. The context metrics go; the
       reply-window countdown stays, because it is the only one with a
       deadline attached. Deterministic rather than a media query — what
       matters is whether the panel is open, not how wide the screen is. */
    .tva-chat.has-profile .tva-mx__c:not(.tva-mx__c--window) { display:none; }
    .tva-chat.has-profile .tva-cs__btn { max-width:132px; }

    @media (max-width: 1500px) {
        /* Three columns stop fitting well before they stop fitting at all.
           The profile floats over the thread rather than squeezing the
           conversation into uselessness. */
        .tva-cp { position:absolute; right:0; top:0; bottom:0; z-index:60;
                  box-shadow:-12px 0 32px rgba(15,23,42,.14); }
        .tva-chat { position:relative; }
        /* Floating, so the header keeps its full width and its metrics. */
        .tva-chat.has-profile .tva-mx__c:not(.tva-mx__c--window) { display:inline-flex; }
    }
    @media (max-width: 460px) {
        .tva-cp { width:100%; flex-basis:100%; }
    }

    /* ── Mobile ───────────────────────────────────────────────────────
       One pane at a time, the way every messaging app works: the list is
       the page, tapping a conversation replaces it with the thread, and a
       back arrow returns.

       Entirely inside this media query, plus one class toggled on
       `.tva-chat`. Desktop CSS is untouched — the two layouts never share a
       rule, so nothing here can regress the three-column view. */
    @media (max-width: 900px) {
        /* dvh, not vh: mobile browser chrome collapses on scroll, and vh
           measures the tallest state — so the composer sat below the fold
           until the address bar happened to hide. */
        .tva-chat { height: calc(100dvh - 150px); min-height:0; margin-top:10px; border-radius:12px; }

        .tva-chat__list { width:100%; min-width:0; flex:1 1 auto; border-right:none; }
        .tva-chat__main { display:none; }

        /* A conversation is open: swap the panes. */
        .tva-chat.is-thread-open .tva-chat__list { display:none; }
        .tva-chat.is-thread-open .tva-chat__main { display:flex; flex:1 1 auto; }

        .tva-chat__back { display:inline-flex; }

        /* Touch targets and breathing room. 13px on a phone is a squint. */
        .tva-chat__head { padding:10px 12px; gap:10px; }
        .tva-chat__thread { padding:12px; }
        .tva-msg { max-width:88%; }
        .tva-chat__composer { padding:9px 10px; }
        .tva-iconbtn { width:38px; height:38px; }
        .tva-composer-row textarea { font-size:16px; }   /* <16px makes iOS zoom on focus */

        /* The profile panel is already a full-width overlay below 460px;
           make it one across the whole phone range. */
        .tva-cp { position:absolute; inset:0; width:100%; flex-basis:100%; z-index:70; }

        /* Header metrics never fit beside a name on a phone. The countdown
           stays because it is the only one with a deadline. */
        .tva-mx__c:not(.tva-mx__c--window) { display:none; }
        .tva-cs__btn span { max-width:70px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    }

    /* Back arrow: mobile only, and never rendered on desktop where the list
       is always visible beside the thread. */
    .tva-chat__back { display:none; align-items:center; justify-content:center;
                      width:32px; height:32px; margin-right:-4px; flex-shrink:0;
                      border:none; background:transparent; color:#64748b;
                      border-radius:8px; cursor:pointer; }
    .tva-chat__back:hover { background:#f1f5f9; color:#4f46e5; }
    .tva-chat__back svg { width:19px !important; height:19px !important; }
    html.dark .tva-chat__back:hover { background:#1e293b; }

    /* ── Main column ── */
    .tva-chat__main { flex:1 1 auto; display:flex; flex-direction:column; min-width:0; min-height:0; }
    /* min-width:0 lets the identity block shrink instead of forcing the row
       wider than the column. Without it, opening the 320px contact panel
       pushed the header past its container: the name broke onto three lines,
       the metrics squeezed into unreadable slivers, and a horizontal
       scrollbar appeared under the thread. */
    .tva-chat__head { padding:11px 16px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center;
                      gap:12px; flex:0 0 auto; min-width:0; overflow:hidden; }
    /* The name and channel truncate; everything else keeps its size. */
    .tva-chat__head > .min-w-0 { flex:1 1 auto; min-width:0; }
    #hdrName, #hdrChannel { display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    #hdrChannel { display:inline-flex; max-width:100%; }
    .tva-chat__head > .ml-auto { flex:0 0 auto; }
    .tva-convo__av#hdrAvatar { flex:0 0 auto; }
    html.dark .tva-chat__head { border-bottom-color:#334155; }
    .tva-chat__thread { flex:1 1 auto; min-height:0; overflow-y:auto; overflow-x:hidden; padding:18px; background:#f6f7fb; display:flex; flex-direction:column; }
    html.dark .tva-chat__thread { background:#0b1220; }

    /* ── Bubbles: size to content, capped width ── */
    .tva-msg { width:fit-content; max-width:72%; min-width:54px; margin-bottom:9px; padding:7px 11px 5px; border-radius:13px; font-size:13.5px; line-height:1.42; word-wrap:break-word; overflow-wrap:anywhere; box-shadow:0 1px 1px rgba(0,0,0,.04); }
    .tva-msg--in { background:#fff; border:1px solid #e9edf3; margin-right:auto; border-bottom-left-radius:4px; color:#0f172a; }
    html.dark .tva-msg--in { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    .tva-msg--out { background:#dcfce7; margin-left:auto; border-bottom-right-radius:4px; color:#0f3d22; } html.dark .tva-msg--out { background:#14532d; color:#dcfce7; }
    .tva-msg--bot { background:#e0f2fe; margin-left:auto; border-bottom-right-radius:4px; color:#0c4a6e; } html.dark .tva-msg--bot { background:#0c4a6e; color:#e0f2fe; }
    .tva-msg__author { font-size:10px; font-weight:700; opacity:.75; margin-bottom:2px; }
    .tva-msg__time { font-size:10px; opacity:.55; margin-top:3px; text-align:right; white-space:nowrap; }
    .tva-msg__txt { white-space:pre-wrap; }

    /* ── Custom media ── */
    .tva-att-img { max-width:230px; border-radius:9px; display:block; margin-top:5px; cursor:pointer; }
    .tva-att-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px; margin-top:5px; max-width:236px; }
    .tva-att-grid .tva-att-img { max-width:100%; height:108px; object-fit:cover; margin-top:0; }
    .tva-att-doc { display:inline-flex; gap:8px; align-items:center; margin-top:5px; padding:8px 10px; background:rgba(0,0,0,.05); border-radius:8px; text-decoration:none; color:inherit; font-size:12.5px; }
    html.dark .tva-att-doc { background:rgba(255,255,255,.08); }

    .tva-audio { display:flex; align-items:center; gap:9px; margin-top:5px; min-width:200px; max-width:250px; }
    .tva-audio__play { width:34px; height:34px; border-radius:50%; border:none; background:rgba(0,0,0,.12); cursor:pointer; flex-shrink:0; font-size:13px; color:inherit; display:flex; align-items:center; justify-content:center; }
    html.dark .tva-audio__play { background:rgba(255,255,255,.15); }
    .tva-audio__bar { flex:1; height:4px; border-radius:2px; background:rgba(0,0,0,.18); cursor:pointer; position:relative; }
    html.dark .tva-audio__bar { background:rgba(255,255,255,.2); }
    .tva-audio__fill { height:100%; width:0; border-radius:2px; background:currentColor; opacity:.7; }
    .tva-audio__time { font-size:11px; opacity:.7; min-width:34px; text-align:right; }

    .tva-video { position:relative; max-width:240px; margin-top:5px; border-radius:9px; overflow:hidden; background:#000; }
    .tva-video video { display:block; width:100%; border-radius:9px; }
    .tva-video__ov { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; cursor:pointer; }
    .tva-video__ov span { width:48px; height:48px; border-radius:50%; background:rgba(0,0,0,.55); color:#fff; display:flex; align-items:center; justify-content:center; font-size:18px; }

    /* ── Composer (fixed at bottom) ── */
    .tva-chat__composer { border-top:1px solid #e2e8f0; padding:10px 12px; position:relative; flex:0 0 auto; }
    html.dark .tva-chat__composer { border-top-color:#334155; }
    .tva-composer-row { display:flex; align-items:flex-end; gap:7px; }
    .tva-composer-row textarea { flex:1; resize:none; border:1px solid #e2e8f0; border-radius:10px; padding:9px 12px; font-size:13.5px; max-height:120px; min-height:38px; }
    html.dark .tva-composer-row textarea { background:#1e293b; border-color:#334155; color:#f1f5f9; }
    .tva-iconbtn { width:38px; height:38px; border-radius:10px; border:1px solid #e2e8f0; background:#fff; display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; color:#475569; }
    html.dark .tva-iconbtn { background:#1e293b; border-color:#334155; color:#cbd5e1; }
    .tva-iconbtn:hover { background:#f1f5f9; } html.dark .tva-iconbtn:hover { background:#334155; }
    .tva-iconbtn.is-on { background:#fee2e2; color:#b91c1c; border-color:#fecaca; }
    .tva-send { background:var(--tva-gradient); color:#fff; border:none; }

    /* ── Live recording bar ── */
    .tva-recbar { display:flex; align-items:center; gap:10px; padding:4px 2px; }
    .tva-rec-dot { width:11px; height:11px; border-radius:50%; background:#ef4444; animation:tvaPulse 1s infinite; }
    @keyframes tvaPulse { 0%,100%{opacity:1;} 50%{opacity:.25;} }
    .tva-rec-wave { flex:1; height:24px; display:flex; align-items:center; gap:2px; overflow:hidden; }
    .tva-rec-wave i { width:3px; background:#ef4444; border-radius:2px; opacity:.55; animation:tvaWave 1s infinite ease-in-out; }
    @keyframes tvaWave { 0%,100%{height:5px;} 50%{height:20px;} }

    /* ── Popovers (emoji + template) — inside the chat container ── */
    .tva-pop { position:absolute; bottom:62px; left:12px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 12px 32px rgba(0,0,0,.16); z-index:30; display:none; }
    html.dark .tva-pop { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    #emojiPicker { padding:8px; grid-template-columns:repeat(8,1fr); gap:2px; max-width:300px; }
    #emojiPicker span { cursor:pointer; font-size:20px; text-align:center; padding:2px; border-radius:6px; } #emojiPicker span:hover { background:#f1f5f9; }
    #tplPanel { width:340px; max-width:calc(100% - 24px); padding:12px; max-height:60vh; overflow:auto; }
    #tplPanel .tpl-item { display:block; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:6px; cursor:pointer; font-size:13px; }
    html.dark #tplPanel .tpl-item { border-color:#334155; }

    .tva-window-banner { padding:8px 12px; font-size:12px; border-radius:8px; margin-bottom:8px; display:flex; align-items:center; gap:8px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; }
    .tva-empty { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; gap:8px; }
    .tva-chip { font-size:11px; padding:2px 9px; border-radius:999px; font-weight:600; }
    .tva-chip--open { background:#dcfce7; color:#15803d; } .tva-chip--closed { background:#fee2e2; color:#b91c1c; }
    .tva-lightbox { position:fixed; inset:0; background:rgba(0,0,0,.85); display:none; align-items:center; justify-content:center; z-index:80; }
    .tva-lightbox img { max-width:90vw; max-height:90vh; border-radius:8px; }

    /* ── Per-message actions, quote, edit, gallery ── */
    .tva-msg { position:relative; }
    .tva-msg__more { position:absolute; top:1px; right:3px; width:20px; height:20px; border:none; background:transparent; cursor:pointer; opacity:0; font-size:15px; line-height:1; color:inherit; border-radius:4px; }
    .tva-msg:hover .tva-msg__more { opacity:.5; }
    .tva-msg__more:hover { opacity:1; background:rgba(0,0,0,.08); }
    .tva-quote { border-left:3px solid currentColor; padding:3px 8px; margin-bottom:4px; font-size:12px; opacity:.85; background:rgba(0,0,0,.06); border-radius:4px; }
    html.dark .tva-quote { background:rgba(255,255,255,.08); }
    .tva-quote b { display:block; font-size:10.5px; opacity:.9; }
    #msgMenu { position:fixed; z-index:90; background:#fff; border:1px solid #e2e8f0; border-radius:9px; box-shadow:0 10px 28px rgba(0,0,0,.18); display:none; min-width:140px; padding:4px; }
    html.dark #msgMenu { background:#1e293b; border-color:#334155; color:#e2e8f0; }
    #msgMenu button { display:flex; gap:8px; align-items:center; width:100%; text-align:left; padding:7px 10px; border:none; background:transparent; cursor:pointer; border-radius:6px; font-size:13px; color:inherit; }
    #msgMenu button:hover { background:#f1f5f9; } html.dark #msgMenu button:hover { background:#334155; }
    .tva-reply-bar { display:flex; align-items:center; gap:8px; padding:6px 10px; margin-bottom:8px; background:#eef2ff; border-radius:8px; font-size:12.5px; border-left:3px solid #6366f1; }
    html.dark .tva-reply-bar { background:#1e293b; }
    .tva-gallery { position:fixed; inset:0; background:rgba(15,23,42,.96); z-index:85; display:none; flex-direction:column; padding:20px; }
    .tva-gallery__grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; overflow:auto; }
    .tva-gallery__grid img, .tva-gallery__grid video { width:100%; height:150px; object-fit:cover; border-radius:8px; cursor:pointer; background:#000; }

    /* ── Day separators + typing indicator ── */
    .tva-sep { align-self:center; margin:10px 0 8px; font-size:11px; font-weight:600; color:#64748b; background:rgba(0,0,0,.06); padding:3px 12px; border-radius:999px; }
    html.dark .tva-sep { background:rgba(255,255,255,.1); color:#cbd5e1; }
    .tva-typing { display:inline-flex; gap:4px; align-items:center; }
    .tva-typing i { width:7px; height:7px; border-radius:50%; background:currentColor; opacity:.4; animation:tvaBlink 1.2s infinite; }
    .tva-typing i:nth-child(2) { animation-delay:.2s; } .tva-typing i:nth-child(3) { animation-delay:.4s; }
    @keyframes tvaBlink { 0%,60%,100%{opacity:.25; transform:translateY(0);} 30%{opacity:1; transform:translateY(-3px);} }

    /* ── Message row (action button beside bubble, never overlapping) ── */
    .tva-row { display:flex; align-items:center; gap:6px; }
    .tva-row--in { justify-content:flex-start; }
    .tva-row--out { justify-content:flex-end; }
    .tva-row .tva-msg { margin:0 0 9px; }
    .tva-row__more { width:26px; height:26px; border:none; background:transparent; cursor:pointer; color:#94a3b8; border-radius:6px; opacity:0; flex-shrink:0; font-size:16px; line-height:1; }
    .tva-row:hover .tva-row__more { opacity:.8; } .tva-row__more:hover { background:rgba(0,0,0,.1); opacity:1; }
    .tva-row--in .tva-row__more { order:2; } .tva-row--out .tva-row__more { order:0; }

    /* ── Options menu (per-message + composer) ── */
    .tva-cmenu { min-width:210px; padding:6px; }
    .tva-cmenu button { display:flex; gap:10px; align-items:center; width:100%; text-align:left; padding:9px 10px; border:none; background:transparent; cursor:pointer; border-radius:8px; font-size:13px; color:inherit; }
    .tva-cmenu button:hover { background:#f1f5f9; } html.dark .tva-cmenu button:hover { background:#334155; }

    /* ── Toasts ── */
    .tva-toasts { position:fixed; top:18px; right:18px; z-index:120; display:flex; flex-direction:column; gap:8px; }
    .tva-toast { background:#fff; border:1px solid #e2e8f0; border-left:4px solid #6366f1; border-radius:10px; padding:11px 14px; font-size:13px; box-shadow:0 10px 30px rgba(0,0,0,.14); min-width:220px; max-width:340px; transition:opacity .25s; }
    .tva-toast--success { border-left-color:#22c55e; } .tva-toast--error { border-left-color:#ef4444; }
    html.dark .tva-toast { background:#1e293b; border-color:#334155; color:#e2e8f0; }

    /* ── Dialog (confirm / prompt) ── */
    .tva-ov { position:fixed; inset:0; background:rgba(15,23,42,.5); z-index:110; display:none; align-items:center; justify-content:center; }
    .tva-ov.open { display:flex; }
    .tva-dlg { background:#fff; border-radius:16px; width:430px; max-width:calc(100vw - 32px); padding:22px; box-shadow:0 24px 60px rgba(0,0,0,.3); animation:tvaPop .16s; }
    html.dark .tva-dlg { background:#1e293b; color:#e2e8f0; }
    @keyframes tvaPop { from{opacity:0; transform:scale(.96);} to{opacity:1; transform:scale(1);} }
    .tva-dlg__title { font-size:16px; font-weight:700; margin-bottom:4px; }
    .tva-dlg__text { font-size:12.5px; color:#64748b; margin-bottom:12px; }
    .tva-dlg label { font-size:12px; font-weight:600; display:block; margin:9px 0 3px; }
    .tva-dlg input, .tva-dlg textarea { width:100%; border:1px solid #e2e8f0; border-radius:9px; padding:9px 11px; font-size:13.5px; }
    html.dark .tva-dlg input, html.dark .tva-dlg textarea { background:#0f172a; border-color:#334155; color:#f1f5f9; }
    .tva-dlg__foot { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; }

    /* ── Lightbox navigation ── */
    .tva-lb-btn { position:absolute; top:50%; transform:translateY(-50%); width:46px; height:46px; border-radius:50%; background:rgba(255,255,255,.16); color:#fff; border:none; font-size:22px; cursor:pointer; z-index:81; }
    .tva-lb-btn:hover { background:rgba(255,255,255,.3); }
    .tva-lb-prev { left:22px; } .tva-lb-next { right:22px; } .tva-lb-close { top:22px; right:22px; transform:none; width:40px; height:40px; }
    /* Unread as a number, not a dot: "3 waiting" is actionable, "something
       new" is not. Capped at 99+ so the pill never resizes the row. */
    .tva-unread { display:inline-flex; align-items:center; justify-content:center;
                  min-width:17px; height:17px; padding:0 5px; border-radius:999px;
                  background:#dc2626; color:#fff; font-size:9.5px; font-weight:800;
                  line-height:1; font-variant-numeric:tabular-nums; }
    #hdrChannel a { color:inherit; text-decoration:none; display:inline-flex; align-items:center; }
    #hdrChannel a:hover { text-decoration:underline; }

    /* Timestamp is secondary information — it should be readable when looked
       for and invisible when scanning names and messages. */
    .tva-convo__time { font-size:9px; line-height:1; color:#94a3b8; white-space:nowrap;
                       font-variant-numeric:tabular-nums; letter-spacing:.01em; }
    html.dark .tva-convo__time { color:#64748b; }

    /* Channel shown as a mark, not a word — see channelIcon().
     *
     * A true circle in the platform's OWN colour with a white glyph, rather
     * than the earlier tinted rounded-rect. Two reasons it reads better: a
     * column of circles aligns perfectly whatever the provider, and these are
     * the colours people already recognise from the apps themselves, so the
     * channel registers without being read. Instagram gets its gradient. */
    .tva-badge--icon { width:19px; height:19px; padding:0; border-radius:50%;
                       display:inline-flex; align-items:center; justify-content:center;
                       line-height:0; color:#fff; flex-shrink:0;
                       box-shadow:0 1px 2px rgba(15,23,42,.16); }
    .tva-badge--icon svg { width:11px; height:11px; }
    .tva-badge--icon.tva-badge--whatsapp  { background:#25d366; color:#fff; }
    .tva-badge--icon.tva-badge--facebook,
    .tva-badge--icon.tva-badge--messenger { background:#1877f2; color:#fff; }
    .tva-badge--icon.tva-badge--instagram { color:#fff;
        background:radial-gradient(circle at 30% 107%, #fdf497 0%, #fd5949 45%, #d6249f 60%, #285AEB 90%); }
    /* Channels with no brand mark (web, phone, API) keep a neutral disc so
       the row still lines up instead of collapsing. */
    .tva-badge--icon:not([class*="--whatsapp"]):not([class*="--facebook"]):not([class*="--messenger"]):not([class*="--instagram"])
        { background:#94a3b8; }
    .tva-badge__txt { font-size:8px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; }
    #hdrChannel { display:inline-flex; align-items:center; }
    #hdrName a { color:inherit; text-decoration:none; border-bottom:1px dashed currentColor; }
    #hdrName a:hover { opacity:.8; }
</style>

<div class="content">
    <div class="flex items-center gap-3 mt-4">
        <h2 class="text-lg font-semibold">Messages</h2>
        <select id="presenceSel" class="form-select form-select-sm ml-auto" title="Your availability" style="width:auto; display:none;">
            <option value="offline">⚫ Offline</option>
            <option value="away">🟡 Away</option>
            <option value="online">🟢 Online</option>
        </select>
        <form method="GET">
            <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="tva-chat">
        <div class="tva-chat__list">
            {{-- Filters are arranged by how often they get used, so the common
                 case costs no clicks at all:

                   row 1  ownership — the switch an agent flips all day
                   row 2  search
                   row 3  one-tap chips for the two questions actually asked
                          hourly ("who is waiting?", "what expires soon?"),
                          plus everything else behind a single popover
                   row 4  appears only once something is on, so the filter
                          state can never be a mystery

                 Counts sit on every option. They are what turns a filter list
                 into a dashboard — you can see there are 12 unanswered
                 Instagram chats without filtering to find out. --}}
            <div class="tva-chat__listhead">
                <div class="tva-seg" id="filterTabs">
                    <button data-f="all" class="is-active"><i data-lucide="inbox" class="w-3.5 h-3.5"></i> All <span class="tva-seg__n" data-n="all"></span></button>
                    <button data-f="mine"><i data-lucide="user" class="w-3.5 h-3.5"></i> Mine <span class="tva-seg__n" data-n="mine"></span></button>
                    <button data-f="queue"><i data-lucide="clock" class="w-3.5 h-3.5"></i> Queue <span class="tva-seg__n" data-n="queue"></span></button>
                </div>

                <div class="tva-search">
                    <i data-lucide="search"></i>
                    <input id="chatSearch" type="text" class="form-control form-control-sm" placeholder="Search conversations…">
                </div>

                {{-- The three toggles are icon-only so all four fit one line in
                     a 302px column. Nothing becomes discoverable-by-icon-alone,
                     which is the usual cost of this: each has a full tooltip,
                     the same three appear spelled out inside the Filters panel,
                     and an ACTIVE one names itself in the pill row below. The
                     count badge appears only when there is something to find,
                     so the row is quiet until it has news. --}}
                <div class="tva-qf">
                    <button type="button" class="tva-qf__ico" data-quick="needs_reply"
                            aria-label="Waiting for a reply"
                            title="Waiting — the customer is waiting and Meta's 24-hour window is still open, so you can reply without an approved template">
                        <i data-lucide="corner-up-left"></i>
                        <span class="tva-qf__n" data-n="needs_reply"></span>
                    </button>
                    <button type="button" class="tva-qf__ico tva-qf__ico--alert" data-quick="needs_human"
                            aria-label="Needs a person"
                            title="Needs a person — the customer asked for a human, or the AI escalated, and nobody has replied yet">
                        <i data-lucide="hand"></i>
                        <span class="tva-qf__n" data-n="needs_human"></span>
                    </button>
                    <button type="button" class="tva-qf__ico" data-quick="unread"
                            aria-label="Unread" title="Unread — the customer has written since anyone opened this">
                        <i data-lucide="mail"></i>
                        <span class="tva-qf__n" data-n="unread"></span>
                    </button>

                    <button type="button" id="filterBtn" class="tva-qf__chip tva-qf__chip--more" title="All filters">
                        Filters
                        <span id="filterCount" class="tva-qf__badge" hidden></span>
                        <i data-lucide="chevron-down" class="tva-qf__caret"></i>
                    </button>
                </div>

                <div id="activeFilters" class="tva-af" hidden></div>

                {{-- Live-applies on every click. An Apply button would be one
                     extra click on every single interaction to protect against
                     a mistake that costs one click to undo. --}}
                <div id="filterPanel" class="tva-fp" hidden>
                    <div class="tva-fp__grid">
                        <div class="tva-fp__group">
                            <div class="tva-fp__h">Status</div>
                            <div class="tva-fp__opts" data-group="states">
                                <button data-v="active"><span class="tva-st tva-st--active"></span> Active <b data-n="states.active"></b></button>
                                <button data-v="expiring"><span class="tva-st tva-st--expiring"></span> Expiring soon <b data-n="states.expiring"></b></button>
                                <button data-v="expired"><span class="tva-st tva-st--expired"></span> Expired <b data-n="states.expired"></b></button>
                                <button data-v="closed"><span class="tva-st tva-st--closed"></span> Closed <b data-n="states.closed"></b></button>
                            </div>
                        </div>

                        <div class="tva-fp__group">
                            <div class="tva-fp__h">Handled by</div>
                            <div class="tva-fp__opts" data-group="handlers">
                                <button data-v="bot"><i data-lucide="bot"></i> AI agent <b data-n="handlers.bot"></b></button>
                                <button data-v="agent"><i data-lucide="user"></i> A person <b data-n="handlers.agent"></b></button>
                                <button data-v="queued"><i data-lucide="clock"></i> Queued <b data-n="handlers.queued"></b></button>
                            </div>
                        </div>

                        <div class="tva-fp__group" id="convStatusGroup" hidden>
                            <div class="tva-fp__h">Conversation status</div>
                            <div class="tva-fp__opts" data-group="conv_statuses" id="convStatusOpts"></div>
                        </div>

                        <div class="tva-fp__group">
                            <div class="tva-fp__h">Read state</div>
                            <div class="tva-fp__opts" data-group="read" data-single="1">
                                <button data-v="unread">Unread <b data-n="unread"></b></button>
                                <button data-v="read">Read <b data-n="read"></b></button>
                            </div>
                        </div>

                        <div class="tva-fp__group">
                            <div class="tva-fp__h">Last activity</div>
                            <div class="tva-fp__opts" data-group="date" data-single="1">
                                <button data-v="today">Today</button>
                                <button data-v="7d">Last 7 days</button>
                                <button data-v="30d">Last 30 days</button>
                            </div>
                        </div>

                        <div class="tva-fp__group">
                            <div class="tva-fp__h">Channel</div>
                            <div class="tva-fp__opts" data-group="channels">
                                <button data-v="whatsapp">WhatsApp <b data-n="channels.whatsapp"></b></button>
                                <button data-v="instagram">Instagram <b data-n="channels.instagram"></b></button>
                                <button data-v="facebook">Facebook <b data-n="channels.facebook"></b></button>
                            </div>
                        </div>

                        <div class="tva-fp__group">
                            <div class="tva-fp__h">Conversation type</div>
                            <div class="tva-fp__opts" data-group="kinds">
                                <button data-v="dm"><i data-lucide="message-circle" class="w-3.5 h-3.5"></i> Direct messages <b data-n="kinds.dm"></b></button>
                                <button data-v="comment"><i data-lucide="message-square" class="w-3.5 h-3.5"></i> Post comments <b data-n="kinds.comment"></b></button>
                            </div>
                        </div>

                        <div class="tva-fp__group" id="accountGroup" hidden>
                            <div class="tva-fp__h">Page / number</div>
                            <div class="tva-fp__opts" data-group="accounts" id="accountOpts"></div>
                        </div>
                    </div>

                    <div class="tva-fp__foot">
                        <button type="button" id="filterClear" class="tva-fp__clear">Clear all</button>
                        <button type="button" id="filterDone" class="btn btn-sm btn-primary">Done</button>
                    </div>
                </div>
            </div>
            <div id="chatConvos" class="tva-chat__convos"></div>
        </div>

        <div class="tva-chat__main">
            <div id="chatEmpty" class="tva-empty">
                <i data-lucide="messages-square" class="w-10 h-10"></i>
                <div>Select a conversation</div>
            </div>

            <div id="chatPane" style="display:none; flex:1 1 auto; min-height:0; flex-direction:column;">
                <div class="tva-chat__head">
                    {{-- Mobile only. Hidden on desktop, where the list never
                         goes away and there is nothing to go back to. --}}
                    <button type="button" class="tva-chat__back" onclick="closeThread()" aria-label="Back to conversations">
                        <i data-lucide="arrow-left"></i>
                    </button>
                    <div class="tva-convo__av" id="hdrAvatar"></div>
                    <div class="min-w-0">
                        <div class="tva-convo__name" id="hdrName"></div>
                        {{-- The raw channel_account (page id / phone_number_id)
                             used to sit here. It is an internal identifier: it
                             cannot be dialled or opened, and it made the header
                             read like a debug dump. The Page's NAME plus its
                             channel mark answers "which of ours is this on?"
                             without any of that. --}}
                        <div class="text-xs text-slate-500"><span id="hdrChannel"></span></div>
                    </div>
                    {{-- Metrics before controls, reading left to right: what is
                         true about this conversation, then what you can do
                         about it. Each is icon + short value with the full
                         wording in the tooltip — a header has room for four
                         numbers only if none of them spells itself out. --}}
                    <div class="ml-auto flex items-center gap-2">
                        <div id="hdrMetrics" class="tva-mx"></div>

                        <div class="tva-cs">
                            <button type="button" id="transferBtn" class="tva-cs__btn" title="Transfer this conversation">
                                <i data-lucide="users"></i>
                                <span id="transferLabel">Transfer</span>
                                <i data-lucide="chevron-down" class="tva-cs__caret"></i>
                            </button>
                            <div id="transferMenu" class="tva-cs__menu" hidden>
                                <div id="transferOpts"></div>
                            </div>
                        </div>

                        <div class="tva-cs">
                            <button type="button" id="statusBtn" class="tva-cs__btn" title="Set conversation status">
                                <span class="tva-cs__dot" id="statusDot"></span>
                                <span id="statusLabel">Status</span>
                                <i data-lucide="chevron-down" class="tva-cs__caret"></i>
                            </button>
                            <div id="statusMenu" class="tva-cs__menu" hidden>
                                <div id="statusOpts"></div>
                                <div class="tva-cs__sep"></div>
                                <button type="button" class="tva-cs__manage" onclick="openStatusManager()">
                                    <i data-lucide="settings-2"></i> Manage statuses
                                </button>
                            </div>
                        </div>

                        <span id="hdrHandoff" class="tva-chip" style="display:none;"></span>
                        <button id="handoffBtn" class="btn btn-sm" style="display:none;"></button>
                        <button id="galleryBtn" class="btn btn-sm btn-secondary" title="Shared media"><i data-lucide="image" class="w-3 h-3"></i></button>
                        <button id="botToggle" class="btn btn-sm btn-secondary" title="Pause/resume the AI for this chat">
                            <i data-lucide="bot" class="w-3 h-3 mr-1 inline"></i><span id="botToggleLabel">Bot on</span>
                        </button>
                    </div>
                </div>

                <div id="chatThread" class="tva-chat__thread"></div>

                <div class="tva-chat__composer">
                    <div id="replyBar" style="display:none;"></div>
                    <div id="windowBanner"></div>
                    <div id="emojiPicker" class="tva-pop"></div>
                    <div id="tplPanel" class="tva-pop"></div>
                    <div id="composerMenu" class="tva-pop tva-cmenu">
                        <button onclick="composerAction('attach')"><i data-lucide="paperclip" class="w-4 h-4"></i> Attach file</button>
                        <button onclick="composerAction('interactive')"><i data-lucide="list" class="w-4 h-4"></i> Quick-reply buttons</button>
                        <button class="wa-only" onclick="composerAction('flow')"><i data-lucide="clipboard-list" class="w-4 h-4"></i> Send a Flow form</button>
                        <button class="wa-only" onclick="composerAction('catalog')"><i data-lucide="shopping-bag" class="w-4 h-4"></i> Send catalog products</button>
                        <button class="wa-only" onclick="composerAction('template')"><i data-lucide="file-text" class="w-4 h-4"></i> Send template</button>
                    </div>

                    {{-- Why the composer is locked (or why a reply still works
                         past 24h). Rendered from the server's reply policy so
                         the explanation can never contradict the enforcement. --}}
                    <div id="policyNote" class="tva-policy" hidden></div>

                    <div class="tva-composer-row" id="composerRow">
                        <button class="tva-iconbtn" id="btnMore" title="More options"><i data-lucide="plus" class="w-4 h-4"></i></button>
                        <button class="tva-iconbtn" id="btnEmoji" title="Emoji">😊</button>
                        <button class="tva-iconbtn" id="btnVoice" title="Record voice note"><i data-lucide="mic" class="w-4 h-4"></i></button>
                        <textarea id="chatInput" rows="1" placeholder="Type a message…"></textarea>
                        <button class="tva-iconbtn tva-send" id="btnSend" title="Send"><i data-lucide="send" class="w-4 h-4"></i></button>
                    </div>

                    <div class="tva-recbar" id="recBar" style="display:none;">
                        <button class="tva-iconbtn" id="recCancel" title="Cancel"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        <span class="tva-rec-dot"></span>
                        <span id="recTime" style="font-variant-numeric:tabular-nums;">0:00</span>
                        <div class="tva-rec-wave" id="recWave"></div>
                        <button class="tva-iconbtn tva-send" id="recSend" title="Send voice note"><i data-lucide="send" class="w-4 h-4"></i></button>
                    </div>

                    <input type="file" id="fileInput" style="display:none;" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx">
                </div>
            </div>
        </div>

        {{-- Contact profile. A third column rather than an overlay: an agent
             reads the profile WHILE replying, and a modal would force them to
             close it to type. Collapses to an overlay only on narrow screens,
             where three columns genuinely do not fit. --}}
        <aside class="tva-cp" id="contactPanel" hidden>
            <div class="tva-cp__head">
                <span>Contact</span>
                <button type="button" class="tva-cp__x" onclick="closeContact()" title="Close">
                    <i data-lucide="x"></i>
                </button>
            </div>
            <div class="tva-cp__body" id="contactBody">
                <div class="tva-empty-sm">Loading…</div>
            </div>
            <input type="file" id="contactAvatarInput" accept="image/*" style="display:none"
                   onchange="uploadContactAvatar(this.files[0])">
        </aside>
    </div>

    <div class="tva-lightbox" id="lightbox">
        <button class="tva-lb-btn tva-lb-close" onclick="closeLightbox(event)">✕</button>
        <button class="tva-lb-btn tva-lb-prev" onclick="lbNav(event,-1)">‹</button>
        <img id="lightboxImg" src="">
        <button class="tva-lb-btn tva-lb-next" onclick="lbNav(event,1)">›</button>
    </div>
    <div id="msgMenu"></div>
    <div class="tva-toasts" id="tvaToasts"></div>
    <div class="tva-ov" id="tvaDlg"><div class="tva-dlg"></div></div>

    <div class="tva-ov" id="statusMgr" onclick="if(event.target===this) closeStatusManager()">
        <div class="tva-dlg tva-sm">
            <div class="tva-dlg__title">Conversation statuses</div>
            <div class="tva-dlg__text">Your own labels for where a conversation stands. One marked
                <em>closes</em> will resolve the conversation when applied.</div>
            <div id="statusMgrBody"></div>
            <div class="flex justify-end mt-3">
                <button class="btn btn-sm btn-secondary" onclick="closeStatusManager()">Close</button>
            </div>
        </div>
    </div>
    <div class="tva-gallery" id="galleryOverlay">
        <div class="flex items-center mb-3" style="color:#fff;">
            <b class="flex-1">Shared media</b>
            <button class="btn btn-sm btn-secondary" onclick="document.getElementById('galleryOverlay').style.display='none'">Close</button>
        </div>
        <div class="tva-gallery__grid" id="galleryGrid"></div>
    </div>
</div>

<script>
const CHAT = {
    projectId: '{{ hashid($projectId) }}',
    base: '{{ url('c/'.$client->slug.'/chat') }}',
    convosUrl: '{{ route('chat.conversations', ['client' => $client->slug]) }}',
    leadsUrl:  '{{ route('leads.index', ['client' => $client->slug]) }}',
    csrf: '{{ csrf_token() }}',
};
const EMOJIS = '😀 😁 😂 🤣 😊 😍 😘 👍 🙏 🙌 👏 🔥 ✅ ❌ ❤️ 🎉 😎 🤔 😅 😢 😡 🙂 👌 💯 📞 📦 🛒 💳 ⏰ 📅 ✨ 🚀'.split(' ');
let activeSid=null, lastMsgId=0, threadTimer=null;

function h(s){ return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function timeAgo(ts){ if(!ts) return ''; const d=Math.floor(Date.now()/1000)-ts; if(d<60)return 'now'; if(d<3600)return Math.floor(d/60)+'m'; if(d<86400)return Math.floor(d/3600)+'h'; return Math.floor(d/86400)+'d'; }
function fmtTime(ts){ if(!ts) return ''; const d=new Date(ts*1000); let hh=d.getHours(); const mm=d.getMinutes(); const ap=hh>=12?'PM':'AM'; hh=hh%12||12; return hh+':'+(mm<10?'0'+mm:mm)+' '+ap; }
function mmss(s){ s=Math.max(0,Math.floor(s)); return Math.floor(s/60)+':'+String(s%60).padStart(2,'0'); }
function dayKey(ts){ if(!ts) return ''; const d=new Date(ts*1000); return d.getFullYear()+'-'+d.getMonth()+'-'+d.getDate(); }
function fmtDay(ts){ const d=new Date(ts*1000); const now=Date.now()/1000; if(dayKey(ts)===dayKey(now)) return 'Today'; if(dayKey(ts)===dayKey(now-86400)) return 'Yesterday'; return d.toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'}); }
let currentBotPaused=false;
function refreshTyping(){
    const box=document.getElementById('chatThread'); if(!box) return;
    const ex=document.getElementById('typingRow'); if(ex) ex.remove();
    const last=window.MSGS && window.MSGS[lastMsgId];
    if(last && last.direction==='in' && !currentBotPaused){
        const near=box.scrollHeight-box.scrollTop-box.clientHeight < 150;
        box.insertAdjacentHTML('beforeend','<div class="tva-msg tva-msg--bot" id="typingRow" style="padding:10px 14px;"><div class="tva-msg__author">🤖 AI</div><span class="tva-typing"><i></i><i></i><i></i></span></div>');
        if(near) box.scrollTop=box.scrollHeight;
    }
}
// Initials from the FIRST and LAST name — "Ayesha Khan" → AK.
//
// Not the first two characters of the string, which is what this used to do
// and which gave "Ay". A single-word name has no last name to take, so it
// keeps two letters from the one word it has.
function initials(name){
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

// Placeholder for a contact with no photo.
//
// The hue is derived from the conversation id rather than the name, so rows
// stay visually distinct even where several contacts are unresolved and
// share the same placeholder text.
function avatarFallback(seed, name){
    let n = 0;
    const s = String(seed || '?');
    for (let i = 0; i < s.length; i++) n = (n * 31 + s.charCodeAt(i)) >>> 0;
    const hue = n % 360;

    return '<span class="tva-convo__ini" style="background:hsl(' + hue + ',52%,90%);color:hsl(' + hue + ',45%,32%)">'
        + h(initials(name)) + '</span>';
}

// A broken <img> is worse than no <img>: a stale Meta CDN link renders as the
// browser's torn-page icon. onerror swaps in the initials instead.
function avatarHtml(url, seed, name){
    if (!url) return avatarFallback(seed, name);
    return '<img src="' + h(url) + '" alt="" loading="lazy"'
        + ' onerror="this.parentNode.innerHTML=' + h(JSON.stringify(avatarFallback(seed, name))) + '">';
}

/* Channel identity as a mark rather than a word.
   The full provider name ("facebook_page") ate a third of the row and told an
   operator nothing they couldn't see at a glance from the logo. Paths are the
   same brand marks App\Support\BrandIcons renders server-side, inlined here
   because this list is built in JavaScript. */
const CHANNEL_MARKS = {
    whatsapp:      'M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 2.1.55 4.15 1.6 5.96L2 22l4.26-1.68a9.9 9.9 0 0 0 5.78 1.85h.01c5.46 0 9.9-4.45 9.9-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm5.8 14.06c-.24.68-1.42 1.3-1.96 1.35-.5.05-.99.23-3.35-.7-2.82-1.11-4.6-3.97-4.74-4.16-.14-.19-1.13-1.5-1.13-2.86 0-1.36.71-2.03.96-2.31.25-.28.55-.35.73-.35h.52c.17 0 .4-.06.62.48.24.57.8 1.98.87 2.12.07.14.12.31.02.5-.09.19-.14.31-.28.47l-.42.49c-.14.14-.28.29-.12.57.16.28.72 1.18 1.54 1.91 1.06.94 1.95 1.23 2.23 1.37.28.14.44.12.6-.07.17-.19.7-.81.88-1.09.19-.28.37-.23.63-.14.26.09 1.66.78 1.94.93.28.14.47.21.54.33.07.12.07.68-.17 1.36z',
    instagram:     'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zm0 5.68A4.16 4.16 0 1 0 16.16 12 4.16 4.16 0 0 0 12 7.84zm0 6.86A2.7 2.7 0 1 1 14.7 12 2.7 2.7 0 0 1 12 14.7zm5.3-7.1a.97.97 0 1 1-.97-.97.97.97 0 0 1 .97.97z',
    facebook_page: 'M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.69.24 2.69.24v2.97h-1.52c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z',
};
// Keyed on sessions.channel, which is NOT the same vocabulary as the Meta
// provider name. A Messenger conversation is stored as `facebook`, not
// `facebook_page` (see CrmInboundMessageHandler::channelFor) — keying only on
// the provider names meant every Facebook conversation missed the lookup and
// fell through to the text fallback, printing the word "facebook" in the
// badge. Both spellings are mapped so either vocabulary resolves.
CHANNEL_MARKS.facebook  = CHANNEL_MARKS.facebook_page;
CHANNEL_MARKS.messenger = CHANNEL_MARKS.facebook_page;

const CHANNEL_LABELS = {
    whatsapp: 'WhatsApp', instagram: 'Instagram',
    facebook: 'Facebook', facebook_page: 'Facebook', messenger: 'Messenger',
    web: 'Web chat', voice: 'Voice', phone: 'Phone', sms: 'SMS',
    twilio: 'Phone', plivo: 'Phone', api: 'API', internal: 'Internal',
};

function channelLabel(ch){ return CHANNEL_LABELS[ch] || (ch||'').replace(/_/g,' '); }

function channelIcon(ch){
    const d = CHANNEL_MARKS[ch];
    if(!d) return '<span class="tva-badge__txt">' + h(channelLabel(ch)) + '</span>';
    return '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="' + d + '"/></svg>';
}
async function api(url,opts={}){ opts.headers=Object.assign({'X-CSRF-TOKEN':CHAT.csrf,'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},opts.headers||{}); return fetch(url,opts); }
function msgUrl(p){ return `${CHAT.base}/${activeSid}/${p}`; }

// ── Toast + dialog (replaces native alert/prompt/confirm) ──
function tvaToast(msg, type='info'){
    const c=document.getElementById('tvaToasts'); const t=document.createElement('div');
    t.className='tva-toast tva-toast--'+type; t.textContent=msg; c.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; setTimeout(()=>t.remove(),260); }, 3200);
}
function tvaPrompt({title, text, fields, confirmText='Send'}){
    return new Promise(res=>{
        const ov=document.getElementById('tvaDlg'); const dlg=ov.querySelector('.tva-dlg');
        dlg.innerHTML = `<div class="tva-dlg__title">${h(title||'')}</div>`+(text?`<div class="tva-dlg__text">${h(text)}</div>`:'')+
            (fields||[]).map(f=>`<label>${h(f.label)}</label>`+(f.type==='textarea'
                ? `<textarea data-f="${f.name}" rows="3" placeholder="${h(f.placeholder||'')}">${h(f.value||'')}</textarea>`
                : `<input data-f="${f.name}" placeholder="${h(f.placeholder||'')}" value="${h(f.value||'')}">`)).join('')+
            `<div class="tva-dlg__foot"><button class="btn btn-secondary btn-sm" data-act="cancel">Cancel</button><button class="btn btn-primary btn-sm" data-act="ok">${h(confirmText)}</button></div>`;
        ov.classList.add('open');
        const first=dlg.querySelector('[data-f]'); if(first) first.focus();
        const done=v=>{ ov.classList.remove('open'); res(v); };
        dlg.querySelector('[data-act=cancel]').onclick=()=>done(null);
        dlg.querySelector('[data-act=ok]').onclick=()=>{ const o={}; dlg.querySelectorAll('[data-f]').forEach(el=>o[el.dataset.f]=el.value); done(o); };
    });
}
async function tvaConfirm(opts){ const v=await tvaPrompt({...opts, fields:[]}); return v!==null; }

// ── Lightbox (with prev/next) ──
let lbIdx=0;
function lbImages(){ return (window.MEDIA||[]).filter(a=>a.type==='image'||a.type==='sticker').map(a=>a.proxy); }
function lightbox(src){ const imgs=lbImages(); lbIdx=Math.max(0,imgs.indexOf(src)); document.getElementById('lightboxImg').src=src; document.getElementById('lightbox').style.display='flex'; }
function lbNav(e,d){ if(e) e.stopPropagation(); const imgs=lbImages(); if(!imgs.length) return; lbIdx=(lbIdx+d+imgs.length)%imgs.length; document.getElementById('lightboxImg').src=imgs[lbIdx]; }
function closeLightbox(e){ if(e) e.stopPropagation(); document.getElementById('lightbox').style.display='none'; }

// ── Composer "+" menu ──
function composerAction(type){
    closePops();
    if(type==='attach') return document.getElementById('fileInput').click();
    if(type==='interactive') return sendInteractive();
    if(type==='flow') return sendFlowMsg();
    if(type==='catalog') return sendProductMsg();
    if(type==='template') return openTemplates();
}

// ── Conversation list ──
let currentFilter='all';

// Multi-select dimensions are Sets; `read` and `date` are single-valued
// because they are genuinely exclusive.
const MULTI = ['states','channels','accounts','kinds','handlers','conv_statuses'];
const FILTERS = {
    states: new Set(), channels: new Set(), accounts: new Set(), kinds: new Set(),
    handlers: new Set(), conv_statuses: new Set(),
    read: null, date: null, needs_human: false,
};
const FILTER_LABELS = {
    states:   {active:'Active', expiring:'Expiring soon', expired:'Expired', closed:'Closed'},
    channels: {whatsapp:'WhatsApp', instagram:'Instagram', facebook:'Facebook'},
    kinds:    {dm:'Direct messages', comment:'Post comments'},
    handlers: {bot:'AI agent', agent:'A person', queued:'Queued'},
    read:     {unread:'Unread', read:'Read'},
    date:     {today:'Today', '7d':'Last 7 days', '30d':'Last 30 days'},
};
let ACCOUNT_NAMES = {}, STATUS_NAMES = {};

function filterQuery(){
    const p = new URLSearchParams({project_id: CHAT.projectId, filter: currentFilter});
    for (const k of MULTI) {
        if (FILTERS[k].size) p.set(k, [...FILTERS[k]].join(','));
    }
    if (FILTERS.read) p.set('read', FILTERS.read);
    if (FILTERS.date) p.set('date', FILTERS.date);
    if (FILTERS.needs_human) p.set('needs_human','1');
    return p.toString();
}

function activeFilterCount(){
    return MULTI.reduce((n,k)=>n+FILTERS[k].size, 0)
         + (FILTERS.read ? 1 : 0) + (FILTERS.date ? 1 : 0) + (FILTERS.needs_human ? 1 : 0);
}

async function loadConvos(){
    try{
        const r=await api(CHAT.convosUrl+'?'+filterQuery());
        const d=await r.json();
        renderConvos(d.conversations||[]);
        applyPresence(d.me);
        applyFacets(d.facets||{}, d.accounts||[]);
        applyStatusFilterOptions(d.statuses||[]);
    }catch(e){}
}

// ── Filter chrome ──
function facetAt(facets, path){
    return path.split('.').reduce((o,k)=> (o==null?undefined:o[k]), facets);
}

function applyFacets(facets, accounts){
    // Counts everywhere they were declared, resolved by dotted path so the
    // markup stays the single source of truth for which count goes where.
    document.querySelectorAll('[data-n]').forEach(el=>{
        const v = facetAt(facets, el.dataset.n);

        // Quick chips drop a zero rather than printing it. Standard badge
        // behaviour, and it buys back the width that was pushing this row past
        // the column edge — the chip's own presence already says the filter
        // exists, and an empty result is what the list will show anyway.
        // Panel options and the All/Mine/Queue tabs keep their zeros, where
        // "none" is the answer to a question the user asked.
        const hideZero = el.classList.contains('tva-qf__n');

        el.textContent = (v === undefined || v === null || (hideZero && v === 0)) ? '' : v;

        const btn = el.closest('.tva-fp__opts button');
        if (btn) btn.classList.toggle('is-empty', v === 0);
    });

    // The Page/number list is per-project and only worth showing when there
    // is a choice to make.
    const sig = accounts.map(a=>a.id).join('|');
    if (sig !== (applyFacets._sig||'')) {
        applyFacets._sig = sig;
        ACCOUNT_NAMES = {};
        const box = document.getElementById('accountOpts');
        box.innerHTML = accounts.map(a=>{
            ACCOUNT_NAMES[a.id] = a.name;
            return '<button data-v="'+h(a.id)+'">'+channelIcon(a.channel)+' <span>'+h(a.name)+'</span>'
                 + '<b data-n="accounts.'+h(a.id)+'"></b></button>';
        }).join('');
        document.getElementById('accountGroup').hidden = accounts.length < 2;
        syncFilterUI();
        // Re-run so the freshly built buttons get their counts too.
        if (accounts.length) applyFacets(facets, []);
    }
}

function applyStatusFilterOptions(statuses){
    const sig = statuses.map(s=>s.id+':'+s.name).join('|');
    if (sig === (applyStatusFilterOptions._sig||'')) return;
    applyStatusFilterOptions._sig = sig;

    STATUS_NAMES = {};
    document.getElementById('convStatusOpts').innerHTML = statuses.map(s=>{
        STATUS_NAMES[s.id] = s.name;
        // Name in its own unclassed <span> so the CSS can ellipsis it — a
        // custom status can be 60 characters and would otherwise widen the
        // button past the panel.
        return '<button data-v="'+s.id+'"><span class="tva-cs__dot" style="background:'+h(s.color)+'"></span>'
             + '<span>'+h(s.name)+'</span> <b data-n="statuses.'+s.id+'"></b></button>';
    }).join('');
    document.getElementById('convStatusGroup').hidden = statuses.length === 0;
    syncFilterUI();
}

function syncFilterUI(){
    document.querySelectorAll('.tva-fp__opts').forEach(box=>{
        const g = box.dataset.group;
        box.querySelectorAll('button').forEach(b=>{
            const on = box.dataset.single ? FILTERS[g] === b.dataset.v : FILTERS[g].has(b.dataset.v);
            b.classList.toggle('is-on', on);
        });
    });

    document.querySelector('[data-quick="unread"]').classList.toggle('is-on', FILTERS.read === 'unread');
    document.querySelector('[data-quick="needs_reply"]').classList.toggle('is-on', isNeedsReply());
    document.querySelector('[data-quick="needs_human"]').classList.toggle('is-on', FILTERS.needs_human);

    const n = activeFilterCount(), badge = document.getElementById('filterCount');
    badge.textContent = n; badge.hidden = n === 0;

    renderActiveFilters();
}

// "Needs reply" is a saved combination, not a dimension of its own: unread,
// and still inside the window where a free-form answer is allowed.
function isNeedsReply(){
    return FILTERS.read === 'unread'
        && FILTERS.states.size === 2
        && FILTERS.states.has('active') && FILTERS.states.has('expiring');
}

// Each pill names its dimension ("Channel: Instagram"), because the value
// alone is ambiguous once several filters are on — "Active" could plausibly
// be a status or an agent, and "Today" could be a date or a shift.
const FILTER_DIMENSIONS = {
    states:'Window', channels:'Channel', kinds:'Type', handlers:'Handled by',
    accounts:'Page', conv_statuses:'Status', read:'', date:'Activity', needs_human:'',
};

function renderActiveFilters(){
    const pills = [];
    const add = (group, value, label) =>
        pills.push('<span class="tva-af__pill">'
            + (FILTER_DIMENSIONS[group] ? '<em>' + h(FILTER_DIMENSIONS[group]) + '</em>' : '')
            + h(label)
            + '<button onclick="removeFilter(\''+group+'\',\''+h(value)+'\')" title="Remove this filter">'
            + '<i data-lucide="x"></i></button></span>');

    for (const g of ['states','channels','kinds','handlers']) {
        FILTERS[g].forEach(v => add(g, v, FILTER_LABELS[g][v] || v));
    }
    FILTERS.accounts.forEach(v => add('accounts', v, ACCOUNT_NAMES[v] || v));
    FILTERS.conv_statuses.forEach(v => add('conv_statuses', v, STATUS_NAMES[v] || v));
    if (FILTERS.needs_human) add('needs_human', '1', 'Needs a person');
    if (FILTERS.read) add('read', FILTERS.read, FILTER_LABELS.read[FILTERS.read]);
    if (FILTERS.date) add('date', FILTERS.date, FILTER_LABELS.date[FILTERS.date]);

    const box = document.getElementById('activeFilters');
    box.hidden = pills.length === 0;
    box.innerHTML = pills.join('')
        + (pills.length > 1 ? '<button class="tva-af__clear" onclick="clearFilters()">Clear all</button>' : '');
    if (window.lucide) lucide.createIcons();
}

function removeFilter(group, value){
    if (group === 'needs_human') FILTERS.needs_human = false;
    else if (group === 'read' || group === 'date') FILTERS[group] = null;
    // Sets are keyed by the option's own type; status ids arrive as numbers
    // from the payload and as strings from a pill's onclick, so try both.
    else { FILTERS[group].delete(value); FILTERS[group].delete(Number(value)); }
    syncFilterUI(); loadConvos();
}

function clearFilters(){
    MULTI.forEach(k=>FILTERS[k].clear());
    FILTERS.read = null; FILTERS.date = null; FILTERS.needs_human = false;
    syncFilterUI(); loadConvos();
}
function applyPresence(me){
    const sel=document.getElementById('presenceSel');
    if(me){ sel.style.display=''; if(!sel.dataset.touched) sel.value=me.presence; }
    else { sel.style.display='none'; }
}
// (handoffBadge removed — rowTags() supersedes it and shows the same handoff
//  state alongside the assignee avatar and the conversation status.)
function renderConvos(list){
    const q=(document.getElementById('chatSearch').value||'').toLowerCase();
    document.getElementById('chatConvos').innerHTML = list.filter(c=>!q||(c.name||'').toLowerCase().includes(q)).map(c=>`
        <div class="tva-convo ${c.id===activeSid?'is-active':''}" onclick="openThread(${c.id})">
            <div class="tva-convo__av">${avatarHtml(c.avatar, c.id, c.name)}</div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2"><span class="tva-convo__name flex-1 truncate">${h(c.name)}</span><span class="tva-badge tva-badge--${c.channel} tva-badge--icon" title="${h(channelLabel(c.channel))}">${channelIcon(c.channel)}</span></div>
                <div class="flex items-center gap-2 mt-0.5">
                    <span class="tva-convo__last flex-1">${h(c.last_message)}</span>
                    <span class="tva-convo__time">${timeAgo(c.last_at)}</span>
                    ${c.unread_count>0?`<span class="tva-unread">${c.unread_count>99?'99+':c.unread_count}</span>`:(c.unread?'<span class="tva-dot tva-dot--unread"></span>':'')}
                    <span class="tva-st tva-st--${c.state||'active'}" title="${h(STATE_HINTS[c.state]||'')}"></span>
                </div>
                ${rowTags(c)}
            </div>
        </div>`).join('') || emptyListHtml(q);
}

/**
 * The third line of a conversation row: who owns it, whether a person is
 * needed, and the customer-defined status.
 *
 * Ordered by urgency, not by category — "needs a human" is the only one that
 * is a call to action, so it comes first and is the only one that shouts.
 */
function rowTags(c){
    const tags = [];

    if (c.needs_human) {
        tags.push('<span class="tva-tag tva-tag--alert" title="The customer asked for a person, or the AI escalated — nobody has replied yet">'
            + '<i data-lucide="hand"></i>Needs a person</span>');
    }

    const hd = c.handler || {type:'bot', name:'AI agent'};
    if (hd.type === 'agent') {
        // A named person gets an avatar, so a glance down the column shows who
        // is carrying what.
        tags.push('<span class="tva-tag tva-tag--agent" title="Assigned to '+h(hd.name)+'">'
            + '<span class="tva-tag__ini">'+h(initials(hd.name))+'</span>'+h(hd.name)+'</span>');
    } else if (hd.type === 'bot') {
        tags.push('<span class="tva-tag tva-tag--bot" title="The AI is handling this conversation">'
            + '<i data-lucide="bot"></i>AI agent</span>');
    } else if (hd.type === 'queued') {
        tags.push('<span class="tva-tag tva-tag--wait" title="Escalated — waiting for an agent to free up">'
            + '<i data-lucide="clock"></i>Queued</span>');
    } else if (hd.type === 'human') {
        tags.push('<span class="tva-tag tva-tag--agent" title="A person has taken this over">'
            + '<i data-lucide="user"></i>Person</span>');
    }

    if (c.status) {
        tags.push('<span class="tva-tag" style="color:'+h(c.status.color)+';border-color:'+h(c.status.color)+'44;'
            + 'background:'+h(c.status.color)+'14" title="Status: '+h(c.status.name)+'">'
            + '<span class="tva-cs__dot" style="background:'+h(c.status.color)+'"></span>'+h(c.status.name)+'</span>');
    }

    return tags.length ? '<div class="tva-tags">'+tags.join('')+'</div>' : '';
}

// The state dot doubles as the legend for the Status filter — same four
// colours, so a row's colour and a filter option's colour mean one thing.
const STATE_HINTS = {
    active:   'Active — reply freely',
    expiring: 'Expiring soon — under 2 hours to reply without a template',
    expired:  '24-hour window closed — only an approved template will reopen it',
    closed:   'Closed',
};

/**
 * An empty list has to say WHY it is empty, because the three reasons need
 * three different actions: clear a filter, clear the search, or wait for a
 * message. "No conversations." covered all three and helped with none.
 */
function emptyListHtml(query){
    if (query) {
        return '<div class="tva-empty-sm">No conversation matches “' + h(query) + '”.</div>';
    }
    if (FILTERS.kinds.has('comment') && FILTERS.kinds.size === 1) {
        // Honest about a real gap rather than looking broken: comments are not
        // ingested yet, so this filter cannot have results.
        return '<div class="tva-empty-sm">Post comments aren’t being collected yet — only direct messages'
             + ' reach the inbox for now.<br><button class="tva-af__clear" onclick="clearFilters()">Clear filters</button></div>';
    }
    if (activeFilterCount() > 0) {
        return '<div class="tva-empty-sm">Nothing matches these filters.'
             + '<br><button class="tva-af__clear" onclick="clearFilters()">Clear filters</button></div>';
    }
    return '<div class="tva-empty-sm">No conversations yet.</div>';
}
function applyHandoff(d){
    const badge=document.getElementById('hdrHandoff'); const btn=document.getElementById('handoffBtn'); const ho=d.handoff||'bot';
    if(ho==='assigned'){ badge.style.display=''; badge.style.background='#e0e7ff'; badge.style.color='#3730a3'; badge.textContent='🙋 '+(d.assigned_to||'Agent'); }
    else if(ho==='queued'){ badge.style.display=''; badge.style.background='#fef3c7'; badge.style.color='#92400e'; badge.textContent='⏳ Queued'; }
    else { badge.style.display='none'; }
    if(d.is_human_agent && ho==='assigned'){ btn.style.display=''; btn.className='btn btn-sm btn-secondary'; btn.textContent='Resolve'; btn.onclick=resolveChat; }
    else if(d.is_human_agent && (ho==='queued'||ho==='bot')){ btn.style.display=''; btn.className='btn btn-sm btn-primary'; btn.textContent='Take chat'; btn.onclick=claimChat; }
    else { btn.style.display='none'; }
}
async function claimChat(){ const r=await api(msgUrl('claim'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId})}); if(!r.ok){ tvaToast('Could not take chat.','error'); return; } tvaToast('Chat assigned to you','success'); loadThread(true); loadConvos(); }
async function resolveChat(){ const r=await api(msgUrl('resolve'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId})}); if(!r.ok){ tvaToast('Could not resolve.','error'); return; } tvaToast('Resolved — handed back to AI','success'); loadThread(true); loadConvos(); }

// ── Thread ──
async function openThread(sid){
    activeSid=sid; lastMsgId=0;
    window.MSGS={}; window.MEDIA=[]; window.lastDay=null; clearReply();
    document.getElementById('chatEmpty').style.display='none';
    document.getElementById('chatPane').style.display='flex';
    document.getElementById('chatThread').innerHTML='';
    closePops();
    // Swaps the panes on mobile; inert on desktop, where the CSS for this
    // class only exists inside the phone media query.
    document.querySelector('.tva-chat').classList.add('is-thread-open');
    await loadThread(true);
    if(threadTimer) clearInterval(threadTimer);
    threadTimer=setInterval(()=>loadThread(false),4000);
    loadConvos();
}
async function loadThread(full){
    try{
        const r=await api(msgUrl('messages')+'?project_id='+CHAT.projectId+'&after='+(full?0:lastMsgId));
        const d=await r.json();
        if(full){ applyHeader(d); applyHandoff(d); applyStatuses(d); applyTransfer(d); }
        applyMetrics(d);       // refreshed on every poll so the timer never drifts
        applyReplyPolicy(d);   // and so the composer unlocks the moment they reply
        appendMessages(d.messages||[]);
        applyWindow(d);
        currentBotPaused = !!d.bot_paused;
        refreshTyping();
    }catch(e){}
}
function applyHeader(d){
    const c=d.contact||{};
    var hdrName=document.getElementById('hdrName');
    // Clicking the name opens OUR contact profile, not the customer's social
    // profile — the second is only resolvable on WhatsApp anyway, and the
    // first is the thing an agent actually needs mid-conversation.
    hdrName.innerHTML = '<a href="#" onclick="openContact();return false;" '
        + 'title="Open contact profile">' + h(c.name||'') + '</a>';
    // Channel mark + the Page/number this conversation is on, linked where
    // Meta exposes a public URL (Page ids are public; customer PSIDs are not).
    var hdrCh=document.getElementById('hdrChannel');
    var chLabel = h(c.channel_name || channelLabel(c.channel));
    var chInner = channelIcon(c.channel) + '<span class="ml-1">' + chLabel + '</span>';
    hdrCh.innerHTML = c.channel
        ? (c.channel_url
            ? '<a href="' + h(c.channel_url) + '" target="_blank" rel="noopener" title="Open ' + chLabel + '">' + chInner + '</a>'
            : chInner)
        : '';
    document.getElementById('hdrAvatar').innerHTML=avatarHtml(c.avatar, activeSid, c.name);
    setBot(d.bot_paused);
    document.querySelectorAll('.wa-only').forEach(b=> b.style.display=(c.channel==='whatsapp')?'flex':'none');
}
function applyWindow(d){
    // The countdown itself now lives in the metric strip, which ticks every
    // second — a single owner, so the header cannot show one number while the
    // banner implies another.
    const banner=document.getElementById('windowBanner'), ta=document.getElementById('chatInput');
    if(d.window_open){
        banner.innerHTML=''; ta.disabled=false;
    }else{
        banner.innerHTML='<div class="tva-window-banner"><i data-lucide="clock" class="w-4 h-4"></i><span>24-hour window closed. Reply via an approved <b>template</b> to start a new conversation.</span></div>';
        if(window.lucide) try{lucide.createIcons();}catch(_){}
    }
}

// ── Header metrics ──
let METRICS = null;

/**
 * Hours, minutes and seconds with their units spelled out:
 * "4h 31m 47s" · "31m 47s" · "47s".
 *
 * Always to the second. It was "4h 31m", which only changed once a minute — so
 * for 59 seconds out of every 60 the countdown looked frozen, indistinguishable
 * from a static number that had failed to load.
 *
 * Unit letters rather than a bare "4:31:47" clock, because a colon-separated
 * triple is ambiguous at a glance — it reads as a time of day just as easily as
 * a duration.
 */
function fmtCountdown(sec){
    if (sec <= 0) return 'Expired';
    const hh=Math.floor(sec/3600), mm=Math.floor((sec%3600)/60), ss=sec%60;
    const p = n => String(n).padStart(2,'0');

    if (hh > 0) return hh+'h '+p(mm)+'m '+p(ss)+'s';
    if (mm > 0) return mm+'m '+p(ss)+'s';
    return ss+'s';
}

/** Elapsed time in one unit — "45s", "12m", "3h", "6d". */
function fmtDur(sec){
    if (sec === null || sec === undefined) return '—';
    if (sec < 60)    return Math.round(sec)+'s';
    if (sec < 3600)  return Math.round(sec/60)+'m';
    if (sec < 86400) return Math.round(sec/3600)+'h';
    return Math.round(sec/86400)+'d';
}

function applyMetrics(d){
    METRICS = d.metrics || null;
    renderMetrics();
}

/**
 * Every chip carries a VISIBLE label, not just a tooltip.
 *
 * Four numbers with only icons to tell them apart meant the header could not
 * be read without hovering each one — and a tooltip is not an answer when the
 * question is "what am I looking at?". The label costs one 8px line and
 * removes the guessing entirely.
 */
function renderMetrics(){
    const box = document.getElementById('hdrMetrics');
    if (!box) return;
    if (!METRICS) { box.innerHTML=''; return; }

    const m = METRICS, now = Date.now()/1000, chips = [];

    // Label + value, plus a per-metric accent so the four are distinguishable
    // at a glance without any of them competing with the deadline for alarm.
    const chip = (kind, label, value, title, extra='') =>
        '<span class="tva-mx__c tva-mx__c--'+kind+'" title="'+title+'">'
        + '<i data-lucide="'+({window:'timer',started:'play',first:'zap',rate:'trending-up',lead:'user-check'}[kind])+'"></i>'
        + '<span class="tva-mx__t"><span class="tva-mx__lbl">'+label+'</span>'
        + '<b class="tva-mx__v">'+value+'</b></span>' + extra + '</span>';

    // 1. The reply window — the only hard deadline in the inbox, so it leads
    //    and it is the only chip whose colour changes.
    if (m.window_expires_at) {
        const left = Math.floor(m.window_expires_at - now);
        const pct  = Math.max(0, Math.min(100, (left / (m.window_seconds||86400)) * 100));

        chips.push('<span id="mxWindow" class="tva-mx__c tva-mx__c--window '+windowTone(left)+'" title="'
            + (left<=0 ? 'Meta’s 24-hour reply window has closed — only an approved template can reopen it'
                       : 'Time left to reply without an approved template')+'">'
            + '<i data-lucide="timer"></i>'
            + '<span class="tva-mx__t"><span class="tva-mx__lbl">'
            + (left<=0 ? 'Reply window' : 'Expires in') + '</span>'
            + '<b class="tva-mx__v" id="mxCountdown">'+h(fmtCountdown(left))+'</b></span>'
            // A dot that blinks once a second, so the number is visibly live
            // rather than possibly stale.
            + (left>0 ? '<span class="tva-mx__pulse"></span>' : '')
            + '<span class="tva-mx__bar" id="mxBar" style="width:'+pct.toFixed(1)+'%"></span></span>');
    }

    if (m.started_at) {
        chips.push(chip('started', 'Started', h(fmtDur(now - m.started_at)) + ' ago',
            'Conversation started '+h(new Date(m.started_at*1000).toLocaleString())));
    }

    chips.push(chip('first', 'First reply', h(fmtDur(m.first_response)),
        m.first_response===null ? 'No reply has been sent yet'
            : 'The first reply took '+m.first_response+'s after the customer’s first message'));

    if (m.conversion_rate !== null && m.conversion_rate !== undefined) {
        chips.push(chip('rate', 'Converted', m.conversion_rate+'%',
            'Leads converted across this project — the same figure as the dashboard'));
    }

    if (m.lead) {
        chips.push(chip('lead', 'Lead', h(m.lead.status),
            'This conversation produced a lead'
            + (m.lead.confidence!==null ? ' · '+m.lead.confidence+'% confidence' : '')));
    }

    box.innerHTML = chips.join('');
    if (window.lucide) try{ lucide.createIcons(); }catch(_){}
}

function windowTone(left){
    return left <= 0 ? 'is-dead' : (left < 7200 ? 'is-warn' : 'is-ok');
}

/**
 * The per-second tick.
 *
 * Patches only the countdown text, the progress bar and the tone class —
 * NOT the whole strip. Rebuilding innerHTML every second would re-run
 * lucide.createIcons() on the entire header sixty times a minute, which
 * flickers the icons and throws away any text selection the user had.
 */
function tickCountdown(){
    if (!METRICS || !METRICS.window_expires_at) return;

    const el = document.getElementById('mxCountdown');
    if (!el) return;

    const left = Math.floor(METRICS.window_expires_at - Date.now()/1000);
    const text = fmtCountdown(left);
    if (el.textContent !== text) el.textContent = text;

    const wrap = document.getElementById('mxWindow');
    if (wrap) {
        const tone = windowTone(left);
        if (!wrap.classList.contains(tone)) {
            wrap.classList.remove('is-ok','is-warn','is-dead');
            wrap.classList.add(tone);
        }
    }

    const bar = document.getElementById('mxBar');
    if (bar) {
        bar.style.width = Math.max(0, Math.min(100,
            (left / (METRICS.window_seconds||86400)) * 100)).toFixed(2) + '%';
    }

    // Crossing zero changes the label and drops the pulse, which is a
    // structural change rather than a text one.
    if (left <= 0 && wrap && wrap.querySelector('.tva-mx__pulse')) renderMetrics();
}

setInterval(tickCountdown, 1000);

/**
 * Back to the conversation list (mobile).
 *
 * Stops the 4-second thread poll as well as swapping the panes — a phone
 * sitting on the list should not keep refetching a thread nobody is
 * looking at, on a connection that is usually metered.
 */
function closeThread(){
    document.querySelector('.tva-chat').classList.remove('is-thread-open');
    closeContact();
    if (threadTimer) { clearInterval(threadTimer); threadTimer = null; }
    activeSid = null;
    loadConvos();
}

// ── Contact profile ──
let CONTACT = null;

/** Keep the layout class in step with the panel — the header sheds its
 *  optional metrics while the profile is taking 320px. */
function setContactPanel(open){
    document.getElementById('contactPanel').hidden = !open;
    document.querySelector('.tva-chat').classList.toggle('has-profile', open);
}

function closeContact(){ setContactPanel(false); }

async function openContact(){
    if (!activeSid) return;
    const panel = document.getElementById('contactPanel');

    // Second click closes it. The panel costs screen width, so an agent
    // toggling it off must not have to hunt for the ✕.
    if (!panel.hidden) { setContactPanel(false); return; }

    setContactPanel(true);
    document.getElementById('contactBody').innerHTML = '<div class="tva-empty-sm">Loading…</div>';

    try {
        const r = await api(msgUrl('contact') + '?project_id=' + CHAT.projectId);
        const d = await r.json();
        if (!d.ok) {
            document.getElementById('contactBody').innerHTML =
                '<div class="tva-empty-sm">' + h(d.message || 'No contact record for this conversation.') + '</div>';
            return;
        }
        CONTACT = d.contact;
        renderContact();
    } catch (e) {
        document.getElementById('contactBody').innerHTML =
            '<div class="tva-empty-sm">Could not load the contact.</div>';
    }
}

function renderContact(){
    const c = CONTACT, m = c.messages, e = c.engagement;
    const parts = [];

    // Identity, editable in place.
    parts.push('<div class="tva-cp__id">'
        // The photo is the control. A separate "change image" button would
        // be one more thing to find; clicking the picture is where everyone
        // tries first anyway.
        + '<div class="tva-cp__av" id="cpAvatar" onclick="pickContactAvatar()" title="Click to change the photo">'
        + avatarHtml(c.avatar, c.id, c.name)
        + '<span class="tva-cp__cam"><i data-lucide="camera"></i></span></div>'
        + '<div class="tva-cp__name">' + h(c.name) + '</div>'
        + (c.email ? '<div class="tva-cp__sub">' + h(c.email) + '</div>' : '')
        + (c.phone ? '<div class="tva-cp__sub">+' + h(c.phone) + '</div>' : '')
        + '<button class="tva-cp__btn" style="margin-top:8px" onclick="editContact()">Edit contact</button>'
        + '</div>');

    // Engagement, with its reasoning visible.
    parts.push('<div class="tva-cp__sec"><div class="tva-cp__h">Engagement</div>'
        + '<div class="tva-cp__score"><b>' + e.score + '</b>'
        + '<span class="tva-cp__badge tva-cp__badge--' + e.label.toLowerCase() + '">' + h(e.label) + '</span></div>'
        + '<div class="tva-cp__bar"><i style="width:' + e.score + '%"></i></div>'
        + e.reasons.map(r => '<div class="tva-cp__why"><span>' + h(r[0]) + '</span><span>' + h(r[1]) + '</span></div>').join('')
        + '</div>');

    // Where they reach you, and how much they actually write.
    parts.push('<div class="tva-cp__sec"><div class="tva-cp__h">Channels</div>'
        + (c.identities.length
            ? c.identities.map(i => '<span class="tva-cp__pill">' + channelIcon(i.channel) + h(i.label) + '</span>').join('')
            : '<div class="tva-cp__sub">No linked channels yet.</div>')
        + '</div>');

    const chans = Object.entries(m.by_channel || {});
    parts.push('<div class="tva-cp__sec"><div class="tva-cp__h">Messages</div>'
        + '<div class="tva-cp__row"><i data-lucide="messages-square"></i>Total<b>' + m.total + '</b></div>'
        + '<div class="tva-cp__row"><i data-lucide="arrow-down-left"></i>From customer<b>' + m.inbound + '</b></div>'
        + '<div class="tva-cp__row"><i data-lucide="arrow-up-right"></i>From you<b>' + m.outbound + '</b></div>'
        + chans.map(([ch, n]) => '<div class="tva-cp__row">' + channelIcon(ch) + h(channelLabel(ch)) + '<b>' + n + '</b></div>').join('')
        + '</div>');

    // Merges: what was folded in, and what a human still needs to decide.
    if ((c.merged || []).length) {
        parts.push('<div class="tva-cp__sec"><div class="tva-cp__h">Merged profiles</div>'
            + c.merged.map(x => '<div class="tva-cp__row"><i data-lucide="git-merge"></i>'
                + h(x.name || ('Contact #' + x.id)) + '</div>').join('')
            + '</div>');
    }

    if ((c.suggested || []).length) {
        parts.push('<div class="tva-cp__sec"><div class="tva-cp__h">Possible duplicates</div>'
            + c.suggested.map(s => '<div class="tva-cp__merge">'
                + '<div><b>' + h(s.name || ('Contact #' + s.id)) + '</b></div>'
                + '<div style="margin:3px 0 6px">' + h(s.reason) + '</div>'
                + '<button class="tva-cp__btn" onclick="mergeContact(' + s.id + ')">Merge into this contact</button>'
                + '</div>').join('')
            + '</div>');
    }

    // Leads roll up to the person, not the conversation.
    parts.push('<div class="tva-cp__sec"><div class="tva-cp__h">Leads (' + c.leads.count + ')</div>'
        + (c.leads.count
            ? c.leads.items.map(l => '<div class="tva-cp__lead" onclick="openLead(' + l.id + ')">'
                + '<i data-lucide="user-check"></i><span class="flex-1">' + h(l.status) + '</span>'
                + (l.confidence !== null ? '<span class="tva-cp__sub">' + l.confidence + '%</span>' : '')
                + '</div>').join('')
            : '<div class="tva-cp__sub">No leads captured yet.</div>')
        + '</div>');

    document.getElementById('contactBody').innerHTML = parts.join('');
    if (window.lucide) try { lucide.createIcons(); } catch(_){}
}

function openLead(id){ window.open(CHAT.leadsUrl + '?lead=' + id, '_blank'); }

function pickContactAvatar(){
    const input = document.getElementById('contactAvatarInput');
    input.value = '';                 // re-picking the same file must still fire
    input.click();
}

async function uploadContactAvatar(file){
    if (!file || !CONTACT) return;

    const body = new FormData();
    body.append('project_id', CHAT.projectId);
    body.append('avatar', file);

    const r = await api(CHAT.base + '/contacts/' + CONTACT.id, {method:'POST', body});
    if (!r.ok) {
        const e = await r.json().catch(()=>({}));
        tvaToast(e.message || 'Could not upload that image.', 'error');
        return;
    }

    CONTACT = (await r.json()).contact;
    renderContact();
    // The list and the open thread both render from the contact now, so
    // they have to refetch or the old photo stays on screen beside the new
    // one — which reads as the upload having failed.
    loadConvos();
    loadThread(true);
    tvaToast('Photo updated', 'success');
}

async function editContact(){
    const v = await tvaPrompt({
        title: 'Edit contact',
        text:  'Corrections here are permanent — the AI will not overwrite them from a later conversation.',
        fields: [
            {name:'name',  label:'Name',  value: CONTACT.raw_name || ''},
            {name:'email', label:'Email', value: CONTACT.email || ''},
            {name:'phone', label:'Phone', value: CONTACT.phone || ''},
            {name:'notes', label:'Notes', value: CONTACT.notes || ''},
        ],
        confirmText: 'Save',
    });
    if (!v) return;

    const body = new FormData();
    body.append('project_id', CHAT.projectId);
    ['name','email','phone','notes'].forEach(k => body.append(k, v[k] || ''));

    const r = await api(CHAT.base + '/contacts/' + CONTACT.id, {method:'POST', body});
    if (!r.ok) { tvaToast('Could not save the contact.','error'); return; }

    CONTACT = (await r.json()).contact;
    renderContact();
    loadConvos();
    tvaToast('Contact updated','success');
}

async function mergeContact(otherId){
    const ok = await tvaConfirm({
        title: 'Merge these contacts?',
        text:  'Their conversations, channels and leads move onto this contact. This cannot be undone.',
        confirmText: 'Merge',
    });
    if (!ok) return;

    const r = await api(CHAT.base + '/contacts/' + CONTACT.id + '/merge', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({project_id: CHAT.projectId, other: otherId}),
    });
    if (!r.ok) {
        const e = await r.json().catch(()=>({}));
        tvaToast(e.message || 'Could not merge.','error');
        return;
    }
    CONTACT = (await r.json()).contact;
    renderContact();
    loadConvos();
    tvaToast('Contacts merged','success');
}

// ── Reply window enforcement ──
// The rules come from the server (ChatController::replyPolicy). Meta's
// allowances differ per channel and change; deciding this in JS as well would
// guarantee the two drift, and the failure mode is a composer that looks
// usable and then 409s after the agent has typed a paragraph.
function applyReplyPolicy(d){
    const p = d.reply_policy || {allowed:true, mode:'free'};
    const ta = document.getElementById('chatInput');
    const row = document.getElementById('composerRow');

    ta.disabled = !p.allowed;
    ta.placeholder = p.allowed
        ? (p.mode === 'human_agent' ? 'Reply (human-agent window)…' : 'Type a message…')
        : 'Replies are closed until the customer writes again';
    row.classList.toggle('is-locked', !p.allowed);

    // Everything that sends is gated together — leaving the attach or voice
    // buttons live on a locked conversation just moves the failure.
    ['btnSend','btnVoice','btnMore','btnEmoji'].forEach(id=>{
        const b=document.getElementById(id); if(b) b.disabled = !p.allowed;
    });

    // Templates are the one thing that still works on an expired WhatsApp
    // thread — it is the documented way back in, so it stays enabled.
    const tpl=document.getElementById('btnTemplate');
    if (tpl) tpl.disabled = false;

    const note=document.getElementById('policyNote');
    if (note) {
        note.hidden = !p.reason;
        note.className = 'tva-policy' + (p.allowed ? ' is-soft' : '');
        note.textContent = p.reason || '';
    }
}

// ── Transfer ──
let AGENTS = [], IS_OWNER = false;

function applyTransfer(d){
    AGENTS = d.agents || [];
    IS_OWNER = !!d.is_owner;
    renderTransferControl(d);
}

function renderTransferControl(d){
    const cur = AGENTS.find(a=>a.current) || null;
    document.getElementById('transferLabel').textContent = cur ? cur.name : 'Transfer';

    if (!AGENTS.length) {
        document.getElementById('transferOpts').innerHTML =
            '<div class="tva-cs__none">No human agents on this project yet.<br>'
            + 'Add them under <b>Agents</b> to hand conversations over.</div>';
        return;
    }

    // Presence and current load are shown because handing a chat to someone
    // offline with six open threads is the mistake this data prevents.
    document.getElementById('transferOpts').innerHTML = AGENTS.map(a=>{
        const dot = a.presence==='online' ? '#22c55e' : (a.presence==='away' ? '#f59e0b' : '#94a3b8');
        const full = a.max !== null && a.load >= a.max;
        return '<button type="button" class="tva-cs__opt'+(a.current?' is-on':'')+'" onclick="transferTo('+a.id+')">'
            + '<span class="tva-cs__ini">'+h(initials(a.name))+'</span>'
            + '<span class="flex-1 min-w-0"><span class="block truncate">'+h(a.name)+(a.me?' (you)':'')+'</span>'
            + '<span class="tva-cs__sub"><span class="tva-cs__dot" style="background:'+dot+'"></span>'
            + h(a.presence)+' · '+a.load+(a.max!==null?'/'+a.max:'')+' open</span></span>'
            + (full?'<span class="tva-cs__tag">full</span>':'')
            + '</button>';
    }).join('')
    + '<div class="tva-cs__sep"></div>'
    + '<button type="button" class="tva-cs__opt tva-cs__opt--clear" onclick="transferTo(null)">'
    + '<i data-lucide="bot"></i> Hand back to the AI</button>';
}

async function transferTo(agentId){
    document.getElementById('transferMenu').hidden = true;

    const r = await api(msgUrl('transfer'), {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({project_id: CHAT.projectId, agent: agentId}),
    });
    if (!r.ok) {
        const e = await r.json().catch(()=>({}));
        tvaToast(e.message || 'Could not transfer this conversation.','error');
        return;
    }
    const d = await r.json();
    tvaToast('Transferred to '+(d.assigned_to||'the AI'),'success');
    loadThread(true); loadConvos();
}

// ── Conversation status ──
let STATUSES = [], CURRENT_STATUS = null;

function applyStatuses(d){
    STATUSES = d.statuses || [];
    CURRENT_STATUS = d.status_id || null;
    renderStatusControl();
}

function renderStatusControl(){
    const cur = STATUSES.find(s=>s.id===CURRENT_STATUS) || null;
    document.getElementById('statusLabel').textContent = cur ? cur.name : 'Set status';
    const dot = document.getElementById('statusDot');
    dot.style.background = cur ? cur.color : 'transparent';
    dot.style.boxShadow  = cur ? 'none' : 'inset 0 0 0 1.5px #cbd5e1';

    document.getElementById('statusOpts').innerHTML = STATUSES.map(s=>
        '<button type="button" class="tva-cs__opt'+(s.id===CURRENT_STATUS?' is-on':'')+'" onclick="setStatus('+s.id+')">'
        + '<span class="tva-cs__dot" style="background:'+h(s.color)+'"></span>'
        + '<span class="flex-1 truncate">'+h(s.name)+'</span>'
        + (s.is_closing?'<span class="tva-cs__tag">closes</span>':'')
        + '</button>'
    ).join('')
    + (CURRENT_STATUS ? '<button type="button" class="tva-cs__opt tva-cs__opt--clear" onclick="setStatus(null)">Clear status</button>' : '');
}

// Managed from the inbox rather than a settings page: statuses are only ever
// edited while looking at the conversations they describe, and a round trip
// to Settings to add "Waiting on parts" then back is three navigations for
// one word.
const STATUS_PALETTE = @json(\App\Models\ConversationStatus::PALETTE);

function openStatusManager(){
    document.getElementById('statusMenu').hidden = true;
    document.getElementById('statusMgr').classList.add('open');
    renderStatusManager();
}
function closeStatusManager(){ document.getElementById('statusMgr').classList.remove('open'); }

function renderStatusManager(editing){
    const rows = STATUSES.map(s=>
        '<div class="tva-sm__row">'
        + '<span class="tva-cs__dot" style="background:'+h(s.color)+'"></span>'
        + '<span class="flex-1 truncate">'+h(s.name)+'</span>'
        + (s.is_closing?'<span class="tva-cs__tag">closes</span>':'')
        + '<button class="tva-sm__ico" title="Rename" onclick="editStatusRow('+s.id+')"><i data-lucide="pencil"></i></button>'
        + '<button class="tva-sm__ico tva-sm__ico--del" title="Archive" onclick="archiveStatus('+s.id+')"><i data-lucide="archive"></i></button>'
        + '</div>').join('');

    const e = editing || {id:null, name:'', color:STATUS_PALETTE[0], is_closing:false};
    document.getElementById('statusMgrBody').innerHTML = rows
        + '<div class="tva-sm__form">'
        + '<input id="smName" class="form-control form-control-sm" maxlength="60" placeholder="Status name" value="'+h(e.name)+'">'
        + '<div class="tva-sm__sw">' + STATUS_PALETTE.map(c=>
            '<button type="button" class="tva-sm__c'+(c===e.color?' is-on':'')+'" style="background:'+c+'" data-c="'+c+'"'
            + ' onclick="pickStatusColor(this)" title="'+c+'"></button>').join('') + '</div>'
        + '<label class="tva-sm__chk"><input type="checkbox" id="smClosing"'+(e.is_closing?' checked':'')+'>'
        + '<span>Marks the conversation resolved</span></label>'
        + '<div id="smError" class="tva-sm__err" hidden></div>'
        + '<div class="flex gap-2">'
        + '<button id="smSave" class="btn btn-sm btn-primary flex-1" onclick="saveStatus('+(e.id||'null')+')">'+(e.id?'Save':'Add status')+'</button>'
        + (e.id?'<button class="btn btn-sm btn-secondary" onclick="renderStatusManager()">Cancel</button>':'')
        + '</div></div>';
    document.getElementById('statusMgrBody').dataset.color = e.color;
    if (window.lucide) try{ lucide.createIcons(); }catch(_){}
}

function pickStatusColor(btn){
    document.getElementById('statusMgrBody').dataset.color = btn.dataset.c;
    btn.closest('.tva-sm__sw').querySelectorAll('.tva-sm__c').forEach(b=>b.classList.toggle('is-on', b===btn));
}
function editStatusRow(id){ renderStatusManager(STATUSES.find(s=>s.id===id)); }

/** Show the reason inside the modal, where the user is actually looking. */
function statusMgrError(msg){
    const box = document.getElementById('smError');
    if (!box) return;
    box.hidden = !msg;
    box.textContent = msg || '';
}

async function saveStatus(id){
    statusMgrError('');

    const name = (document.getElementById('smName').value||'').trim();
    if (!name) { statusMgrError('Give the status a name.'); return; }

    const btn = document.getElementById('smSave');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    // Wrapped, because an unhandled rejection here is INVISIBLE: `await` on a
    // fetch that throws inside an async click handler produces no error, no
    // toast and no change — which is exactly "click Add and nothing happens".
    try {
        const body = JSON.stringify({
            project_id: CHAT.projectId, name,
            color: document.getElementById('statusMgrBody').dataset.color,
            is_closing: document.getElementById('smClosing').checked,
        });
        const url = CHAT.base + '/statuses' + (id ? '/' + id : '');
        const r = await api(url, {method: id?'PATCH':'POST', headers:{'Content-Type':'application/json'}, body});

        if (!r.ok) {
            // The server's own message, not a generic one — it is the only
            // thing that can say "run tenant:migrate" or name the bad field.
            const e = await r.json().catch(()=>({}));
            statusMgrError(e.message
                || (e.errors ? Object.values(e.errors).flat().join(' ') : '')
                || ('The server refused this (HTTP '+r.status+').'));
            return;
        }

        const d = await r.json();
        STATUSES = d.statuses || STATUSES;
        renderStatusControl();
        renderStatusManager();
        tvaToast(id?'Status updated':'Status added','success');
    } catch (err) {
        statusMgrError('Could not reach the server: ' + (err && err.message ? err.message : 'network error'));
    } finally {
        const b = document.getElementById('smSave');
        if (b) { b.disabled = false; b.textContent = id ? 'Save' : 'Add status'; }
    }
}

async function archiveStatus(id){
    // Archived, not deleted — conversations already labelled with it keep the
    // label. Said plainly here so nobody expects a hard delete.
    const ok = await tvaConfirm({
        title:'Archive this status?',
        text:'It stops being offered for new conversations. Any conversation already using it keeps it.',
        confirmText:'Archive',
    });
    if (!ok) return;

    const r = await api(CHAT.base+'/statuses/'+id, {
        method:'DELETE', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({project_id: CHAT.projectId}),
    });
    if (!r.ok) { tvaToast('Could not archive it.','error'); return; }

    const d = await r.json();
    STATUSES = d.statuses || [];
    if (!STATUSES.some(s=>s.id===CURRENT_STATUS)) CURRENT_STATUS = null;
    renderStatusControl(); renderStatusManager(); loadConvos();
}

async function setStatus(id){
    document.getElementById('statusMenu').hidden = true;
    const prev = CURRENT_STATUS;
    CURRENT_STATUS = id; renderStatusControl();      // optimistic

    const r = await api(msgUrl('status'), {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({project_id: CHAT.projectId, status: id}),
    });
    if (!r.ok) {
        CURRENT_STATUS = prev; renderStatusControl();
        tvaToast('Could not set the status.','error');
        return;
    }
    // A closing status resolves the conversation server-side, so both the
    // thread header and the list have to be refetched rather than patched.
    loadThread(true); loadConvos();
}
/**
 * An internal event, drawn as a separator across the thread rather than a
 * bubble on one side — it belongs to neither party.
 *
 * A person is shown as an avatar disc with their initials; the AI as a glyph.
 * That asymmetry is the point: at a glance you can see whether a human or the
 * bot was on either end of the handover.
 */
function renderEvent(m){
    if (m.event !== 'transfer' || !m.from || !m.to) {
        // Unknown event — fall back to the sentence the server also stored, so
        // a newer event type degrades to plain text instead of vanishing.
        return '<div class="tva-ev"><span class="tva-ev__body">'+h(m.content||'')+'</span></div>';
    }

    const party = p => p.type === 'agent'
        ? '<span class="tva-ev__p"><span class="tva-ev__ini">'+h(initials(p.name))+'</span>'+h(p.name)+'</span>'
        : '<span class="tva-ev__p"><span class="tva-ev__bot"><i data-lucide="bot"></i></span>'+h(p.name)+'</span>';

    return '<div class="tva-ev"><span class="tva-ev__body">'
        + party(m.from)
        + '<i data-lucide="arrow-right" class="tva-ev__arrow"></i>'
        + party(m.to)
        + (m.by ? '<span class="tva-ev__by">by '+h(m.by)+'</span>' : '')
        + (m.note ? '<span class="tva-ev__note">'+h(m.note)+'</span>' : '')
        + '</span></div>';
}

/**
 * WhatsApp-style delivery ticks, on outbound messages only.
 *
 *   (nothing)  queued — we have not heard back from Meta yet
 *   ✓          sent
 *   ✓✓         delivered to the handset
 *   ✓✓ blue    read
 *   !          failed, with Meta's reason on hover
 *
 * Absence is meaningful and deliberately not filled in with a hopeful tick:
 * a message we cannot confirm left the building should not claim it did.
 */
function ticks(m){
    if (m.direction !== 'out' || !m.delivery) return '';

    if (m.delivery === 'failed') {
        return '<span class="tva-tick tva-tick--fail" title="'
            + h(m.delivery_error || 'Meta could not deliver this message') + '">!</span>';
    }
    var cls = m.delivery === 'read' ? ' tva-tick--read' : '';
    var mark = m.delivery === 'sent' ? '✓' : '✓✓';
    var label = {sent:'Sent', delivered:'Delivered', read:'Read'}[m.delivery] || '';

    return '<span class="tva-tick' + cls + '" title="' + label + '">' + mark + '</span>';
}

function appendMessages(msgs){
    const box=document.getElementById('chatThread'); const nearBottom = box.scrollHeight-box.scrollTop-box.clientHeight < 120;
    const tr=document.getElementById('typingRow'); if(tr) tr.remove();   // keep new content below the typing row
    msgs.forEach(m=>{
        if(m.id<=lastMsgId) return; lastMsgId=Math.max(lastMsgId,m.id);
        window.MSGS=window.MSGS||{}; window.MSGS[m.id]=m;
        const dk=dayKey(m.created_at);
        if(dk && dk!==window.lastDay){ box.insertAdjacentHTML('beforeend',`<div class="tva-sep">${h(fmtDay(m.created_at))}</div>`); window.lastDay=dk; }

        // Internal events are not messages — they were never sent to anyone.
        // Rendering one as an outbound bubble would claim the customer saw
        // "Transferred from … to …", which they did not.
        if (m.author === 'system') { box.insertAdjacentHTML('beforeend', renderEvent(m)); return; }

        window.MEDIA=window.MEDIA||[];
        (m.attachments||[]).forEach(a=>{ if(['image','sticker','video','document','audio'].includes(a.type)) window.MEDIA.push(a); });
        const cls=m.direction==='in'?'tva-msg--in':(m.author==='bot'?'tva-msg--bot':'tva-msg--out');
        // The owner gets their own label. When the boss answers a customer
        // directly the team needs to see that in the history — "Agent" for
        // someone who holds no seat would be actively misleading.
        const author=m.author==='customer' ? '' : (
            m.author==='bot'   ? '<div class="tva-msg__author">🤖 AI</div>' :
            m.author==='owner' ? '<div class="tva-msg__author"><span class="tva-msg__admin">Admin</span>'
                                 + h(m.author_name||'')+'</div>'
                               : '<div class="tva-msg__author">🙋 '+h(m.author_name||'Agent')+'</div>');
        const txt=m.content?`<div class="tva-msg__txt">${h(m.content)}</div>`:'';
        const atts=renderAtts(m.attachments||[]);
        const who=r=>r==='customer'?'Customer':(r==='bot'?'AI':'Agent');
        const quote=m.reply?`<div class="tva-quote"><b>${who(m.reply.author)}</b>${h(m.reply.preview||'')}</div>`:'';
        const edited=m.edited?' · edited':'';
        const canEdit=(m.author==='agent' && (Date.now()/1000 - m.created_at) < 900) ? 1 : 0;
        box.insertAdjacentHTML('beforeend',`<div class="tva-row tva-row--${m.direction==='in'?'in':'out'}">
            <button class="tva-row__more" onclick="openMsgMenu(event,${m.id},${canEdit})">⋮</button>
            <div class="tva-msg ${cls}" data-id="${m.id}">${quote}${author}${txt}${atts}<div class="tva-msg__time">${fmtTime(m.created_at)}${edited}${ticks(m)}</div></div>
        </div>`);
    });
    if(nearBottom) box.scrollTop=box.scrollHeight;
}
function renderAtts(atts){
    if(!atts.length) return '';
    const imgs=atts.filter(a=>a.type==='image'||a.type==='sticker');
    let html='';
    if(imgs.length>1){
        html+='<div class="tva-att-grid">'+imgs.map(a=>`<img class="tva-att-img" src="${h(a.proxy)}" loading="lazy" onclick="lightbox('${h(a.proxy)}')">`).join('')+'</div>';
    }
    atts.forEach(a=>{
        if((a.type==='image'||a.type==='sticker')){ if(imgs.length<=1) html+=`<img class="tva-att-img" src="${h(a.proxy)}" loading="lazy" onclick="lightbox('${h(a.proxy)}')">`; return; }
        if(a.type==='audio'){ html+=audioPlayer(a.proxy); return; }
        if(a.type==='video'){ html+=`<div class="tva-video" data-src="${h(a.proxy)}"><video src="${h(a.proxy)}" preload="metadata"></video><div class="tva-video__ov" onclick="toggleVideo(this)"><span>▶</span></div></div>`; return; }
        html+=`<a class="tva-att-doc" href="${h(a.proxy)}" target="_blank">📄 ${h(a.filename||'Document')}</a>`;
    });
    return html;
}

// ── Custom audio player ──
let audioSeq=0;
function audioPlayer(src){
    const id='aud'+(audioSeq++);
    return `<div class="tva-audio" id="${id}" data-src="${h(src)}">
        <button class="tva-audio__play" onclick="audioToggle('${id}')">▶</button>
        <div class="tva-audio__bar" onclick="audioSeek(event,'${id}')"><div class="tva-audio__fill"></div></div>
        <span class="tva-audio__time">0:00</span></div>`;
}
const _audios={};
function audioEl(id){
    if(_audios[id]) return _audios[id];
    const wrap=document.getElementById(id); const a=new Audio(wrap.dataset.src); _audios[id]=a;
    a.ontimeupdate=()=>{ const f=wrap.querySelector('.tva-audio__fill'); const t=wrap.querySelector('.tva-audio__time');
        if(a.duration){ f.style.width=(a.currentTime/a.duration*100)+'%'; } t.textContent=mmss(a.currentTime); };
    a.onended=()=>{ wrap.querySelector('.tva-audio__play').textContent='▶'; wrap.querySelector('.tva-audio__fill').style.width='0%'; };
    return a;
}
function audioToggle(id){
    const a=audioEl(id), wrap=document.getElementById(id), btn=wrap.querySelector('.tva-audio__play');
    Object.entries(_audios).forEach(([k,o])=>{ if(k!==id && !o.paused){ o.pause(); document.getElementById(k).querySelector('.tva-audio__play').textContent='▶'; }});

    if(!a.paused){ a.pause(); btn.textContent='▶'; return; }

    btn.textContent='⏸';
    // play() returns a promise that REJECTS on a decode failure or an
    // autoplay block. It had no .catch(), so the button flipped to pause and
    // absolutely nothing else happened — the single reason a broken voice
    // note looked like a broken UI rather than a broken file.
    const p = a.play();
    if (p && p.catch) {
        p.catch(err=>{
            btn.textContent='▶';
            const why = (a.error && a.error.code === 4)
                ? 'This browser cannot play the audio format (WhatsApp sends OGG/Opus, which Safari does not support).'
                : (err && err.name === 'NotAllowedError'
                    ? 'The browser blocked playback — click the play button again.'
                    : 'The audio could not be loaded. It may have expired on Meta’s side.');
            tvaToast(why, 'error');
            console.warn('audio playback failed', err, a.error);
        });
    }
}
function audioSeek(e,id){ const a=audioEl(id); const r=e.currentTarget.getBoundingClientRect(); if(a.duration) a.currentTime=((e.clientX-r.left)/r.width)*a.duration; }
function toggleVideo(ov){ const v=ov.parentElement.querySelector('video'); if(v.paused){ v.play(); ov.style.display='none'; v.setAttribute('controls',''); } }
function lightbox(src){ document.getElementById('lightboxImg').src=src; document.getElementById('lightbox').style.display='flex'; }

// ── Per-message actions (reply / copy / edit) ──
let replyTarget=null;
function openMsgMenu(e,id,canEdit){
    e.stopPropagation();
    const m=window.MSGS[id]; const menu=document.getElementById('msgMenu');
    let items=`<button onclick="doReply(${id})"><i data-lucide="reply" class="w-4 h-4"></i> Reply</button>`;
    if(m.content) items+=`<button onclick="doCopy(${id})"><i data-lucide="copy" class="w-4 h-4"></i> Copy</button>`;
    if(canEdit) items+=`<button onclick="doEdit(${id})"><i data-lucide="pencil" class="w-4 h-4"></i> Edit</button>`;
    menu.innerHTML=items; menu.style.display='block';
    menu.style.left=Math.min(e.clientX, window.innerWidth-160)+'px';
    menu.style.top=Math.min(e.clientY, window.innerHeight-150)+'px';
    if(window.lucide) try{lucide.createIcons();}catch(_){}
}
document.addEventListener('click',e=>{ if(!e.target.closest('#msgMenu') && !e.target.closest('.tva-msg__more')) document.getElementById('msgMenu').style.display='none'; });
function doReply(id){ replyTarget=id; showReplyBar(window.MSGS[id]); document.getElementById('msgMenu').style.display='none'; document.getElementById('chatInput').focus(); }
function showReplyBar(m){
    const who=m.author==='customer'?'Customer':(m.author==='bot'?'AI':'Agent');
    const bar=document.getElementById('replyBar'); bar.style.display='flex'; bar.className='tva-reply-bar';
    bar.innerHTML=`<div class="flex-1 min-w-0"><b>Replying to ${who}</b><div class="truncate">${h((m.content||'📎 Attachment').slice(0,120))}</div></div><button class="tva-iconbtn" style="width:28px;height:28px;" onclick="clearReply()">✕</button>`;
}
function clearReply(){ replyTarget=null; const b=document.getElementById('replyBar'); if(b){ b.style.display='none'; b.innerHTML=''; } }
async function doCopy(id){ try{ await navigator.clipboard.writeText(window.MSGS[id].content||''); tvaToast('Copied to clipboard','success'); }catch(e){} document.getElementById('msgMenu').style.display='none'; }
async function doEdit(id){
    const m=window.MSGS[id]; document.getElementById('msgMenu').style.display='none';
    const v=await tvaPrompt({title:'Edit message', text:'Within 15 minutes. Corrects the console record — the WhatsApp API cannot change a message already on the customer’s phone.', fields:[{name:'text', label:'Message', type:'textarea', value:m.content||''}], confirmText:'Save'});
    if(!v || !v.text.trim()) return; const nv=v.text;
    const r=await api(msgUrl('edit'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,message_id:id,text:nv})});
    if(!r.ok){ const d=await r.json().catch(()=>({})); tvaToast(d.message||'Edit failed.','error'); return; }
    const d=await r.json(); window.MSGS[id]=d.message;
    const el=document.querySelector(`.tva-msg[data-id="${id}"]`);
    if(el){ const t=el.querySelector('.tva-msg__txt'); if(t) t.textContent=d.message.content; const tm=el.querySelector('.tva-msg__time'); if(tm && !tm.textContent.includes('edited')) tm.textContent+=' · edited'; }
}
function openGallery(){
    const grid=document.getElementById('galleryGrid');
    grid.innerHTML=(window.MEDIA||[]).map(a=>{
        if(a.type==='image'||a.type==='sticker') return `<img src="${h(a.proxy)}" onclick="lightbox('${h(a.proxy)}')">`;
        if(a.type==='video') return `<video src="${h(a.proxy)}" controls></video>`;
        return `<a href="${h(a.proxy)}" target="_blank" style="color:#fff;display:flex;align-items:center;justify-content:center;height:150px;border:1px solid #475569;border-radius:8px;text-align:center;padding:6px;">📄 ${h(a.filename||'Document')}</a>`;
    }).join('') || '<div style="color:#94a3b8;">No media shared yet.</div>';
    document.getElementById('galleryOverlay').style.display='flex';
}

// ── Sending ──
async function sendText(){
    const ta=document.getElementById('chatInput'); const text=ta.value.trim(); if(!text||!activeSid) return;
    ta.value=''; ta.style.height='auto';
    const r=await api(msgUrl('reply'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,text,reply_to:replyTarget})});
    if(r.status===409){ tvaToast('The 24-hour window has closed — send a template to re-open.','error'); return; }
    if(!r.ok){ tvaToast('Could not send.','error'); return; }
    appendMessages([(await r.json()).message]); clearReply();
}
async function sendFile(file){
    if(!file||!activeSid) return;
    const fd=new FormData(); fd.append('project_id',CHAT.projectId); fd.append('file',file);
    const r=await api(msgUrl('media'),{method:'POST',body:fd});
    if(r.status===409){ tvaToast('Window closed — use a template.','error'); return; }
    if(!r.ok){ tvaToast('Upload failed.','error'); return; }
    appendMessages([(await r.json()).message]);
}
async function setBotRequest(){ const r=await api(msgUrl('toggle-bot'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId})}); if(r.ok) setBot((await r.json()).bot_paused); }
function setBot(p){ currentBotPaused=!!p; document.getElementById('botToggleLabel').textContent=p?'Bot paused':'Bot on'; document.getElementById('botToggle').classList.toggle('is-on',!!p); refreshTyping(); }
async function sendInteractive(){
    const v=await tvaPrompt({title:'Quick-reply buttons', text:'Capture intent — the customer taps a button.', fields:[
        {name:'body', label:'Message', type:'textarea', placeholder:'Shall I place your order?'},
        {name:'buttons', label:'Buttons (comma-separated, max 3)', value:'Place order, Change, Cancel'},
    ]});
    if(!v || !v.body || !v.buttons) return;
    const buttons=v.buttons.split(',').map(s=>s.trim()).filter(Boolean).slice(0,3).map((t,i)=>({id:'btn_'+i,title:t.slice(0,20)}));
    const r=await api(msgUrl('interactive'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,body:v.body,buttons})});
    if(!r.ok){ tvaToast('Could not send (WhatsApp, within 24h).','error'); return; } appendMessages([(await r.json()).message]);
}
async function sendFlowMsg(){
    const v=await tvaPrompt({title:'Send a Flow form', text:'Send a published WhatsApp Flow to capture structured data.', fields:[
        {name:'flow_id', label:'Flow ID (from Meta Flow Builder)'},
        {name:'body', label:'Message shown with the form', type:'textarea', value:'Please fill in this quick form'},
        {name:'cta', label:'Button label', value:'Open form'},
        {name:'screen', label:'First screen id (optional)'},
    ]});
    if(!v || !v.flow_id || !v.body) return;
    const r=await api(msgUrl('flow'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,flow_id:v.flow_id,cta:v.cta||'Open form',body:v.body,screen:v.screen||null})});
    if(!r.ok){ tvaToast('Flow send failed (WhatsApp, within 24h).','error'); return; } appendMessages([(await r.json()).message]);
}
async function sendProductMsg(){
    const v=await tvaPrompt({title:'Send catalog products', fields:[
        {name:'catalog_id', label:'Catalog ID'},
        {name:'retailer_ids', label:'Product retailer IDs (comma-separated)'},
        {name:'body', label:'Caption (optional)'},
    ]});
    if(!v || !v.catalog_id || !v.retailer_ids) return;
    const retailer_ids=v.retailer_ids.split(',').map(s=>s.trim()).filter(Boolean);
    const r=await api(msgUrl('product'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,catalog_id:v.catalog_id,retailer_ids,body:v.body||null})});
    if(!r.ok){ tvaToast('Product send failed.','error'); return; } appendMessages([(await r.json()).message]);
}

// ── Template popover (inside chat) ──
let tplChosen=null;
function closePops(){
    ['emojiPicker','tplPanel','composerMenu'].forEach(id=>{ const el=document.getElementById(id); if(el) el.style.display='none'; });
    // The filter panel uses [hidden] rather than inline display, so it needs
    // its own line here — otherwise opening the emoji picker leaves it open.
    const fp=document.getElementById('filterPanel');
    if(fp){ fp.hidden=true; document.getElementById('filterBtn').classList.remove('is-open'); }
    ['statusMenu','transferMenu'].forEach(id=>{ const el=document.getElementById(id); if(el) el.hidden=true; });
}
async function openTemplates(){
    const panel=document.getElementById('tplPanel'); const show=panel.style.display!=='block'; closePops();
    if(!show) return; panel.style.display='block'; tplChosen=null;
    panel.innerHTML='<div class="text-xs text-slate-500">Loading approved templates…</div>';
    try{
        const d=await(await api(msgUrl('templates')+'?project_id='+CHAT.projectId)).json(); const list=d.templates||[];
        if(!list.length){ panel.innerHTML='<div class="text-xs text-slate-500">'+h(d.note||'No approved templates found.')+'</div>'; return; }
        panel.innerHTML='<div class="font-semibold text-sm mb-2">Send a template</div>'+list.map((t,i)=>`<div class="tpl-item" onclick="pickTpl(${i})" id="tpl${i}"><b>${h(t.name)}</b> <span class="text-xs text-slate-400">${h(t.language)}${t.params?(' · '+t.params+' params'):''}</span></div>`).join('')+'<div id="tplParams"></div>';
        window._tpls=list;
    }catch(e){ panel.innerHTML='Could not load templates.'; }
}
function pickTpl(i){
    tplChosen=window._tpls[i]; const n=tplChosen.params||0;
    document.querySelectorAll('#tplPanel .tpl-item').forEach((el,idx)=>el.style.borderColor=idx===i?'#6366f1':'');
    const box=document.getElementById('tplParams');
    box.innerHTML=(n?Array.from({length:n}).map((_,k)=>`<input class="form-control form-control-sm mb-1" data-pi="${k}" placeholder="Parameter ${k+1}">`).join(''):'')+
        '<button class="btn btn-primary btn-sm w-full mt-1" onclick="sendChosenTemplate()">Send template</button>';
}
async function sendChosenTemplate(){
    if(!tplChosen) return;
    const params=Array.from(document.querySelectorAll('#tplParams [data-pi]')).map(el=>el.value);
    const r=await api(msgUrl('template'),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,template:tplChosen.name,language:tplChosen.language,params})});
    if(!r.ok){ tvaToast('Template send failed.','error'); return; } appendMessages([(await r.json()).message]); closePops();
}

// ── Voice recording (live) ──
let mediaRec=null, chunks=[], recCancelled=false, recTimer=null, recStart=0;
function showRec(on){ document.getElementById('composerRow').style.display=on?'none':'flex'; document.getElementById('recBar').style.display=on?'flex':'none'; }
async function startRec(){
    try{
        const stream=await navigator.mediaDevices.getUserMedia({audio:true});
        mediaRec=new MediaRecorder(stream); chunks=[]; recCancelled=false;
        mediaRec.ondataavailable=e=>chunks.push(e.data);
        mediaRec.onstop=()=>{ stream.getTracks().forEach(t=>t.stop()); clearInterval(recTimer); showRec(false);
            if(!recCancelled){ const blob=new Blob(chunks,{type:'audio/webm'}); sendFile(new File([blob],'voice-note.webm',{type:'audio/webm'})); } };
        mediaRec.start(); showRec(true); recStart=Date.now();
        document.getElementById('recTime').textContent='0:00';
        document.getElementById('recWave').innerHTML=Array.from({length:28}).map((_,i)=>`<i style="animation-delay:${(i%10)*0.07}s"></i>`).join('');
        recTimer=setInterval(()=>{ document.getElementById('recTime').textContent=mmss((Date.now()-recStart)/1000); },250);
    }catch(e){ tvaToast('Microphone access denied.','error'); }
}
function stopRec(cancel){ if(!mediaRec) return; recCancelled=cancel; if(mediaRec.state!=='inactive') mediaRec.stop(); }

// ── Wire ──
document.getElementById('btnSend').onclick=sendText;
document.getElementById('chatInput').addEventListener('keydown',e=>{ if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); sendText(); }});
document.getElementById('chatInput').addEventListener('input',e=>{ e.target.style.height='auto'; e.target.style.height=Math.min(e.target.scrollHeight,120)+'px'; });
document.getElementById('chatSearch').oninput=loadConvos;
document.getElementById('botToggle').onclick=setBotRequest;
document.getElementById('galleryBtn').onclick=openGallery;
document.getElementById('btnVoice').onclick=startRec;
document.getElementById('recSend').onclick=()=>stopRec(false);
document.getElementById('recCancel').onclick=()=>stopRec(true);
document.getElementById('fileInput').onchange=e=>{ if(e.target.files[0]) sendFile(e.target.files[0]); e.target.value=''; };
document.getElementById('lightbox').onclick=e=>{ if(e.target.id==='lightbox') closeLightbox(); };
document.getElementById('btnMore').onclick=()=>{ const m=document.getElementById('composerMenu'); const show=m.style.display!=='block'; closePops(); m.style.display=show?'block':'none'; if(show && window.lucide) try{lucide.createIcons();}catch(_){} };
document.querySelectorAll('#filterTabs button').forEach(b=>b.onclick=()=>{
    currentFilter=b.dataset.f;
    document.querySelectorAll('#filterTabs button').forEach(x=>x.classList.toggle('is-active', x===b));
    loadConvos();
});

// ── Filter interactions ──
// Delegated, because the Page/number options are built from the server
// response and would miss a listener bound at load.
document.getElementById('filterPanel').addEventListener('click', e=>{
    const btn = e.target.closest('.tva-fp__opts button');
    if (!btn) return;
    const box = btn.closest('.tva-fp__opts'), g = box.dataset.group, v = btn.dataset.v;

    if (box.dataset.single) {
        FILTERS[g] = FILTERS[g] === v ? null : v;   // re-click clears
    } else {
        FILTERS[g].has(v) ? FILTERS[g].delete(v) : FILTERS[g].add(v);
    }
    syncFilterUI(); loadConvos();
});

document.querySelectorAll('[data-quick]').forEach(b=>b.onclick=()=>{
    if (b.dataset.quick === 'unread') {
        FILTERS.read = FILTERS.read === 'unread' ? null : 'unread';
    } else if (b.dataset.quick === 'needs_human') {
        FILTERS.needs_human = !FILTERS.needs_human;
    } else {
        // One tap sets the whole combination, and a second tap takes it back
        // off — including the states it switched on, so it never leaves a
        // filter behind that the user did not choose themselves.
        if (isNeedsReply()) { FILTERS.read = null; FILTERS.states.clear(); }
        else { FILTERS.read = 'unread'; FILTERS.states.clear(); FILTERS.states.add('active'); FILTERS.states.add('expiring'); }
    }
    syncFilterUI(); loadConvos();
});

function setFilterPanel(open){
    document.getElementById('filterPanel').hidden = !open;
    document.getElementById('filterBtn').classList.toggle('is-open', open);
    if (open && window.lucide) try{ lucide.createIcons(); }catch(_){}
}
document.getElementById('filterBtn').onclick=()=>{
    const show=document.getElementById('filterPanel').hidden;
    closePops();
    setFilterPanel(show);
};
document.getElementById('filterDone').onclick=()=>setFilterPanel(false);

[['statusBtn','statusMenu'], ['transferBtn','transferMenu']].forEach(([btnId, menuId])=>{
    document.getElementById(btnId).onclick=e=>{
        e.stopPropagation();
        const m=document.getElementById(menuId); const show=m.hidden;
        closePops(); m.hidden=!show;
        if (show && window.lucide) try{ lucide.createIcons(); }catch(_){}
    };
    document.addEventListener('click', e=>{
        const m=document.getElementById(menuId);
        if (m && !m.hidden && !m.contains(e.target) && !e.target.closest('#'+btnId)) m.hidden=true;
    });
});
document.getElementById('filterClear').onclick=clearFilters;

// Click-away. Guarded on the panel's own subtree so selecting an option does
// not close the thing you are selecting in.
document.addEventListener('click', e=>{
    const p=document.getElementById('filterPanel');
    if (!p || p.hidden) return;
    if (!p.contains(e.target) && !e.target.closest('#filterBtn')) setFilterPanel(false);
});
document.getElementById('presenceSel').onchange=async function(){
    this.dataset.touched='1';
    const r=await api(CHAT.base+'/presence',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({project_id:CHAT.projectId,status:this.value})});
    if(!r.ok){ tvaToast('Could not set presence — are you a human agent on this project?','error'); }
    else { tvaToast('You are now '+this.value,'success'); loadConvos(); }
};
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeLightbox(); document.getElementById('galleryOverlay').style.display='none'; const ov=document.getElementById('tvaDlg'); if(ov) ov.classList.remove('open'); }
    if(document.getElementById('lightbox').style.display==='flex'){ if(e.key==='ArrowLeft') lbNav(null,-1); if(e.key==='ArrowRight') lbNav(null,1); } });

const ep=document.getElementById('emojiPicker');
ep.innerHTML=EMOJIS.map(e=>`<span>${e}</span>`).join('');
ep.querySelectorAll('span').forEach(s=>s.onclick=()=>{ const ta=document.getElementById('chatInput'); ta.value+=s.textContent; ta.focus(); });
document.getElementById('btnEmoji').onclick=()=>{ const show=ep.style.display!=='grid'; closePops(); ep.style.display=show?'grid':'none'; };

// Chat defaults to the compact icon menu for space (runs after the global
// nav-collapse partial so it isn't overridden). The top-bar menu button
// toggles full ↔ icon for the whole app.
window.addEventListener('load',()=>document.body.classList.add('tva-nav-collapsed'));
loadConvos(); setInterval(loadConvos,6000);
if(window.lucide) try{ lucide.createIcons(); }catch(_){}
</script>
@endsection
