<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    @php
        // The FAQ is built here, above the <head> include, because the same
        // array feeds two things that must never drift apart: the visible
        // <details> list further down the page and the FAQPage structured
        // data below. Marking up an answer that isn't on the page is exactly
        // the kind of thing that gets rich results revoked.
        $faqDefaults = [
            ['How long does setup actually take?', 'Most teams are live in under five minutes. Connect a data source, pick a voice, drop the chat widget on your site — and your agent starts answering. Connecting a phone number or WhatsApp takes a few minutes more.'],
            ['Do I need any technical skills or developers?', 'No. Everything — data sources, voices, flows, channels and team access — is configured from a point-and-click dashboard. If you can fill in a form, you can launch an agent.'],
            ['Where does the agent get its answers?', 'Only from the data you give it: your website, your documents, and your databases. It does not make things up about your business — and you control exactly which data it can see.'],
            ['Is my customer data safe?', 'Yes. Every workspace is isolated in its own database, you choose which tables and columns the AI may read, and every conversation is logged and exportable. You can also bring your own AI keys or run models locally.'],
            ['Can it really sound like me?', 'A 10-second sample is enough to clone your voice, or you can pick from 30+ ready-made voices in 13 languages. Different agents can use different voices for sales, support, or billing.'],
            ['What does it cost, and can I cancel?', 'Start free — no credit card required. Upgrade when you’re ready, cancel anytime, and take your data with you. No long-term contracts, no lock-in.'],
        ];
        $faqs = [];
        foreach ($faqDefaults as $i => $pair) {
            $faqs[] = [
                tva_setting('content.faq' . ($i + 1) . '_q', $pair[0]),
                tva_setting('content.faq' . ($i + 1) . '_a', $pair[1]),
            ];
        }

        $brandName = tva_setting('content.brand_name', 'Serve AI');

        $homeJsonLd = [
            // The product itself. No aggregateRating and no offers: we have
            // neither published reviews nor a public price list, and inventing
            // either is a manual-action risk, not a shortcut.
            [
                '@type'               => 'SoftwareApplication',
                '@id'                 => \App\Support\Seo::origin() . '/#software',
                'name'                => $brandName,
                'applicationCategory' => 'BusinessApplication',
                'applicationSubCategory' => 'Customer Relationship Management',
                'operatingSystem'     => 'Web browser',
                'url'                 => \App\Support\Seo::origin() . '/',
                'description'         => tva_setting('content.hero_subtitle', ''),
                'featureList'         => array_values(array_filter([
                    tva_setting('content.feat1_title', ''),
                    tva_setting('content.feat2_title', ''),
                    tva_setting('content.feat3_title', ''),
                    tva_setting('content.feat4_title', ''),
                    tva_setting('content.feat5_title', ''),
                    tva_setting('content.feat6_title', ''),
                ])),
                'publisher'           => ['@id' => \App\Support\Seo::origin() . '/#organization'],
            ],
            [
                '@type'      => 'FAQPage',
                '@id'        => \App\Support\Seo::canonical('/') . '#faq',
                'mainEntity' => array_map(fn ($f) => [
                    '@type'          => 'Question',
                    'name'           => strip_tags((string) $f[0]),
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) $f[1])],
                ], $faqs),
            ],
        ];
    @endphp

    {{-- SEO meta, social cards, analytics, structured data — managed in /admin/seo --}}
    @include('partials.seo-head', [
        'canonicalPath' => '/',
        'jsonLd'        => $homeJsonLd,
    ])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    {{-- Fonts are loaded without blocking the first paint: the stylesheet is
         fetched at `print` priority and promoted to `all` once it lands. The
         page already renders in the system-UI fallback (font-family lists it
         second), so this trades a brief font swap for a faster LCP. --}}
    <link rel="preload" as="style"
          href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap">
    <link rel="stylesheet" media="print" onload="this.media='all'"
          href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap">
    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap">
    </noscript>

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
            --hot:       #ff5e87;
            --warn:      #ffcb6b;
            --radius:    14px;
        }

        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-weight: 400;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }
        a { color: inherit; text-decoration: none; }
        ::selection { background: var(--neon); color: #000; }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }

        /* ─── Starfield canvas (full bg) ─────────────────────────────── */
        #stars {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background: radial-gradient(ellipse at 50% 0%, #0d1a2e 0%, #050609 55%, #000 100%);
        }

        /* ─── Top nav ────────────────────────────────────────────────── */
        .nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 50;
            padding: 16px 28px;
            display: flex; align-items: center; gap: 18px;
            backdrop-filter: blur(8px);
            background: rgba(5, 6, 9, .55);
            border-bottom: 1px solid var(--line);
        }
        .nav__brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 17px; letter-spacing: .01em; }
        .nav__brand-mark {
            width: 28px; height: 28px; display: block; object-fit: contain;
            filter: drop-shadow(0 0 12px rgba(59, 130, 246, .5));
        }
        .nav__links { margin-left: auto; display: flex; gap: 22px; font-size: 13px; color: var(--text-dim); }
        .nav__links a:hover { color: var(--text); }
        .nav__cta {
            background: var(--neon); color: #ffffff; padding: 7px 14px;
            border-radius: 999px; font-weight: 600; font-size: 13px;
            box-shadow: 0 0 22px rgba(59, 130, 246, .45);
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .nav__cta:hover { transform: translateY(-1px); box-shadow: 0 0 32px rgba(59, 130, 246, .65); }

        /* ── Mobile menu ──
           The links used to be `display:none` under 720px with nothing to open
           them, so a phone had no way to reach How it works / Channels / FAQ /
           Sign in. Below 860px they move into a slide-down drawer behind a
           hamburger; the desktop bar is untouched above that. */
        .nav__toggle {
            display: none; margin-left: auto;
            width: 42px; height: 42px; padding: 0; flex-shrink: 0;
            align-items: center; justify-content: center;
            background: rgba(59,130,246,.08); border: 1px solid var(--line-hot);
            border-radius: 11px; color: var(--text); cursor: pointer;
        }
        .nav__toggle span {
            display: block; position: relative; width: 18px; height: 2px;
            background: currentColor; border-radius: 2px;
            transition: background .2s ease;
        }
        .nav__toggle span::before, .nav__toggle span::after {
            content: ''; position: absolute; left: 0; width: 18px; height: 2px;
            background: currentColor; border-radius: 2px; transition: transform .25s ease;
        }
        .nav__toggle span::before { top: -6px; }
        .nav__toggle span::after  { top:  6px; }
        /* Hamburger morphs into an X while the drawer is open. */
        .nav.is-open .nav__toggle span { background: transparent; }
        .nav.is-open .nav__toggle span::before { transform: translateY(6px) rotate(45deg); }
        .nav.is-open .nav__toggle span::after  { transform: translateY(-6px) rotate(-45deg); }

        @media (max-width: 860px) {
            .nav { padding: 12px 16px; flex-wrap: wrap; gap: 10px; row-gap: 0; }
            .nav__toggle { display: flex; }
            /* Row 1 stays brand → CTA → hamburger. The CTA keeps its place on
               the bar because it's the primary conversion action; only the
               secondary links move into the drawer. */
            .nav__brand  { order: 1; }
            .nav__cta    { order: 2; margin-left: auto; }
            .nav__toggle { order: 3; margin-left: 0; }
            /* Row 2: the drawer. Collapsed by max-height (not display:none) so
               it animates, and taken out of the a11y tree + tab order when shut. */
            .nav__links {
                order: 4; margin-left: 0;
                flex-basis: 100%; flex-direction: column; align-items: stretch;
                gap: 0; font-size: 15px;
                max-height: 0; overflow: hidden; visibility: hidden;
                transition: max-height .3s ease, visibility .3s;
            }
            .nav__links a {
                padding: 13px 4px; border-bottom: 1px solid var(--line); color: var(--text);
            }
            .nav__links a:last-child { border-bottom: none; }
            .nav.is-open .nav__links { max-height: 70vh; overflow-y: auto; visibility: visible; }
            /* The bar is translucent by design, but an open drawer over the
               hero left the menu text competing with the headline behind it. */
            .nav.is-open { background: rgba(5, 6, 9, .97); }
        }
        @media (max-width: 380px) {
            .nav__brand { font-size: 15px; }
            .nav__cta { padding: 7px 11px; font-size: 12px; }
        }

        /* ─── Layout container ───────────────────────────────────────── */
        .wrap { position: relative; z-index: 2; max-width: 1240px; margin: 0 auto; padding: 0 28px; }
        @media (max-width: 540px) { .wrap { padding: 0 18px; } }

        /* ─── Hero ───────────────────────────────────────────────────── */
        .hero { position: relative; padding: 130px 0 80px; min-height: 100vh; display: flex; align-items: center; }
        .hero__grid {
            display: grid; grid-template-columns: 1.05fr .95fr; gap: 60px; align-items: center; width: 100%;
        }
        /* `.hero` is a flex container, so `.wrap` is a flex item and defaults to
           `min-width: auto` — it refuses to shrink below the min-content width
           of the headline / call-bar / 3D scene and silently grows WIDER than
           the phone, which is what clipped the hero on mobile. These two lines
           are the actual fix; everything below is polish on top of them. */
        .hero > .wrap { min-width: 0; max-width: 100%; }
        .hero__grid > * { min-width: 0; }
        @media (max-width: 980px) {
            .hero { padding: 110px 0 60px; min-height: auto; }
            .hero__grid { grid-template-columns: 1fr; gap: 40px; }
        }

        .hero__eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 11px; font-weight: 600; color: var(--neon);
            text-transform: uppercase; letter-spacing: .14em;
            background: rgba(59, 130, 246, .08);
            border: 1px solid rgba(59, 130, 246, .25);
            border-radius: 999px; padding: 6px 14px;
            margin-bottom: 26px;
        }
        .hero__eyebrow::before { content:''; width:6px; height:6px; border-radius:50%; background:var(--neon); box-shadow:0 0 10px var(--neon); animation:pulse 1.8s infinite; }
        @keyframes pulse { 0%,100%{opacity:1; transform:scale(1);} 50%{opacity:.4; transform:scale(.6);} }

        .hero h1 {
            /* The old 34px floor was wider than a 390px phone once the longest
               word was laid out. Scale from 26px and allow long words to break
               so the headline can never run past the screen edge. */
            font-size: clamp(26px, 7.2vw, 64px);
            font-weight: 800; letter-spacing: -0.02em; line-height: 1.06;
            margin: 0 0 18px;
            overflow-wrap: break-word; word-wrap: break-word; hyphens: auto;
        }
        .hero h1 .accent {
            background: linear-gradient(90deg, var(--neon), var(--neon-2) 60%, #dbeafe);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        .hero p.sub { font-size: 17px; color: var(--text-dim); max-width: 520px; margin: 0 0 30px; }

        /* Call-me-now bar */
        .callbar {
            display: flex; align-items: center; gap: 10px;
            background: var(--panel-2); border: 1px solid var(--line);
            padding: 8px; border-radius: 14px;
            backdrop-filter: blur(6px);
            box-shadow: 0 12px 40px -10px rgba(59, 130, 246, .15), inset 0 0 0 1px rgba(59, 130, 246, .04);
            max-width: 460px;
        }
        .callbar__icon {
            width: 38px; height: 38px; border-radius: 10px;
            background: rgba(59, 130, 246, .12); color: var(--neon);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .callbar input {
            background: transparent; border: none; outline: none;
            color: var(--text); font-size: 15px; flex: 1; min-width: 0;
            font-family: inherit;
        }
        .callbar input::placeholder { color: var(--text-dim2); }
        .callbar button {
            background: var(--neon); color: #ffffff; border: none;
            padding: 10px 18px; border-radius: 10px;
            font-weight: 700; font-size: 14px; cursor: pointer;
            white-space: nowrap;
            box-shadow: 0 0 22px rgba(59, 130, 246, .35);
            transition: transform .15s, box-shadow .15s;
        }
        .callbar button:hover { transform: translateY(-1px); box-shadow: 0 0 32px rgba(59, 130, 246, .55); }
        .callbar__msg { font-size: 12px; color: var(--text-dim); margin-top: 10px; min-height: 16px; }
        .callbar__msg.is-ok  { color: var(--neon); }
        .callbar__msg.is-err { color: var(--hot); }
        /* On a phone the icon + input + "Call me now" button can't share one
           row without the button sliding off the right edge — stack instead. */
        @media (max-width: 560px) {
            .callbar { flex-wrap: wrap; max-width: 100%; }
            /* basis 0, not auto — otherwise the input claims its placeholder's
               intrinsic width and wraps onto a line of its own below the icon. */
            .callbar input { flex: 1 1 0; width: auto; min-width: 0; }
            .callbar button { flex: 1 0 100%; padding: 12px 18px; }
        }
        /* 16px keeps iOS/iPadOS from zooming the page on focus. */
        @media (max-width: 1024px) { .callbar input { font-size: 16px; } }

        .hero__meta { display: flex; gap: 18px; margin-top: 28px; flex-wrap: wrap; }
        .hero__meta-item { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-dim); }
        .hero__meta-item svg { width: 14px; height: 14px; color: var(--neon); }

        /* ─── 3D scene canvas ─────────────────────────────────────────── */
        .hero__scene {
            position: relative; aspect-ratio: 1 / 1;
            display: flex; align-items: center; justify-content: center;
            min-height: 360px;
        }
        .hero__scene canvas { display: block; width: 100% !important; height: 100% !important; }
        .hero__scene::before {
            content: ''; position: absolute; inset: -10%;
            background: radial-gradient(circle at 50% 50%, rgba(59, 130, 246, .22), transparent 60%);
            filter: blur(40px); z-index: -1;
        }
        /* CSS fallback orb — used when Three.js is suppressed (mobile/reduced-motion). */
        .scene-fallback {
            position: absolute; inset: 18%;
            border-radius: 50%;
            background:
                radial-gradient(circle at 30% 30%, rgba(59,130,246,.35), transparent 65%),
                conic-gradient(from 0deg, rgba(59,130,246,.18), rgba(59,130,246,.02), rgba(59,130,246,.18));
            box-shadow: 0 0 80px rgba(59,130,246,.4), inset 0 0 60px rgba(59,130,246,.2);
            animation: orbSpin 22s linear infinite;
        }
        .scene-fallback::after {
            content:''; position:absolute; inset:14%;
            border-radius:50%;
            border:2px solid rgba(59,130,246,.5);
            box-shadow: 0 0 30px rgba(59,130,246,.3);
        }
        @keyframes orbSpin { to { transform: rotate(360deg); } }
        /* Show fallback only when Three.js canvas isn't there. */
        .hero__scene canvas + .scene-fallback { display: none; }
        /* Orbiting status ring around scene */
        .scene-ring {
            position: absolute; inset: 0; pointer-events: none;
            display: flex; align-items: center; justify-content: center;
        }
        .scene-ring__dot {
            position: absolute; width: 8px; height: 8px; border-radius: 50%;
            background: var(--neon); box-shadow: 0 0 12px var(--neon);
        }
        .scene-ring__label {
            position: absolute; font-size: 10px; color: var(--neon);
            text-transform: uppercase; letter-spacing: .12em; font-weight: 600;
            white-space: nowrap;
        }
        /* The scene is a square that tracks its column width; on a phone that
           column is the full screen, so the orb and its orbiting labels spilled
           past the right edge. Cap it and clip the decoration to its own box. */
        @media (max-width: 980px) {
            .hero__scene {
                min-height: 0; width: 100%;
                max-width: min(360px, 78vw); margin: 0 auto;
            }
            /* The labels are pinned to the ring's outer edge (one at right:-4px),
               so on a narrow screen they hang off the orb and get sliced. Keep
               the orbiting dots — they carry the motion — and drop the text. */
            .scene-ring__label { display: none; }
        }

        /* ─── Section frame ───────────────────────────────────────────── */
        .section { padding: 100px 0; position: relative; }
        @media (max-width: 720px) { .section { padding: 70px 0; } }
        .section__eyebrow {
            display: inline-flex; gap: 8px; align-items: center;
            font-size: 11px; font-weight: 700; letter-spacing: .14em;
            text-transform: uppercase; color: var(--neon);
            margin-bottom: 14px;
        }
        .section__eyebrow::before { content:''; width:18px; height:1px; background:var(--neon); }
        .section h2 { font-size: clamp(28px, 4vw, 44px); font-weight: 800; letter-spacing: -0.02em; line-height: 1.1; margin: 0 0 16px; }
        .section p.lead { font-size: 17px; color: var(--text-dim); max-width: 640px; margin: 0 0 50px; }

        /* ─── Mission console (animated mockups strip) ────────────────── */
        .console-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px;
        }
        @media (max-width: 980px) { .console-grid { grid-template-columns: 1fr; } }

        .console {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: var(--radius); padding: 22px;
            position: relative; overflow: hidden;
            backdrop-filter: blur(8px);
            transform: perspective(1200px) rotateY(0deg);
            transition: transform .4s ease, border-color .3s;
        }
        .console:hover { border-color: var(--line-hot); }
        .console::before {
            content:''; position: absolute; top:0; left:0; right:0; height:1px;
            background: linear-gradient(90deg, transparent, var(--neon), transparent);
            opacity: .5;
        }
        .console__head {
            display: flex; align-items: center; gap: 8px;
            font-size: 11px; color: var(--text-dim); text-transform: uppercase;
            letter-spacing: .1em; font-weight: 600; margin-bottom: 18px;
        }
        .console__dot { width:8px; height:8px; border-radius:50%; background:var(--neon); box-shadow:0 0 8px var(--neon); animation: pulse 1.4s infinite; }

        /* Mockup: incoming call ringing */
        .mock-call {
            border: 1px solid var(--line); border-radius: 10px; padding: 18px;
            background: rgba(0,0,0,.3); position: relative;
        }
        .mock-call__avatar {
            width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 12px;
            background: radial-gradient(circle at 30% 30%, var(--neon), #1e3a8a);
            position: relative;
            animation: ring 1.4s infinite ease-out;
        }
        .mock-call__avatar::after {
            content:''; position:absolute; inset:-8px; border-radius:50%;
            border: 2px solid var(--neon); opacity:.4;
            animation: ringWave 1.4s infinite;
        }
        @keyframes ring { 0%,100%{transform:scale(1);} 50%{transform:scale(1.05);} }
        @keyframes ringWave { 0%{transform:scale(.8); opacity:.6;} 100%{transform:scale(1.4); opacity:0;} }
        .mock-call__num { text-align:center; font-family:'JetBrains Mono', monospace; font-size:14px; color:var(--text); letter-spacing:.04em; }
        .mock-call__label { text-align:center; font-size:11px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.14em; margin-top:4px; }
        .mock-call__btns { display:flex; gap:10px; justify-content:center; margin-top:14px; }
        .mock-call__btn {
            width:36px; height:36px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:14px;
        }
        .mock-call__btn--ans { background:var(--neon); color:#ffffff; }
        .mock-call__btn--rej { background:rgba(255,94,135,.18); color:var(--hot); border:1px solid rgba(255,94,135,.4); }

        /* Mockup: live transcript */
        .mock-trans { border: 1px solid var(--line); border-radius: 10px; padding: 14px; background: rgba(0,0,0,.3); height: 220px; overflow: hidden; position: relative; }
        .mock-trans__msg { display:flex; gap:8px; margin-bottom:10px; font-size:13px; opacity:0; transform:translateY(8px); }
        .mock-trans__msg.is-shown { opacity:1; transform:translateY(0); transition: all .35s ease; }
        .mock-trans__who { font-family:'JetBrains Mono', monospace; font-size:10px; color:var(--text-dim); text-transform:uppercase; letter-spacing:.1em; width:54px; flex-shrink:0; padding-top:2px; }
        .mock-trans__who.is-bot { color: var(--neon); }
        .mock-trans__bubble { background: rgba(255,255,255,.04); padding: 8px 12px; border-radius: 10px; flex: 1; line-height: 1.4; color: var(--text); }
        .mock-trans__bubble.is-bot { background: rgba(59,130,246,.08); color: #dbeafe; border: 1px solid rgba(59,130,246,.18); }
        .mock-trans__cursor { display:inline-block; width:6px; height:13px; background:var(--neon); animation: blink 1s infinite; vertical-align:middle; }
        @keyframes blink { 50% { opacity: 0; } }

        /* Mockup: lead extracted */
        .mock-lead {
            border: 1px solid var(--line); border-radius: 10px; padding: 16px; background: rgba(0,0,0,.3);
            position: relative;
        }
        .mock-lead__stamp {
            position: absolute; top: 14px; right: 14px;
            font-size: 9px; color: var(--neon);
            text-transform: uppercase; letter-spacing: .14em; font-weight: 700;
            background: rgba(59,130,246,.1); padding: 3px 9px; border-radius: 6px;
            border: 1px solid rgba(59,130,246,.3);
            opacity: 0; transform: scale(.7);
            animation: stampIn .5s ease-out 1.5s forwards;
        }
        @keyframes stampIn { to { opacity:1; transform:scale(1); } }
        .mock-lead__row {
            display: flex; justify-content: space-between; padding: 8px 0;
            border-bottom: 1px dashed rgba(255,255,255,.06);
            font-size: 12.5px;
        }
        .mock-lead__row:last-child { border-bottom: none; }
        .mock-lead__k { color: var(--text-dim); font-family:'JetBrains Mono', monospace; font-size: 11px; }
        .mock-lead__v { color: var(--text); font-weight: 500; }
        .mock-lead__row.is-new .mock-lead__v {
            color: var(--neon);
            animation: typeIn .35s ease both;
        }
        @keyframes typeIn { from{ opacity:0; transform:translateX(-4px); } to{ opacity:1; transform:translateX(0); } }

        /* ─── How-it-works steps ─────────────────────────────────────── */
        .steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
        @media (max-width: 900px) { .steps { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 540px) { .steps { grid-template-columns: 1fr; } }

        .step {
            position: relative; padding: 28px 22px;
            background: var(--panel); border: 1px solid var(--line);
            border-radius: var(--radius); backdrop-filter: blur(6px);
            transition: all .3s;
        }
        .step:hover { transform: translateY(-4px); border-color: var(--line-hot); box-shadow: 0 12px 40px -10px rgba(59,130,246,.2); }
        .step__num {
            position: absolute; top: -14px; left: 22px;
            background: var(--bg); border: 1px solid var(--line-hot);
            color: var(--neon); font-family:'JetBrains Mono', monospace;
            padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700;
        }
        .step__icon {
            width: 42px; height: 42px; border-radius: 10px;
            background: rgba(59,130,246,.08); color: var(--neon);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px; font-size: 18px;
        }
        .step__title { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
        .step__body { font-size: 13px; color: var(--text-dim); line-height: 1.55; }

        /* ─── Capabilities grid ──────────────────────────────────────── */
        .caps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        @media (max-width: 900px) { .caps { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 540px) { .caps { grid-template-columns: 1fr; } }

        .cap {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: var(--radius); padding: 24px; backdrop-filter: blur(6px);
            transition: all .3s;
        }
        .cap:hover { border-color: var(--line-hot); transform: translateY(-3px); }
        .cap__icon { width:40px; height:40px; border-radius:10px; background:rgba(59,130,246,.08); color:var(--neon); display:flex; align-items:center; justify-content:center; margin-bottom:14px; font-size:18px; }
        .cap h3 { font-size: 15px; font-weight: 700; margin: 0 0 8px; }
        .cap p { font-size: 13px; color: var(--text-dim); margin: 0; line-height: 1.55; }

        /* ─── Trust strip ────────────────────────────────────────────── */
        .trust {
            display: flex; flex-wrap: wrap; gap: 28px; justify-content: space-around;
            padding: 22px; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
            background: rgba(0,0,0,.2);
        }
        .trust__item { display: flex; align-items: center; gap: 10px; color: var(--text-dim); font-size: 13px; }
        .trust__num { font-family:'JetBrains Mono', monospace; color: var(--neon); font-size: 18px; font-weight: 700; }

        /* ─── CTA ────────────────────────────────────────────────────── */
        .cta {
            position: relative;
            border: 1px solid var(--line-hot);
            background: linear-gradient(135deg, rgba(59,130,246,.06), rgba(0,0,0,.5));
            border-radius: 24px; padding: 60px 40px; text-align: center;
            overflow: hidden;
        }
        .cta::before {
            content:''; position:absolute; inset:0;
            background: radial-gradient(circle at 50% 100%, rgba(59,130,246,.2), transparent 50%);
        }
        .cta > * { position: relative; }
        .cta h2 { font-size: clamp(28px, 4vw, 42px); margin: 0 0 14px; }
        .cta p { color: var(--text-dim); margin: 0 auto 30px; max-width: 480px; font-size: 16px; }
        .cta a.btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--neon); color: #ffffff;
            padding: 14px 26px; border-radius: 12px; font-weight: 700;
            box-shadow: 0 0 32px rgba(59,130,246,.5);
        }
        .cta a.btn:hover { transform: translateY(-2px); }

        /* ─── Channels strip ─────────────────────────────────────────── */
        .chan-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; }
        @media (max-width: 980px) { .chan-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 560px) { .chan-grid { grid-template-columns: repeat(2, 1fr); } }
        .chan {
            background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius);
            padding: 22px 16px; text-align: center; backdrop-filter: blur(6px); transition: all .3s;
        }
        .chan:hover { border-color: var(--line-hot); transform: translateY(-3px); }
        .chan__icon { font-size: 26px; margin-bottom: 10px; line-height: 1; }
        /* Brand SVG variant: `currentColor` glyphs (voice/chat/SMS) pick up the
           accent, while WhatsApp/Instagram/Facebook carry their own fill. */
        .chan__icon--svg { color: var(--neon-2); height: 30px; }
        .chan__icon--svg svg { display: inline-block; vertical-align: top; transition: transform .3s; }
        .chan:hover .chan__icon--svg svg { transform: translateY(-2px) scale(1.08); }
        .chan__title { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 5px; }
        .chan__body { font-size: 12px; color: var(--text-dim); line-height: 1.45; }

        /* ─── Security grid ──────────────────────────────────────────── */
        .sec-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
        @media (max-width: 900px) { .sec-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .sec-grid { grid-template-columns: 1fr; } }
        .sec {
            display: flex; gap: 14px; align-items: flex-start;
            background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius);
            padding: 22px; backdrop-filter: blur(6px); transition: all .3s;
        }
        .sec:hover { border-color: var(--line-hot); transform: translateY(-3px); }
        .sec__check {
            width: 34px; height: 34px; flex-shrink: 0; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(59,130,246,.12); color: var(--neon); border: 1px solid rgba(59,130,246,.3);
        }
        .sec__check svg { width: 16px; height: 16px; }
        .sec__title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
        .sec__body { font-size: 13px; color: var(--text-dim); line-height: 1.5; }
        .sec-link { color: var(--neon-2); font-weight: 600; font-size: 14px; }
        .sec-link:hover { color: var(--neon); }

        /* ─── FAQ accordion ──────────────────────────────────────────── */
        .faq { display: flex; flex-direction: column; gap: 12px; margin-top: 8px; }
        .faq__item {
            background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius);
            backdrop-filter: blur(6px); overflow: hidden; transition: border-color .3s;
        }
        .faq__item[open] { border-color: var(--line-hot); }
        .faq__q {
            list-style: none; cursor: pointer; display: flex; align-items: center;
            justify-content: space-between; gap: 16px; padding: 20px 22px;
            font-size: 16px; font-weight: 600; color: var(--text);
        }
        .faq__q::-webkit-details-marker { display: none; }
        .faq__chev { color: var(--neon); transition: transform .25s; display: flex; }
        .faq__chev svg { width: 18px; height: 18px; }
        .faq__item[open] .faq__chev { transform: rotate(180deg); }
        .faq__a { padding: 0 22px 20px; font-size: 14.5px; color: var(--text-dim); line-height: 1.6; }

        /* ─── Floating widget launcher (bottom-right) ────────────────── */
        .tva-launcher-floating {
            position: fixed; bottom: 22px; right: 22px; z-index: 60;
            width: 60px; height: 60px; border-radius: 50%;
            background: var(--neon); color: #ffffff;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: 0 0 40px rgba(59,130,246,.5);
            transition: transform .2s;
            border: none;
        }
        .tva-launcher-floating:hover { transform: scale(1.05); }
        .tva-launcher-floating::before {
            content:''; position: absolute; inset: -6px; border-radius: 50%;
            border: 2px solid var(--neon); opacity: .4; animation: ringWave 2s infinite;
        }
        .tva-launcher-floating svg { width: 26px; height: 26px; }

        .tva-iframe-frame {
            position: fixed; bottom: 100px; right: 22px; z-index: 60;
            width: min(380px, calc(100vw - 32px));
            height: min(580px, calc(100vh - 140px));
            border-radius: 18px; overflow: hidden;
            box-shadow: 0 30px 80px -20px rgba(0,0,0,.7), 0 0 0 1px rgba(59,130,246,.2);
            border: none; background: #0a0d14;
            display: none;
            animation: popIn .25s ease-out;
        }
        .tva-iframe-frame.is-open { display: block; }
        @keyframes popIn { from { opacity:0; transform: translateY(10px) scale(.96); } to { opacity:1; transform: translateY(0) scale(1); } }

        /* Reveal-on-scroll defaults */
        .reveal { opacity: 0; transform: translateY(30px); }

        /* ─── Advanced cursor (hidden on touch) ──────────────────────── */
        @media (hover: hover) and (pointer: fine) {
            html, body, a, button, input, .console, .step, .cap, .nav__cta { cursor: none; }
            .tva-cursor-dot, .tva-cursor-ring {
                position: fixed; top: 0; left: 0;
                pointer-events: none; z-index: 9999;
                will-change: transform;
                mix-blend-mode: difference;
            }
            .tva-cursor-dot {
                width: 6px; height: 6px; border-radius: 50%;
                background: #fff;
                transform: translate(-50%, -50%);
                transition: width .2s, height .2s, background .2s;
            }
            .tva-cursor-ring {
                width: 38px; height: 38px; border-radius: 50%;
                border: 1.5px solid rgba(255, 255, 255, .85);
                transform: translate(-50%, -50%);
                transition: width .25s cubic-bezier(.2,.8,.2,1),
                            height .25s cubic-bezier(.2,.8,.2,1),
                            border-color .2s, background .25s, opacity .2s;
                display: flex; align-items: center; justify-content: center;
            }
            .tva-cursor-ring::before {
                content: ''; position: absolute; inset: 6px;
                border: 1px solid rgba(59, 130, 246, .55);
                border-radius: 50%;
                animation: cursorSpin 5s linear infinite;
                clip-path: polygon(0 0, 70% 0, 70% 100%, 0 100%);
            }
            @keyframes cursorSpin { to { transform: rotate(360deg); } }
            .tva-cursor-ring__label {
                font-family: 'JetBrains Mono', monospace;
                font-size: 9px; letter-spacing: .14em;
                color: #fff; text-transform: uppercase; font-weight: 700;
                opacity: 0; transform: scale(.6);
                transition: all .25s;
                white-space: nowrap;
            }
            /* Hover over an interactive element */
            .tva-cursor-ring.is-hover {
                width: 64px; height: 64px;
                background: rgba(59, 130, 246, .12);
                border-color: var(--neon);
            }
            .tva-cursor-ring.is-hover .tva-cursor-ring__label {
                opacity: 1; transform: scale(1);
            }
            .tva-cursor-dot.is-hover { width: 0; height: 0; }
            /* Pressed */
            .tva-cursor-ring.is-down { transform: translate(-50%, -50%) scale(.78); }
        }

        /* ─── Universal card-tilt + cursor spotlight ─────────────────── */
        .tilt {
            transform-style: preserve-3d;
            transform: perspective(900px) rotateX(0) rotateY(0);
            transition: transform .25s cubic-bezier(.2,.8,.2,1);
            position: relative;
        }
        .tilt::after {
            content:''; position: absolute; inset: 0; border-radius: inherit;
            pointer-events: none; opacity: 0; transition: opacity .25s;
            background: radial-gradient(420px circle at var(--mx, 50%) var(--my, 50%),
                                        rgba(59, 130, 246, .14), transparent 45%);
        }
        .tilt:hover::after { opacity: 1; }
        @media (prefers-reduced-motion: reduce) {
            .tilt { transition: none; }
        }

        /* ─── Continuous gentle float per section ────────────────────── */
        .float-y    { animation: floatY 7s ease-in-out infinite; }
        .float-y-2  { animation: floatY 9s ease-in-out infinite -1.2s; }
        .float-y-3  { animation: floatY 11s ease-in-out infinite -2.8s; }
        @keyframes floatY {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(-10px); }
        }
        .float-tilt {
            animation: floatTilt 14s ease-in-out infinite;
            transform-style: preserve-3d;
        }
        @keyframes floatTilt {
            0%, 100% { transform: translateY(0) rotateZ(0); }
            50%      { transform: translateY(-12px) rotateZ(.6deg); }
        }
        @media (prefers-reduced-motion: reduce) {
            .float-y, .float-y-2, .float-y-3, .float-tilt { animation: none; }
        }

        /* Mini Three.js mounts in sections */
        .mini-3d {
            position: absolute; pointer-events: none;
            opacity: .85;
        }
        .mini-3d canvas { display: block; }
        /* These float outside the content box on purpose (one sits at left:-60px).
           There's no room for that on a phone — it just widened the page. */
        @media (max-width: 980px) { .mini-3d { display: none; } }

        /* ════════ Cinematic descent system ════════════════════════════ */

        /* Faint CRT scanlines over the whole page (below content). */
        .fx-scanlines {
            position: fixed; inset: 0; z-index: 1; pointer-events: none;
            background: repeating-linear-gradient(to bottom,
                rgba(59,130,246,.035) 0, rgba(59,130,246,.035) 1px, transparent 1px, transparent 3px);
            mix-blend-mode: screen; opacity: .45;
        }
        /* Vignette that deepens as you scroll — "going further inside". */
        .fx-depth-tint {
            position: fixed; inset: 0; z-index: 1; pointer-events: none; opacity: 0;
            background: radial-gradient(ellipse at 50% 50%, transparent 28%, rgba(0,0,0,.72) 100%);
            transition: opacity .25s linear;
        }
        /* A scan bar that sweeps the viewport on fast scroll. */
        .fx-sweep {
            position: fixed; left: 0; right: 0; top: 0; height: 2px; z-index: 1; pointer-events: none;
            background: linear-gradient(90deg, transparent, rgba(59,130,246,.55), transparent);
            box-shadow: 0 0 18px rgba(59,130,246,.5); opacity: 0;
        }

        /* ── Depth HUD (right rail) ── */
        .hud {
            position: fixed; right: 16px; top: 50%; transform: translateY(-50%); z-index: 40;
            display: flex; align-items: center; gap: 12px; pointer-events: none;
            font-family: 'JetBrains Mono', monospace;
        }
        .hud__col { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; text-align: right; }
        .hud__status { font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; color: var(--neon-2); }
        .hud__status .dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--neon); box-shadow: 0 0 8px var(--neon); margin-right: 5px; animation: pulse 1.4s infinite; vertical-align: middle; }
        .hud__depth { font-size: 15px; font-weight: 600; color: var(--text); letter-spacing: .04em; }
        .hud__depth small { color: var(--text-dim2); font-size: 10px; font-weight: 400; }
        .hud__sub { font-size: 8.5px; letter-spacing: .2em; text-transform: uppercase; color: var(--text-dim2); }
        .hud__rail { width: 2px; height: 220px; border-radius: 2px; background: rgba(120,180,220,.12); position: relative; overflow: visible; }
        .hud__fill { position: absolute; top: 0; left: 0; right: 0; height: 0%; border-radius: 2px; background: linear-gradient(var(--neon-2), var(--neon)); box-shadow: 0 0 10px rgba(59,130,246,.6); }
        .hud__marker { position: absolute; left: 50%; top: 0; width: 9px; height: 9px; border-radius: 50%; transform: translate(-50%,-50%); background: var(--neon); box-shadow: 0 0 12px var(--neon); }
        .hud__marker::after { content: ''; position: absolute; inset: -5px; border-radius: 50%; border: 1px solid var(--neon); opacity: .5; animation: ringWave 2s infinite; }
        @media (max-width: 1100px) { .hud { display: none; } }

        /* ── Layer seam dividers between sections ── */
        .seam { position: relative; height: 64px; display: flex; align-items: center; justify-content: center; pointer-events: none; }
        .seam__line {
            position: absolute; left: 8%; right: 8%; top: 50%; height: 1px; transform: scaleX(0); transform-origin: center;
            background: linear-gradient(90deg, transparent, var(--line-hot) 18%, var(--neon) 50%, var(--line-hot) 82%, transparent);
            box-shadow: 0 0 10px rgba(59,130,246,.4);
        }
        .seam__scan {
            position: absolute; top: 50%; left: 8%; width: 90px; height: 3px; transform: translateY(-50%);
            background: radial-gradient(closest-side, var(--neon), transparent);
            box-shadow: 0 0 16px var(--neon); opacity: 0;
        }
        .seam__label {
            position: relative; font-size: 9px; letter-spacing: .22em; text-transform: uppercase; color: var(--neon);
            background: var(--bg); padding: 4px 14px; border: 1px solid var(--line-hot); border-radius: 999px;
            opacity: 0; transform: translateY(6px);
        }

        /* ── Ambient-sound toggle (bottom-left) ── */
        .sfx-toggle {
            position: fixed; bottom: 22px; left: 22px; z-index: 60;
            width: 46px; height: 46px; border-radius: 50%; border: 1px solid var(--line-hot);
            background: var(--panel-2); color: var(--neon); cursor: pointer;
            display: flex; align-items: center; justify-content: center; backdrop-filter: blur(6px);
            transition: transform .2s, box-shadow .2s; padding: 0;
        }
        .sfx-toggle:hover { transform: scale(1.06); }
        .sfx-toggle.is-on { box-shadow: 0 0 24px rgba(59,130,246,.55); color: var(--neon-2); }
        .sfx-toggle svg { width: 20px; height: 20px; }
        .sfx-toggle:not(.is-on)::before {
            content: ''; position: absolute; inset: -5px; border-radius: 50%;
            border: 2px solid var(--neon); opacity: .35; animation: ringWave 2.4s infinite;
        }
        .sfx-toggle__hint {
            position: absolute; left: 58px; top: 50%; transform: translateY(-50%); white-space: nowrap;
            font-size: 11px; color: var(--text-dim); background: var(--panel-2);
            border: 1px solid var(--line); padding: 6px 10px; border-radius: 8px; opacity: 0;
            transition: opacity .25s; pointer-events: none;
        }
        .sfx-toggle:hover .sfx-toggle__hint { opacity: 1; }
        @media (max-width: 560px) { .sfx-toggle { bottom: 16px; left: 16px; width: 42px; height: 42px; } }

        @media (prefers-reduced-motion: reduce) {
            .fx-scanlines, .fx-depth-tint, .fx-sweep { display: none; }
            .hud__status .dot, .hud__marker::after, .sfx-toggle::before { animation: none; }
        }
    </style>
