@extends('layouts.master')

@section('content')
@php
    /* Stroke icons (Lucide geometry) inlined, so the page never depends on
       which names the bundled icon build happens to carry. */
    $ICONS = [
        'sparkles' => '<path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/><path d="M5 3v4M19 17v4M3 5h4M17 19h4"/>',
        'send'     => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'draft'    => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
        'film'     => '<rect x="2" y="2" width="20" height="20" rx="2.2"/><path d="M7 2v20M17 2v20M2 12h20M2 7h5M2 17h5M17 17h5M17 7h5"/>',
        'story'    => '<circle cx="12" cy="12" r="10" stroke-dasharray="4 3"/><circle cx="12" cy="12" r="4.5"/>',
        'upload'   => '<path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M12 12v9M16 16l-4-4-4 4"/>',
        'refresh'  => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'trash'    => '<path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'check'    => '<path d="M20 6 9 17l-5-5"/>',
        'plus'     => '<path d="M5 12h14M12 5v14"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'wand'     => '<path d="m21.6 3.6-1.2-1.2a1.2 1.2 0 0 0-1.7 0L2.4 18.6a1.2 1.2 0 0 0 0 1.7l1.2 1.3a1.2 1.2 0 0 0 1.8 0L21.6 5.4a1.2 1.2 0 0 0 0-1.8Z"/><path d="m14 7 3 3"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'alert'    => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
        'play'     => '<path d="M6 3 20 12 6 21Z"/>',
        'text'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M16 13H8M16 17H8M10 9H8"/>',
        'heart'    => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'comment'  => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'eye'      => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    ];
    $ico = fn (string $name, int $size = 16) => new \Illuminate\Support\HtmlString(
        '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($ICONS[$name] ?? '') . '</svg>'
    );
    $brand = fn (string $provider, int $size = 18) => \App\Support\BrandIcons::render($provider === 'facebook_page' ? 'facebook' : $provider, $size);

    $providerLabel = fn ($p) => match ($p) { 'instagram' => 'Instagram', 'youtube' => 'YouTube', default => 'Facebook Page' };
    $nowLocal      = now()->setTimezone($timezone)->format('Y-m-d\TH:i');
    $needsGrant    = $accounts->filter(fn ($a) => ($a->missingPublishScopes() ?? []) !== []);
    $enabledAccts  = $accounts->where('status', 'enabled');

    $statusLabels = [
        'draft' => 'Draft', 'scheduled' => 'Scheduled', 'publishing' => 'Publishing',
        'published' => 'Published', 'partial' => 'Partly published', 'failed' => 'Failed',
        'pending' => 'Waiting', 'processing' => 'Processing',
    ];
    $c = fn (...$keys) => array_sum(array_map(fn ($k) => (int) ($counts[$k] ?? 0), $keys));
    $tabs = [
        'all'       => ['All',       array_sum(array_map('intval', $counts))],
        'scheduled' => ['Scheduled', $c('scheduled', 'publishing')],
        'published' => ['Published', $c('published', 'partial')],
        'draft'     => ['Drafts',    $c('draft')],
        'failed'    => ['Failed',    $c('failed', 'partial')],
    ];
    $formatMeta = [
        'post'  => ['icon' => 'image', 'hint' => 'Photo, carousel or video'],
        'reel'  => ['icon' => 'film',  'hint' => 'One vertical video'],
        'story' => ['icon' => 'story', 'hint' => 'Instagram, 24 hours'],
    ];
@endphp

