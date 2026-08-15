<!DOCTYPE html>
<html lang="en" class="{{ tva_theme_class() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    @php
        $brand = tva_setting('content.brand_name', 'Serve AI');

        // $pageTitle carries markup (<span class="accent">…</span>) because it
        // is also the visible <h1>, and a heading is rarely a good
        // search-result title. A page passes `seoTitle` to set the <title>
        // verbatim (brand included); otherwise we fall back to the stripped
        // heading with the brand appended.
        $headTitle = isset($seoTitle)
            ? $seoTitle
            : trim(strip_tags($pageTitle ?? '') . ' — ' . $brand, ' —');
    @endphp
    {{-- SEO meta (with per-page title/description/schema overrides) --}}
    @include('partials.seo-head', [
        'metaTitle'       => $headTitle,
        'metaDescription' => $metaDescription ?? null,
        'metaKeywords'    => $metaKeywords ?? null,
        'breadcrumbs'     => $breadcrumbs ?? null,
        'pageSchemaType'  => $pageSchemaType ?? 'WebPage',
        'jsonLd'          => $jsonLd ?? [],
    ])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    <style>
        /* LIGHT IS THE BASE. The blue-black identity moves to `html.dark`
           below — same tokens, same names, so every rule further down this
           file and in every page that uses this layout keeps working with no
           edit. That is the whole reason the site was tokenised. */
        :root {
            --bg:        #eef3f9;
            --bg-2:      #f6f9fc;
            --panel:     #ffffff;
            --panel-2:   #f8fafd;
            --line:      rgba(27, 57, 98, .14);
            --line-hot:  rgba(27, 57, 98, .32);
            --text:      #1b3962;
            --text-dim:  #46587a;
            --text-dim2: #6b7c9c;
            /* On white, the old #3b82f6 gives 3.1:1 — below AA. Darkened to
               hold 4.6:1 for text while staying recognisably the same blue. */
            --neon:      #1b3962;
            --neon-btn:  #1b3962;
            --neon-2:    #2f6fb5;
            --radius:    14px;
            /* Light needs real shadows; dark leans on borders and glow. */
            --shadow:    0 1px 2px rgba(16,24,40,.05);
            --shadow-lg: 0 16px 40px -12px rgba(16,24,40,.16);
            /* Surfaces that used a black wash to sit on the dark page.
               On paper that is a grey smear, so light gets real white with
               a navy hairline. Named tokens so the same four rules serve
               both themes and anything added later inherits the fix. */
            --surface:     #ffffff;
            --surface-cta: linear-gradient(135deg, #ffffff 0%, #f3f7fc 100%);
        }

        html.dark {
            --bg:        #050609;
            --bg-2:      #0a0d14;
            --panel:     rgba(15, 21, 35, .55);
            --panel-2:   rgba(20, 28, 46, .85);
            --line:      rgba(120, 180, 220, .12);
            --line-hot:  rgba(59, 130, 246, .35);
            --text:      #e6edf3;
            --text-dim:  #8b96a8;
            --text-dim2: #727e93;
            --neon:      #3b82f6;
            /* Button fill only. White on --neon is 3.68:1 (AA needs 4.5);
               this is 5.17:1. --neon stays as-is for text/glow, where it
               already passes at 5.51:1 on the dark background. */
            --neon-btn:  #2563eb;
            --neon-2:    #60a5fa;
            --shadow:    0 1px 2px rgba(0,0,0,.4);
            --shadow-lg: 0 18px 44px -14px rgba(0,0,0,.6);
            --surface:     rgba(0,0,0,.28);
            --surface-cta: linear-gradient(135deg, rgba(59,130,246,.06), rgba(0,0,0,.4));
        }

        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            /* A single soft wash from the top rather than the dark radial
               vignette. Light pages want the paper to stay paper — a heavy
               gradient on white reads as a rendering artefact. */
            background:
                linear-gradient(180deg, #e7eef7 0%, var(--bg) 52%) fixed;
            color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.65; -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }
        html.dark, html.dark body {
            background:
                radial-gradient(ellipse at 50% -10%, #0d1a2e 0%, #050609 55%, #000 100%) fixed;
        }
        a { color: inherit; text-decoration: none; }
        ::selection { background: var(--neon); color: #fff; }
        html.dark ::selection { color: #000; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }

        /* ── Nav ── */
        .nav {
            position: sticky; top: 0; z-index: 50;
            padding: 14px 28px; display: flex; align-items: center; gap: 18px;
            backdrop-filter: blur(8px);
            background: rgba(238, 243, 249, .82); border-bottom: 1px solid var(--line);
        }
        html.dark .nav { background: rgba(5, 6, 9, .6); }
        .nav__brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 17px; }
        .nav__brand-mark { width: 30px; height: 30px; display: block; object-fit: contain; }
        /* The glow belongs to the dark theme. On white it reads as a print
           mistake — a halo around a logo with nothing to glow against. */
        html.dark .nav__brand-mark { filter: drop-shadow(0 0 10px rgba(59, 130, 246, .45)); }
        .nav__links { margin-left: auto; display: flex; gap: 22px; font-size: 13px; color: var(--text-dim); align-items: center; }
        .nav__links a:hover { color: var(--text); }
        .nav__cta {
            background: var(--neon-btn); color: #fff; padding: 7px 14px; border-radius: 999px;
            font-weight: 600; font-size: 13px; box-shadow: var(--shadow-lg);
            transition: transform .15s, box-shadow .15s;
        }
        .nav__cta:hover { transform: translateY(-1px); box-shadow: 0 8px 20px -6px rgba(29,78,216,.45); }
        html.dark .nav__cta { box-shadow: 0 0 22px rgba(59,130,246,.45); }
        html.dark .nav__cta:hover { box-shadow: 0 0 32px rgba(59,130,246,.65); }
        @media (max-width: 720px) { .nav { padding: 12px 16px; } .nav__links a:not(.nav__cta) { display: none; } }

        /* ── Page hero band ── */
        .page-hero { position: relative; padding: 72px 0 30px; text-align: center; }
        .page-hero .wrap { max-width: 820px; }
        .page-hero__eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 11px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
            color: var(--neon); background: rgba(59,130,246,.08);
            border: 1px solid rgba(59,130,246,.25); border-radius: 999px; padding: 6px 14px; margin-bottom: 20px;
        }
        .page-hero h1 { font-size: clamp(30px, 5vw, 50px); font-weight: 800; letter-spacing: -.02em; line-height: 1.08; margin: 0 0 14px; }
        .page-hero h1 .accent { background: linear-gradient(90deg, var(--neon), var(--neon-2) 70%, #3b82f6); -webkit-background-clip: text; background-clip: text; color: transparent; }
        html.dark .page-hero h1 .accent { background: linear-gradient(90deg, var(--neon), var(--neon-2) 60%, #dbeafe); -webkit-background-clip: text; background-clip: text; }
        .page-hero p { font-size: 17px; color: var(--text-dim); margin: 0 auto; max-width: 620px; }
        .page-hero__meta { margin-top: 16px; font-size: 12px; color: var(--text-dim2); text-transform: uppercase; letter-spacing: .1em; }

        /* ── Layout container ── */
        .wrap { position: relative; z-index: 2; max-width: 1240px; margin: 0 auto; padding: 0 28px; }
        @media (max-width: 540px) { .wrap { padding: 0 18px; } }

        /* ── Prose / legal article ── */
        .article { padding: 30px 0 40px; }
        /* 820px is a reading measure — right for the legal and policy pages
           this layout was built for, since long lines of prose are tiring. */
        .article .wrap { max-width: 820px; }

        /* Pages whose content is a grid rather than prose (pricing tables,
           card layouts) need the full container. Without this they inherit the
           reading measure above and sit in a narrow column with large empty
           margins — which is what happened to /pricing. Matches the 1240px
           the homepage sections use, so the same plan cards look identical in
           both places. */
        .article--wide .wrap { max-width: 1240px; }
        /* …while any prose *inside* a wide page keeps a comfortable measure. */
        .article--wide .prose { max-width: 820px; margin-left: auto; margin-right: auto; }
        .prose {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 18px; padding: 40px clamp(20px, 4vw, 48px);
            backdrop-filter: blur(8px);
        }
        .prose > :first-child { margin-top: 0; }
        .prose h2 {
            font-size: 22px; font-weight: 800; letter-spacing: -.01em;
            margin: 38px 0 12px; color: var(--text); scroll-margin-top: 90px;
        }
        .prose h3 { font-size: 17px; font-weight: 700; margin: 26px 0 8px; color: var(--text); }
        .prose p, .prose li { color: var(--text-dim); font-size: 15.5px; }
        .prose p { margin: 0 0 14px; }
        .prose ul, .prose ol { margin: 0 0 16px; padding-left: 22px; }
        .prose li { margin: 0 0 8px; }
        .prose strong { color: var(--text); font-weight: 600; }
        .prose a { color: var(--neon-2); text-decoration: underline; text-underline-offset: 2px; }
        .prose a:hover { color: var(--neon); }
        .prose hr { border: none; border-top: 1px solid var(--line); margin: 30px 0; }
        .prose table { width: 100%; border-collapse: collapse; margin: 0 0 18px; font-size: 14px; }
        .prose th, .prose td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); color: var(--text-dim); vertical-align: top; }
        .prose th { color: var(--text); font-weight: 600; }
        .prose .lead { font-size: 16px; color: var(--text); }
        .prose .note {
            margin: 22px 0; padding: 14px 18px; border-radius: 12px;
            background: rgba(59,130,246,.06); border: 1px solid rgba(59,130,246,.22);
            color: var(--text-dim); font-size: 14px;
        }

        /* ── Generic CTA band reused by pages ── */
        .page-cta {
            margin: 8px auto 0; max-width: 820px;
            border: 1px solid var(--line-hot);
            background: var(--surface-cta, linear-gradient(135deg, rgba(59,130,246,.06), rgba(0,0,0,.4)));
            border-radius: 20px; padding: 40px 28px; text-align: center;
        }
        .page-cta h2 { font-size: clamp(22px, 3.4vw, 32px); margin: 0 0 10px; }
        .page-cta p { color: var(--text-dim); margin: 0 auto 22px; max-width: 460px; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--neon-btn); color: #fff; padding: 13px 24px; border-radius: 12px;
            font-weight: 700; box-shadow: 0 10px 24px -8px rgba(29,78,216,.5); transition: transform .15s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn--ghost { background: transparent; border: 1px solid var(--line-hot); color: var(--text); box-shadow: none; }

        /* ── Breadcrumbs ── */
        .crumbs { padding: 18px 0 0; font-size: 12.5px; color: var(--text-dim2); }
        .crumbs ol { list-style: none; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0; padding: 0; }
        .crumbs li { display: flex; align-items: center; gap: 8px; }
        .crumbs a { color: var(--text-dim); }
        .crumbs a:hover { color: var(--neon-2); text-decoration: underline; text-underline-offset: 2px; }
        .crumbs [aria-current="page"] { color: var(--text); }
        .crumbs__sep { color: var(--text-dim2); }
    
        /* Navy nav on the inner pages too, matching the homepage. A light
           header here and a navy one there would look like two sites. */
        html:not(.dark) .nav {
            --text:     #0b1b33;
            --text-dim: #46586f;
            --line:     rgba(16,32,56,.10);
            --line-hot: rgba(29,78,216,.30);
            background: rgba(255,255,255,.88);
            border-bottom-color: rgba(16,32,56,.08);
            color: var(--text);
        }
        html:not(.dark) .nav .nav__cta {
            background: #1d4ed8; color: #fff;
            box-shadow: 0 6px 16px -7px rgba(29,78,216,.55);
        }
        html:not(.dark) .nav .nav__cta:hover { background: #1743bd; }
        html:not(.dark) .nav .nav__brand-mark { filter: none; }
</style>
    @stack('head')
    @include('partials.sweet-alert')
</head>
<body>

<nav class="nav">
    <a href="{{ url('/') }}" class="nav__brand">
        @php $navIconWebp = serveai_icon_sized(64, 'webp'); @endphp
        <picture>
            @if ($navIconWebp)<source srcset="{{ $navIconWebp }}" type="image/webp">@endif
            <img class="nav__brand-mark" src="{{ serveai_icon_sized(64) }}" alt="{{ $brand }} logo"
                 width="30" height="30" fetchpriority="high" decoding="async">
        </picture>{{ $brand }}
    </a>
    <div class="nav__links">
        <a href="{{ url('/') }}#platform">Features</a>
        <a href="{{ url('/') }}#cases">Use cases</a>
        <a href="{{ url('/pricing') }}">Pricing</a>
        <a href="{{ url('/blog') }}">{{ tva_setting('content.blog_label', 'Insights') }}</a>
        <a href="{{ url('/security') }}">Security</a>
        <a href="{{ url('/about') }}">About</a>
        <a href="{{ url('/contact') }}">Contact</a>
        {{-- Signed in → straight back to the app; /dashboard resolves the
             active workspace (or the picker). Signed out → sign in / sign up. --}}
        @auth
            <a href="{{ url('/dashboard') }}" class="nav__cta">Dashboard</a>
        @else
            <a href="{{ route('login') }}">Sign in</a>
            <a href="{{ url('/register') }}" class="nav__cta">Get started free</a>
        @endauth
        @include('partials.theme-toggle-public')
    </div>
</nav>

{{-- Visible breadcrumb trail. Mirrors the BreadcrumbList JSON-LD emitted by
     partials.seo-head, so the markup and the page never disagree — and gives
     every inner page a crawlable link back up to the homepage. --}}
@isset($breadcrumbs)
<nav class="crumbs wrap" aria-label="Breadcrumb">
    <ol>
        <li><a href="{{ url('/') }}">Home</a></li>
        @foreach ($breadcrumbs as $crumb)
            <li>
                <span class="crumbs__sep" aria-hidden="true">/</span>
                @if ($loop->last)
                    <span aria-current="page">{{ strip_tags($crumb['name']) }}</span>
                @else
                    <a href="{{ url($crumb['url']) }}">{{ strip_tags($crumb['name']) }}</a>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
@endisset

<header class="page-hero">
    <div class="wrap">
        @isset($pageEyebrow)<div class="page-hero__eyebrow">{{ $pageEyebrow }}</div>@endisset
        <h1>{!! $pageTitle ?? 'Page' !!}</h1>
        @isset($pageSubtitle)<p>{{ $pageSubtitle }}</p>@endisset
        @isset($pageMeta)<div class="page-hero__meta">{{ $pageMeta }}</div>@endisset
    </div>
</header>

<main>
    @yield('content')
</main>

@include('partials.site-footer')

@include('partials.cookie-consent')
</body>
</html>