</head>
<body>

<canvas id="stars" aria-hidden="true"></canvas>

<!-- ── Advanced custom cursor (auto-hidden on touch devices) ──────── -->
<div class="tva-cursor-dot"  id="tvaCursorDot"  aria-hidden="true"></div>
<div class="tva-cursor-ring" id="tvaCursorRing" aria-hidden="true">
    <span class="tva-cursor-ring__label" id="tvaCursorLabel"></span>
</div>

<!-- ── Top nav ─────────────────────────────────────────────────────── -->
<nav class="nav" id="siteNav">
    <div class="nav__brand">
        <img class="nav__brand-mark" src="{{ serveai_icon() }}" alt="{{ tva_setting('content.brand_name', 'Serve AI') }} logo" width="28" height="28">
        {{ tva_setting('content.brand_name', 'Serve AI') }}
    </div>
    <button type="button" class="nav__toggle" id="navToggle"
            aria-label="Open menu" aria-expanded="false" aria-controls="navLinks">
        <span></span>
    </button>
    <div class="nav__links" id="navLinks">
        <a href="#how">How it works</a>
        <a href="#channels">Channels</a>
        <a href="#platform">Features</a>
        <a href="#cases">Use cases</a>
        <a href="#faq">FAQ</a>
        {{-- Real pages, not anchors: these are the only crawlable links from
             the homepage into /security and /about, which otherwise hang off
             the footer alone. --}}
        <a href="{{ url('/security') }}">Security</a>
        <a href="{{ url('/contact') }}">Contact</a>
        {{-- Signed-in visitors get a way back into the app instead of being
             asked to sign in again. /dashboard redirects to the active
             workspace, or to the picker when there's more than one. --}}
        @guest
            <a href="{{ route('login') }}">Sign in</a>
        @endguest
    </div>
    @auth
        <a href="{{ url('/dashboard') }}" class="nav__cta" data-cursor="open">Dashboard</a>
    @else
        <a href="{{ url('/register') }}" class="nav__cta" data-cursor="open">Get started free</a>
    @endauth
</nav>

<!-- ── HERO ────────────────────────────────────────────────────────── -->
<section class="hero">
    <div class="wrap hero__grid">
        <div class="reveal">
            <div class="hero__eyebrow">{{ tva_setting('content.hero_eyebrow', 'Live · AI Mission Console') }}</div>
            <h1>{{ tva_setting('content.hero_title', 'Your AI receptionist that') }} <span class="accent">{{ tva_setting('content.hero_title_accent', 'never sleeps.') }}</span></h1>
            <p class="sub">
                {{ tva_setting('content.hero_subtitle', 'Serve AI answers your calls and chats 24/7 in your own cloned voice, qualifies leads on the spot, and drops them straight into your CRM. Drop your data — watch it work.') }}
            </p>

            <form id="callForm" class="callbar" autocomplete="off">
                <div class="callbar__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </div>
                <input type="tel" name="phone" placeholder="+1 (555) 010-0100" required data-cursor="call">
                <button type="submit" data-cursor="call">{{ tva_setting('content.hero_cta_label', 'Call me now →') }}</button>
            </form>
            <div id="callMsg" class="callbar__msg">{{ tva_setting('content.hero_callbar_msg', 'Our AI agent will call you in under 10 seconds.') }}</div>

            <div class="hero__meta">
                <div class="hero__meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ tva_setting('content.hero_meta1', 'No credit card') }}
                </div>
                <div class="hero__meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ tva_setting('content.hero_meta2', 'Your data stays in your DB') }}
                </div>
                <div class="hero__meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ tva_setting('content.hero_meta3', 'Set up in 90 seconds') }}
                </div>
            </div>
        </div>

        <div class="hero__scene reveal" id="heroScene">
            <div class="scene-fallback" aria-hidden="true"></div>
            <div class="scene-ring">
                <div class="scene-ring__dot"   style="top:8%;  left:50%;"></div>
                <div class="scene-ring__label" style="top:2%;  left:50%; transform:translateX(-50%);">Listening</div>
                <div class="scene-ring__dot"   style="top:50%; right:6%;"></div>
                <div class="scene-ring__label" style="top:50%; right:-4px; transform:translateY(-50%);">STT</div>
                <div class="scene-ring__dot"   style="bottom:8%; left:50%;"></div>
                <div class="scene-ring__label" style="bottom:2%; left:50%; transform:translateX(-50%);">Speaking</div>
                <div class="scene-ring__dot"   style="top:50%; left:6%;"></div>
                <div class="scene-ring__label" style="top:50%; left:-4px; transform:translateY(-50%);">TTS</div>
            </div>
        </div>
    </div>
</section>

<!-- ── MISSION CONSOLE strip ──────────────────────────────────────── -->
<section class="section" id="how" style="position: relative;">
    <div class="mini-3d" id="mini3dConsole" style="top:5%; right:-40px; width:240px; height:240px;"></div>
    <div class="wrap">
        <div class="section__eyebrow reveal">{{ tva_setting('content.how_eyebrow', 'Mission Console') }}</div>
        <h2 class="reveal">{{ tva_setting('content.how_title', 'Every call. Every chat. Every lead — in real time.') }}</h2>
        <p class="lead reveal">
            {{ tva_setting('content.how_lead', "Watch a call come in, the agent transcribe + respond live, and a fresh lead land in your CRM — three glass panels you'll see every day inside Serve AI.") }}
        </p>

        <div class="console-grid">
            <!-- Panel 1: incoming call -->
            <div class="console reveal tilt float-y" data-cursor="answer">
                <div class="console__head"><span class="console__dot"></span> Inbound · Twilio</div>
                <div class="mock-call">
                    <div class="mock-call__avatar"></div>
                    <div class="mock-call__num">+1 (415) 555&hairsp;0192</div>
                    <div class="mock-call__label">Incoming call</div>
                    <div class="mock-call__btns">
                        <div class="mock-call__btn mock-call__btn--rej">✕</div>
                        <div class="mock-call__btn mock-call__btn--ans">✓</div>
                    </div>
                </div>
            </div>

            <!-- Panel 2: transcript -->
            <div class="console reveal tilt float-y-2" data-cursor="live">
                <div class="console__head"><span class="console__dot"></span> Live transcript</div>
                <div class="mock-trans" id="mockTrans">
                    <!-- messages injected by JS -->
                </div>
            </div>

            <!-- Panel 3: lead extracted -->
            <div class="console reveal tilt float-y-3" data-cursor="lead">
                <div class="console__head"><span class="console__dot"></span> Lead extracted</div>
                <div class="mock-lead">
                    <span class="mock-lead__stamp">+ New lead</span>
                    <div class="mock-lead__row"><span class="mock-lead__k">name</span><span class="mock-lead__v">Sarah Chen</span></div>
                    <div class="mock-lead__row"><span class="mock-lead__k">email</span><span class="mock-lead__v">sarah@acme.io</span></div>
                    <div class="mock-lead__row"><span class="mock-lead__k">intent</span><span class="mock-lead__v">Demo for 8-seat team</span></div>
                    <div class="mock-lead__row"><span class="mock-lead__k">confidence</span><span class="mock-lead__v">94%</span></div>
                    <div class="mock-lead__row is-new"><span class="mock-lead__k">status</span><span class="mock-lead__v">qualified</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── HOW IT WORKS ───────────────────────────────────────────────── -->
<section class="section">
    <div class="wrap">
        <div class="section__eyebrow reveal">{{ tva_setting('content.steps_eyebrow', 'Launch sequence') }}</div>
        <h2 class="reveal">{{ tva_setting('content.steps_title', 'From signup to first call in 90 seconds.') }}</h2>
        <p class="lead reveal">{{ tva_setting('content.steps_lead', 'Four steps. No code. No engineers. Done before your coffee cools.') }}</p>

        <div class="steps">
            <div class="step reveal tilt" data-cursor="step">
                <div class="step__num mono">01</div>
                <div class="step__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </div>
                <div class="step__title">{{ tva_setting('content.step1_title', 'Drop your data') }}</div>
                <div class="step__body">{{ tva_setting('content.step1_body', 'Paste a URL, upload a CSV, or connect your DB. We index it in 30 seconds.') }}</div>
            </div>
            <div class="step reveal tilt" data-cursor="step">
                <div class="step__num mono">02</div>
                <div class="step__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                </div>
                <div class="step__title">{{ tva_setting('content.step2_title', 'Pick a voice') }}</div>
                <div class="step__body">{{ tva_setting('content.step2_body', 'Choose from 30+ voices or clone yours from a 10-second sample.') }}</div>
            </div>
            <div class="step reveal tilt" data-cursor="step">
                <div class="step__num mono">03</div>
                <div class="step__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </div>
                <div class="step__title">{{ tva_setting('content.step3_title', 'Connect a number') }}</div>
                <div class="step__body">{{ tva_setting('content.step3_body', 'Bring a Twilio number, or get one of ours. Route per skill or agent.') }}</div>
            </div>
            <div class="step reveal tilt" data-cursor="step">
                <div class="step__num mono">04</div>
                <div class="step__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="step__title">{{ tva_setting('content.step4_title', 'Go live') }}</div>
                <div class="step__body">{{ tva_setting('content.step4_body', 'Embed the chat widget on your site. Watch your dashboard fill with leads.') }}</div>
            </div>
        </div>
    </div>
</section>

<!-- ── CHANNELS ───────────────────────────────────────────────────── -->
<section class="section" id="channels">
    <div class="wrap">
        <div class="section__eyebrow reveal">{{ tva_setting('content.channels_eyebrow', 'One agent. Every channel.') }}</div>
        <h2 class="reveal">{{ tva_setting('content.channels_title', 'Your customers reach out everywhere. Now you answer everywhere.') }}</h2>
        <p class="lead reveal">{{ tva_setting('content.channels_lead', 'The same brain — your data, your voice, your rules — picks up the phone, replies on WhatsApp, and chats on your website. No channel left on read.') }}</p>

        @php
            // Icon slugs are resolved to inline brand SVGs by BrandIcons;
            // an operator may still type an emoji into the content editor.
            $channels = [
                ['voice', 'Voice calls', 'Inbound & outbound phone, answered in a human voice.'],
                ['webchat', 'Website chat', 'One script tag. Live in minutes on any site.'],
                ['whatsapp', 'WhatsApp', 'Official Cloud API. Templates, media, and flows.'],
                ['instagram', 'Instagram', 'DMs and story replies handled automatically.'],
                ['facebook', 'Facebook', 'Messenger conversations, never missed again.'],
                ['sms', 'SMS & more', 'Text fallback and new channels added over time.'],
            ];
        @endphp
        <div class="chan-grid">
            @foreach ($channels as $i => $c)
                @php $chanIcon = (string) tva_setting('content.channel'.($i+1).'_icon', $c[0]); @endphp
                <div class="chan reveal tilt">
                    <div class="chan__icon {{ \App\Support\BrandIcons::has($chanIcon) ? 'chan__icon--svg' : '' }}">{!! \App\Support\BrandIcons::render($chanIcon, 30) !!}</div>
                    <div class="chan__title">{{ tva_setting('content.channel'.($i+1).'_title', $c[1]) }}</div>
                    <div class="chan__body">{{ tva_setting('content.channel'.($i+1).'_body', $c[2]) }}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<!-- ── PLATFORM FEATURES ──────────────────────────────────────────── -->
<section class="section" id="platform" style="position: relative;">
    <div class="mini-3d" id="mini3dCaps" style="top:8%; left:-60px; width:220px; height:220px;"></div>
    <div class="mini-3d" id="mini3dCaps2" style="bottom:8%; right:-40px; width:180px; height:180px;"></div>
    <div class="wrap">
        <div class="section__eyebrow reveal">{{ tva_setting('content.platform_eyebrow', 'The whole platform') }}</div>
        <h2 class="reveal">{{ tva_setting('content.platform_title', 'Everything you need to turn conversations into customers.') }}</h2>
        <p class="lead reveal">{{ tva_setting('content.platform_lead', 'Not a chatbot bolted onto a CRM — one system where the agent that talks to your customers and the CRM that remembers them are the same thing.') }}</p>

        @php
            $features = [
                ['🎙️', 'Voice cloning', 'Clone your own voice from a 10-second sample, or choose from 30+ studio voices across 13 languages. Every agent can sound different.'],
                ['🧠', 'Knows your business', 'Point it at your website, upload your price list or brochures, or connect your system. It answers real questions from your actual information.'],
                ['📥', 'Omnichannel inbox', 'Every WhatsApp, Instagram, Facebook and web conversation in one shared inbox. Jump in live, hand back to the bot, never lose context.'],
                ['🧭', 'Visual flow builder', 'Design guided conversations with a drag-and-drop canvas — qualify, route, book, and collect — then test it live before going public.'],
                ['🎭', 'Multi-agent & skills', 'Spin up sales, support and billing personas. Route by phone number or skill. Each agent gets its own voice, tools and knowledge.'],
                ['🎯', 'Automatic lead capture', 'The agent extracts names, intent and contact details mid-conversation and drops a qualified lead straight into your CRM — scored and ready.'],
                ['🔒', 'Per-table access control', 'Decide exactly which tables and columns the AI may read. Sensitive data stays invisible to the model. Privacy by design.'],
                ['🌍', 'Speaks their language', 'Auto-detects the language a customer uses and replies in kind. One agent serves a global audience with no extra setup.'],
                ['🔌', 'Choose your AI engine', 'Use a top AI provider for the smartest answers, or a private model on your own servers for maximum privacy. Switch in one click.'],
                ['👥', 'Teams, roles & access', 'Invite your team with custom roles and per-project permissions. Owners decide who sees billing, who edits flows, who works the inbox.'],
                ['📊', 'Conversations & insights', 'Replay every call and chat with full transcripts. See what customers ask, where they drop off, and what your agent is closing.'],
                ['🏢', 'Your data, your database', 'True multi-tenant isolation — every customer’s data lives in its own database. Nothing pooled, nothing shared, nothing leaked.'],
            ];
        @endphp
        <div class="caps">
            @foreach ($features as $i => $f)
                <div class="cap reveal tilt" data-cursor="explore">
                    <div class="cap__icon">{{ tva_setting('content.feat'.($i+1).'_icon', $f[0]) }}</div>
                    <h3>{{ tva_setting('content.feat'.($i+1).'_title', $f[1]) }}</h3>
                    <p>{{ tva_setting('content.feat'.($i+1).'_body', $f[2]) }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

<!-- ── USE CASES ──────────────────────────────────────────────────── -->
<section class="section" id="cases">
    <div class="wrap">
        <div class="section__eyebrow reveal">{{ tva_setting('content.cases_eyebrow', 'Made for your business') }}</div>
        <h2 class="reveal">{{ tva_setting('content.cases_title', 'If you have customers, it pays for itself.') }}</h2>
        <p class="lead reveal">{{ tva_setting('content.cases_lead', 'It doesn’t matter what you sell — if people call, message, or fill in a form, you’re losing some of them to slow replies and missed calls. Here’s what changes.') }}</p>

        @php
            $cases = [
                ['🛍️', 'Shops & online stores', 'Answers “do you have this?”, “how much?”, and “where’s my order?” instantly — and turns browsers into buyers, even at 2am.'],
                ['🦷', 'Clinics & salons', 'Books appointments, answers patients after hours, and stops no-shows from missed calls. Your front desk never sleeps.'],
                ['🏠', 'Real estate & property', 'Replies to every listing enquiry in seconds, qualifies serious buyers, and books viewings while you’re showing another home.'],
                ['🍽️', 'Restaurants & hospitality', 'Takes reservations, answers menu and hours questions, and handles the dinner-rush calls you can’t pick up.'],
                ['🧰', 'Services & trades', 'Captures job details from every caller, quotes from your price list, and makes sure no lead slips away while you’re on a job.'],
                ['📈', 'Agencies & B2B', 'Qualifies leads around the clock, books demos straight into your calendar, and hands sales a CRM full of ready-to-call prospects.'],
            ];
        @endphp
        <div class="caps">
            @foreach ($cases as $i => $c)
                <div class="cap reveal tilt" data-cursor="explore">
                    <div class="cap__icon">{{ tva_setting('content.case'.($i+1).'_icon', $c[0]) }}</div>
                    <h3>{{ tva_setting('content.case'.($i+1).'_title', $c[1]) }}</h3>
                    <p>{{ tva_setting('content.case'.($i+1).'_body', $c[2]) }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

<!-- ── SECURITY & CONTROL ─────────────────────────────────────────── -->
<section class="section" id="security">
    <div class="wrap">
        <div class="section__eyebrow reveal">{{ tva_setting('content.security_eyebrow', 'Built for trust') }}</div>
        <h2 class="reveal">{{ tva_setting('content.security_title', 'Enterprise-grade control, without the enterprise contract.') }}</h2>
        <p class="lead reveal">{{ tva_setting('content.security_lead', 'You hand an AI the keys to your customers and your data. We built it so you stay in control of both.') }}</p>

        @php
            $secs = [
                ['Isolated by tenant', 'Each workspace gets its own database. Your data is never mixed with anyone else’s.'],
                ['You gate the AI', 'Field-level access controls let you hide sensitive columns from the model entirely.'],
                ['Human handover', 'Your team can take over any conversation instantly — and hand it back when it’s done.'],
                ['Full audit trail', 'Every action and every conversation is logged and replayable. Nothing happens in the dark.'],
                ['Run it your way', 'Use our cloud, your own LLM keys, or fully local models. Your stack, your choice.'],
                ['No lock-in', 'Export your leads and conversations whenever you want. Cancel anytime, keep your data.'],
            ];
        @endphp
        <div class="sec-grid">
            @foreach ($secs as $i => $s)
                <div class="sec reveal tilt">
                    <div class="sec__check">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="sec__title">{{ tva_setting('content.security'.($i+1).'_title', $s[0]) }}</div>
                        <div class="sec__body">{{ tva_setting('content.security'.($i+1).'_body', $s[1]) }}</div>
                    </div>
                </div>
            @endforeach
        </div>
        <div style="margin-top:26px;" class="reveal">
            <a href="{{ url('/security') }}" class="sec-link" data-cursor="explore">Read more about security &amp; trust →</a>
        </div>
    </div>
</section>

<!-- ── TRUST STRIP ────────────────────────────────────────────────── -->
<section class="section" id="trust" style="padding-top: 40px;">
    <div class="wrap">
        <div class="trust reveal">
            <div class="trust__item"><span class="trust__num">{{ tva_setting('content.trust1_num', '<1s') }}</span> {{ tva_setting('content.trust1_label', 'first-byte response') }}</div>
            <div class="trust__item"><span class="trust__num">{{ tva_setting('content.trust2_num', '99.9%') }}</span> {{ tva_setting('content.trust2_label', 'call delivery') }}</div>
            <div class="trust__item"><span class="trust__num">{{ tva_setting('content.trust3_num', '30+') }}</span> {{ tva_setting('content.trust3_label', 'voices · 13 languages') }}</div>
            <div class="trust__item"><span class="trust__num">{{ tva_setting('content.trust4_num', 'SOC 2') }}</span> {{ tva_setting('content.trust4_label', 'roadmap Q3') }}</div>
        </div>
    </div>
</section>

<!-- ── FAQ ────────────────────────────────────────────────────────── -->
<section class="section" id="faq">
    <div class="wrap" style="max-width: 860px;">
        <div class="section__eyebrow reveal">{{ tva_setting('content.faq_eyebrow', 'Questions, answered') }}</div>
        <h2 class="reveal">{{ tva_setting('content.faq_title', 'Everything you’re probably wondering.') }}</h2>

        {{-- $faqs is built at the top of this file, so the FAQPage JSON-LD in
             the <head> and this visible list are always the same questions. --}}
        <div class="faq">
            @foreach ($faqs as $i => $q)
                <details class="faq__item reveal" @if($i === 0) open @endif>
                    <summary class="faq__q" data-cursor="explore">
                        <span>{{ $q[0] }}</span>
                        <span class="faq__chev" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                        </span>
                    </summary>
                    <div class="faq__a">{{ $q[1] }}</div>
                </details>
            @endforeach
        </div>
    </div>
</section>

<!-- ── FINAL CTA ──────────────────────────────────────────────────── -->
<section class="section">
    <div class="wrap">
        <div class="cta reveal">
            <h2>{{ tva_setting('content.cta_title', 'Ready to never miss a lead again?') }}</h2>
            <p>{{ tva_setting('content.cta_subtitle', "Spin up your agent in 90 seconds. Cancel anytime. We won't sleep on a single call.") }}</p>
            <a href="{{ url('/register') }}" class="btn" data-cursor="open">{{ tva_setting('content.cta_button', 'Start free — no card required →') }}</a>
        </div>
    </div>
</section>

@include('partials.site-footer')

<!-- ── Webchat widget (real production embed via loader.js) ───────── -->
@php
    // Prefer the production loader.js embed (same snippet customers
    // paste on their sites). Set LANDING_DEMO_KEY in admin/.env to the
    // project_api_key of whichever project drives the marketing demo.
    $tvaDemoKey   = (string) config('services.landing.demo_key', '');
    $tvaLoaderSrc = request()->getSchemeAndHttpHost() .
                    rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') .
                    '/widget/loader.js';
    // Fallback to the old manual iframe if LANDING_WIDGET_URL is set
    // and no demo key — keeps backward compat for anyone with the
    // older env var still in place.
    $tvaLegacyIframe = config('services.landing.widget_url');
@endphp

@if ($tvaDemoKey !== '')
    {{-- Production embed: loader.js drops a launcher + shadow-DOM
         iframe with full style isolation. Identical to what a paying
         customer pastes on their own site. --}}
    <script src="{{ $tvaLoaderSrc }}" data-project-key="{{ $tvaDemoKey }}" async></script>
@elseif ($tvaLegacyIframe)
    {{-- Legacy manual iframe — only used if LANDING_WIDGET_URL is set
         AND LANDING_DEMO_KEY isn't. --}}
    <iframe id="tvaIframe" class="tva-iframe-frame" src="{{ $tvaLegacyIframe }}" title="Chat with Serve AI" loading="lazy"></iframe>
    <button id="tvaLauncher" class="tva-launcher-floating" aria-label="Open chat" data-cursor="chat">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    </button>
@endif

<!-- ── Three.js + GSAP via CDN ────────────────────────────────────── -->
{{-- three.js is ~600 KB and gsap another ~70 KB. Loaded synchronously they
     hold the main thread for the whole download+parse, which is what shows up
     as Total Blocking Time / poor INP in Core Web Vitals. `defer` lets them
     download in parallel with the rest of the page and run in source order
     just before DOMContentLoaded — so the block below, which now waits for
     that event, still sees THREE and gsap exactly as it did before. --}}
<script defer src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
/* ──────────────────────────── Mobile menu ────────────────────────── */
(function () {
    var nav    = document.getElementById('siteNav');
    var toggle = document.getElementById('navToggle');
    var links  = document.getElementById('navLinks');
    if (!nav || !toggle || !links) return;

    function setOpen(open) {
        nav.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }

    toggle.addEventListener('click', function () {
        setOpen(!nav.classList.contains('is-open'));
    });

    // Every link is an in-page anchor, so leaving the drawer open would cover
    // the section the visitor just jumped to.
    links.addEventListener('click', function (e) {
        if (e.target.closest('a')) setOpen(false);
    });

    document.addEventListener('click', function (e) {
        if (nav.classList.contains('is-open') && !nav.contains(e.target)) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
    });
    // Resizing past the breakpoint leaves `is-open` stuck on the desktop bar.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 860) setOpen(false);
    });
})();

/* ───────────────────────────── Starfield ─────────────────────────── */
(function () {
    var canvas = document.getElementById('stars');
    var ctx = canvas.getContext('2d');
    var stars = [];
    var w, h;

    function resize() {
        w = canvas.width  = window.innerWidth  * window.devicePixelRatio;
        h = canvas.height = window.innerHeight * window.devicePixelRatio;
        canvas.style.width  = window.innerWidth  + 'px';
        canvas.style.height = window.innerHeight + 'px';
        var density = Math.min(220, Math.floor((w * h) / 12000));
        stars = [];
        for (var i = 0; i < density; i++) {
            stars.push({
                x: Math.random() * w,
                y: Math.random() * h,
                z: Math.random() * 1 + 0.2,   // depth
                a: Math.random()                // phase
            });
        }
    }
    function draw() {
        ctx.clearRect(0, 0, w, h);
        var t = performance.now() * 0.0008;
        for (var i = 0; i < stars.length; i++) {
            var s = stars[i];
            var twinkle = (Math.sin(t * 2 + s.a * 6) + 1) * 0.5;
            var size = s.z * 1.4 * window.devicePixelRatio;
            ctx.beginPath();
            ctx.arc(s.x, s.y, size, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(' + Math.floor(200 + s.z * 55) + ','
                                    + Math.floor(220 + s.z * 35) + ',255,'
                                    + (0.25 + twinkle * 0.6 * s.z) + ')';
            ctx.fill();
            // slow drift
            s.y += s.z * 0.15;
            if (s.y > h) s.y = 0;
        }
        requestAnimationFrame(draw);
    }
    window.addEventListener('resize', resize);
    resize();
    draw();
})();

/* ───────────────────────────── Three.js hero orb ───────────────── */
(function () {
    // Skip the WebGL scene on phones / low-power devices — the
    // CSS-only glow blob + status ring + starfield are enough atmosphere
    // and we save ~600 KB of JS execution.
    var isPhone = window.matchMedia('(max-width: 720px)').matches;
    var prefersReduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (isPhone || prefersReduce) return;
    if (typeof THREE === 'undefined') return;
    var mount = document.getElementById('heroScene');
    if (!mount) return;

    var w = mount.clientWidth;
    var h = mount.clientHeight;
    var scene    = new THREE.Scene();
    var camera   = new THREE.PerspectiveCamera(50, w / h, 0.1, 100);
    camera.position.z = 4.4;
    var renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(w, h);
    mount.appendChild(renderer.domElement);

    // Glowing wireframe orb (the "AI brain")
    var orbGeom = new THREE.IcosahedronGeometry(1.35, 2);
    var orbMat  = new THREE.MeshBasicMaterial({ color: 0x3b82f6, wireframe: true, transparent: true, opacity: 0.85 });
    var orb     = new THREE.Mesh(orbGeom, orbMat);
    scene.add(orb);

    // Inner solid core for depth
    var coreGeom = new THREE.IcosahedronGeometry(1.0, 1);
    var coreMat  = new THREE.MeshBasicMaterial({ color: 0x1e3a8a, transparent: true, opacity: 0.25 });
    scene.add(new THREE.Mesh(coreGeom, coreMat));

    // Outer faint ring shell
    var ringGeom = new THREE.RingGeometry(1.75, 1.78, 64);
    var ringMat  = new THREE.MeshBasicMaterial({ color: 0x3b82f6, side: THREE.DoubleSide, transparent: true, opacity: 0.35 });
    var ring     = new THREE.Mesh(ringGeom, ringMat);
    ring.rotation.x = Math.PI / 2.4;
    scene.add(ring);

    // Particle cloud around the orb
    var pGeom = new THREE.BufferGeometry();
    var pCount = 220;
    var positions = new Float32Array(pCount * 3);
    for (var i = 0; i < pCount; i++) {
        var r = 2.2 + Math.random() * 1.4;
        var theta = Math.random() * Math.PI * 2;
        var phi   = Math.acos(2 * Math.random() - 1);
        positions[i * 3]     = r * Math.sin(phi) * Math.cos(theta);
        positions[i * 3 + 1] = r * Math.sin(phi) * Math.sin(theta);
        positions[i * 3 + 2] = r * Math.cos(phi);
    }
    pGeom.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    var pMat = new THREE.PointsMaterial({ color: 0x60a5fa, size: 0.04, transparent: true, opacity: 0.7 });
    scene.add(new THREE.Points(pGeom, pMat));

    function animate() {
        requestAnimationFrame(animate);
        orb.rotation.y += 0.0035;
        orb.rotation.x += 0.0018;
        ring.rotation.z += 0.0012;
        renderer.render(scene, camera);
    }
    animate();

    window.addEventListener('resize', function () {
        var nw = mount.clientWidth, nh = mount.clientHeight;
        camera.aspect = nw / nh; camera.updateProjectionMatrix();
        renderer.setSize(nw, nh);
    });
})();

/* ─────────────────────────── Reveal on scroll ───────────────────── */
(function () {
    if (typeof gsap === 'undefined') return;
    gsap.registerPlugin(ScrollTrigger);
    // Generic fade-up for NON-card reveals (eyebrows, headings, leads, CTA…).
    // Cards/panels are owned by the dedicated "assembly" system (below) so we
    // exclude them here — otherwise two tweens fight over the same element.
    gsap.utils.toArray('.reveal:not(.cap):not(.chan):not(.sec):not(.faq__item):not(.step):not(.console)').forEach(function (el) {
        gsap.to(el, {
            opacity: 1, y: 0, duration: 0.9, ease: 'power3.out',
            scrollTrigger: { trigger: el, start: 'top 88%', toggleActions: 'play none none reverse' },
        });
    });
})();

/* ─────────────────── Fake live transcript animator ──────────────── */
(function () {
    var box = document.getElementById('mockTrans');
    if (!box) return;
    var script = [
        { who: 'caller', text: 'Hi, I saw your post about AI receptionists?' },
        { who: 'bot',    text: 'Yes! Serve AI handles 24/7 calls + chats. What\'s your use case?' },
        { who: 'caller', text: 'I run a dental clinic. 8 staff, miss a lot of after-hours calls.' },
        { who: 'bot',    text: 'Got it. Want me to book a 10-min demo with our team?' },
        { who: 'caller', text: 'Yeah — Tuesday afternoon works.' },
        { who: 'bot',    text: 'Booked. Confirmation sent to your email.' },
    ];

    function row(msg, withCursor) {
        var el = document.createElement('div');
        el.className = 'mock-trans__msg';
        el.innerHTML =
            '<div class="mock-trans__who ' + (msg.who === 'bot' ? 'is-bot' : '') + '">' +
                (msg.who === 'bot' ? 'AI' : 'User') +
            '</div>' +
            '<div class="mock-trans__bubble ' + (msg.who === 'bot' ? 'is-bot' : '') + '">' +
                msg.text +
                (withCursor ? '<span class="mock-trans__cursor"></span>' : '') +
            '</div>';
        return el;
    }

    function run() {
        box.innerHTML = '';
        var idx = 0;
        function next() {
            if (idx >= script.length) {
                // Restart after a pause
                setTimeout(run, 4000);
                return;
            }
            var el = row(script[idx], false);
            box.appendChild(el);
            // Trim if overflowing
            while (box.scrollHeight > box.clientHeight + 10 && box.firstChild) {
                box.removeChild(box.firstChild);
            }
            requestAnimationFrame(function () { el.classList.add('is-shown'); });
            idx++;
            setTimeout(next, 1400);
        }
        next();
    }
    // Only start when in viewport
    var started = false;
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (e.isIntersecting && !started) { started = true; run(); }
        });
    });
    io.observe(box);
})();

/* ───────────────────── Call-me-now form (capture stub) ──────────── */
(function () {
    var f = document.getElementById('callForm');
    var msg = document.getElementById('callMsg');
    if (!f) return;
    f.addEventListener('submit', function (e) {
        e.preventDefault();
        var phone = f.querySelector('input[name="phone"]').value.trim();
        if (!phone) return;
        msg.textContent = 'Connecting…';
        msg.className = 'callbar__msg';

        fetch('{{ url('/api/demo-call') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify({ phone: phone })
        })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (body) {
            msg.textContent = body.message || 'Thanks — we\'ll call you shortly.';
            msg.className = 'callbar__msg is-ok';
            f.querySelector('input').value = '';
        })
        .catch(function () {
            msg.textContent = 'Couldn\'t reach our servers. Try again in a moment.';
            msg.className = 'callbar__msg is-err';
        });
    });
})();