<style>
    /* ── Social Posts ─────────────────────────────────────────────────
       Built on the theme tokens (partials/theme), so it follows the
       workspace's white-label colours and light/dark mode on its own. */
    .sp { --sp-r:12px; --sp-pri: var(--tva-primary, #4f46e5); --sp-ink: var(--sp-pri); color:var(--tva-text-2); }
    /* The brand colour is often a deep navy: fine as a fill, unreadable as
       text on a dark card. Dark mode lifts it for text, icons and fills. */
    html.dark .sp { --sp-ink: color-mix(in srgb, var(--sp-pri) 45%, #ffffff); }
    html.dark .sp-format:has(input:checked) .sp-format__ico { background:var(--tva-gradient); }
    .sp *, .sp *::before, .sp *::after { box-sizing:border-box; }

    /* Hero */
    /* 24px top = the `mt-6` every other page hero uses to clear the top bar. */
    .sp-hero { background:var(--tva-gradient); color:#fff; border-radius:16px; padding:22px 26px;
        margin:24px 0 20px; box-shadow:var(--tva-shadow-lg); display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
    .sp-hero__mark { width:52px; height:52px; border-radius:14px; background:rgba(255,255,255,.16);
        border:1px solid rgba(255,255,255,.28); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .sp-hero h1 { font-size:20px; font-weight:700; margin:0; letter-spacing:-.01em; color:#fff; }
    .sp-hero p { font-size:13px; opacity:.9; margin:4px 0 0; max-width:560px; }
    .sp-hero__stats { display:flex; gap:10px; margin-left:auto; flex-wrap:wrap; }
    .sp-stat { background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.22); border-radius:12px; padding:9px 16px; min-width:92px; }
    .sp-stat b { display:block; font-size:19px; font-weight:700; line-height:1.15; color:#fff; }
    .sp-stat span { font-size:11px; opacity:.85; text-transform:uppercase; letter-spacing:.05em; }
    .sp-hero__project { background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.3); color:#fff;
        border-radius:10px; padding:8px 12px; font-size:13px; font-family:inherit; }
    .sp-hero__project option { color:#16202e; }

    /* Layout */
    .sp-grid { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:20px; align-items:start; }
    @media (max-width:1180px) { .sp-grid { grid-template-columns:1fr; } }
    .sp-side { display:flex; flex-direction:column; gap:20px; position:sticky; top:84px; }
    @media (max-width:1180px) { .sp-side { position:static; } }

    /* Cards */
    .sp-card { background:var(--tva-surface); border:1px solid var(--tva-border); border-radius:16px; box-shadow:var(--tva-shadow); }
    .sp-card__head { display:flex; align-items:center; gap:10px; padding:16px 20px; border-bottom:1px solid var(--tva-border-2); flex-wrap:wrap; }
    .sp-card__title { font-size:14.5px; font-weight:650; color:var(--tva-text); margin:0; }
    .sp-card__sub { font-size:12px; color:var(--tva-text-3); margin:2px 0 0; }
    .sp-card__body { padding:20px; }
    .sp-section + .sp-section { margin-top:22px; }

    .sp-label { display:flex; align-items:center; gap:6px; font-size:12px; font-weight:650; color:var(--tva-text); margin-bottom:8px; letter-spacing:.01em; }
    .sp-label small { font-weight:500; color:var(--tva-text-3); }
    .sp-hint { font-size:11.5px; color:var(--tva-text-3); margin-top:6px; line-height:1.5; }

    .sp-input, .sp-select, .sp-textarea {
        width:100%; height:40px; padding:0 12px; border-radius:10px; font-size:13.5px; font-family:inherit;
        border:1px solid var(--tva-border); background:var(--tva-surface); color:var(--tva-text);
        transition:border-color .15s, box-shadow .15s;
    }
    .sp-textarea { height:auto; min-height:168px; padding:12px 14px; resize:vertical; line-height:1.6; }
    .sp-input:focus, .sp-select:focus, .sp-textarea:focus { outline:none; border-color:var(--sp-pri); box-shadow:0 0 0 3px var(--tva-ring); }
    .sp-input::placeholder, .sp-textarea::placeholder { color:var(--tva-text-3); }

    /* Buttons */
    .sp-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; height:40px; padding:0 16px;
        border-radius:10px; border:1px solid transparent; font-size:13.5px; font-weight:600; font-family:inherit;
        cursor:pointer; text-decoration:none; white-space:nowrap; line-height:1;
        transition:background .15s, border-color .15s, box-shadow .15s, transform .05s, filter .15s; }
    .sp-btn:active { transform:translateY(1px); }
    .sp-btn:focus-visible { outline:none; box-shadow:0 0 0 3px var(--tva-ring); }
    .sp-btn:disabled { opacity:.55; cursor:not-allowed; transform:none; }
    .sp-btn--primary { background:var(--tva-gradient, var(--sp-pri)); color:#fff; box-shadow:0 1px 2px rgba(16,24,40,.12), inset 0 1px 0 rgba(255,255,255,.12); }
    .sp-btn--primary:hover:not(:disabled) { filter:brightness(1.07); box-shadow:0 4px 12px -2px color-mix(in srgb, var(--sp-pri) 45%, transparent); }
    .sp-btn--secondary { background:var(--tva-surface); color:var(--tva-text); border-color:var(--tva-border); box-shadow:var(--tva-shadow); }
    .sp-btn--secondary:hover:not(:disabled) { background:var(--tva-hover); }
    .sp-btn--ghost { background:transparent; color:var(--tva-text-2); }
    .sp-btn--ghost:hover:not(:disabled) { background:var(--tva-hover); color:var(--tva-text); }
    .sp-btn--ai { color:#fff; background:linear-gradient(135deg,#7c3aed,#db2777); box-shadow:0 4px 14px -4px rgba(124,58,237,.55); }
    .sp-btn--ai:hover:not(:disabled) { filter:brightness(1.08); }
    .sp-btn--sm { height:34px; padding:0 12px; font-size:12.5px; border-radius:9px; }
    .sp-btn--block { width:100%; }
    .sp-iconbtn { width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; border-radius:9px;
        border:1px solid var(--tva-border); background:var(--tva-surface); color:var(--tva-text-2); cursor:pointer; transition:background .15s, color .15s, border-color .15s; }
    .sp-iconbtn:hover { background:var(--tva-hover); color:var(--tva-text); }
    .sp-iconbtn:focus-visible { outline:none; box-shadow:0 0 0 3px var(--tva-ring); }
    .sp-iconbtn--primary { color:var(--sp-ink); }
    .sp-iconbtn--danger:hover { color:var(--tva-danger); border-color:var(--tva-danger-line); background:var(--tva-danger-bg); }
    .sp-spin { animation:sp-spin .8s linear infinite; }
    @keyframes sp-spin { to { transform:rotate(360deg); } }

    /* Alerts */
    .sp-alert { display:flex; gap:10px; align-items:flex-start; padding:12px 14px; border-radius:12px; font-size:13px; line-height:1.55; margin-bottom:16px; border:1px solid; }
    .sp-alert svg { flex-shrink:0; margin-top:2px; }
    .sp-alert--ok     { background:var(--tva-ok-bg); border-color:var(--tva-ok-line); color:var(--tva-ok); }
    .sp-alert--err    { background:var(--tva-danger-bg); border-color:var(--tva-danger-line); color:var(--tva-danger); }
    .sp-alert--warn   { background:var(--tva-warn-bg); border-color:var(--tva-warn-line); color:var(--tva-warn); }
    .sp-alert--info   { background:var(--tva-info-bg); border-color:var(--tva-info-line); color:var(--tva-info); }
    .sp-alert__close { margin-left:auto; background:none; border:0; color:inherit; opacity:.7; cursor:pointer; padding:0; }

    /* Format tiles */
    .sp-formats { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
    @media (max-width:560px) { .sp-formats { grid-template-columns:1fr; } }
    .sp-format { position:relative; display:flex; align-items:center; gap:11px; padding:12px 14px; margin:0;
        border:1px solid var(--tva-border); border-radius:12px; cursor:pointer; background:var(--tva-surface);
        transition:border-color .15s, background .15s, box-shadow .15s; }
    .sp-format input { position:absolute; opacity:0; pointer-events:none; }
    .sp-format__ico { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center;
        background:var(--tva-surface-3); color:var(--tva-text-2); flex-shrink:0; transition:background .15s, color .15s; }
    .sp-format__txt b { display:block; font-size:13.5px; font-weight:650; color:var(--tva-text); }
    .sp-format__txt small { display:block; font-size:11.5px; color:var(--tva-text-3); margin-top:1px; }
    .sp-format:hover { border-color:color-mix(in srgb, var(--sp-pri) 45%, var(--tva-border)); }
    .sp-format:has(input:checked) { border-color:var(--sp-pri); background:color-mix(in srgb, var(--sp-pri) 6%, var(--tva-surface)); box-shadow:0 0 0 1px var(--sp-pri); }
    .sp-format:has(input:checked) .sp-format__ico { background:var(--sp-pri); color:#fff; }
    .sp-format:has(input:focus-visible) { box-shadow:0 0 0 3px var(--tva-ring); }

    /* Account picker */
    .sp-picks { display:flex; flex-wrap:wrap; gap:10px; }
    .sp-pick { position:relative; display:flex; align-items:center; gap:10px; padding:8px 14px 8px 8px; margin:0;
        border:1px solid var(--tva-border); border-radius:999px; cursor:pointer; background:var(--tva-surface);
        transition:border-color .15s, background .15s, box-shadow .15s, opacity .15s; user-select:none; }
    .sp-pick input { position:absolute; opacity:0; pointer-events:none; }
    .sp-pick__logo { width:30px; height:30px; border-radius:50%; background:var(--tva-surface-3); display:flex; align-items:center; justify-content:center; flex-shrink:0; position:relative; }
    .sp-pick__name { font-size:13px; font-weight:600; color:var(--tva-text); line-height:1.2; }
    .sp-pick__sub { font-size:11px; color:var(--tva-text-3); }
    .sp-pick__tick { position:absolute; right:-3px; bottom:-3px; width:16px; height:16px; border-radius:50%; background:var(--sp-pri);
        color:#fff; display:none; align-items:center; justify-content:center; border:2px solid var(--tva-surface); }
    .sp-pick:hover { border-color:color-mix(in srgb, var(--sp-pri) 45%, var(--tva-border)); }
    .sp-pick:has(input:checked) { border-color:var(--sp-pri); background:color-mix(in srgb, var(--sp-pri) 6%, var(--tva-surface)); }
    .sp-pick:has(input:checked) .sp-pick__tick { display:flex; }
    .sp-pick:has(input:focus-visible) { box-shadow:0 0 0 3px var(--tva-ring); }
    .sp-pick:has(input:disabled) { opacity:.45; cursor:not-allowed; }

    /* AI panel */
    .sp-ai { position:relative; border-radius:14px; padding:16px; background:
        linear-gradient(var(--tva-surface-2), var(--tva-surface-2)) padding-box,
        linear-gradient(135deg, rgba(124,58,237,.55), rgba(219,39,119,.55)) border-box;
        border:1px solid transparent; }
    .sp-ai__head { display:flex; align-items:center; gap:8px; margin-bottom:12px; }
    .sp-ai__badge { width:32px; height:32px; flex-shrink:0; border-radius:8px; display:flex; align-items:center; justify-content:center; color:#fff; background:linear-gradient(135deg,#7c3aed,#db2777); }
    .sp-ai__head b { font-size:13.5px; color:var(--tva-text); }
    .sp-ai__head small { display:block; font-size:11.5px; color:var(--tva-text-3); }
    .sp-ai__row { display:grid; grid-template-columns:minmax(0,1fr) 150px 150px; gap:10px; }
    @media (max-width:720px) { .sp-ai__row { grid-template-columns:1fr; } }
    .sp-ai__actions { display:flex; align-items:center; gap:10px; margin-top:12px; flex-wrap:wrap; }
    .sp-ai__msg { font-size:12px; color:var(--tva-text-3); }

    /* Caption */
    .sp-caption-foot { display:flex; justify-content:space-between; gap:10px; margin-top:6px; font-size:11.5px; color:var(--tva-text-3); }
    .sp-caption-foot .is-over { color:var(--tva-danger); font-weight:650; }

    /* Dropzone */
    .sp-drop { position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; gap:6px;
        padding:24px 16px; border:1.5px dashed var(--tva-border); border-radius:14px; background:var(--tva-surface-2);
        cursor:pointer; transition:border-color .15s, background .15s; margin:0; }
    .sp-drop:hover, .sp-drop.is-over { border-color:var(--sp-pri); background:color-mix(in srgb, var(--sp-pri) 5%, var(--tva-surface-2)); }
    .sp-drop__ico { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; background:var(--tva-surface); color:var(--sp-ink); box-shadow:var(--tva-shadow); margin-bottom:4px; }
    .sp-drop b { font-size:13.5px; color:var(--tva-text); }
    .sp-drop b u { color:var(--sp-ink); text-decoration:none; }
    .sp-drop__hint { font-size:11.5px; color:var(--tva-text-3); }
    /* Visually hidden, not display:none: the theme forces file inputs visible,
       and a hidden input must stay focusable for the label to open it. */
    .sp .sp-drop input[type=file] { position:absolute !important; width:1px !important; height:1px !important; padding:0 !important;
        margin:-1px !important; overflow:hidden !important; clip:rect(0,0,0,0) !important; border:0 !important; opacity:0 !important; }
    .sp-thumbs { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
    .sp-thumb { position:relative; width:84px; height:84px; border-radius:10px; overflow:hidden; background:#0f172a; border:1px solid var(--tva-border); }
    .sp-thumb img, .sp-thumb video { width:100%; height:100%; object-fit:cover; display:block; }
    .sp-thumb__x { position:absolute; top:4px; right:4px; width:22px; height:22px; border-radius:50%; border:0; cursor:pointer;
        background:rgba(15,23,42,.72); color:#fff; display:flex; align-items:center; justify-content:center; }
    .sp-thumb__tag { position:absolute; left:4px; bottom:4px; font-size:10px; font-weight:700; color:#fff; background:rgba(15,23,42,.72); padding:2px 6px; border-radius:6px; }

    /* Action bar */
    .sp-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:16px 20px; border-top:1px solid var(--tva-border-2);
        background:var(--tva-surface-2); border-radius:0 0 16px 16px; }
    .sp-schedule { display:flex; align-items:stretch; border:1px solid var(--tva-border); border-radius:10px; overflow:hidden; background:var(--tva-surface); box-shadow:var(--tva-shadow); }
    .sp-schedule:focus-within { border-color:var(--sp-pri); box-shadow:0 0 0 3px var(--tva-ring); }
    .sp-schedule__when { display:flex; align-items:center; gap:8px; padding:0 10px 0 12px; color:var(--tva-text-3); }
    .sp-schedule input { border:0; background:transparent; color:var(--tva-text); font-size:13px; font-family:inherit; height:38px; outline:none; color-scheme:light dark; }
    .sp-schedule .sp-btn { border-radius:0; border:0; border-left:1px solid var(--tva-border); height:auto; box-shadow:none; }
    .sp-actions__tz { font-size:11px; color:var(--tva-text-3); width:100%; text-align:right; margin-top:-2px; }

    /* Account list (side) */
    .sp-acct { display:flex; align-items:center; gap:12px; padding:12px 20px; border-bottom:1px solid var(--tva-border-2); }
    .sp-acct:last-child { border-bottom:0; }
    .sp-acct__logo { width:38px; height:38px; border-radius:11px; display:flex; align-items:center; justify-content:center; background:var(--tva-surface-3); flex-shrink:0; overflow:hidden; }
    .sp-acct__logo img { width:100%; height:100%; object-fit:cover; }
    .sp-acct__name { font-size:13.5px; font-weight:600; color:var(--tva-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sp-acct__sub { font-size:11.5px; color:var(--tva-text-3); display:flex; align-items:center; gap:5px; }
    .sp-acct--soon { opacity:.6; }
    .sp-connect { display:grid; grid-template-columns:1fr 1fr; gap:10px; padding:16px 20px; border-top:1px solid var(--tva-border-2); }

    /* Chips */
    .sp-chip { display:inline-flex; align-items:center; gap:6px; height:24px; padding:0 9px; border-radius:999px; font-size:11.5px; font-weight:600; white-space:nowrap; border:1px solid transparent; }
    .sp-chip::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
    .sp-chip--plain::before { display:none; }
    .sp-chip--ok      { background:var(--tva-ok-bg); color:var(--tva-ok); border-color:var(--tva-ok-line); }
    .sp-chip--warn    { background:var(--tva-warn-bg); color:var(--tva-warn); border-color:var(--tva-warn-line); }
    .sp-chip--danger  { background:var(--tva-danger-bg); color:var(--tva-danger); border-color:var(--tva-danger-line); }
    .sp-chip--info    { background:var(--tva-info-bg); color:var(--tva-info); border-color:var(--tva-info-line); }
    .sp-chip--muted   { background:var(--tva-surface-3); color:var(--tva-text-2); border-color:var(--tva-border); }
    .sp-chip--ai      { background:rgba(124,58,237,.1); color:#7c3aed; border-color:rgba(124,58,237,.25); }
    html.dark .sp-chip--ai { color:#c4b5fd; }
    .sp-chip--processing::before, .sp-chip--publishing::before { animation:sp-pulse 1.2s ease-in-out infinite; }
    @keyframes sp-pulse { 50% { opacity:.25; } }

    /* Preview */
    .sp-preview { border:1px solid var(--tva-border); border-radius:14px; overflow:hidden; background:var(--tva-surface); }
    .sp-preview__head { display:flex; align-items:center; gap:10px; padding:12px 14px; }
    .sp-preview__avatar { width:36px; height:36px; border-radius:50%; background:var(--tva-surface-3); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; }
    .sp-preview__avatar img { width:100%; height:100%; object-fit:cover; }
    .sp-preview__name { font-size:13px; font-weight:650; color:var(--tva-text); }
    .sp-preview__meta { font-size:11px; color:var(--tva-text-3); }
    .sp-preview__text { padding:0 14px 12px; font-size:13px; line-height:1.55; color:var(--tva-text); white-space:pre-wrap; word-break:break-word; max-height:180px; overflow:hidden; }
    .sp-preview__text.is-empty { color:var(--tva-text-3); font-style:italic; }
    .sp-preview__media { position:relative; background:var(--tva-surface-3); aspect-ratio:1/1; display:flex; align-items:center; justify-content:center; color:var(--tva-text-3); }
    .sp-preview__media.is-tall { aspect-ratio:9/16; max-height:420px; }
    .sp-preview__media img, .sp-preview__media video { width:100%; height:100%; object-fit:cover; }
    .sp-preview__count { position:absolute; top:10px; right:10px; font-size:11px; font-weight:700; color:#fff; background:rgba(15,23,42,.7); padding:3px 8px; border-radius:999px; }
    .sp-preview__bar { display:flex; gap:16px; padding:10px 14px; color:var(--tva-text-3); border-top:1px solid var(--tva-border-2); }

    /* Post list */
    .sp-tabs { display:flex; gap:4px; padding:4px; background:var(--tva-surface-3); border-radius:11px; overflow-x:auto; margin-left:auto; }
    .sp-tab { display:inline-flex; align-items:center; gap:6px; height:30px; padding:0 12px; border-radius:8px; font-size:12.5px; font-weight:600;
        color:var(--tva-text-2); text-decoration:none; white-space:nowrap; transition:background .15s, color .15s; }
    .sp-tab:hover { color:var(--tva-text); }
    .sp-tab.is-active { background:var(--tva-surface); color:var(--tva-text); box-shadow:var(--tva-shadow); }
    .sp-tab em { font-style:normal; font-size:11px; padding:1px 7px; border-radius:999px; background:var(--tva-border-2); color:var(--tva-text-3); }
    .sp-tab.is-active em { background:color-mix(in srgb, var(--sp-ink) 16%, transparent); color:var(--sp-ink); }

    .sp-post { display:flex; gap:16px; padding:18px 20px; border-bottom:1px solid var(--tva-border-2); transition:background .15s; }
    .sp-post:hover { background:var(--tva-surface-2); }
    .sp-post:last-child { border-bottom:0; }
    .sp-post__thumb { width:72px; height:72px; border-radius:12px; overflow:hidden; flex-shrink:0; background:var(--tva-surface-3);
        display:flex; align-items:center; justify-content:center; color:var(--tva-text-3); position:relative; }
    .sp-post__thumb img { width:100%; height:100%; object-fit:cover; }
    .sp-post__thumb--video { background:#0f172a; color:#fff; }
    .sp-post__count { position:absolute; right:4px; bottom:4px; font-size:10px; font-weight:700; color:#fff; background:rgba(15,23,42,.72); padding:1px 6px; border-radius:6px; }
    .sp-post__main { flex:1; min-width:0; }
    .sp-post__top { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:6px; }
    .sp-post__when { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:var(--tva-text-3); }
    .sp-post__caption { font-size:13.5px; line-height:1.55; color:var(--tva-text); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; word-break:break-word; }
    .sp-post__caption.is-empty { color:var(--tva-text-3); font-style:italic; }
    .sp-post__targets { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
    .sp-target { display:inline-flex; align-items:center; gap:7px; height:30px; padding:0 10px 0 6px; border-radius:999px;
        border:1px solid var(--tva-border); background:var(--tva-surface); font-size:12px; color:var(--tva-text-2); }
    .sp-target__logo { width:20px; height:20px; display:flex; align-items:center; justify-content:center; }
    .sp-target__dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
    .sp-target__dot--published { background:var(--tva-ok); }
    .sp-target__dot--failed { background:var(--tva-danger); }
    .sp-target__dot--pending, .sp-target__dot--processing { background:var(--tva-warn); animation:sp-pulse 1.2s ease-in-out infinite; }
    .sp-target a { display:inline-flex; align-items:center; gap:3px; color:var(--sp-ink); font-weight:600; text-decoration:none; }
    .sp-target a:hover { text-decoration:underline; }
    .sp-post__error { display:flex; gap:8px; align-items:flex-start; margin-top:8px; padding:8px 10px; border-radius:9px;
        background:var(--tva-danger-bg); border:1px solid var(--tva-danger-line); color:var(--tva-danger); font-size:12px; line-height:1.5; }
    .sp-post__error svg { flex-shrink:0; margin-top:2px; }
    .sp-post__note { margin-top:8px; font-size:12px; color:var(--tva-warn); }
    .sp-post__actions { display:flex; gap:6px; align-items:flex-start; flex-shrink:0; }
    .sp-post__actions form { margin:0; }
    @media (max-width:640px) { .sp-post { flex-wrap:wrap; } .sp-post__actions { width:100%; justify-content:flex-end; } }

    .sp-empty { text-align:center; padding:44px 20px; }
    .sp-empty__ico { width:56px; height:56px; border-radius:16px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; background:var(--tva-surface-3); color:var(--tva-text-3); }
    .sp-empty__title { display:block; font-size:14.5px; color:var(--tva-text); }
    .sp-empty__text { display:block; font-size:12.5px; color:var(--tva-text-3); margin:4px 0 14px; }
    .sp-pager { padding:14px 20px; border-top:1px solid var(--tva-border-2); }
</style>

<div class="sp">

    {{-- ── Hero ──────────────────────────────────────────────────────── --}}
    <div class="sp-hero">
        <div class="sp-hero__mark">{!! $ico('send', 24) !!}</div>
        <div style="min-width:0;">
            <h1>Social Posts</h1>
            <p>Create posts with AI, then publish them now or schedule them for later on your Facebook Pages and Instagram accounts.</p>
        </div>
        <div class="sp-hero__stats">
            @if ($projects->count() > 1)
                <form method="GET" style="display:flex;align-items:center;">
                    <select name="project_id" class="sp-hero__project" onchange="this.form.submit()" aria-label="Project">
                        @foreach ($projects as $p)
                            <option value="{{ hashid($p->id) }}" @selected((int) $projectId === (int) $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
            @if ($project)
                <div class="sp-stat"><b>{{ $tabs['scheduled'][1] }}</b><span>Scheduled</span></div>
                <div class="sp-stat"><b>{{ $c('published', 'partial') }}</b><span>Published</span></div>
                <div class="sp-stat"><b>{{ $enabledAccts->count() }}</b><span>Accounts</span></div>
            @endif
        </div>
    </div>

    {{-- ── Flash ─────────────────────────────────────────────────────── --}}
    @if (session('success'))
        <div class="sp-alert sp-alert--ok" role="status">{!! $ico('check', 16) !!}<div>{{ session('success') }}</div>
            <button type="button" class="sp-alert__close" onclick="this.parentElement.remove()" aria-label="Dismiss">{!! $ico('x', 15) !!}</button></div>
    @endif
    @if (session('error'))
        <div class="sp-alert sp-alert--err" role="alert">{!! $ico('alert', 16) !!}<div>{{ session('error') }}</div>
            <button type="button" class="sp-alert__close" onclick="this.parentElement.remove()" aria-label="Dismiss">{!! $ico('x', 15) !!}</button></div>
    @endif
    @if ($errors->any())
        <div class="sp-alert sp-alert--err" role="alert">{!! $ico('alert', 16) !!}
            <div>@foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach</div></div>
    @endif

    @if (! $project)
        <div class="sp-alert sp-alert--warn">{!! $ico('alert', 16) !!}<div>Create a project first, then come back to start posting.</div></div>
    @else

    <div class="sp-grid">

        {{-- ── Composer ──────────────────────────────────────────────── --}}
        <div class="sp-card" id="spComposer">
            <div class="sp-card__head">
                <div>
                    <h2 class="sp-card__title">Create a post</h2>
                    <p class="sp-card__sub">Pick where it goes, write it (or let AI write it), add media, then publish.</p>
                </div>
            </div>

            <form id="spForm" method="POST" action="{{ route('social-posts.store', ['client' => $client->slug]) }}" enctype="multipart/form-data" autocomplete="off">
                @csrf
                <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
                <input type="hidden" name="ai_generated" id="spAiFlag" value="{{ old('ai_generated', 0) }}">

                <div class="sp-card__body">

                    {{-- Type --}}
                    <div class="sp-section">
                        <div class="sp-label">Post type</div>
                        <div class="sp-formats">
                            @foreach ($formats as $key => $label)
                                <label class="sp-format">
                                    <input type="radio" name="format" value="{{ $key }}" @checked(old('format', 'post') === $key)>
                                    <span class="sp-format__ico">{!! $ico($formatMeta[$key]['icon'], 18) !!}</span>
                                    <span class="sp-format__txt"><b>{{ $label }}</b><small>{{ $formatMeta[$key]['hint'] }}</small></span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Accounts --}}
                    <div class="sp-section">
                        <div class="sp-label">Post to</div>
                        @if ($enabledAccts->isEmpty())
                            <div class="sp-alert sp-alert--info" style="margin:0;">{!! $ico('users', 16) !!}
                                <div>Connect a Facebook Page or Instagram account first (panel on the right). You can still save a draft.</div></div>
                        @else
                            <div class="sp-picks">
                                @foreach ($enabledAccts as $a)
                                    @php $avatar = (string) data_get($a->metadata, 'profile_pic', ''); @endphp
                                    <label class="sp-pick" title="{{ $providerLabel($a->provider) }}">
                                        <input type="checkbox" name="accounts[]" value="{{ hashid($a->id) }}"
                                               data-provider="{{ $a->provider }}" data-name="{{ $a->name ?: $a->external_id }}" data-avatar="{{ $avatar }}"
                                               @checked(in_array(hashid($a->id), (array) old('accounts', []), true))>
                                        <span class="sp-pick__logo">{!! $brand($a->provider, 18) !!}<span class="sp-pick__tick">{!! $ico('check', 10) !!}</span></span>
                                        <span>
                                            <span class="sp-pick__name">{{ $a->name ?: $a->external_id }}</span><br>
                                            <span class="sp-pick__sub">{{ $providerLabel($a->provider) }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- AI --}}
                    <div class="sp-section sp-ai">
                        <div class="sp-ai__head">
                            <span class="sp-ai__badge">{!! $ico('sparkles', 15) !!}</span>
                            <div><b>Write with AI</b><small>Uses your Project Profile so the copy sounds like your business.</small></div>
                        </div>
                        <div class="sp-ai__row">
                            <input class="sp-input" id="spBrief" maxlength="1000" aria-label="What is this post about?"
                                   placeholder="What’s the post about? e.g. Weekend sale, 20% off all shoes until Sunday">
                            <select class="sp-select" id="spTone" aria-label="Tone">
                                <option value="friendly and professional">Friendly</option>
                                <option value="exciting and energetic">Exciting</option>
                                <option value="professional and formal">Formal</option>
                                <option value="warm and emotional">Warm</option>
                                <option value="funny and playful">Playful</option>
                                <option value="short and punchy">Short &amp; punchy</option>
                            </select>
                            <select class="sp-select" id="spLang" aria-label="Language">
                                <option>English</option>
                                <option>Urdu</option>
                                <option value="Roman Urdu (Urdu written in English letters)">Roman Urdu</option>
                                <option>Arabic</option>
                                <option>Hindi</option>
                                <option>Spanish</option>
                                <option>French</option>
                            </select>
                        </div>
                        <div class="sp-ai__actions">
                            <button type="button" class="sp-btn sp-btn--ai sp-btn--sm" id="spAiWrite">{!! $ico('sparkles', 15) !!}<span>Generate caption</span></button>
                            <button type="button" class="sp-btn sp-btn--secondary sp-btn--sm" id="spAiImprove">{!! $ico('wand', 15) !!}<span>Improve my caption</span></button>
                            <span class="sp-ai__msg" id="spAiMsg" aria-live="polite"></span>
                        </div>
                    </div>

                    {{-- Caption --}}
                    <div class="sp-section">
                        <label class="sp-label" for="spCaption">Caption</label>
                        <textarea class="sp-textarea" id="spCaption" name="caption" maxlength="2200"
                                  placeholder="Write your caption here, or generate one with AI above.">{{ old('caption') }}</textarea>
                        <div class="sp-caption-foot"><span id="spTags"></span><span id="spCounter">0 / 2,200</span></div>
                    </div>

                    {{-- Media --}}
                    <div class="sp-section">
                        <div class="sp-label">Photos &amp; video <small id="spMediaRule"></small></div>
                        <label class="sp-drop" id="spDrop">
                            <input type="file" id="spMedia" name="media[]" multiple
                                   accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime">
                            <span class="sp-drop__ico">{!! $ico('upload', 22) !!}</span>
                            <b>Drag &amp; drop files here, or <u>browse</u></b>
                            <span class="sp-drop__hint">JPG, PNG, WebP up to 8 MB &middot; MP4 or MOV up to 50 MB</span>
                        </label>
                        <div class="sp-thumbs" id="spThumbs"></div>
                    </div>

                    {{-- Link --}}
                    <div class="sp-section" id="spLinkWrap">
                        <label class="sp-label" for="spLink">{!! $ico('link', 14) !!} Link <small>(optional, Facebook only)</small></label>
                        <input class="sp-input" id="spLink" name="link" type="url" maxlength="500" value="{{ old('link') }}" placeholder="https://example.com/offer">
                        <div class="sp-hint">Shown as a link preview on Facebook text posts. Instagram doesn’t allow links in posts.</div>
                    </div>
                </div>

                <div class="sp-actions">
                    <button class="sp-btn sp-btn--ghost" type="submit" name="action" value="draft" formnovalidate>{!! $ico('draft', 16) !!}<span>Save draft</span></button>
                    <span style="flex:1"></span>
                    <div class="sp-schedule">
                        <label class="sp-schedule__when" for="spWhen">{!! $ico('calendar', 15) !!}
                            <input type="datetime-local" id="spWhen" name="scheduled_at" min="{{ $nowLocal }}" value="{{ old('scheduled_at') }}" aria-label="Schedule date and time">
                        </label>
                        <button class="sp-btn sp-btn--secondary" type="submit" name="action" value="schedule">{!! $ico('clock', 16) !!}<span>Schedule</span></button>
                    </div>
                    <button class="sp-btn sp-btn--primary" type="submit" name="action" value="now">{!! $ico('send', 16) !!}<span>Publish now</span></button>
                    <div class="sp-actions__tz">Times are in {{ str_replace('_', ' ', $timezone) }}</div>
                </div>
            </form>
        </div>

        {{-- ── Side: accounts + preview ─────────────────────────────── --}}
        <div class="sp-side">
            <div class="sp-card">
                <div class="sp-card__head">
                    <div>
                        <h2 class="sp-card__title">Connected accounts</h2>
                        <p class="sp-card__sub">Where your posts can be published.</p>
                    </div>
                </div>

                @if ($needsGrant->isNotEmpty())
                    <div style="padding:14px 20px 0;">
                        <div class="sp-alert sp-alert--warn" style="margin:0;">{!! $ico('alert', 16) !!}
                            <div>{{ $needsGrant->count() === 1 ? 'One account was' : $needsGrant->count() . ' accounts were' }} connected for messaging only.
                                Press <b>Connect</b> for that platform and keep every permission ticked. Messaging is not affected.</div></div>
                    </div>
                @endif

                <div>
                    @forelse ($accounts as $a)
                        @php
                            $missing = $a->missingPublishScopes();
                            $avatar  = (string) data_get($a->metadata, 'profile_pic', '');
                        @endphp
                        <div class="sp-acct">
                            <span class="sp-acct__logo">
                                @if ($avatar !== '')
                                    <img src="{{ $avatar }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                                @else
                                    {!! $brand($a->provider, 20) !!}
                                @endif
                            </span>
                            <div style="min-width:0;flex:1;">
                                <div class="sp-acct__name">{{ $a->name ?: $a->external_id }}</div>
                                <div class="sp-acct__sub">{!! $brand($a->provider, 11) !!} {{ $providerLabel($a->provider) }}</div>
                            </div>
                            @if (! $a->isEnabled())
                                <span class="sp-chip sp-chip--muted">Off</span>
                            @elseif ($missing === null)
                                <span class="sp-chip sp-chip--muted" title="We can’t tell which permissions this account has. Publishing will try anyway.">Unknown</span>
                            @elseif ($missing)
                                <span class="sp-chip sp-chip--warn" title="Missing: {{ implode(', ', $missing) }}">Needs permission</span>
                            @else
                                <span class="sp-chip sp-chip--ok">Ready</span>
                            @endif
                        </div>
                    @empty
                        <div class="sp-empty" style="padding:28px 20px 20px;">
                            <div class="sp-empty__ico">{!! $ico('users', 24) !!}</div>
                            <b class="sp-empty__title">No accounts yet</b>
                            <span class="sp-empty__text">Connect a Facebook Page or Instagram account to start publishing.</span>
                        </div>
                    @endforelse

                    @foreach (['youtube' => 'YouTube', 'tiktok' => 'TikTok'] as $slug => $label)
                        <div class="sp-acct sp-acct--soon">
                            <span class="sp-acct__logo">{!! \App\Support\BrandIcons::render($slug, 20) !!}</span>
                            <div style="min-width:0;flex:1;">
                                <div class="sp-acct__name">{{ $label }}</div>
                                <div class="sp-acct__sub">Publishing support is on the way</div>
                            </div>
                            <span class="sp-chip sp-chip--plain sp-chip--muted">Coming soon</span>
                        </div>
                    @endforeach
                </div>

                <div class="sp-connect">
                    <button type="button" class="sp-btn sp-btn--secondary sp-btn--sm" onclick="spConnect('facebook')">{!! \App\Support\BrandIcons::render('facebook', 16) !!}<span>Connect Facebook</span></button>
                    <button type="button" class="sp-btn sp-btn--secondary sp-btn--sm" onclick="spConnect('instagram')">{!! \App\Support\BrandIcons::render('instagram', 16) !!}<span>Connect Instagram</span></button>
                </div>
            </div>

            <div class="sp-card">
                <div class="sp-card__head">
                    <div>
                        <h2 class="sp-card__title">Preview</h2>
                        <p class="sp-card__sub">A rough idea of how the post will look.</p>
                    </div>
                    <span style="margin-left:auto;color:var(--tva-text-3);">{!! $ico('eye', 16) !!}</span>
                </div>
                <div class="sp-card__body">
                    <div class="sp-preview">
                        <div class="sp-preview__head">
                            <span class="sp-preview__avatar" id="spPvAvatar">{!! $ico('users', 16) !!}</span>
                            <div style="min-width:0;">
                                <div class="sp-preview__name" id="spPvName">{{ $project->name }}</div>
                                <div class="sp-preview__meta" id="spPvMeta">Just now</div>
                            </div>
                        </div>
                        <div class="sp-preview__text is-empty" id="spPvText">Your caption will appear here.</div>
                        <div class="sp-preview__media" id="spPvMedia">{!! $ico('image', 30) !!}</div>
                        <div class="sp-preview__bar">{!! $ico('heart', 18) !!}{!! $ico('comment', 18) !!}{!! $ico('send', 18) !!}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Posts ─────────────────────────────────────────────────────── --}}
    <div class="sp-card" style="margin-top:20px;">
        <div class="sp-card__head">
            <div>
                <h2 class="sp-card__title">Your posts</h2>
                <p class="sp-card__sub">Scheduled posts come first.</p>
            </div>
            <nav class="sp-tabs" aria-label="Filter posts">
                @foreach ($tabs as $key => [$label, $n])
                    <a class="sp-tab {{ $filter === $key ? 'is-active' : '' }}"
                       href="{{ route('social-posts.index', ['client' => $client->slug, 'project_id' => hashid($project->id), 'filter' => $key]) }}">
                        {{ $label }} <em>{{ $n }}</em>
                    </a>
                @endforeach
            </nav>
        </div>

        @forelse ($posts as $post)
            @php
                $items = $post->mediaItems();
                $first = $items[0] ?? null;
                $tone  = match ($post->status) {
                    'published' => 'ok', 'failed' => 'danger', 'partial' => 'warn',
                    'scheduled' => 'info', 'publishing' => 'warn sp-chip--publishing', default => 'muted',
                };
            @endphp
            <div class="sp-post">
                <div class="sp-post__thumb {{ $first && $first['type'] === 'video' ? 'sp-post__thumb--video' : '' }}">
                    @if ($first && $first['type'] === 'image')
                        <img src="{{ \App\Models\SocialPost::mediaUrl($first) }}" alt="" loading="lazy">
                    @elseif ($first)
                        {!! $ico('play', 22) !!}
                    @else
                        {!! $ico('text', 22) !!}
                    @endif
                    @if (count($items) > 1)
                        <span class="sp-post__count">+{{ count($items) - 1 }}</span>
                    @endif
                </div>

                <div class="sp-post__main">
                    <div class="sp-post__top">
                        <span class="sp-chip sp-chip--{{ $tone }}">{{ $statusLabels[$post->status] ?? $post->status }}</span>
                        <span class="sp-chip sp-chip--plain sp-chip--muted">{!! $ico($formatMeta[$post->format]['icon'] ?? 'image', 12) !!} {{ $formats[$post->format] ?? $post->format }}</span>
                        @if ($post->ai_generated)
                            <span class="sp-chip sp-chip--plain sp-chip--ai">{!! $ico('sparkles', 12) !!} AI</span>
                        @endif
                        <span class="sp-post__when">
                            @if ($post->status === 'scheduled' && $post->scheduled_at)
                                {!! $ico('calendar', 13) !!} {{ $post->scheduled_at->copy()->setTimezone($timezone)->format('D j M Y, g:i A') }}
                            @else
                                {!! $ico('clock', 13) !!} {{ ($post->published_at ?? $post->created_at)->copy()->setTimezone($timezone)->format('j M Y, g:i A') }}
                            @endif
                        </span>
                    </div>

                    <div class="sp-post__caption {{ trim((string) $post->caption) === '' ? 'is-empty' : '' }}">{{ trim((string) $post->caption) !== '' ? $post->caption : 'No caption' }}</div>

                    @if ($post->targets->isNotEmpty())
                        <div class="sp-post__targets">
                            @foreach ($post->targets as $t)
                                <span class="sp-target" title="{{ $providerLabel($t->provider) }} · {{ $statusLabels[$t->status] ?? $t->status }}">
                                    <span class="sp-target__logo">{!! $brand($t->provider, 15) !!}</span>
                                    <span>{{ $t->account_name ?: $providerLabel($t->provider) }}</span>
                                    <span class="sp-target__dot sp-target__dot--{{ $t->status }}"></span>
                                    @if ($t->permalink)
                                        <a href="{{ $t->permalink }}" target="_blank" rel="noopener">View {!! $ico('external', 12) !!}</a>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @foreach ($post->targets->where('status', 'failed')->filter(fn ($t) => $t->error) as $t)
                        <div class="sp-post__error">{!! $ico('alert', 14) !!}
                            <div><b>{{ $t->account_name ?: $providerLabel($t->provider) }}:</b> {{ $t->error }}</div></div>
                    @endforeach
                    @foreach ($post->targets->filter(fn ($t) => data_get($t->state, 'note')) as $t)
                        <div class="sp-post__note">{{ $t->account_name }}: {{ data_get($t->state, 'note') }}</div>
                    @endforeach
                </div>

                <div class="sp-post__actions">
                    @if ($post->isEditable() && $post->targets->isNotEmpty())
                        <form method="POST" action="{{ route('social-posts.publish', ['client' => $client->slug, 'id' => hashid($post->id)]) }}">
                            @csrf
                            <button class="sp-iconbtn sp-iconbtn--primary" title="Publish now" aria-label="Publish now">{!! $ico('send', 16) !!}</button>
                        </form>
                    @endif
                    @if (in_array($post->status, ['failed', 'partial'], true))
                        <form method="POST" action="{{ route('social-posts.retry', ['client' => $client->slug, 'id' => hashid($post->id)]) }}">
                            @csrf
                            <button class="sp-btn sp-btn--secondary sp-btn--sm" title="Retry the accounts that failed">{!! $ico('refresh', 14) !!}<span>Retry</span></button>
                        </form>
                    @endif
                    @if ($post->status !== 'publishing')
                        <form method="POST" action="{{ route('social-posts.destroy', ['client' => $client->slug, 'id' => hashid($post->id)]) }}"
                              onsubmit="return confirm({{ json_encode($post->published_at ? 'Remove this post from the list? It stays live on Facebook and Instagram.' : 'Delete this post?') }});">
                            @csrf
                            @method('DELETE')
                            <button class="sp-iconbtn sp-iconbtn--danger" title="{{ $post->published_at ? 'Remove from list' : 'Delete' }}" aria-label="{{ $post->published_at ? 'Remove from list' : 'Delete' }}">{!! $ico('trash', 16) !!}</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div class="sp-empty">
                <div class="sp-empty__ico">{!! $ico('send', 26) !!}</div>
                @if ($filter === 'all')
                    <b class="sp-empty__title">No posts yet</b>
                    <span class="sp-empty__text">Your published, scheduled and draft posts will show up here.</span>
                    <button type="button" class="sp-btn sp-btn--primary sp-btn--sm" onclick="document.getElementById('spComposer').scrollIntoView({behavior:'smooth'});document.getElementById('spBrief').focus({preventScroll:true});">{!! $ico('plus', 15) !!}<span>Create your first post</span></button>
                @else
                    <b class="sp-empty__title">Nothing here</b>
                    <span class="sp-empty__text">No posts match this filter.</span>
                @endif
            </div>
        @endforelse

        @if ($posts && $posts->hasPages())
            <div class="sp-pager">{{ $posts->links() }}</div>
        @endif
    </div>

    @php
        // Built here, not inline: @json splits its argument on commas, so a
        // multi-key route() array inside it does not compile.
        $spUrls = [
            'caption'   => route('social-posts.caption', ['client' => $client->slug]),
            'facebook'  => route('social-posts.connect', ['client' => $client->slug, 'provider' => 'facebook',  'project_id' => hashid($project->id), 'purpose' => 'publish']),
            'instagram' => route('social-posts.connect', ['client' => $client->slug, 'provider' => 'instagram', 'project_id' => hashid($project->id), 'purpose' => 'publish']),
        ];
        $spIcons = ['spin' => (string) $ico('refresh', 15), 'sparkles' => (string) $ico('sparkles', 15), 'play' => (string) $ico('play', 28), 'image' => (string) $ico('image', 30), 'x' => (string) $ico('x', 12)];
        $spBrands = ['facebook_page' => (string) $brand('facebook_page', 20), 'instagram' => (string) $brand('instagram', 20)];
    @endphp
    <script>
    (function () {
        var form     = document.getElementById('spForm');
        var caption  = document.getElementById('spCaption');
        var counter  = document.getElementById('spCounter');
        var tagsOut  = document.getElementById('spTags');
        var media    = document.getElementById('spMedia');
        var drop     = document.getElementById('spDrop');
        var thumbs   = document.getElementById('spThumbs');
        var linkWrap = document.getElementById('spLinkWrap');
        var rule     = document.getElementById('spMediaRule');
        var aiMsg    = document.getElementById('spAiMsg');
        var aiFlag   = document.getElementById('spAiFlag');
        var URLS     = @json($spUrls);
        var ICONS    = @json($spIcons);
        var BRANDS   = @json($spBrands);
        var PROJECT  = @json($project->name);
        var picked   = [];   /* the files the form will send, in order */

        /* Facebook can't be iframed, so consent runs in a popup that reloads
           this page and closes itself when it is done. */
        window.spConnect = function (provider) {
            var url = URLS[provider], w = 600, h = 720;
            var x = window.screenX + (window.outerWidth - w) / 2;
            var y = window.screenY + (window.outerHeight - h) / 2;
            var popup = window.open(url, 'metaconnect', 'popup,width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
            if (!popup) { window.location.href = url; }
        };

        var RULES = {
            post:  '(1–10 photos for a carousel, or one video)',
            reel:  '(one vertical video, 9:16)',
            story: '(one photo or video)'
        };

        function format() {
            var r = form.querySelector('input[name="format"]:checked');
            return r ? r.value : 'post';
        }

        function onFormat() {
            var f = format();
            rule.textContent = RULES[f] || '';
            linkWrap.style.display = f === 'post' ? '' : 'none';
            media.multiple = f === 'post';
            if (!media.multiple && picked.length > 1) { picked = picked.slice(0, 1); syncInput(); renderThumbs(); }
            /* Stories are Instagram-only: disable the Facebook choices rather
               than letting the server refuse the whole post. */
            form.querySelectorAll('input[name="accounts[]"][data-provider="facebook_page"]').forEach(function (cb) {
                cb.disabled = f === 'story';
                if (f === 'story') cb.checked = false;
            });
            renderPreview();
        }
        form.querySelectorAll('input[name="format"]').forEach(function (r) { r.addEventListener('change', onFormat); });
        form.querySelectorAll('input[name="accounts[]"]').forEach(function (cb) { cb.addEventListener('change', renderPreview); });

        /* ── Caption counter ─────────────────────────────────────── */
        function count() {
            var n = caption.value.length;
            var tags = (caption.value.match(/#[^\s#]+/g) || []).length;
            counter.textContent = n.toLocaleString() + ' / 2,200';
            counter.className = n > 2200 ? 'is-over' : '';
            tagsOut.textContent = tags ? tags + ' hashtag' + (tags === 1 ? '' : 's') + (tags > 30 ? ' (Instagram allows 30)' : '') : '';
            tagsOut.className = tags > 30 ? 'is-over' : '';
        }
        caption.addEventListener('input', function () { count(); renderPreview(); });

        /* ── Media: picker, drag & drop, remove ──────────────────── */
        function syncInput() {
            var dt = new DataTransfer();
            picked.forEach(function (f) { dt.items.add(f); });
            media.files = dt.files;
        }
        function addFiles(list) {
            var files = Array.prototype.slice.call(list);
            picked = media.multiple ? picked.concat(files).slice(0, 10) : files.slice(0, 1);
            syncInput();
            renderThumbs();
            renderPreview();
        }
        function renderThumbs() {
            thumbs.innerHTML = '';
            picked.forEach(function (f, i) {
                var box = document.createElement('div');
                box.className = 'sp-thumb';
                var url = URL.createObjectURL(f), el;
                if (f.type.indexOf('video/') === 0) {
                    el = document.createElement('video'); el.src = url; el.muted = true;
                    var tag = document.createElement('span'); tag.className = 'sp-thumb__tag'; tag.textContent = 'VIDEO';
                    box.appendChild(el); box.appendChild(tag);
                } else {
                    el = document.createElement('img'); el.src = url; el.alt = f.name;
                    box.appendChild(el);
                }
                box.title = f.name + ' (' + (f.size / 1048576).toFixed(1) + ' MB)';
                var x = document.createElement('button');
                x.type = 'button'; x.className = 'sp-thumb__x'; x.innerHTML = ICONS.x;
                x.setAttribute('aria-label', 'Remove ' + f.name);
                x.addEventListener('click', function () { picked.splice(i, 1); syncInput(); renderThumbs(); renderPreview(); });
                box.appendChild(x);
                thumbs.appendChild(box);
            });
        }
        media.addEventListener('change', function () {
            /* The native picker replaces the selection; merge it instead so
               "browse" twice builds up a carousel. */
            var files = Array.prototype.slice.call(media.files);
            picked = media.multiple ? picked.concat(files.filter(function (f) { return picked.indexOf(f) === -1; })).slice(0, 10) : files.slice(0, 1);
            syncInput(); renderThumbs(); renderPreview();
        });
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });

        /* ── Live preview ────────────────────────────────────────── */
        var pvName = document.getElementById('spPvName'), pvMeta = document.getElementById('spPvMeta'),
            pvAvatar = document.getElementById('spPvAvatar'), pvText = document.getElementById('spPvText'),
            pvMedia = document.getElementById('spPvMedia'), pvUrl = null;

        function renderPreview() {
            var cb = form.querySelector('input[name="accounts[]"]:checked');
            pvName.textContent = cb ? cb.dataset.name : PROJECT;
            var others = form.querySelectorAll('input[name="accounts[]"]:checked').length - 1;
            pvMeta.textContent = 'Just now' + (others > 0 ? ' · and ' + others + ' more account' + (others === 1 ? '' : 's') : '');
            if (cb && cb.dataset.avatar) {
                pvAvatar.innerHTML = '<img alt="" referrerpolicy="no-referrer">';
                pvAvatar.firstChild.src = cb.dataset.avatar;
            } else {
                pvAvatar.innerHTML = cb ? (BRANDS[cb.dataset.provider] || '') : ICONS.sparkles;
            }

            var text = caption.value.trim();
            pvText.textContent = text || 'Your caption will appear here.';
            pvText.classList.toggle('is-empty', !text);

            var f = format();
            pvMedia.classList.toggle('is-tall', f !== 'post');
            if (pvUrl) { URL.revokeObjectURL(pvUrl); pvUrl = null; }
            var first = picked[0];
            if (!first) {
                pvMedia.innerHTML = f === 'post' ? ICONS.image : ICONS.play;
                pvMedia.style.display = (f === 'post' && text) ? 'none' : '';
                return;
            }
            pvMedia.style.display = '';
            pvUrl = URL.createObjectURL(first);
            pvMedia.innerHTML = first.type.indexOf('video/') === 0
                ? '<video muted playsinline loop autoplay></video>'
                : '<img alt="">';
            pvMedia.firstChild.src = pvUrl;
            if (picked.length > 1) {
                var n = document.createElement('span');
                n.className = 'sp-preview__count'; n.textContent = '1/' + picked.length;
                pvMedia.appendChild(n);
            }
        }

        /* ── AI ──────────────────────────────────────────────────── */
        var btnWrite = document.getElementById('spAiWrite'), btnImprove = document.getElementById('spAiImprove');
        function ai(improve) {
            var brief = document.getElementById('spBrief').value.trim();
            if (!improve && !brief) { aiMsg.textContent = 'Type what the post is about first.'; document.getElementById('spBrief').focus(); return; }
            if (improve && !caption.value.trim()) { aiMsg.textContent = 'Write a caption first, then the AI can improve it.'; caption.focus(); return; }

            var btn = improve ? btnImprove : btnWrite, label = btn.innerHTML;
            btnWrite.disabled = btnImprove.disabled = true;
            btn.innerHTML = ICONS.spin.replace('<svg', '<svg class="sp-spin"') + '<span>Writing…</span>';
            aiMsg.textContent = '';

            var body = new FormData();
            body.append('_token', form.querySelector('input[name="_token"]').value);
            body.append('project_id', form.querySelector('input[name="project_id"]').value);
            body.append('brief', brief);
            body.append('draft', improve ? caption.value : '');
            body.append('format', format());
            body.append('tone', document.getElementById('spTone').value);
            body.append('language', document.getElementById('spLang').value);
            var seen = {};
            form.querySelectorAll('input[name="accounts[]"]:checked').forEach(function (cb) {
                if (!seen[cb.dataset.provider]) { seen[cb.dataset.provider] = 1; body.append('platforms[]', cb.dataset.provider); }
            });

            fetch(URLS.caption, { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (res) {
                    if (res.ok && res.d.ok) {
                        caption.value = res.d.caption;
                        aiFlag.value = 1;
                        count(); renderPreview();
                        aiMsg.textContent = 'Done. Edit it as you like before posting.';
                    } else {
                        var m = res.d.message;
                        if (!m && res.d.errors) { m = Object.keys(res.d.errors).map(function (k) { return res.d.errors[k][0]; }).join(' '); }
                        aiMsg.textContent = m || 'The AI could not write a caption just now.';
                    }
                })
                .catch(function () { aiMsg.textContent = 'Network error. Please try again.'; })
                .finally(function () { btn.innerHTML = label; btnWrite.disabled = btnImprove.disabled = false; });
        }
        btnWrite.addEventListener('click', function () { ai(false); });
        btnImprove.addEventListener('click', function () { ai(true); });

        /* Schedule needs a time; the browser can't express "required only
           for this one button", so check here. */
        form.addEventListener('submit', function (e) {
            var btn = e.submitter;
            if (btn && btn.value === 'schedule' && !document.getElementById('spWhen').value) {
                e.preventDefault();
                document.getElementById('spWhen').focus();
                document.getElementById('spWhen').showPicker && document.getElementById('spWhen').showPicker();
                return;
            }
            if (btn) {
                setTimeout(function () { form.querySelectorAll('button[type=submit]').forEach(function (b) { b.disabled = true; }); }, 0);
                if (btn.value !== 'draft') { btn.innerHTML = ICONS.spin.replace('<svg', '<svg class="sp-spin"') + '<span>Working…</span>'; }
            }
        });

        onFormat();
        count();
    })();
    </script>

    @endif
</div>
@endsection
