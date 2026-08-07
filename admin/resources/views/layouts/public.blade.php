<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    @php $brand = tva_setting('content.brand_name', 'Serve AI'); @endphp
    {{-- SEO meta (with per-page title/description override) --}}
    @include('partials.seo-head', [
        'metaTitle'       => trim(($pageTitle ?? '') . ' — ' . $brand, ' —'),
        'metaDescription' => $metaDescription ?? null,
    ])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg:        #050609;
            --bg-2:      #0a0d14;
            --panel:     rgba(15, 21, 35, .55);
            --panel-2:   rgba(20, 28, 46, .85);
            --line:      rgba(120, 180, 220, .12);
            --line-hot:  rgba(59, 130, 246, .35);
            --text:      #e6edf3;
            --text-dim:  #8b96a8;
            --text-dim2: #5a6478;
            --neon:      #3b82f6;
            --neon-2:    #60a5fa;
            --radius:    14px;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background:
                radial-gradient(ellipse at 50% -10%, #0d1a2e 0%, #050609 55%, #000 100%) fixed;
            color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            line-height: 1.65; -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }
        a { color: inherit; text-decoration: none; }
        ::selection { background: var(--neon); color: #000; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }

        /* ── Nav ── */
        .nav {
            position: sticky; top: 0; z-index: 50;
            padding: 14px 28px; display: flex; align-items: center; gap: 18px;
            backdrop-filter: blur(8px);
            background: rgba(5, 6, 9, .6); border-bottom: 1px solid var(--line);
        }
        .nav__brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 17px; }
        .nav__brand-mark {
            width: 30px; height: 30px; display: block; object-fit: contain;
            filter: drop-shadow(0 0 10px rgba(59, 130, 246, .45));
        }
        .nav__links { margin-left: auto; display: flex; gap: 22px; font-size: 13px; color: var(--text-dim); align-items: center; }
        .nav__links a:hover { color: var(--text); }
        .nav__cta {
            background: var(--neon); color: #fff; padding: 7px 14px; border-radius: 999px;
            font-weight: 600; font-size: 13px; box-shadow: 0 0 22px rgba(59, 130, 246, .45);
            transition: transform .15s, box-shadow .15s;
        }
        .nav__cta:hover { transform: translateY(-1px); box-shadow: 0 0 32px rgba(59, 130, 246, .65); }
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
        .page-hero h1 .accent { background: linear-gradient(90deg, var(--neon), var(--neon-2) 60%, #dbeafe); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .page-hero p { font-size: 17px; color: var(--text-dim); margin: 0 auto; max-width: 620px; }
        .page-hero__meta { margin-top: 16px; font-size: 12px; color: var(--text-dim2); text-transform: uppercase; letter-spacing: .1em; }

        /* ── Layout container ── */
        .wrap { position: relative; z-index: 2; max-width: 1240px; margin: 0 auto; padding: 0 28px; }
        @media (max-width: 540px) { .wrap { padding: 0 18px; } }

        /* ── Prose / legal article ── */
        .article { padding: 30px 0 40px; }
        .article .wrap { max-width: 820px; }
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
            background: linear-gradient(135deg, rgba(59,130,246,.06), rgba(0,0,0,.4));
            border-radius: 20px; padding: 40px 28px; text-align: center;
        }
        .page-cta h2 { font-size: clamp(22px, 3.4vw, 32px); margin: 0 0 10px; }
        .page-cta p { color: var(--text-dim); margin: 0 auto 22px; max-width: 460px; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--neon); color: #fff; padding: 13px 24px; border-radius: 12px;
            font-weight: 700; box-shadow: 0 0 32px rgba(59,130,246,.45); transition: transform .15s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn--ghost { background: transparent; border: 1px solid var(--line-hot); color: var(--text); box-shadow: none; }
    </style>
    @stack('head')
    @include('partials.sweet-alert')
</head>
<body>

<nav class="nav">
    <a href="{{ url('/') }}" class="nav__brand">
        <img class="nav__brand-mark" src="{{ serveai_icon() }}" alt="{{ $brand }} logo" width="30" height="30">{{ $brand }}
    </a>
    <div class="nav__links">
        <a href="{{ url('/') }}#platform">Features</a>
        <a href="{{ url('/') }}#cases">Use cases</a>
        <a href="{{ url('/contact') }}">Contact</a>
        <a href="{{ route('login') }}">Sign in</a>
        <a href="{{ url('/register') }}" class="nav__cta">Get started free</a>
    </div>
</nav>

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

</body>
</html>