/* ─────────────────── Advanced custom cursor ────────────────────── */
(function () {
    // Skip on touch devices entirely — no hover support means no native
    // cursor to hide, and the elements would just sit dead-center forever.
    var canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    if (!canHover) return;

    var dot   = document.getElementById('tvaCursorDot');
    var ring  = document.getElementById('tvaCursorRing');
    var label = document.getElementById('tvaCursorLabel');
    if (!dot || !ring) return;

    // Two-layer cursor: the inner dot tracks the pointer 1:1 (instant
    // accuracy for clicks); the outer ring eases toward the pointer
    // with friction, giving the satisfying "lag" feel.
    var mx = window.innerWidth / 2, my = window.innerHeight / 2;
    var rx = mx, ry = my;
    document.addEventListener('mousemove', function (e) {
        mx = e.clientX; my = e.clientY;
        dot.style.transform = 'translate(' + mx + 'px, ' + my + 'px) translate(-50%, -50%)';
    }, { passive: true });

    function tick() {
        // Lerp the ring toward the dot at 18% per frame ≈ pleasant lag.
        rx += (mx - rx) * 0.18;
        ry += (my - ry) * 0.18;
        ring.style.transform = 'translate(' + rx + 'px, ' + ry + 'px) translate(-50%, -50%)';
        requestAnimationFrame(tick);
    }
    tick();

    // Hover affordances — outer ring grows + label appears on
    // interactive surfaces. Reads data-cursor="..." to set the label
    // text; otherwise picks a sensible default by element kind.
    function setLabel(s) {
        label.textContent = s || '';
    }
    var labelMap = {
        answer: 'Pickup', live: 'Live', lead: 'Hot lead',
        step: 'Step',  explore: 'Explore', call: 'Call',
        chat: 'Chat', open: 'Open',
    };
    function bindHover(selector, defaultLabel) {
        document.querySelectorAll(selector).forEach(function (el) {
            el.addEventListener('mouseenter', function () {
                ring.classList.add('is-hover');
                dot.classList.add('is-hover');
                var key = el.dataset.cursor;
                setLabel(labelMap[key] || defaultLabel || '');
            });
            el.addEventListener('mouseleave', function () {
                ring.classList.remove('is-hover');
                dot.classList.remove('is-hover');
                setLabel('');
            });
        });
    }
    bindHover('a',           'View');
    bindHover('button',      'Click');
    bindHover('input',       'Type');
    bindHover('[data-cursor]', '');

    // "Click" affordance — ring contracts briefly.
    document.addEventListener('mousedown', function () { ring.classList.add('is-down'); });
    document.addEventListener('mouseup',   function () { ring.classList.remove('is-down'); });

    // Hide cursor when the pointer leaves the window so it doesn't get
    // stuck in the corner.
    document.addEventListener('mouseleave', function () {
        dot.style.opacity = ring.style.opacity = 0;
    });
    document.addEventListener('mouseenter', function () {
        dot.style.opacity = ring.style.opacity = 1;
    });
})();

