@extends('layouts.ops')

@section('content')
<style>
    .seo-wrap { max-width: 1100px; }

    .seo-statgrid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:18px; }
    @media (max-width:820px){ .seo-statgrid{ grid-template-columns:1fr; } }
    .seo-statcard {
        background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px;
        display:flex; align-items:flex-start; gap:12px;
    }
    .seo-statcard__ic { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#fff7ed;color:#c2410c;font-size:18px; }
    .seo-statcard__label { font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;font-weight:600; }
    .seo-statcard__val { font-size:13px;font-weight:700;color:#0f172a;margin-top:2px;word-break:break-all; }
    .seo-statcard__val a { color:#c2410c; }
    .seo-statcard__sub { font-size:11px;color:#94a3b8;margin-top:2px; }

    .seo-layout { display:grid; grid-template-columns: 210px 1fr; gap:18px; align-items:start; }
    @media (max-width:880px){ .seo-layout{ grid-template-columns:1fr; } }

    .seo-tabs { display:flex; flex-direction:column; gap:4px; position:sticky; top:16px; }
    @media (max-width:880px){ .seo-tabs{ flex-direction:row; flex-wrap:wrap; position:static; } }
    .seo-tab {
        display:flex; align-items:center; gap:9px; padding:10px 12px; border-radius:10px;
        font-size:13px; font-weight:600; color:#475569; cursor:pointer; border:1px solid transparent;
        background:transparent; text-align:left; transition:all .12s;
    }
    .seo-tab:hover { background:#fff7ed; color:#c2410c; }
    .seo-tab.is-active { background:var(--tva-gradient); color:#fff; box-shadow:0 6px 16px -8px rgba(201,122,0,.6); }
    .seo-tab i, .seo-tab svg { width:16px;height:16px; }

    .seo-panel { display:none; }
    .seo-panel.is-active { display:block; }

    .seo-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px 24px; margin-bottom:16px; }
    .seo-card__head { margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
    .seo-card__title { font-size:15px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; }
    .seo-card__sub { font-size:12px; color:#64748b; margin-top:3px; }

    .seo-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:640px){ .seo-grid2{ grid-template-columns:1fr; } }

    .seo-field { margin-bottom:14px; }
    .seo-field > label { display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:6px; }
    .seo-field .hint { font-size:11px; color:#94a3b8; font-weight:400; margin-left:6px; }
    .seo-input, .seo-textarea, .seo-select {
        width:100%; padding:9px 12px; border:1px solid #e2e8f0; border-radius:9px;
        background:#fff; font-size:13px; color:#0f172a; transition:border-color .12s, box-shadow .12s;
    }
    .seo-textarea { resize:vertical; min-height:90px; font-family:inherit; }
    .seo-textarea.mono { font-family: ui-monospace, monospace; font-size:12.5px; }
    .seo-input:focus, .seo-textarea:focus, .seo-select:focus { outline:none; border-color:var(--tva-accent); box-shadow:0 0 0 3px rgba(255,184,0,.15); }
    .seo-counter { font-size:11px; color:#94a3b8; margin-top:4px; text-align:right; }
    .seo-counter.is-over { color:#dc2626; font-weight:600; }

    .seo-toggle { display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; background:#fafbff; margin-bottom:14px; }
    .seo-toggle input { width:18px;height:18px; accent-color:#c97a00; }
    .seo-toggle__txt b { font-size:13px; color:#0f172a; }
    .seo-toggle__txt span { display:block; font-size:11px; color:#64748b; }

    .seo-fav { display:flex; align-items:center; gap:14px; }
    .seo-fav__box { width:64px;height:64px;border-radius:14px;border:2px dashed #cbd5e1;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;color:#94a3b8; }
    .seo-fav__box img { width:100%;height:100%;object-fit:contain; }

    .seo-sm-row { display:grid; grid-template-columns: 1fr 130px 90px 38px; gap:8px; margin-bottom:8px; align-items:center; }
    @media (max-width:640px){ .seo-sm-row{ grid-template-columns:1fr 1fr; } }
    .seo-sm-del { border:1px solid #fecaca;color:#dc2626;background:#fff;border-radius:8px;height:38px;cursor:pointer;display:flex;align-items:center;justify-content:center; }
    .seo-sm-del:hover { background:#fef2f2; }

    .seo-savebar {
        position:sticky; bottom:0; z-index:20; margin-top:6px;
        background:rgba(255,255,255,.92); backdrop-filter:blur(8px);
        border:1px solid #e2e8f0; border-radius:12px; padding:12px 18px;
        display:flex; align-items:center; justify-content:space-between; gap:12px;
        box-shadow:0 -6px 20px -12px rgba(0,0,0,.25);
    }
    .seo-savebar__note { font-size:12px; color:#64748b; }

    .seo-preview {
        border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; background:#fafbff; margin-top:6px;
    }
    .seo-preview__url { color:#0f7d27; font-size:12px; }
    .seo-preview__title { color:#1a0dab; font-size:17px; margin:2px 0; }
    .seo-preview__desc { color:#4d5156; font-size:13px; }

    /* dark mode */
    html.dark .seo-statcard, html.dark .seo-card { background:#1e293b; border-color:#334155; }
    html.dark .seo-statcard__val { color:#f1f5f9; }
    html.dark .seo-card__title { color:#f1f5f9; }
    html.dark .seo-card__head { border-bottom-color:#334155; }
    html.dark .seo-field > label { color:#cbd5e1; }
    html.dark .seo-input, html.dark .seo-textarea, html.dark .seo-select { background:#0f172a; color:#f1f5f9; border-color:#334155; }
    html.dark .seo-toggle, html.dark .seo-preview { background:#0f172a; border-color:#334155; }
    html.dark .seo-toggle__txt b { color:#f1f5f9; }
    html.dark .seo-savebar { background:rgba(15,23,42,.92); border-color:#334155; }
    html.dark .seo-tab:hover { background:#7c2d12; color:#fcd34d; }
    html.dark .seo-fav__box { background:#0f172a; border-color:#334155; }
    html.dark .seo-preview__title { color:#8ab4f8; }
    html.dark .seo-preview__desc { color:#bdc1c6; }
    html.dark .seo-preview__url { color:#86efac; }
</style>

<div class="content seo-wrap">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🔍</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">SEO &amp; Search Visibility</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Control everything search engines read from your public site — meta tags, social cards,
                robots.txt, sitemap, Google/Bing verification, analytics &amp; structured data. Saving applies live.
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-warning-soft show mb-4 flex items-center">
            <i data-lucide="alert-triangle" class="w-4 h-4 mr-2"></i> {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    {{-- ── Live status of the on-disk crawler files ─────────────────── --}}
    <div class="seo-statgrid">
        <div class="seo-statcard">
            <div class="seo-statcard__ic">🤖</div>
            <div class="flex-1">
                <div class="seo-statcard__label">robots.txt</div>
                <div class="seo-statcard__val"><a href="{{ $files['robots']['url'] }}" target="_blank">{{ $files['robots']['url'] }}</a></div>
                <div class="seo-statcard__sub">
                    @if ($files['robots']['shadowed'])
                        <span style="color:#f87171;">⚠ A static public/robots.txt is overriding the live version — press Save to remove it.</span>
                    @else
                        Generated live from these settings
                    @endif
                </div>
            </div>
        </div>
        <div class="seo-statcard">
            <div class="seo-statcard__ic">🗺️</div>
            <div class="flex-1">
                <div class="seo-statcard__label">sitemap.xml</div>
                <div class="seo-statcard__val"><a href="{{ $files['sitemap']['url'] }}" target="_blank">{{ $files['sitemap']['url'] }}</a></div>
                <div class="seo-statcard__sub">
                    @if ($files['sitemap']['shadowed'])
                        <span style="color:#f87171;">⚠ A static public/sitemap.xml is overriding the live version — press Save to remove it.</span>
                    @else
                        Generated live · only indexable URLs
                    @endif
                </div>
            </div>
        </div>
        <div class="seo-statcard">
            <div class="seo-statcard__ic">📡</div>
            <div class="flex-1">
                <div class="seo-statcard__label">Submit to engines</div>
                <div class="seo-statcard__sub" style="margin-bottom:8px;">Ping Google + Bing. Both retired these endpoints — the real path is submitting the sitemap once in Search Console.</div>
                <form method="POST" action="{{ route('ops.seo.ping') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm"><i data-lucide="send" class="w-3 h-3 inline -mt-0.5 mr-1"></i> Submit sitemap</button>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('ops.seo.update') }}" enctype="multipart/form-data" id="seoForm">
        @csrf
        <div class="seo-layout">
            {{-- ── Tab rail ─────────────────────────────────────────── --}}
            <div class="seo-tabs" id="seoTabs">
                <button type="button" class="seo-tab is-active" data-tab="meta"><i data-lucide="tag"></i> General Meta</button>
                <button type="button" class="seo-tab" data-tab="social"><i data-lucide="share-2"></i> Social Cards</button>
                <button type="button" class="seo-tab" data-tab="icons"><i data-lucide="image"></i> Icons</button>
                <button type="button" class="seo-tab" data-tab="verify"><i data-lucide="badge-check"></i> Verify &amp; Analytics</button>
                <button type="button" class="seo-tab" data-tab="robots"><i data-lucide="bot"></i> robots.txt</button>
                <button type="button" class="seo-tab" data-tab="sitemap"><i data-lucide="map"></i> Sitemap</button>
                <button type="button" class="seo-tab" data-tab="schema"><i data-lucide="boxes"></i> Structured Data</button>
                <button type="button" class="seo-tab" data-tab="advanced"><i data-lucide="code-2"></i> Advanced</button>
            </div>

            <div>
                {{-- ── META ─────────────────────────────────────────── --}}
                <div class="seo-panel is-active" data-panel="meta">
                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">🏷️ Core meta tags</div>
                            <div class="seo-card__sub">The title &amp; description Google shows in search results. Keep title ≤ 60 and description ≤ 160 characters.</div>
                        </div>

                        <div class="seo-field">
                            <label>Meta title <span class="hint">shown as the clickable headline</span></label>
                            <input type="text" name="meta_title" class="seo-input js-count" data-max="60" value="{{ $seo['meta_title'] ?? '' }}">
                            <div class="seo-counter"></div>
                        </div>
                        <div class="seo-field">
                            <label>Meta description</label>
                            <textarea name="meta_description" class="seo-textarea js-count" data-max="160">{{ $seo['meta_description'] ?? '' }}</textarea>
                            <div class="seo-counter"></div>
                        </div>
                        <div class="seo-field">
                            <label>Focus keywords <span class="hint">comma-separated</span></label>
                            <input type="text" name="meta_keywords" class="seo-input" value="{{ $seo['meta_keywords'] ?? '' }}">
                        </div>
                        <div class="seo-grid2">
                            <div class="seo-field">
                                <label>Canonical / site URL</label>
                                <input type="text" name="canonical_url" class="seo-input" value="{{ $seo['canonical_url'] ?? '' }}" placeholder="https://example.com">
                            </div>
                            <div class="seo-field">
                                <label>Author / publisher</label>
                                <input type="text" name="author" class="seo-input" value="{{ $seo['author'] ?? '' }}">
                            </div>
                        </div>
                        <div class="seo-grid2">
                            <div class="seo-field">
                                <label>Theme color <span class="hint">browser UI tint</span></label>
                                <input type="text" name="theme_color" class="seo-input" value="{{ $seo['theme_color'] ?? '' }}" placeholder="#3b82f6">
                            </div>
                        </div>

                        <div class="seo-preview">
                            <div class="seo-preview__url">{{ $seo['canonical_url'] ?? 'https://example.com' }}</div>
                            <div class="seo-preview__title" id="prevTitle">{{ $seo['meta_title'] ?? '' }}</div>
                            <div class="seo-preview__desc" id="prevDesc">{{ $seo['meta_description'] ?? '' }}</div>
                        </div>
                    </div>

                    <div class="seo-card">
                        <div class="seo-card__head"><div class="seo-card__title">🔓 Crawl control</div></div>
                        <label class="seo-toggle">
                            <input type="checkbox" name="allow_indexing" value="1" @checked($seo['allow_indexing'] ?? true)>
                            <span class="seo-toggle__txt"><b>Allow search engines to index this site</b>
                                <span>When off, a <code>noindex, nofollow</code> tag is emitted and robots.txt disallows all — use for staging.</span>
                            </span>
                        </label>
                    </div>
                </div>

                {{-- ── SOCIAL ───────────────────────────────────────── --}}
                <div class="seo-panel" data-panel="social">
                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">📘 Open Graph <span class="hint">Facebook · LinkedIn · WhatsApp</span></div>
                            <div class="seo-card__sub">Controls the link preview when your site is shared. Image: 1200×630px.</div>
                        </div>
                        <div class="seo-field"><label>OG title</label><input type="text" name="og_title" class="seo-input" value="{{ $seo['og_title'] ?? '' }}"></div>
                        <div class="seo-field"><label>OG description</label><textarea name="og_description" class="seo-textarea">{{ $seo['og_description'] ?? '' }}</textarea></div>
                        <div class="seo-grid2">
                            <div class="seo-field"><label>OG image URL</label><input type="text" name="og_image" class="seo-input" value="{{ $seo['og_image'] ?? '' }}" placeholder="https://…/share.png"></div>
                            <div class="seo-field"><label>OG type</label><input type="text" name="og_type" class="seo-input" value="{{ $seo['og_type'] ?? '' }}" placeholder="website"></div>
                        </div>
                        <div class="seo-field"><label>OG site name</label><input type="text" name="og_site_name" class="seo-input" value="{{ $seo['og_site_name'] ?? '' }}"></div>
                    </div>
                    <div class="seo-card">
                        <div class="seo-card__head"><div class="seo-card__title">🐦 Twitter / X card</div></div>
                        <div class="seo-grid2">
                            <div class="seo-field"><label>Card type</label>
                                <select name="twitter_card" class="seo-select">
                                    @foreach (['summary_large_image' => 'Large image', 'summary' => 'Summary'] as $v => $l)
                                        <option value="{{ $v }}" @selected(($seo['twitter_card'] ?? '') === $v)>{{ $l }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="seo-field"><label>Site @handle</label><input type="text" name="twitter_site" class="seo-input" value="{{ $seo['twitter_site'] ?? '' }}" placeholder="@serveai"></div>
                        </div>
                        <div class="seo-field"><label>Twitter image URL <span class="hint">falls back to OG image</span></label><input type="text" name="twitter_image" class="seo-input" value="{{ $seo['twitter_image'] ?? '' }}"></div>
                    </div>
                </div>

                {{-- ── ICONS ────────────────────────────────────────── --}}
                <div class="seo-panel" data-panel="icons">
                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">🖼️ Favicon &amp; app icons</div>
                            <div class="seo-card__sub">The little icon in the browser tab and bookmarks. Upload a square PNG/SVG/ICO (≥ 64×64).</div>
                        </div>
                        <div class="seo-fav" style="margin-bottom:16px;">
                            <div class="seo-fav__box">
                                @if (!empty($seo['favicon_url']))
                                    <img src="{{ $seo['favicon_url'] }}" alt="favicon" id="favPrev">
                                @else
                                    <i data-lucide="image" class="w-6 h-6"></i>
                                @endif
                            </div>
                            <div class="flex-1">
                                <div class="seo-field" style="margin-bottom:6px;">
                                    <label>Upload new favicon</label>
                                    <input type="file" name="favicon" accept=".ico,.png,.jpg,.jpeg,.svg,.webp" class="seo-input">
                                </div>
                                @if (!empty($seo['favicon_url']))
                                    <div class="seo-statcard__sub">Current: <a href="{{ $seo['favicon_url'] }}" target="_blank" style="color:#c2410c">{{ $seo['favicon_url'] }}</a></div>
                                @endif
                            </div>
                        </div>
                        <div class="seo-field">
                            <label>Apple touch icon URL <span class="hint">180×180 PNG for iOS home-screen</span></label>
                            <input type="text" name="apple_touch_icon" class="seo-input" value="{{ $seo['apple_touch_icon'] ?? '' }}">
                        </div>
                    </div>
                </div>

                {{-- ── VERIFY + ANALYTICS ───────────────────────────── --}}
                <div class="seo-panel" data-panel="verify">
                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">✅ Search engine verification</div>
                            <div class="seo-card__sub">Prove ownership in Google Search Console &amp; Bing Webmaster Tools. Use either the meta-tag token or the HTML-file method.</div>
                        </div>
                        <div class="seo-field">
                            <label>Google site verification token <span class="hint">the <code>content</code> value from the meta-tag method</span></label>
                            <input type="text" name="google_site_verification" class="seo-input" value="{{ $seo['google_site_verification'] ?? '' }}" placeholder="e.g. Xy12abc…">
                        </div>
                        <div class="seo-field">
                            <label>Bing verification token <span class="hint">msvalidate.01</span></label>
                            <input type="text" name="bing_site_verification" class="seo-input" value="{{ $seo['bing_site_verification'] ?? '' }}">
                        </div>
                        <div class="seo-grid2">
                            <div class="seo-field">
                                <label>HTML-file method — filename</label>
                                <input type="text" name="verification_file_name" class="seo-input" value="{{ $seo['verification_file_name'] ?? '' }}" placeholder="google1234abcd.html">
                            </div>
                            <div class="seo-field">
                                <label>HTML-file contents <span class="hint">leave blank for default</span></label>
                                <input type="text" name="verification_file_body" class="seo-input" value="{{ $seo['verification_file_body'] ?? '' }}">
                            </div>
                        </div>
                        <div class="seo-statcard__sub">Saving writes the file to your web root so Google/Bing can fetch <code>/{{ $seo['verification_file_name'] ?: 'yourfile.html' }}</code>.</div>
                    </div>

                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">📈 Analytics &amp; tag managers</div>
                            <div class="seo-card__sub">IDs only — the tracking snippets are injected for you on every public page.</div>
                        </div>
                        <div class="seo-grid2">
                            <div class="seo-field"><label>Google Analytics 4 (GA4)</label><input type="text" name="ga4_id" class="seo-input" value="{{ $seo['ga4_id'] ?? '' }}" placeholder="G-XXXXXXXXXX"></div>
                            <div class="seo-field"><label>Google Tag Manager</label><input type="text" name="gtm_id" class="seo-input" value="{{ $seo['gtm_id'] ?? '' }}" placeholder="GTM-XXXXXXX"></div>
                        </div>
                        <div class="seo-field"><label>Meta (Facebook) Pixel ID</label><input type="text" name="facebook_pixel" class="seo-input" value="{{ $seo['facebook_pixel'] ?? '' }}" placeholder="1234567890"></div>
                    </div>
                </div>

                {{-- ── ROBOTS ───────────────────────────────────────── --}}
                <div class="seo-panel" data-panel="robots">
                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">🤖 robots.txt</div>
                            <div class="seo-card__sub">Tells crawlers what they may access. A <code>Sitemap:</code> line is appended automatically. Served live at <code>/robots.txt</code>.</div>
                        </div>
                        <div class="seo-field">
                            <textarea name="robots_txt" class="seo-textarea mono" rows="10">{{ $seo['robots_txt'] ?? '' }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- ── SITEMAP ──────────────────────────────────────── --}}
                <div class="seo-panel" data-panel="sitemap">
                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">🗺️ XML sitemap</div>
                            <div class="seo-card__sub">List the URLs you want indexed. Use absolute URLs or root-relative paths (e.g. <code>/pricing</code>). Served live at <code>/sitemap.xml</code>. Entries that are on the noindex list, or that no longer resolve to a page, are dropped automatically.</div>
                        </div>
                        <div class="seo-sm-row" style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:700;">
                            <div>URL / path</div><div>Change freq</div><div>Priority</div><div></div>
                        </div>
                        <div id="smRows">
                            @php $smUrls = $seo['sitemap_urls'] ?? []; @endphp
                            @forelse ($smUrls as $u)
                                <div class="seo-sm-row">
                                    <input type="text" name="sm_loc[]" class="seo-input" value="{{ $u['loc'] ?? '' }}">
                                    <select name="sm_changefreq[]" class="seo-select">
                                        @foreach (['always','hourly','daily','weekly','monthly','yearly','never'] as $cf)
                                            <option value="{{ $cf }}" @selected(($u['changefreq'] ?? 'weekly') === $cf)>{{ ucfirst($cf) }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="sm_priority[]" class="seo-input" value="{{ $u['priority'] ?? '0.5' }}">
                                    <button type="button" class="seo-sm-del" onclick="this.closest('.seo-sm-row').remove()"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                </div>
                            @empty
                            @endforelse
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm mt-2" id="smAdd"><i data-lucide="plus" class="w-3 h-3 inline -mt-0.5 mr-1"></i> Add URL</button>
                    </div>
                </div>

                {{-- ── STRUCTURED DATA ──────────────────────────────── --}}
                <div class="seo-panel" data-panel="schema">
                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">📦 Structured data (JSON-LD)</div>
                            <div class="seo-card__sub">Helps Google show rich results &amp; a knowledge panel. Emits Organization + WebSite schema.</div>
                        </div>
                        <label class="seo-toggle">
                            <input type="checkbox" name="structured_data" value="1" @checked($seo['structured_data'] ?? true)>
                            <span class="seo-toggle__txt"><b>Emit Organization &amp; WebSite schema</b><span>Injected as a JSON-LD script in the page head.</span></span>
                        </label>
                        <div class="seo-grid2">
                            <div class="seo-field"><label>Organization name</label><input type="text" name="org_name" class="seo-input" value="{{ $seo['org_name'] ?? '' }}"></div>
                            <div class="seo-field"><label>Logo URL</label><input type="text" name="org_logo" class="seo-input" value="{{ $seo['org_logo'] ?? '' }}"></div>
                        </div>
                        <div class="seo-grid2">
                            <div class="seo-field"><label>Contact phone</label><input type="text" name="org_phone" class="seo-input" value="{{ $seo['org_phone'] ?? '' }}"></div>
                            <div class="seo-field"><label>Contact email</label><input type="text" name="org_email" class="seo-input" value="{{ $seo['org_email'] ?? '' }}"></div>
                        </div>
                        <div class="seo-field">
                            <label>Social profile URLs <span class="hint">one per line — sameAs links</span></label>
                            <textarea name="social_links" class="seo-textarea" rows="4" placeholder="https://twitter.com/…&#10;https://linkedin.com/company/…">{{ is_array($seo['social_links'] ?? null) ? implode("\n", $seo['social_links']) : ($seo['social_links'] ?? '') }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- ── ADVANCED ─────────────────────────────────────── --}}
                <div class="seo-panel" data-panel="advanced">
                    <div class="seo-card">
                        <div class="seo-card__head">
                            <div class="seo-card__title">🧬 Custom &lt;head&gt; HTML</div>
                            <div class="seo-card__sub">Raw markup injected at the end of every public page's <code>&lt;head&gt;</code> — extra verification tags, preloads, third-party snippets. Use with care.</div>
                        </div>
                        <div class="seo-field">
                            <textarea name="custom_head_html" class="seo-textarea mono" rows="8" placeholder="&lt;meta name=&quot;…&quot; content=&quot;…&quot;&gt;">{{ $seo['custom_head_html'] ?? '' }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- ── SAVE BAR ─────────────────────────────────────── --}}
                <div class="seo-savebar">
                    <div class="seo-savebar__note"><i data-lucide="info" class="w-3.5 h-3.5 inline -mt-0.5"></i> Changes apply to the live website immediately.</div>
                    <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Save SEO settings</button>
                </div>
            </div>
        </div>
    </form>

    {{-- ── Activity log ─────────────────────────────────────────────── --}}
    <div class="seo-card" style="margin-top:18px;">
        <div class="seo-card__head"><div class="seo-card__title">🧾 SEO activity log</div>
            <div class="seo-card__sub">Append-only record of every change applied here.</div>
        </div>
        <div class="overflow-x-auto">
            <table class="tva-dt-table" style="width:100%;">
                <thead><tr><th>When</th><th>Action</th><th>Actor</th><th>Detail</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td style="font-family:ui-monospace,monospace;color:#64748b;font-size:11.5px;white-space:nowrap;">{{ date('M j, H:i', (int) $log->created_at) }}</td>
                        <td><span class="tva-status is-new">{{ $log->action }}</span></td>
                        <td>{{ optional($log->actor)->name ?? '—' }}</td>
                        <td style="font-size:11.5px;color:#64748b;">{{ \Illuminate\Support\Str::limit(json_encode($log->payload), 90) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;padding:40px;color:#94a3b8;">No SEO changes recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    if (window.lucide?.createIcons) window.lucide.createIcons();

    // Tabs
    var tabs = document.querySelectorAll('.seo-tab');
    var panels = document.querySelectorAll('.seo-panel');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(x => x.classList.remove('is-active'));
            panels.forEach(p => p.classList.remove('is-active'));
            t.classList.add('is-active');
            var p = document.querySelector('.seo-panel[data-panel="' + t.dataset.tab + '"]');
            if (p) p.classList.add('is-active');
        });
    });

    // Character counters + live SERP preview
    function refreshCounter(el) {
        var max = parseInt(el.dataset.max || '0', 10);
        var c = el.parentElement.querySelector('.seo-counter');
        if (!c) return;
        var len = el.value.length;
        c.textContent = len + (max ? ' / ' + max : '') + ' chars';
        c.classList.toggle('is-over', max && len > max);
    }
    document.querySelectorAll('.js-count').forEach(function (el) {
        refreshCounter(el);
        el.addEventListener('input', function () { refreshCounter(el); });
    });
    var mt = document.querySelector('[name="meta_title"]'), md = document.querySelector('[name="meta_description"]');
    var pt = document.getElementById('prevTitle'), pd = document.getElementById('prevDesc');
    if (mt && pt) mt.addEventListener('input', () => pt.textContent = mt.value);
    if (md && pd) md.addEventListener('input', () => pd.textContent = md.value);

    // Sitemap repeater
    var add = document.getElementById('smAdd');
    if (add) add.addEventListener('click', function () {
        var freq = ['always','hourly','daily','weekly','monthly','yearly','never']
            .map(f => '<option value="' + f + '"' + (f === 'weekly' ? ' selected' : '') + '>' + f.charAt(0).toUpperCase() + f.slice(1) + '</option>').join('');
        var row = document.createElement('div');
        row.className = 'seo-sm-row';
        row.innerHTML =
            '<input type="text" name="sm_loc[]" class="seo-input" placeholder="/pricing">' +
            '<select name="sm_changefreq[]" class="seo-select">' + freq + '</select>' +
            '<input type="text" name="sm_priority[]" class="seo-input" value="0.5">' +
            '<button type="button" class="seo-sm-del"><i data-lucide="trash-2" class="w-4 h-4"></i></button>';
        row.querySelector('.seo-sm-del').addEventListener('click', () => row.remove());
        document.getElementById('smRows').appendChild(row);
        if (window.lucide?.createIcons) window.lucide.createIcons();
    });

    // Favicon live preview
    var fav = document.querySelector('[name="favicon"]');
    if (fav) fav.addEventListener('change', function () {
        if (!fav.files || !fav.files[0]) return;
        var box = document.querySelector('.seo-fav__box');
        var url = URL.createObjectURL(fav.files[0]);
        box.innerHTML = '<img src="' + url + '" alt="favicon">';
    });
})();
</script>
@endsection