/* ─────────────────── Card 3D tilt + spotlight ──────────────────── */
(function () {
    var canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    var reduce   = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!canHover || reduce) return;

    document.querySelectorAll('.tilt').forEach(function (el) {
        var rect = null;
        function refresh() { rect = el.getBoundingClientRect(); }
        refresh();
        window.addEventListener('resize', refresh);

        el.addEventListener('mouseenter', refresh);
        el.addEventListener('mousemove', function (e) {
            if (!rect) refresh();
            var x = e.clientX - rect.left;
            var y = e.clientY - rect.top;
            var px = x / rect.width;        // 0..1
            var py = y / rect.height;
            var rotY = (px - 0.5) *  10;    // degrees
            var rotX = (py - 0.5) * -10;
            el.style.transform = 'perspective(900px) rotateX(' + rotX + 'deg) rotateY(' + rotY + 'deg) translateZ(0)';
            el.style.setProperty('--mx', x + 'px');
            el.style.setProperty('--my', y + 'px');
        });
        el.addEventListener('mouseleave', function () {
            el.style.transform = 'perspective(900px) rotateX(0) rotateY(0)';
        });
    });
})();

/* ─────────────────── Extra 3D mini-scenes (Three.js) ───────────── */
(function () {
    if (typeof THREE === 'undefined') return;
    var isPhone = window.matchMedia('(max-width: 720px)').matches;
    var reduce  = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (isPhone || reduce) return;

    // Tiny helper — build a transparent scene that fits an element.
    function spawnScene(mountId, builder) {
        var mount = document.getElementById(mountId);
        if (!mount) return;
        var w = mount.clientWidth, h = mount.clientHeight;
        var scene = new THREE.Scene();
        var camera = new THREE.PerspectiveCamera(45, w / h, 0.1, 100);
        camera.position.z = 4;
        var renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        renderer.setSize(w, h);
        mount.appendChild(renderer.domElement);
        var ctx = builder(scene, camera);
        function loop() {
            requestAnimationFrame(loop);
            if (ctx && ctx.update) ctx.update();
            renderer.render(scene, camera);
        }
        loop();
        window.addEventListener('resize', function () {
            var nw = mount.clientWidth, nh = mount.clientHeight;
            camera.aspect = nw / nh; camera.updateProjectionMatrix();
            renderer.setSize(nw, nh);
        });
    }

    // Mission Console — a glowing wireframe satellite (torus knot)
    spawnScene('mini3dConsole', function (scene) {
        var knot = new THREE.Mesh(
            new THREE.TorusKnotGeometry(0.9, 0.28, 100, 16),
            new THREE.MeshBasicMaterial({ color: 0x3b82f6, wireframe: true, transparent: true, opacity: 0.7 })
        );
        scene.add(knot);
        return { update: function () { knot.rotation.x += 0.005; knot.rotation.y += 0.008; } };
    });

    // Capabilities — floating cube (data block)
    spawnScene('mini3dCaps', function (scene) {
        var cube = new THREE.Mesh(
            new THREE.BoxGeometry(1.3, 1.3, 1.3),
            new THREE.MeshBasicMaterial({ color: 0x60a5fa, wireframe: true, transparent: true, opacity: 0.55 })
        );
        scene.add(cube);
        var edges = new THREE.LineSegments(
            new THREE.EdgesGeometry(cube.geometry),
            new THREE.LineBasicMaterial({ color: 0x3b82f6, transparent: true, opacity: 0.9 })
        );
        cube.add(edges);
        return { update: function () { cube.rotation.x += 0.004; cube.rotation.y += 0.006; } };
    });

    // Capabilities — bottom-right octahedron crystal
    spawnScene('mini3dCaps2', function (scene) {
        var crys = new THREE.Mesh(
            new THREE.OctahedronGeometry(1.0, 0),
            new THREE.MeshBasicMaterial({ color: 0xff5e87, wireframe: true, transparent: true, opacity: 0.5 })
        );
        scene.add(crys);
        return { update: function () { crys.rotation.x += 0.007; crys.rotation.z += 0.004; } };
    });
})();

/* ───────────────────── Scroll parallax for sections ────────────── */
(function () {
    if (typeof gsap === 'undefined') return;
    // Mini 3D objects drift up as you scroll past them.
    gsap.utils.toArray('.mini-3d').forEach(function (el) {
        gsap.to(el, {
            y: -120, opacity: 1,
            ease: 'none',
            scrollTrigger: {
                trigger: el.closest('.section'),
                start: 'top bottom', end: 'bottom top',
                scrub: true,
            },
        });
    });

    // (Step + capability card animations now handled by the dedicated
    //  "card assembly" system in the cinematic-descent block below.)
})();

/* ───────────────────── Floating widget toggle ──────────────────── */
(function () {
    var btn = document.getElementById('tvaLauncher');
    var iframe = document.getElementById('tvaIframe');
    if (!btn || !iframe) return;
    btn.addEventListener('click', function () {
        iframe.classList.toggle('is-open');
    });
})();

/* ═══════════ Cinematic descent: HUD · seams · scan FX · audio ═══════════ */
(function () {
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var hasGsap = (typeof gsap !== 'undefined');
    if (hasGsap && typeof ScrollTrigger !== 'undefined') gsap.registerPlugin(ScrollTrigger);

    /* ---- overlays ---- */
    var scanlines = document.createElement('div'); scanlines.className = 'fx-scanlines'; document.body.appendChild(scanlines);
    var tint = document.createElement('div'); tint.className = 'fx-depth-tint'; document.body.appendChild(tint);
    var sweep = document.createElement('div'); sweep.className = 'fx-sweep'; document.body.appendChild(sweep);

    /* ---- Depth HUD ---- */
    var hud = document.createElement('div'); hud.className = 'hud';
    hud.innerHTML =
        '<div class="hud__col">' +
            '<div class="hud__status"><span class="dot"></span><span id="hudStatus">BOOTING</span></div>' +
            '<div class="hud__depth"><span id="hudDepth">0000</span><small> m</small></div>' +
            '<div class="hud__sub" id="hudSub">SURFACE</div>' +
        '</div>' +
        '<div class="hud__rail"><div class="hud__fill" id="hudFill"></div><div class="hud__marker" id="hudMarker"></div></div>';
    document.body.appendChild(hud);
    var hudDepth = document.getElementById('hudDepth'),
        hudFill = document.getElementById('hudFill'),
        hudMarker = document.getElementById('hudMarker'),
        hudStatus = document.getElementById('hudStatus'),
        hudSub = document.getElementById('hudSub');

    function pad4(n) { n = String(n); while (n.length < 4) n = '0' + n; return n; }

    var lastY = window.scrollY, sweepTimer = null;
    function onScroll() {
        var max = document.documentElement.scrollHeight - window.innerHeight;
        var p = max > 0 ? window.scrollY / max : 0;
        p = Math.max(0, Math.min(1, p));
        hudFill.style.height = (p * 100) + '%';
        hudMarker.style.top = (p * 100) + '%';
        hudDepth.textContent = pad4(Math.round(p * 9999));
        tint.style.opacity = (p * 0.6).toFixed(3);

        /* fast downward scroll flashes a scan bar */
        if (!reduce) {
            var dy = window.scrollY - lastY;
            if (dy > 26) {
                sweep.style.opacity = Math.min(0.9, dy / 90).toFixed(2);
                clearTimeout(sweepTimer);
                sweepTimer = setTimeout(function () { sweep.style.opacity = 0; }, 120);
            }
        }
        lastY = window.scrollY;
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    onScroll();

    /* ---- per-section layer metadata + seams ---- */
    var sections = Array.prototype.slice.call(document.querySelectorAll('section.section'));
    var layers = [
        { n: 'MISSION CONSOLE', s: 'SCANNING' },
        { n: 'LAUNCH SEQUENCE', s: 'CALIBRATING' },
        { n: 'CHANNEL ARRAY',   s: 'CONNECTING' },
        { n: 'CORE SYSTEMS',    s: 'PROCESSING' },
        { n: 'FIELD UNITS',     s: 'MAPPING' },
        { n: 'SECURITY VAULT',  s: 'DECRYPTING' },
        { n: 'TELEMETRY',       s: 'SYNCING' },
        { n: 'KNOWLEDGE CORE',  s: 'INDEXING' },
        { n: 'LAUNCH BAY',      s: 'ARMED' }
    ];

    sections.forEach(function (sec, i) {
        var meta = layers[i] || { n: 'LAYER ' + (i + 1), s: 'PROCESSING' };
        sec.dataset.layer = meta.n;
        sec.dataset.status = meta.s;

        var seam = document.createElement('div');
        seam.className = 'seam';
        seam.innerHTML =
            '<div class="seam__line"></div><div class="seam__scan"></div>' +
            '<div class="seam__label mono">// LAYER ' + pad4(i + 1).slice(2) + ' — ' + meta.n + '</div>';
        sec.parentNode.insertBefore(seam, sec);

        if (hasGsap && !reduce) {
            var line = seam.querySelector('.seam__line'),
                scn = seam.querySelector('.seam__scan'),
                lbl = seam.querySelector('.seam__label');
            gsap.set(line, { scaleX: 0 });
            gsap.set(lbl, { opacity: 0, y: 6 });
            ScrollTrigger.create({
                trigger: seam, start: 'top 88%',
                onEnter: function () {
                    var tl = gsap.timeline();
                    tl.to(line, { scaleX: 1, duration: .7, ease: 'power2.out' })
                      .fromTo(scn, { left: '8%', opacity: 1 }, { left: '88%', opacity: 0, duration: .9, ease: 'power1.inOut' }, 0)
                      .to(lbl, { opacity: 1, y: 0, duration: .5, ease: 'power2.out' }, '-=0.45');
                }
            });
        } else {
            // reduced motion: show seam statically
            seam.querySelector('.seam__line').style.transform = 'scaleX(1)';
            seam.querySelector('.seam__label').style.opacity = 1;
            seam.querySelector('.seam__label').style.transform = 'none';
        }
    });

    /* ---- Headings & leads "resolve" from blur as you reach them ---- */
    if (hasGsap && !reduce) {
        document.querySelectorAll('.section h2, .section .lead').forEach(function (el) {
            gsap.fromTo(el,
                { filter: 'blur(10px)' },
                {
                    filter: 'blur(0px)', duration: 0.9, ease: 'power2.out',
                    scrollTrigger: { trigger: el, start: 'top 90%' },
                    onComplete: function () { el.style.filter = ''; }
                }
            );
        });

        /* ---- Card "assembly" — each card snaps into place with a servo tick.
           A distinct entrance per section so it reads like parts locking in,
           batched so a row of cards staggers together as it scrolls into view. */
        var assemble = function (selector, fromVars, stagger) {
            var els = gsap.utils.toArray(selector);
            if (!els.length) return;
            els.forEach(function (el) { gsap.set(el, Object.assign({ opacity: 0 }, fromVars)); });
            ScrollTrigger.batch(els, {
                start: 'top 86%', once: true,
                onEnter: function (batch) {
                    batch.forEach(function (el, i) {
                        var d = i * (stagger || 0.08);
                        gsap.to(el, {
                            opacity: 1, x: 0, y: 0, rotateX: 0, rotateY: 0, scale: 1,
                            duration: 0.7, ease: 'back.out(1.5)', delay: d,
                            onStart: function () { mechTick(); }
                        });
                        gsap.to(el, {
                            filter: 'blur(0px)', duration: 0.5, ease: 'power2.out', delay: d,
                            onComplete: function () { el.style.filter = ''; }
                        });
                    });
                }
            });
        };
        var B = 'blur(9px)';
        assemble('#how .console',   { y: 50,  scale: 0.90, rotateY: 8,   filter: B }, 0.12);
        assemble('.steps .step',    { y: 44,  scale: 0.88, rotateX: -22, filter: B }, 0.10);
        assemble('#channels .chan', { y: 44,  scale: 0.50, rotateX: -35, filter: B }, 0.07);
        assemble('#platform .cap',  { y: 56,  scale: 0.80, rotateX: 24,  filter: B }, 0.06);
        assemble('#cases .cap',     { y: 50,  scale: 0.85, rotateY: 12,  filter: B }, 0.08);
        assemble('#security .sec',  { x: -46, scale: 0.90, rotateY: -14, filter: B }, 0.09);
        assemble('#faq .faq__item', { x: -34, y: 8, filter: B }, 0.07);
    }

    /* ═════════ Ambient + scan audio (Web Audio API, synthesized) ═════════ */
    var audioOn = false, actx = null, ambient = null;

    function ctx() {
        if (actx) return actx;
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return null;
        try { actx = new AC(); } catch (e) { actx = null; }
        return actx;
    }

    function startAmbient() {
        var c = ctx(); if (!c) return;
        if (c.state === 'suspended') c.resume();
        var master = c.createGain(); master.gain.value = 0; master.connect(c.destination);
        var lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 480; lp.Q.value = 6; lp.connect(master);
        var o1 = c.createOscillator(); o1.type = 'sine';     o1.frequency.value = 55;
        var o2 = c.createOscillator(); o2.type = 'triangle'; o2.frequency.value = 82.41;
        o1.connect(lp); o2.connect(lp);
        // slow filter LFO → gentle "breathing" of the machine
        var lfo = c.createOscillator(); lfo.frequency.value = 0.06;
        var lfoGain = c.createGain(); lfoGain.gain.value = 220;
        lfo.connect(lfoGain); lfoGain.connect(lp.frequency);
        o1.start(); o2.start(); lfo.start();
        master.gain.linearRampToValueAtTime(0.05, c.currentTime + 2.5);
        ambient = { master: master, nodes: [o1, o2, lfo] };
    }
    function stopAmbient() {
        if (!ambient || !actx) return;
        var c = actx, a = ambient; ambient = null;
        a.master.gain.linearRampToValueAtTime(0, c.currentTime + 0.6);
        setTimeout(function () { a.nodes.forEach(function (n) { try { n.stop(); } catch (e) {} }); }, 800);
    }
    // Mechanical "parts snapping together" tick — a short click/clack plus a
    // fast servo whir. Pitch + direction are randomised, so a stagger of these
    // reads like a robot assembling: "chi-cho-chi-chun".
    function mechTick() {
        if (!audioOn) return;
        var c = ctx(); if (!c) return;
        if (c.state === 'suspended') c.resume();
        var t = c.currentTime;

        // (a) click/clack — a very short, fast-decaying filtered noise burst
        var n = 0.05;
        var len = Math.max(1, Math.floor(c.sampleRate * n));
        var buf = c.createBuffer(1, len, c.sampleRate);
        var data = buf.getChannelData(0);
        for (var i = 0; i < len; i++) {
            data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 2.2);
        }
        var noise = c.createBufferSource(); noise.buffer = buf;
        var bp = c.createBiquadFilter(); bp.type = 'bandpass';
        bp.frequency.value = 1700 + Math.random() * 1700; bp.Q.value = 7;
        var ng = c.createGain(); ng.gain.value = 0.10;
        noise.connect(bp); bp.connect(ng); ng.connect(c.destination);
        noise.start(t); noise.stop(t + n);

        // (b) servo whir — square wave with a quick pitch sweep (up or down)
        var o = c.createOscillator(); o.type = 'square';
        var lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 2600;
        var g = c.createGain(); g.gain.value = 0;
        o.connect(lp); lp.connect(g); g.connect(c.destination);
        var f0 = 300 + Math.random() * 520;
        var up = Math.random() < 0.5;
        o.frequency.setValueAtTime(f0, t);
        o.frequency.exponentialRampToValueAtTime(up ? f0 * 1.9 : f0 * 0.5, t + 0.08);
        g.gain.linearRampToValueAtTime(0.05, t + 0.006);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.11);
        o.start(t); o.stop(t + 0.13);
    }

    var ICON_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
    var ICON_ON  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';

    var toggle = document.createElement('button');
    toggle.className = 'sfx-toggle';
    toggle.setAttribute('aria-label', 'Toggle ambient sound');
    toggle.innerHTML = ICON_OFF + '<span class="sfx-toggle__hint">Sound on — full experience</span>';
    document.body.appendChild(toggle);

    toggle.addEventListener('click', function () {
        audioOn = !audioOn;
        if (audioOn) {
            toggle.classList.add('is-on');
            toggle.innerHTML = ICON_ON + '<span class="sfx-toggle__hint">Sound on</span>';
            startAmbient();
            mechTick();
        } else {
            toggle.classList.remove('is-on');
            toggle.innerHTML = ICON_OFF + '<span class="sfx-toggle__hint">Sound off</span>';
            stopAmbient();
        }
    });

    /* ---- IntersectionObserver: drive HUD status + scan blip per section ---- */
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (!e.isIntersecting) return;
            var sec = e.target;
            if (hudStatus) hudStatus.textContent = sec.dataset.status || 'PROCESSING';
            if (hudSub) hudSub.textContent = sec.dataset.layer || '';
            mechTick();
        });
    }, { threshold: 0.35 });
    sections.forEach(function (s) { io.observe(s); });
})();
});  /* end DOMContentLoaded — see the defer note above the CDN scripts */
</script>

</body>
</html>
