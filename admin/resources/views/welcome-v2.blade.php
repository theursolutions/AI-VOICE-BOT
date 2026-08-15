<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    {{-- SEO meta, social cards, analytics, structured data — managed in /admin/seo --}}
    @include('partials.seo-head')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg:        #04060a;
            --bg-2:      #0a0d14;
            --panel:     rgba(13, 19, 33, .65);
            --panel-hi:  rgba(20, 28, 46, .85);
            --line:      rgba(120, 180, 220, .12);
            --line-hot:  rgba(59, 130, 246, .42);
            --text:      #e6edf3;
            --text-dim:  #8b96a8;
            --text-dim2: #5a6478;
            --primary:   #3b82f6;
            --primary-2: #60a5fa;
            --hot:       #ff5e87;
            --warn:      #ffcb6b;
            --ok:        #10b981;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background: var(--bg);
            color: var(--text);
            font-family: 'Inter', system-ui, sans-serif;
            font-weight: 400;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }
        a { color: inherit; text-decoration: none; }
        ::selection { background: var(--primary); color: #fff; }
        .mono   { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        .grotesk { font-family: 'Space Grotesk', sans-serif; }

        /* ─── Persistent grid + radial background ─────────────────────── */
        body::before {
            content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background:
                radial-gradient(ellipse at 50% -10%, rgba(59, 130, 246, .18) 0%, transparent 50%),
                radial-gradient(ellipse at 0% 100%, rgba(96, 165, 250, .08) 0%, transparent 40%),
                radial-gradient(ellipse at 100% 50%, rgba(59, 130, 246, .06) 0%, transparent 40%);
        }
        body::after {
            content: ''; position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background-image:
                linear-gradient(rgba(120, 180, 220, .04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(120, 180, 220, .04) 1px, transparent 1px);
            background-size: 60px 60px;
            mask-image: radial-gradient(ellipse at 50% 30%, #000 30%, transparent 75%);
        }

        /* ─── Nav ─────────────────────────────────────────────────────── */
        .nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 50;
            padding: 14px 28px; display: flex; align-items: center; gap: 18px;
            backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
            background: rgba(4, 6, 10, .55);
            border-bottom: 1px solid var(--line);
        }
        .nav__brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 17px; letter-spacing: -0.01em; }
        .nav__brand-mark {
            width: 30px; height: 30px; border-radius: 9px;
            background: radial-gradient(circle at 30% 30%, var(--primary), #1e3a8a);
            box-shadow: 0 0 16px rgba(59,130,246,.55), inset 0 0 10px rgba(255,255,255,.18);
            display:flex; align-items:center; justify-content:center;
            font-family: 'Space Grotesk', sans-serif; font-weight: 700;
            color:#fff; font-size: 14px;
        }
        .nav__links { margin-left: auto; display: flex; gap: 22px; font-size: 13px; color: var(--text-dim); }
        .nav__links a { transition: color .15s; }
        .nav__links a:hover { color: var(--text); }
        .nav__cta {
            background: linear-gradient(135deg, var(--primary), #2563eb);
            color: #fff; padding: 9px 16px; border-radius: 10px;
            font-weight: 600; font-size: 13px;
            box-shadow: 0 8px 20px -8px rgba(59,130,246,.5);
            transition: transform .18s, box-shadow .18s;
        }
        .nav__cta:hover { transform: translateY(-1px); box-shadow: 0 12px 28px -8px rgba(59,130,246,.7); }
        @media (max-width: 720px) {
            .nav { padding: 12px 16px; }
            .nav__links { display: none; }
        }

        /* ─── Layout ──────────────────────────────────────────────────── */
        .wrap { position: relative; z-index: 2; max-width: 1240px; margin: 0 auto; padding: 0 28px; }
        @media (max-width: 540px) { .wrap { padding: 0 18px; } }

        /* ─── Hero ────────────────────────────────────────────────────── */
        .hero { position: relative; padding: 140px 0 100px; min-height: 100vh; display: flex; align-items: center; }
        .hero__inner {
            display: grid; grid-template-columns: 1.05fr .95fr;
            gap: 60px; align-items: center; width: 100%;
        }
        @media (max-width: 980px) {
            .hero { padding: 110px 0 60px; min-height: auto; }
            .hero__inner { grid-template-columns: 1fr; gap: 50px; }
        }

        .hero-eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(59, 130, 246, .08);
            border: 1px solid rgba(59, 130, 246, .25);
            border-radius: 999px; padding: 6px 14px;
            font-size: 11.5px; font-weight: 600; color: var(--primary-2);
            text-transform: uppercase; letter-spacing: .14em;
            margin-bottom: 28px;
        }
        .hero-eyebrow::before {
            content: ''; width: 6px; height: 6px; border-radius: 50%;
            background: var(--primary); box-shadow: 0 0 12px var(--primary);
            animation: pulse 1.6s infinite;
        }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.4;transform:scale(.6);} }

        .hero h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(40px, 6vw, 76px);
            font-weight: 700; letter-spacing: -0.03em; line-height: 1.02;
            margin: 0 0 22px;
        }
        .hero h1 .grad {
            background: linear-gradient(180deg, #ffffff 0%, #6b8aa5 100%);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        .hero h1 .accent {
            background: linear-gradient(135deg, var(--primary), var(--primary-2), #93c5fd);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        .hero p.sub {
            font-size: 18px; color: var(--text-dim);
            max-width: 540px; margin: 0 0 36px; line-height: 1.55;
        }

        .hero-cta { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 32px; }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), #2563eb);
            color: #fff; border: none;
            padding: 14px 24px; border-radius: 12px;
            font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit;
            box-shadow: 0 12px 30px -10px rgba(59, 130, 246, .55);
            transition: transform .18s, box-shadow .18s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 18px 40px -10px rgba(59, 130, 246, .8); }
        .btn-ghost {
            background: rgba(255, 255, 255, .04);
            color: var(--text);
            border: 1px solid var(--line);
            padding: 13px 22px; border-radius: 12px;
            font-weight: 600; font-size: 14px; cursor: pointer; font-family: inherit;
            transition: background .18s, border-color .18s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-ghost:hover { background: rgba(255, 255, 255, .07); border-color: var(--line-hot); }

        .hero-meta { display: flex; gap: 22px; font-size: 12.5px; color: var(--text-dim2); flex-wrap: wrap; }
        .hero-meta-item { display: flex; align-items: center; gap: 7px; }
        .hero-meta-item svg { width: 14px; height: 14px; color: var(--primary); }

        /* ─── Robot face (SVG cinematic) ──────────────────────────────── */
        .robot-wrap {
            position: relative;
            aspect-ratio: 1 / 1;
            display: flex; align-items: center; justify-content: center;
        }
        .robot-wrap::before {
            content: ''; position: absolute; inset: -10%;
            background: radial-gradient(circle, rgba(59, 130, 246, .28), transparent 60%);
            filter: blur(40px); z-index: 0;
        }
        .robot-svg { position: relative; z-index: 1; width: 100%; height: 100%; max-width: 520px; }
        @keyframes scan {
            0%, 100% { transform: translateY(-100px); opacity: 0; }
            10% { opacity: .7; }
            50% { transform: translateY(100px); opacity: .7; }
            90% { opacity: .2; }
        }
        @keyframes blink {
            0%, 90%, 100% { transform: scaleY(1); }
            95% { transform: scaleY(0.1); }
        }
        @keyframes orbitSlow { to { transform: rotate(360deg); } }
        @keyframes orbitFast { to { transform: rotate(-360deg); } }
        .robot-eye { transform-origin: center; animation: blink 5s infinite; }
        .robot-ring-outer { transform-origin: 50% 50%; animation: orbitSlow 28s linear infinite; }
        .robot-ring-inner { transform-origin: 50% 50%; animation: orbitFast 18s linear infinite; }
        .robot-scan-line { animation: scan 4s ease-in-out infinite; }

        /* ─── Section base ────────────────────────────────────────────── */
        .section { padding: 110px 0; position: relative; }
        @media (max-width: 720px) { .section { padding: 80px 0; } }
        .section-head { text-align: center; margin-bottom: 60px; }
        .section-eyebrow {
            display: inline-block;
            font-size: 11px; font-weight: 700; color: var(--primary-2);
            text-transform: uppercase; letter-spacing: .18em;
            margin-bottom: 16px;
        }
        .section h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(30px, 4.5vw, 52px);
            font-weight: 700; letter-spacing: -0.022em; line-height: 1.08;
            margin: 0 0 16px;
        }
        .section p.lead { font-size: 17px; color: var(--text-dim); max-width: 640px; margin: 0 auto; }

        /* ─── Feature bento ───────────────────────────────────────────── */
        .bento {
            display: grid; gap: 20px;
            grid-template-columns: repeat(6, 1fr);
            grid-auto-rows: minmax(180px, auto);
        }
        @media (max-width: 980px) { .bento { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 540px) { .bento { grid-template-columns: 1fr; } }
        .bento-card {
            position: relative; overflow: hidden;
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 18px; padding: 26px;
            backdrop-filter: blur(8px);
            transition: border-color .25s, transform .25s;
        }
        .bento-card::before {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(400px circle at var(--mx, 50%) var(--my, 50%), rgba(59, 130, 246, .14), transparent 45%);
            opacity: 0; transition: opacity .25s;
            pointer-events: none;
        }
        .bento-card:hover { border-color: var(--line-hot); transform: translateY(-3px); }
        .bento-card:hover::before { opacity: 1; }
        .bento-card.col-2 { grid-column: span 2; }
        .bento-card.col-3 { grid-column: span 3; }
        .bento-card.col-4 { grid-column: span 4; }
        .bento-card.col-6 { grid-column: span 6; }
        @media (max-width: 980px) {
            .bento-card.col-2, .bento-card.col-3, .bento-card.col-4, .bento-card.col-6 { grid-column: span 2; }
        }
        @media (max-width: 540px) {
            .bento-card.col-2, .bento-card.col-3, .bento-card.col-4, .bento-card.col-6 { grid-column: span 1; }
        }
        .bento-icon {
            width: 44px; height: 44px; border-radius: 11px;
            background: linear-gradient(135deg, rgba(59,130,246,.18), rgba(59,130,246,.05));
            border: 1px solid rgba(59, 130, 246, .25);
            color: var(--primary-2);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px;
        }
        .bento-card h3 { font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 600; margin: 0 0 8px; letter-spacing: -.01em; }
        .bento-card p  { font-size: 13.5px; color: var(--text-dim); margin: 0; line-height: 1.55; }

        /* Decorative orb element used in some bento cards */
        .bento-orb {
            position: absolute; right: -30px; bottom: -30px;
            width: 180px; height: 180px; border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(59,130,246,.25), transparent 60%);
            filter: blur(20px);
        }

        /* ─── Pricing ─────────────────────────────────────────────────── */
        .pricing { display: grid; gap: 22px; grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 900px) { .pricing { grid-template-columns: 1fr; } }
        .price-card {
            position: relative;
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 20px; padding: 32px 28px;
            backdrop-filter: blur(8px);
            transition: transform .25s, border-color .25s;
        }
        .price-card:hover { transform: translateY(-4px); border-color: var(--line-hot); }
        .price-card.is-featured {
            border-color: rgba(59, 130, 246, .55);
            background: linear-gradient(180deg, rgba(59,130,246,.06), var(--panel));
            box-shadow: 0 20px 60px -20px rgba(59, 130, 246, .45);
        }
        .price-card.is-featured::after {
            content: 'MOST POPULAR'; position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
            background: linear-gradient(135deg, var(--primary), #2563eb); color: #fff;
            font-family: 'JetBrains Mono', monospace; font-size: 10.5px; font-weight: 700;
            letter-spacing: .12em; padding: 5px 12px; border-radius: 999px;
            box-shadow: 0 8px 20px -6px rgba(59,130,246,.55);
        }
        .price-tier { font-size: 14px; color: var(--text-dim); margin-bottom: 6px; font-weight: 600; }
        .price-amount { font-family: 'Space Grotesk', sans-serif; font-size: 48px; font-weight: 700; letter-spacing: -.02em; line-height: 1; }
        .price-amount .period { font-size: 14px; color: var(--text-dim); font-weight: 500; }
        .price-desc { font-size: 13.5px; color: var(--text-dim); margin: 14px 0 22px; line-height: 1.5; }
        .price-features { list-style: none; padding: 0; margin: 0 0 28px; }
        .price-features li {
            display: flex; align-items: flex-start; gap: 10px;
            font-size: 13.5px; color: var(--text); padding: 7px 0;
        }
        .price-features li svg { color: var(--primary); flex-shrink: 0; margin-top: 2px; }
        .price-card .btn-primary, .price-card .btn-ghost { width: 100%; justify-content: center; }

        /* ─── Testimonials ────────────────────────────────────────────── */
        .testimonials { display: grid; gap: 20px; grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 900px) { .testimonials { grid-template-columns: 1fr; } }
        .testimonial-card {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 18px; padding: 28px;
            position: relative;
            transition: transform .25s, border-color .25s;
        }
        .testimonial-card:hover { transform: translateY(-3px); border-color: var(--line-hot); }
        .testimonial-card::before {
            content: '"'; position: absolute; top: 8px; left: 18px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 80px; line-height: 1; color: rgba(59,130,246,.18);
            font-weight: 800;
        }
        .testimonial-text {
            font-size: 14.5px; color: var(--text); margin: 30px 0 22px;
            line-height: 1.7; position: relative;
        }
        .testimonial-author { display: flex; align-items: center; gap: 12px; }
        .testimonial-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #2563eb);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 14px;
        }
        .testimonial-name { font-weight: 600; font-size: 14px; }
        .testimonial-role { font-size: 12px; color: var(--text-dim); margin-top: 2px; }

        /* ─── Contact / CTA ───────────────────────────────────────────── */
        .cta-card {
            position: relative;
            border: 1px solid var(--line-hot);
            background:
                linear-gradient(180deg, rgba(59,130,246,.08), rgba(4,6,10,.4)),
                radial-gradient(ellipse at top, rgba(59,130,246,.12), transparent 60%);
            border-radius: 28px;
            padding: 70px 50px;
            text-align: center;
            overflow: hidden;
        }
        @media (max-width: 720px) { .cta-card { padding: 50px 26px; } }
        .cta-card::after {
            content: ''; position: absolute; left: 50%; bottom: -100px;
            transform: translateX(-50%);
            width: 600px; height: 600px; border-radius: 50%;
            background: radial-gradient(circle, rgba(59,130,246,.16), transparent 60%);
            filter: blur(40px); z-index: -1;
        }
        .cta-card h2 { font-size: clamp(28px, 4vw, 44px); margin-bottom: 14px; }
        .cta-card p  { color: var(--text-dim); margin: 0 auto 32px; max-width: 460px; }
        .cta-form {
            display: flex; gap: 10px; max-width: 480px; margin: 0 auto;
            background: var(--panel-hi); border: 1px solid var(--line); border-radius: 14px;
            padding: 6px;
        }
        .cta-form input {
            flex: 1; background: transparent; border: none; outline: none;
            color: var(--text); font-size: 14px; font-family: inherit;
            padding: 10px 14px;
        }
        .cta-form input::placeholder { color: var(--text-dim2); }
        .cta-form button {
            background: linear-gradient(135deg, var(--primary), #2563eb);
            color: #fff; border: none; padding: 10px 18px; border-radius: 10px;
            font-weight: 700; font-size: 13.5px; cursor: pointer; font-family: inherit;
            white-space: nowrap;
        }
        .cta-msg { font-size: 12.5px; color: var(--text-dim); margin-top: 14px; min-height: 18px; }
        .cta-msg.is-ok { color: var(--ok); }

        /* ─── Footer ──────────────────────────────────────────────────── */
        footer.foot {
            padding: 70px 0 30px;
            border-top: 1px solid var(--line);
            margin-top: 100px;
            color: var(--text-dim);
            font-size: 13px;
        }
        .foot-grid {
            display: grid; gap: 40px;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            margin-bottom: 50px;
        }
        @media (max-width: 900px) { .foot-grid { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 540px) { .foot-grid { grid-template-columns: 1fr; } }
        .foot h5 { font-family: 'Space Grotesk', sans-serif; color: var(--text); font-size: 14px; margin: 0 0 14px; letter-spacing: -.01em; }
        .foot ul { list-style: none; padding: 0; margin: 0; }
        .foot ul li { padding: 5px 0; }
        .foot ul li a:hover { color: var(--primary-2); }
        .foot-bottom {
            border-top: 1px solid var(--line);
            padding-top: 22px;
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 14px;
            font-size: 12px; color: var(--text-dim2);
        }
        .foot-brand-line { display: flex; align-items: center; gap: 10px; font-weight: 700; color: var(--text); font-size: 15px; }

        /* Reveal targets default to visible — JS sets opacity:0 + y:40
           transiently when motion is available, then animates back in.
           If the CDN import fails, content still renders normally. */
        .reveal { opacity: 1; transform: none; }
        .reveal.is-pre { opacity: 0; transform: translateY(40px); }

        /* ─── Floating wireframe shapes (decoration) ──────────────────── */
        .float-shape {
            position: absolute;
            border: 1px solid rgba(59, 130, 246, .35);
            opacity: .35;
            pointer-events: none;
            animation: floatBob 14s ease-in-out infinite;
        }
        .float-shape.cube      { width: 80px; height: 80px; transform: rotate(35deg); }
        .float-shape.tri       { width: 0; height: 0; border: none;
                                 border-left: 32px solid transparent; border-right: 32px solid transparent;
                                 border-bottom: 56px solid rgba(59,130,246,.3); }
        .float-shape.diamond   { width: 60px; height: 60px; transform: rotate(45deg); border-radius: 6px; }
        .float-shape.ring      { width: 90px; height: 90px; border-radius: 50%; border-width: 2px; }
        @keyframes floatBob {
            0%,100% { transform: translateY(0) rotate(var(--r, 0deg)); }
            50%     { transform: translateY(-20px) rotate(calc(var(--r, 0deg) + 8deg)); }
        }

        /* ─── Stats counter strip ─────────────────────────────────────── */
        .stats-strip {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px;
            padding: 36px 0;
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            background: rgba(13, 19, 33, .35);
            backdrop-filter: blur(8px);
        }
        @media (max-width: 720px) { .stats-strip { grid-template-columns: repeat(2, 1fr); gap: 18px; padding: 28px 0; } }
        .stat-item { text-align: center; padding: 0 18px; border-right: 1px solid var(--line); }
        .stat-item:last-child { border-right: none; }
        @media (max-width: 720px) {
            .stat-item:nth-child(even) { border-right: none; }
            .stat-item { padding: 8px 12px; }
        }
        .stat-num {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(34px, 5vw, 54px);
            font-weight: 700; letter-spacing: -0.02em; line-height: 1;
            background: linear-gradient(180deg, #ffffff 0%, var(--primary-2) 100%);
            -webkit-background-clip: text; background-clip: text; color: transparent;
            display: block; margin-bottom: 6px;
        }
        .stat-label {
            font-size: 11.5px; color: var(--text-dim);
            text-transform: uppercase; letter-spacing: .12em; font-weight: 600;
        }

        /* ─── Integration marquee ─────────────────────────────────────── */
        .marquee-wrap {
            overflow: hidden;
            margin: 50px 0;
            mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent);
        }
        .marquee {
            display: flex; gap: 60px; padding: 0 30px;
            animation: marquee 38s linear infinite;
            width: max-content;
        }
        .marquee-row { display: flex; gap: 60px; }
        @keyframes marquee { to { transform: translateX(-50%); } }
        .marquee-item {
            display: flex; align-items: center; gap: 10px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 22px; font-weight: 600; color: var(--text-dim);
            white-space: nowrap;
            opacity: .55;
            transition: opacity .2s, color .2s;
        }
        .marquee-item:hover { opacity: 1; color: var(--text); }
        .marquee-item svg, .marquee-item .marquee-dot {
            width: 26px; height: 26px;
        }
        .marquee-dot {
            border-radius: 7px;
            background: linear-gradient(135deg, var(--primary), #2563eb);
            display: inline-block;
        }

        /* ─── How it works (steps with connector) ─────────────────────── */
        .steps-flow {
            position: relative;
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 22px;
        }
        @media (max-width: 980px) { .steps-flow { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 540px) { .steps-flow { grid-template-columns: 1fr; } }
        .steps-flow::before {
            content: ''; position: absolute; top: 40px; left: 6%; right: 6%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(59,130,246,.3) 20%, rgba(59,130,246,.45) 50%, rgba(59,130,246,.3) 80%, transparent);
        }
        @media (max-width: 980px) { .steps-flow::before { display: none; } }
        .step-node {
            position: relative; z-index: 1;
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 16px; padding: 26px 22px;
            backdrop-filter: blur(8px);
            transition: border-color .25s, transform .25s;
        }
        .step-node:hover { border-color: var(--line-hot); transform: translateY(-3px); }
        .step-badge {
            width: 36px; height: 36px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), #2563eb);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 14px;
            margin-bottom: 16px;
            box-shadow: 0 6px 18px -5px rgba(59,130,246,.55);
        }
        .step-node h4 { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 600; margin: 0 0 6px; }
        .step-node p  { font-size: 13.5px; color: var(--text-dim); margin: 0; line-height: 1.55; }

        /* ─── Dashboard mockup card ───────────────────────────────────── */
        .mock-card {
            position: relative; overflow: hidden;
            background: linear-gradient(180deg, rgba(13, 19, 33, .9), rgba(4, 6, 10, .9));
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 18px;
            transform: perspective(1200px) rotateX(8deg) rotateY(-6deg);
            transition: transform .4s;
            box-shadow: 0 30px 80px -20px rgba(59,130,246,.35);
        }
        .mock-card:hover { transform: perspective(1200px) rotateX(2deg) rotateY(-2deg) translateY(-4px); }
        .mock-bar { display: flex; gap: 6px; margin-bottom: 16px; }
        .mock-bar span { width: 10px; height: 10px; border-radius: 50%; background: var(--line); }
        .mock-bar span:nth-child(1){ background: #ff5e87; }
        .mock-bar span:nth-child(2){ background: var(--warn); }
        .mock-bar span:nth-child(3){ background: var(--ok); }
        .mock-row {
            display: grid; grid-template-columns: 24px 1fr 60px; gap: 10px; align-items: center;
            padding: 9px 0; border-bottom: 1px solid var(--line);
            font-size: 12px;
        }
        .mock-row:last-child { border-bottom: none; }
        .mock-row .dot {
            width: 22px; height: 22px; border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), #2563eb);
            color: #fff; font-size: 10px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            font-family: 'JetBrains Mono', monospace;
        }
        .mock-row .name { font-weight: 600; }
        .mock-row .sub  { font-size: 10.5px; color: var(--text-dim); margin-top: 1px; }
        .mock-row .chip { font-size: 10px; padding: 3px 8px; border-radius: 999px; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; text-align: center; }
        .mock-row .chip.is-hot { background: rgba(255,94,135,.18); color: var(--hot); }
        .mock-row .chip.is-new { background: rgba(59,130,246,.18); color: var(--primary-2); }
        .mock-row .chip.is-q   { background: rgba(16,185,129,.18); color: var(--ok); }

        /* ─── Comparison table ────────────────────────────────────────── */
        .compare-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 720px) { .compare-grid { grid-template-columns: 1fr; } }
        .compare-card {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 18px; padding: 30px;
        }
        .compare-card.is-good {
            border-color: rgba(59,130,246,.5);
            background: linear-gradient(180deg, rgba(59,130,246,.07), rgba(4,6,10,.3));
            box-shadow: 0 20px 50px -20px rgba(59,130,246,.35);
        }
        .compare-head {
            display: flex; align-items: center; gap: 12px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px; font-weight: 600;
            padding-bottom: 18px; margin-bottom: 18px;
            border-bottom: 1px solid var(--line);
        }
        .compare-head .badge {
            padding: 4px 10px; border-radius: 999px;
            font-size: 10.5px; font-family: 'JetBrains Mono', monospace; font-weight: 700;
            letter-spacing: .1em; text-transform: uppercase;
        }
        .compare-card.is-bad  .compare-head .badge { background: rgba(255,94,135,.15); color: var(--hot); }
        .compare-card.is-good .compare-head .badge { background: rgba(59,130,246,.18); color: var(--primary-2); }
        .compare-list { list-style: none; padding: 0; margin: 0; }
        .compare-list li {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 10px 0; font-size: 14px;
            border-bottom: 1px dashed var(--line);
        }
        .compare-list li:last-child { border-bottom: none; }
        .compare-list li svg { flex-shrink: 0; margin-top: 2px; }
        .compare-card.is-bad .compare-list li svg  { color: var(--hot); opacity: .8; }
        .compare-card.is-good .compare-list li svg { color: var(--ok); }

        /* ─── FAQ ─────────────────────────────────────────────────────── */
        .faq-list { display: flex; flex-direction: column; gap: 14px; max-width: 820px; margin: 0 auto; }
        .faq-item {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 14px; overflow: hidden;
            transition: border-color .25s;
        }
        .faq-item.is-open { border-color: var(--line-hot); }
        .faq-q {
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 24px; cursor: pointer; user-select: none;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 16px; font-weight: 600;
        }
        .faq-q svg { transition: transform .3s; color: var(--primary-2); flex-shrink: 0; }
        .faq-item.is-open .faq-q svg { transform: rotate(45deg); }
        .faq-a {
            max-height: 0; overflow: hidden;
            transition: max-height .4s ease, padding .3s ease;
            padding: 0 24px;
            color: var(--text-dim); font-size: 14px; line-height: 1.65;
        }
        .faq-item.is-open .faq-a { max-height: 300px; padding: 0 24px 22px; }

        /* ─── Magnetic button effect — JS adds these classes ──────────── */
        .magnetic { transition: transform .25s cubic-bezier(.16, 1, .3, 1); }

        /* ─── Advanced circular cursor (AI-themed) ─────────────────────
           Disabled on touch / coarse-pointer devices so phones keep
           their native tap targets. */
        @media (hover: hover) and (pointer: fine) {
            html, body, a, button, input, .bento-card, .price-card, .step-node,
            .testimonial-card, .compare-card, .faq-q, .nav__cta, .magnetic { cursor: none; }

            .ai-cursor {
                position: fixed; top: 0; left: 0; z-index: 9999;
                pointer-events: none; will-change: transform;
                mix-blend-mode: difference;
                transition: width .25s cubic-bezier(.2,.8,.2,1),
                            height .25s cubic-bezier(.2,.8,.2,1),
                            background .25s, border-color .25s, opacity .2s;
            }
            .ai-cursor__ring {
                width: 42px; height: 42px; border-radius: 50%;
                border: 1.5px solid rgba(255,255,255,.85);
                transform: translate(-50%, -50%);
                display: flex; align-items: center; justify-content: center;
            }
            .ai-cursor__ring::before {
                /* rotating arc accent — distinguishes the cursor as "AI" */
                content: ''; position: absolute; inset: 5px;
                border: 1.2px solid var(--primary);
                border-radius: 50%;
                clip-path: polygon(0 0, 65% 0, 65% 100%, 0 100%);
                animation: aiCursorSpin 4s linear infinite;
            }
            .ai-cursor__icon {
                width: 14px; height: 14px;
                opacity: .85;
                color: #fff;
                animation: aiCursorIconPulse 2.2s ease-in-out infinite;
            }
            .ai-cursor__dot {
                width: 5px; height: 5px; border-radius: 50%;
                background: var(--primary);
                transform: translate(-50%, -50%);
                box-shadow: 0 0 10px var(--primary);
            }
            @keyframes aiCursorSpin     { to { transform: rotate(360deg); } }
            @keyframes aiCursorIconPulse {
                0%,100% { transform: scale(1);    opacity: .85; }
                50%     { transform: scale(1.15); opacity: 1; }
            }

            /* Hover state on interactive elements */
            .ai-cursor__ring.is-hover {
                width: 78px; height: 78px;
                background: rgba(59, 130, 246, .12);
                border-color: var(--primary);
            }
            .ai-cursor__ring.is-hover .ai-cursor__icon { transform: scale(1.3); }
            .ai-cursor__ring.is-down { transform: translate(-50%, -50%) scale(.82); }
        }

        /* ─── Hero particle canvas ───────────────────────────────────── */
        #hero-particles {
            position: absolute; inset: 0; z-index: 0; pointer-events: none;
            opacity: .55;
        }
        .hero, .hero .wrap { position: relative; z-index: 1; }

        /* ─── Neural network SVG decoration ─────────────────────────── */
        .neural-bg {
            position: absolute; pointer-events: none;
            opacity: .35;
        }
        .neural-bg .node {
            animation: neuralPulse 3.5s ease-in-out infinite;
            transform-origin: center;
        }
        .neural-bg .node:nth-child(2) { animation-delay: -0.5s; }
        .neural-bg .node:nth-child(3) { animation-delay: -1s; }
        .neural-bg .node:nth-child(4) { animation-delay: -1.5s; }
        .neural-bg .node:nth-child(5) { animation-delay: -2s; }
        .neural-bg .node:nth-child(6) { animation-delay: -2.5s; }
        @keyframes neuralPulse {
            0%,100% { fill-opacity: .35; transform: scale(1); }
            50%     { fill-opacity: 1;   transform: scale(1.3); }
        }
        .neural-bg .edge { stroke-dasharray: 4 4; animation: neuralFlow 2s linear infinite; }
        @keyframes neuralFlow { to { stroke-dashoffset: -8; } }

        /* ─── Animated AI chip ────────────────────────────────────────── */
        .ai-chip {
            position: relative;
            width: 100%; aspect-ratio: 1.4;
            background: linear-gradient(135deg, rgba(13, 19, 33, .85), rgba(4, 6, 10, .9));
            border: 1px solid rgba(59,130,246,.35);
            border-radius: 14px;
            overflow: hidden;
        }
        .ai-chip::before {
            content: ''; position: absolute; inset: 0;
            background:
                linear-gradient(90deg, rgba(59,130,246,.12) 1px, transparent 1px) 0 0 / 26px 26px,
                linear-gradient(0deg,  rgba(59,130,246,.12) 1px, transparent 1px) 0 0 / 26px 26px;
            mask-image: radial-gradient(circle at center, #000 35%, transparent 75%);
        }
        .ai-chip__core {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 38%; aspect-ratio: 1; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), #1e3a8a);
            box-shadow: 0 0 30px rgba(59,130,246,.5), inset 0 0 14px rgba(255,255,255,.15);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Space Grotesk', sans-serif; font-weight: 700;
            color: #fff; font-size: 18px; letter-spacing: .04em;
            animation: aiChipBreathe 3s ease-in-out infinite;
        }
        @keyframes aiChipBreathe {
            0%,100% { box-shadow: 0 0 30px rgba(59,130,246,.5), inset 0 0 14px rgba(255,255,255,.15); }
            50%     { box-shadow: 0 0 50px rgba(59,130,246,.75), inset 0 0 22px rgba(255,255,255,.25); }
        }
        .ai-chip__pin {
            position: absolute; width: 12px; height: 3px; border-radius: 2px;
            background: linear-gradient(90deg, transparent, var(--primary));
        }
        .ai-chip__pin::after {
            content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 4px; border-radius: 50%;
            background: var(--primary-2); box-shadow: 0 0 6px var(--primary-2);
            animation: pinFlash 1.6s ease-in-out infinite;
        }
        @keyframes pinFlash { 0%,100% { opacity: .35; } 50% { opacity: 1; } }
        .ai-chip__label {
            position: absolute; top: 10px; left: 12px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 9.5px; color: var(--primary-2); opacity: .7;
            letter-spacing: .12em;
        }

        /* ─── Voice waveform decoration ──────────────────────────────── */
        .voice-wave {
            display: flex; align-items: center; gap: 3px;
            height: 32px;
        }
        .voice-wave span {
            width: 3px; border-radius: 2px;
            background: linear-gradient(180deg, var(--primary-2), var(--primary));
            animation: waveBar 1.2s ease-in-out infinite;
        }
        .voice-wave span:nth-child(1) { animation-delay: -0.1s; }
        .voice-wave span:nth-child(2) { animation-delay: -0.3s; }
        .voice-wave span:nth-child(3) { animation-delay: -0.5s; }
        .voice-wave span:nth-child(4) { animation-delay: -0.7s; }
        .voice-wave span:nth-child(5) { animation-delay: -0.9s; }
        .voice-wave span:nth-child(6) { animation-delay: -0.4s; }
        .voice-wave span:nth-child(7) { animation-delay: -0.6s; }
        .voice-wave span:nth-child(8) { animation-delay: -0.8s; }
        @keyframes waveBar {
            0%,100% { height: 6px;  opacity: .5; }
            50%     { height: 28px; opacity: 1; }
        }

        /* ─── Bento layer parallax (icon floats forward) ─────────────── */
        .bento-card { transform-style: preserve-3d; }
        .bento-card .bento-icon { transform: translateZ(40px); transition: transform .35s; }
        .bento-card h3           { transform: translateZ(20px); transition: transform .35s; }
        .bento-card:hover .bento-icon { transform: translateZ(60px) scale(1.05); }
        .bento-card:hover h3          { transform: translateZ(30px); }
    </style>
</head>
<body>

<!-- ── Custom AI cursor (auto-hides on touch) ─────────────────────── -->
<div class="ai-cursor" id="aiCursorDot"  aria-hidden="true">
    <div class="ai-cursor__dot"></div>
</div>
<div class="ai-cursor" id="aiCursorRing" aria-hidden="true">
    <div class="ai-cursor__ring">
        <svg class="ai-cursor__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <!-- Stylised AI / neural-net icon -->
            <circle cx="12" cy="12" r="3" fill="currentColor"/>
            <circle cx="5"  cy="6"  r="1.5"/>
            <circle cx="19" cy="6"  r="1.5"/>
            <circle cx="5"  cy="18" r="1.5"/>
            <circle cx="19" cy="18" r="1.5"/>
            <line x1="5"  y1="6"  x2="12" y2="12"/>
            <line x1="19" y1="6"  x2="12" y2="12"/>
            <line x1="5"  y1="18" x2="12" y2="12"/>
            <line x1="19" y1="18" x2="12" y2="12"/>
        </svg>
    </div>
</div>

<!-- ── NAV ────────────────────────────────────────────────────────── -->
<nav class="nav">
    <div class="nav__brand">
        <div class="nav__brand-mark">N</div>
        <span class="grotesk">Serve AI</span>
    </div>
    <div class="nav__links">
        <a href="#features">Features</a>
        <a href="#pricing">Pricing</a>
        <a href="#testimonials">Reviews</a>
        <a href="#contact">Contact</a>
        @guest
            <a href="{{ route('login') }}">Sign in</a>
        @endguest
    </div>
    {{-- Signed in → back into the app (/dashboard resolves the active
         workspace, or the picker); signed out → sign up. --}}
    @auth
        <a href="{{ url('/dashboard') }}" class="nav__cta">Dashboard</a>
    @else
        <a href="{{ url('/register') }}" class="nav__cta">Get started</a>
    @endauth
</nav>

<!-- ── HERO ───────────────────────────────────────────────────────── -->
<section class="hero">
    <!-- Particle starfield behind the hero (canvas) -->
    <canvas id="hero-particles" aria-hidden="true"></canvas>

    <!-- Neural-network SVG decoration (right side of hero) -->
    <svg class="neural-bg" style="top: 60%; right: -10%; width: 320px; height: 320px; transform: rotate(15deg);" viewBox="0 0 200 200" aria-hidden="true">
        <g stroke="rgba(59,130,246,.4)" stroke-width="0.6" fill="none">
            <line class="edge" x1="40"  y1="50"  x2="100" y2="100"/>
            <line class="edge" x1="40"  y1="150" x2="100" y2="100"/>
            <line class="edge" x1="160" y1="50"  x2="100" y2="100"/>
            <line class="edge" x1="160" y1="150" x2="100" y2="100"/>
            <line class="edge" x1="40"  y1="50"  x2="40"  y2="150"/>
            <line class="edge" x1="160" y1="50"  x2="160" y2="150"/>
        </g>
        <g fill="var(--primary)">
            <circle class="node" cx="40"  cy="50"  r="4"/>
            <circle class="node" cx="40"  cy="150" r="4"/>
            <circle class="node" cx="160" cy="50"  r="4"/>
            <circle class="node" cx="160" cy="150" r="4"/>
            <circle class="node" cx="100" cy="100" r="6" fill="var(--primary-2)"/>
        </g>
    </svg>

    <!-- Floating decorative 3D wireframe shapes scattered behind the hero -->
    <div class="float-shape cube"    style="top: 12%; left: 6%; --r: 35deg;" aria-hidden="true"></div>
    <div class="float-shape diamond" style="top: 28%; right: 8%; --r: 45deg; animation-delay: -3s;" aria-hidden="true"></div>
    <div class="float-shape ring"    style="bottom: 18%; left: 9%; animation-delay: -6s;" aria-hidden="true"></div>
    <div class="float-shape tri"     style="bottom: 30%; right: 5%; opacity:.4; animation-delay: -9s;" aria-hidden="true"></div>
    <div class="wrap hero__inner">
        <div class="reveal">
            <div class="hero-eyebrow">AI Receptionist · 24/7</div>
            <h1>
                <span class="grad">Your AI agent.</span><br>
                <span class="accent">Never. Sleeps.</span>
            </h1>
            <p class="sub">
                Serve AI answers calls + chats around the clock in your own cloned voice,
                qualifies leads on the spot, and drops them straight into your CRM. Built
                for businesses that miss too many calls.
            </p>
            <div class="hero-cta">
                <a href="{{ url('/register') }}" class="btn-primary">
                    Start free
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <a href="#features" class="btn-ghost">
                    See how it works
                </a>
            </div>
            <div class="hero-meta">
                <div class="hero-meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    No credit card
                </div>
                <div class="hero-meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Your data, your DB
                </div>
                <div class="hero-meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Set up in 90 seconds
                </div>
            </div>
        </div>

        <!-- Robot face SVG -->
        <div class="robot-wrap reveal" data-delay="0.15">
            <svg class="robot-svg" viewBox="0 0 500 500" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <radialGradient id="faceGrad" cx="50%" cy="40%">
                        <stop offset="0%"  stop-color="#1e3a8a" stop-opacity="0.95"/>
                        <stop offset="100%" stop-color="#04060a" stop-opacity="1"/>
                    </radialGradient>
                    <linearGradient id="eyeGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#93c5fd"/>
                        <stop offset="100%" stop-color="#3b82f6"/>
                    </linearGradient>
                    <filter id="blueGlow" x="-50%" y="-50%" width="200%" height="200%">
                        <feGaussianBlur stdDeviation="4"/>
                    </filter>
                    <pattern id="circuitPattern" x="0" y="0" width="40" height="40" patternUnits="userSpaceOnUse">
                        <path d="M 0 20 L 40 20 M 20 0 L 20 40" stroke="#3b82f6" stroke-width="0.4" opacity="0.3"/>
                        <circle cx="20" cy="20" r="1.2" fill="#60a5fa"/>
                    </pattern>
                </defs>

                <!-- Orbit rings (animated) -->
                <g class="robot-ring-outer">
                    <circle cx="250" cy="250" r="230" fill="none" stroke="rgba(59,130,246,.18)" stroke-width="1" stroke-dasharray="2 6"/>
                    <circle cx="250" cy="20"  r="4"  fill="#60a5fa" filter="url(#blueGlow)"/>
                    <circle cx="480" cy="250" r="2" fill="#3b82f6"/>
                </g>
                <g class="robot-ring-inner">
                    <circle cx="250" cy="250" r="200" fill="none" stroke="rgba(59,130,246,.28)" stroke-width="1.2"/>
                    <circle cx="50"  cy="250" r="3" fill="#93c5fd" filter="url(#blueGlow)"/>
                    <circle cx="250" cy="450" r="3" fill="#60a5fa"/>
                </g>

                <!-- Face plate -->
                <circle cx="250" cy="250" r="160" fill="url(#faceGrad)" stroke="rgba(59,130,246,.45)" stroke-width="1.5"/>
                <!-- Circuit overlay -->
                <circle cx="250" cy="250" r="160" fill="url(#circuitPattern)" opacity="0.45"/>

                <!-- Forehead status bar -->
                <rect x="195" y="155" width="110" height="6" rx="3" fill="rgba(59,130,246,.18)"/>
                <rect x="195" y="155" width="78"  height="6" rx="3" fill="#3b82f6">
                    <animate attributeName="width" values="0;110;78" dur="3s" repeatCount="indefinite"/>
                </rect>

                <!-- Eyes -->
                <g class="robot-eye">
                    <ellipse cx="208" cy="240" rx="22" ry="30" fill="url(#eyeGrad)" filter="url(#blueGlow)"/>
                    <ellipse cx="208" cy="240" rx="12" ry="18" fill="#1e3a8a"/>
                    <circle  cx="206" cy="232" r="3" fill="#dbeafe"/>
                </g>
                <g class="robot-eye" style="animation-delay: .15s;">
                    <ellipse cx="292" cy="240" rx="22" ry="30" fill="url(#eyeGrad)" filter="url(#blueGlow)"/>
                    <ellipse cx="292" cy="240" rx="12" ry="18" fill="#1e3a8a"/>
                    <circle  cx="290" cy="232" r="3" fill="#dbeafe"/>
                </g>

                <!-- Cheek vents -->
                <g opacity="0.7">
                    <rect x="145" y="270" width="12" height="2" rx="1" fill="#3b82f6"/>
                    <rect x="145" y="278" width="12" height="2" rx="1" fill="#3b82f6"/>
                    <rect x="145" y="286" width="12" height="2" rx="1" fill="#3b82f6"/>
                    <rect x="343" y="270" width="12" height="2" rx="1" fill="#3b82f6"/>
                    <rect x="343" y="278" width="12" height="2" rx="1" fill="#3b82f6"/>
                    <rect x="343" y="286" width="12" height="2" rx="1" fill="#3b82f6"/>
                </g>

                <!-- Audio "mouth" speaker grid -->
                <g transform="translate(220 320)">
                    <rect x="0" y="0" width="60" height="22" rx="11" fill="rgba(59,130,246,.12)" stroke="rgba(59,130,246,.4)" stroke-width="1"/>
                    <g fill="#60a5fa">
                        <rect x="8"  y="6"  width="3" height="10" rx="1.5">
                            <animate attributeName="height" values="10;4;14;6;10" dur="1.2s" repeatCount="indefinite"/>
                            <animate attributeName="y" values="6;9;3;8;6" dur="1.2s" repeatCount="indefinite"/>
                        </rect>
                        <rect x="16" y="4"  width="3" height="14" rx="1.5">
                            <animate attributeName="height" values="14;6;10;4;14" dur="1.2s" repeatCount="indefinite"/>
                            <animate attributeName="y" values="4;8;6;9;4" dur="1.2s" repeatCount="indefinite"/>
                        </rect>
                        <rect x="24" y="2"  width="3" height="18" rx="1.5">
                            <animate attributeName="height" values="18;8;12;6;18" dur="1.2s" repeatCount="indefinite"/>
                            <animate attributeName="y" values="2;7;5;8;2" dur="1.2s" repeatCount="indefinite"/>
                        </rect>
                        <rect x="32" y="4"  width="3" height="14" rx="1.5">
                            <animate attributeName="height" values="14;4;16;6;14" dur="1.2s" repeatCount="indefinite"/>
                            <animate attributeName="y" values="4;9;3;8;4" dur="1.2s" repeatCount="indefinite"/>
                        </rect>
                        <rect x="40" y="6"  width="3" height="10" rx="1.5">
                            <animate attributeName="height" values="10;6;12;4;10" dur="1.2s" repeatCount="indefinite"/>
                            <animate attributeName="y" values="6;8;5;9;6" dur="1.2s" repeatCount="indefinite"/>
                        </rect>
                        <rect x="48" y="8"  width="3" height="6" rx="1.5">
                            <animate attributeName="height" values="6;3;8;4;6" dur="1.2s" repeatCount="indefinite"/>
                            <animate attributeName="y" values="8;10;7;9;8" dur="1.2s" repeatCount="indefinite"/>
                        </rect>
                    </g>
                </g>

                <!-- Sweep scan line -->
                <line class="robot-scan-line" x1="100" y1="0" x2="400" y2="0" stroke="#60a5fa" stroke-width="1.5" opacity="0.5"/>

                <!-- Side antennas -->
                <g stroke="rgba(59,130,246,.7)" stroke-width="2" fill="none">
                    <path d="M 120 130 L 140 110"/>
                    <circle cx="118" cy="132" r="4" fill="#60a5fa"/>
                    <path d="M 380 130 L 360 110"/>
                    <circle cx="382" cy="132" r="4" fill="#60a5fa"/>
                </g>

                <!-- Bottom data ticker -->
                <text x="250" y="400" text-anchor="middle"
                      font-family="JetBrains Mono, monospace" font-size="11"
                      fill="#60a5fa" opacity="0.7" letter-spacing="3">
                    SYSTEM_READY · LISTENING
                </text>
            </svg>
        </div>
    </div>
</section>

<!-- ── STATS STRIP ────────────────────────────────────────────────── -->
<div class="wrap reveal">
    <div class="stats-strip">
        <div class="stat-item">
            <span class="stat-num counter" data-target="800" data-suffix="ms">0</span>
            <div class="stat-label">First-byte response</div>
        </div>
        <div class="stat-item">
            <span class="stat-num counter" data-target="99.9" data-decimals="1" data-suffix="%">0</span>
            <div class="stat-label">Call delivery</div>
        </div>
        <div class="stat-item">
            <span class="stat-num counter" data-target="30" data-suffix="+">0</span>
            <div class="stat-label">Voices · 13 languages</div>
        </div>
        <div class="stat-item">
            <span class="stat-num counter" data-target="24" data-suffix="/7">0</span>
            <div class="stat-label">Never-sleep coverage</div>
        </div>
    </div>
</div>

<!-- ── INTEGRATIONS MARQUEE ───────────────────────────────────────── -->
<div class="wrap reveal" style="text-align:center;">
    <div class="section-eyebrow" style="margin-top: 60px;">Plays well with</div>
    <h2 style="font-family: 'Space Grotesk', sans-serif; font-size: clamp(22px, 3vw, 30px); font-weight: 600; margin: 6px 0 0;">
        Built on the best of the AI stack
    </h2>
</div>
<div class="marquee-wrap">
    <div class="marquee">
        <!-- duplicated for seamless loop -->
        <div class="marquee-row">
            <div class="marquee-item"><span class="marquee-dot"></span> Twilio</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #10b981, #047857);"></span> Groq</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #d97706, #b45309);"></span> Anthropic</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #ef4444, #dc2626);"></span> Gemini</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #6366f1, #4f46e5);"></span> Ollama</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"></span> Coqui XTTS</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #06b6d4, #0891b2);"></span> faster-whisper</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #f59e0b, #d97706);"></span> Qdrant</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #ec4899, #db2777);"></span> Stripe</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #14b8a6, #0d9488);"></span> Laravel</div>
        </div>
        <div class="marquee-row" aria-hidden="true">
            <div class="marquee-item"><span class="marquee-dot"></span> Twilio</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #10b981, #047857);"></span> Groq</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #d97706, #b45309);"></span> Anthropic</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #ef4444, #dc2626);"></span> Gemini</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #6366f1, #4f46e5);"></span> Ollama</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);"></span> Coqui XTTS</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #06b6d4, #0891b2);"></span> faster-whisper</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #f59e0b, #d97706);"></span> Qdrant</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #ec4899, #db2777);"></span> Stripe</div>
            <div class="marquee-item"><span class="marquee-dot" style="background: linear-gradient(135deg, #14b8a6, #0d9488);"></span> Laravel</div>
        </div>
    </div>
</div>

<!-- ── FEATURES (BENTO) ───────────────────────────────────────────── -->
<section class="section" id="features">
    <div class="wrap">
        <div class="section-head reveal">
            <div class="section-eyebrow">Capabilities</div>
            <h2>One agent. <span class="mono" style="color: var(--primary-2);">Every channel.</span></h2>
            <p class="lead">Voice calls, web chat, SMS — all answered by the same AI persona pulling from your data sources.</p>
        </div>

        <div class="bento">
            <div class="bento-card col-3 reveal" data-tilt>
                <div class="bento-orb"></div>
                <div class="bento-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </div>
                <h3>Phone calls with cloned voice</h3>
                <p>Twilio Media Streams + faster-whisper STT + Coqui XTTS. Sub-second latency. Sounds like you.</p>
                <!-- Animated voice waveform decoration -->
                <div class="voice-wave" style="margin-top: 18px;" aria-hidden="true">
                    <span></span><span></span><span></span><span></span>
                    <span></span><span></span><span></span><span></span>
                </div>
            </div>
            <div class="bento-card col-3 reveal" data-tilt>
                <div class="bento-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <h3>Drop-in web widget</h3>
                <p>One <code>&lt;script&gt;</code> tag on your site. Theming, voice replies, CORS allowlist all configurable per project.</p>
            </div>
            <div class="bento-card col-2 reveal" data-tilt>
                <div class="bento-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </div>
                <h3>Multi-source knowledge</h3>
                <p>Crawl your site, upload docs, query your DB live — RAG over everything.</p>
            </div>
            <div class="bento-card col-2 reveal" data-tilt>
                <div class="bento-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <h3>Multi-agent skills</h3>
                <p>Different personas for sales, support, billing — each with its own voice + skill pool.</p>
            </div>
            <div class="bento-card col-2 reveal" data-tilt>
                <div class="bento-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 7 12 15 4 7"/><polyline points="20 17 12 9 4 17"/></svg>
                </div>
                <h3>Swap brains in one click</h3>
                <p>Groq, Anthropic, Gemini or local Ollama. CPU or GPU. No redeploy needed.</p>
                <!-- AI chip illustration -->
                <div class="ai-chip" style="margin-top: 18px;" aria-hidden="true">
                    <div class="ai-chip__label">NEURO-CORE</div>
                    <div class="ai-chip__core">AI</div>
                    <div class="ai-chip__pin" style="top: 28%;  left: -6px;"></div>
                    <div class="ai-chip__pin" style="top: 50%;  left: -6px;"></div>
                    <div class="ai-chip__pin" style="top: 72%;  left: -6px;"></div>
                    <div class="ai-chip__pin" style="top: 28%;  right: -6px; transform: scaleX(-1);"></div>
                    <div class="ai-chip__pin" style="top: 50%;  right: -6px; transform: scaleX(-1);"></div>
                    <div class="ai-chip__pin" style="top: 72%;  right: -6px; transform: scaleX(-1);"></div>
                </div>
            </div>
            <div class="bento-card col-6 reveal" data-tilt>
                <div class="bento-orb" style="right:-50px; bottom: auto; top: -60px; width: 260px; height: 260px;"></div>
                <div class="bento-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h7v7H3z"/><path d="M14 3h7v7h-7z"/><path d="M14 14h7v7h-7z"/><path d="M3 14h7v7H3z"/></svg>
                </div>
                <h3>Multi-tenant by design</h3>
                <p>Every customer gets their own provisioned database. No shared tables, no co-mingled data. Your customers can audit it themselves.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── HOW IT WORKS ───────────────────────────────────────────────── -->
<section class="section" id="how">
    <div class="wrap">
        <div class="section-head reveal">
            <div class="section-eyebrow">Launch sequence</div>
            <h2>From signup to <span style="color: var(--primary-2);">first call</span> in 90 seconds.</h2>
            <p class="lead">Four steps. No code. No engineers. Done before your coffee cools.</p>
        </div>

        <div class="steps-flow">
            <div class="step-node reveal" data-tilt>
                <div class="step-badge">01</div>
                <h4>Drop your data</h4>
                <p>Paste a URL, upload a CSV, or connect your DB. Indexed in 30 seconds.</p>
            </div>
            <div class="step-node reveal" data-tilt>
                <div class="step-badge">02</div>
                <h4>Pick a voice</h4>
                <p>Choose from 30+ built-ins, or clone yours from a 10-second sample.</p>
            </div>
            <div class="step-node reveal" data-tilt>
                <div class="step-badge">03</div>
                <h4>Connect a number</h4>
                <p>Bring a Twilio number or get one of ours. Route per skill or agent.</p>
            </div>
            <div class="step-node reveal" data-tilt>
                <div class="step-badge">04</div>
                <h4>Go live</h4>
                <p>Embed the chat widget. Watch your dashboard fill with qualified leads.</p>
            </div>
        </div>

        <!-- Live-feel dashboard mockup teaser -->
        <div style="display: grid; grid-template-columns: 1.1fr 1fr; gap: 50px; align-items: center; margin-top: 90px;">
            <div class="reveal">
                <div class="section-eyebrow">Live dashboard</div>
                <h2 style="margin-top: 6px;">Every lead. <span style="color: var(--primary-2);">Real-time.</span></h2>
                <p class="lead" style="margin: 14px 0 24px 0;">
                    The instant a caller hangs up, their conversation lands in your CRM with name, email, intent, and a confidence score — extracted by the LLM. Zero data entry.
                </p>
                <a href="{{ url('/register') }}" class="btn-primary magnetic">
                    See your first lead
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
            </div>

            <div class="mock-card reveal">
                <div class="mock-bar">
                    <span></span><span></span><span></span>
                    <span style="margin-left: auto; background: none; color: var(--text-dim); font-family: ui-monospace, monospace; font-size: 10px; width:auto; height:auto;">/c/acme-corp/leads</span>
                </div>
                <div class="mock-row">
                    <div class="dot">SC</div>
                    <div>
                        <div class="name">Sarah Chen</div>
                        <div class="sub">Demo for 8-seat team · sarah@acme.io</div>
                    </div>
                    <div class="chip is-q">qualified</div>
                </div>
                <div class="mock-row">
                    <div class="dot">MR</div>
                    <div>
                        <div class="name">Mike Rodriguez</div>
                        <div class="sub">Pricing for enterprise · +1 415-555-0182</div>
                    </div>
                    <div class="chip is-hot">hot</div>
                </div>
                <div class="mock-row">
                    <div class="dot">JL</div>
                    <div>
                        <div class="name">Jamie Lee</div>
                        <div class="sub">Booking dental cleaning · 555-0144</div>
                    </div>
                    <div class="chip is-new">new</div>
                </div>
                <div class="mock-row">
                    <div class="dot">AP</div>
                    <div>
                        <div class="name">Alex Park</div>
                        <div class="sub">Integration question · alex@buildco.io</div>
                    </div>
                    <div class="chip is-q">qualified</div>
                </div>
                <div class="mock-row">
                    <div class="dot">RG</div>
                    <div>
                        <div class="name">Raj Gupta</div>
                        <div class="sub">After-hours support · +44 7700 900123</div>
                    </div>
                    <div class="chip is-hot">hot</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── PRICING ────────────────────────────────────────────────────── -->
<section class="section" id="pricing">
    <div class="wrap">
        <div class="section-head reveal">
            <div class="section-eyebrow">Pricing</div>
            <h2>Pick a plan. <span style="color: var(--primary-2);">Cancel anytime.</span></h2>
            <p class="lead">Start free. Upgrade when you need more calls or voices.</p>
        </div>

        <div class="pricing">
            <div class="price-card reveal" data-tilt>
                <div class="price-tier">Starter</div>
                <div class="price-amount">$0<span class="period">/mo</span></div>
                <p class="price-desc">For trying things out. Built-in voice, 100 calls/mo.</p>
                <ul class="price-features">
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> 100 calls / month</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> 1 project, 1 agent</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Built-in TTS voices</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Webchat widget</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Email support</li>
                </ul>
                <a href="{{ url('/register') }}" class="btn-ghost">Get started</a>
            </div>

            <div class="price-card is-featured reveal" data-tilt>
                <div class="price-tier">Pro</div>
                <div class="price-amount">$49<span class="period">/mo</span></div>
                <p class="price-desc">For teams running real workloads. Voice cloning + custom agents.</p>
                <ul class="price-features">
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> 2,000 calls / month</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Unlimited projects + agents</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Voice cloning (XTTS)</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Phone numbers + SMS</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Priority support</li>
                </ul>
                <a href="{{ url('/register') }}" class="btn-primary">Start 14-day trial</a>
            </div>

            <div class="price-card reveal" data-tilt>
                <div class="price-tier">Enterprise</div>
                <div class="price-amount">Custom</div>
                <p class="price-desc">Dedicated infrastructure, SSO, white-label, SLA.</p>
                <ul class="price-features">
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Unlimited everything</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> On-prem / VPC deploy</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> SSO + SOC 2</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Dedicated GPU pool</li>
                    <li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> 24/7 support + SLA</li>
                </ul>
                <a href="#contact" class="btn-ghost">Talk to sales</a>
            </div>
        </div>
    </div>
</section>

<!-- ── COMPARISON ─────────────────────────────────────────────────── -->
<section class="section">
    <div class="wrap">
        <div class="section-head reveal">
            <div class="section-eyebrow">Why teams switch</div>
            <h2>The old way vs <span style="color: var(--primary-2);">the Serve AI way</span></h2>
            <p class="lead">$3K/mo call centers, missed evening leads, and inconsistent scripts — vs an AI that never sleeps.</p>
        </div>

        <div class="compare-grid">
            <div class="compare-card is-bad reveal">
                <div class="compare-head">
                    Old way
                    <span class="badge" style="margin-left:auto;">Call center / answering machine</span>
                </div>
                <ul class="compare-list">
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> $2,000–$5,000 / month per agent</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Limited business-hours coverage</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Scripts drift, tone varies by agent</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Leads typed up later (or lost)</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Training a new hire takes weeks</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Caller's data sits with the vendor</li>
                </ul>
            </div>
            <div class="compare-card is-good reveal">
                <div class="compare-head">
                    Serve AI
                    <span class="badge" style="margin-left:auto;">AI receptionist + CRM</span>
                </div>
                <ul class="compare-list">
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> $49 / month for 2,000 calls</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> 24/7. Never sick. Never on lunch</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> One persona, version-controlled</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Leads extracted live, in your CRM</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Onboard a new "agent" in 60 seconds</li>
                    <li><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Multi-tenant: your DB, your audit log</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ── TESTIMONIALS ───────────────────────────────────────────────── -->
<section class="section" id="testimonials">
    <div class="wrap">
        <div class="section-head reveal">
            <div class="section-eyebrow">Loved by teams</div>
            <h2>From the field.</h2>
            <p class="lead">Customers using Serve AI to never miss a lead.</p>
        </div>

        <div class="testimonials">
            <div class="testimonial-card reveal" data-tilt>
                <p class="testimonial-text">Set it up in 5 minutes. The next morning my dashboard had 14 new leads from calls that came in after hours. We used to lose all of those.</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">SC</div>
                    <div>
                        <div class="testimonial-name">Sarah Chen</div>
                        <div class="testimonial-role">Owner · Smile Dental</div>
                    </div>
                </div>
            </div>
            <div class="testimonial-card reveal" data-tilt>
                <p class="testimonial-text">My cloned voice is honestly better than my real voice on calls — I never have a bad day. Patients can't tell.</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar" style="background: linear-gradient(135deg, #8b5cf6, #6d28d9);">JR</div>
                    <div>
                        <div class="testimonial-name">Dr. Jamie Reyes</div>
                        <div class="testimonial-role">MD · Pinewood Family Clinic</div>
                    </div>
                </div>
            </div>
            <div class="testimonial-card reveal" data-tilt>
                <p class="testimonial-text">We replaced a $3K/mo call center with Serve AI Pro. Three months in, no regrets. Conversion rate on after-hours leads actually went up.</p>
                <div class="testimonial-author">
                    <div class="testimonial-avatar" style="background: linear-gradient(135deg, #10b981, #047857);">MP</div>
                    <div>
                        <div class="testimonial-name">Marcus Patel</div>
                        <div class="testimonial-role">Founder · Driveway HVAC</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ── FAQ ────────────────────────────────────────────────────────── -->
<section class="section" id="faq">
    <div class="wrap">
        <div class="section-head reveal">
            <div class="section-eyebrow">Questions</div>
            <h2>Frequently asked.</h2>
            <p class="lead">Everything teams want to know before flipping the switch.</p>
        </div>

        <div class="faq-list">
            <div class="faq-item reveal">
                <div class="faq-q">
                    Does it really sound like me?
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div class="faq-a">Yes. We use Coqui XTTS for voice cloning — a 10-second sample of your voice produces a clone good enough that callers can't reliably tell. We'll show you a sample before going live so you can confirm.</div>
            </div>
            <div class="faq-item reveal">
                <div class="faq-q">
                    Where does my data live?
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div class="faq-a">Every customer gets a provisioned database of their own — no shared tables, no co-mingled data. On Enterprise we can deploy inside your VPC so it never leaves your perimeter.</div>
            </div>
            <div class="faq-item reveal">
                <div class="faq-q">
                    What happens if the AI doesn't know an answer?
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div class="faq-a">It says so. We tune every agent to refuse plausibly-wrong answers and instead capture the question + caller's contact, then page a human via Slack / SMS / email. Better an honest hand-off than a hallucinated booking.</div>
            </div>
            <div class="faq-item reveal">
                <div class="faq-q">
                    How long does setup actually take?
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div class="faq-a">90 seconds for the bot to answer your first call from our test number, ~1 day to port a real Twilio number and add your knowledge sources. Most customers are live by the next morning.</div>
            </div>
            <div class="faq-item reveal">
                <div class="faq-q">
                    Can I cancel anytime?
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div class="faq-a">Yes. No annual contracts on Starter or Pro. One-click cancel from inside the dashboard, your data exports as CSV first.</div>
            </div>
            <div class="faq-item reveal">
                <div class="faq-q">
                    What models can I use?
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div class="faq-a">Groq (fastest), Anthropic (Claude — most accurate), Gemini, or local Ollama (free, runs on your GPU). One-click switch in Brain settings. Pick any per project.</div>
            </div>
        </div>
    </div>
</section>

<!-- ── CONTACT / CTA ──────────────────────────────────────────────── -->
<section class="section" id="contact">
    <div class="wrap">
        <div class="cta-card reveal">
            <h2 class="grotesk">Talk to Serve AI today.</h2>
            <p>Drop your email — we'll send you a working demo number + the embed snippet within 60 seconds.</p>
            <form class="cta-form" id="ctaForm" onsubmit="return false;">
                <input type="email" name="email" placeholder="you@company.com" required>
                <button type="submit">Get my demo</button>
            </form>
            <div class="cta-msg" id="ctaMsg">No card. No spam. Cancel anytime.</div>
        </div>
    </div>
</section>

<!-- ── FOOTER ─────────────────────────────────────────────────────── -->
<footer class="foot">
    <div class="wrap">
        <div class="foot-grid">
            <div>
                <div class="foot-brand-line">
                    <div class="nav__brand-mark">N</div>
                    Serve AI
                </div>
                <p style="margin-top: 12px; max-width: 280px; color: var(--text-dim);">
                    AI receptionist + CRM for businesses that don't want to miss another lead.
                </p>
            </div>
            <div>
                <h5>Product</h5>
                <ul>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#pricing">Pricing</a></li>
                    @auth
                        <li><a href="{{ url('/dashboard') }}">Dashboard</a></li>
                    @else
                        <li><a href="{{ route('login') }}">Sign in</a></li>
                        <li><a href="{{ url('/register') }}">Get started</a></li>
                    @endauth
                </ul>
            </div>
            <div>
                <h5>Company</h5>
                <ul>
                    <li><a href="#">About</a></li>
                    <li><a href="#contact">Contact</a></li>
                    <li><a href="#">Careers</a></li>
                    <li><a href="#">Blog</a></li>
                </ul>
            </div>
            <div>
                <h5>Legal</h5>
                <ul>
                    <li><a href="#">Terms</a></li>
                    <li><a href="#">Privacy</a></li>
                    <li><a href="#">Security</a></li>
                    <li><a href="#">SOC 2</a></li>
                </ul>
            </div>
        </div>
        <div class="foot-bottom">
            <div>&copy; {{ date('Y') }} Serve AI. All rights reserved.</div>
            <div class="mono">Made with motion + ❤️</div>
        </div>
    </div>
</footer>

<!-- ── Motion library (vanilla, no build step) ────────────────────── -->
<script type="module">
    // esm.sh resolves motion's internal imports more reliably than
    // jsdelivr's /+esm endpoint (where some sub-paths 404). If THIS
    // import fails the content still renders because .reveal defaults
    // to visible.
    let animate, scroll, inView;
    try {
        ({ animate, scroll, inView } = await import('https://esm.sh/motion@11'));
    } catch (err) {
        console.warn('[v2] motion failed to load — falling back to no-animation mode', err);
    }

    // If motion is unavailable bail early — content already renders.
    if (!animate) {
        // Nothing more to do; the page is already usable.
    } else {
        // Pre-hide reveal targets only after motion is confirmed
        // available, so the JS-disabled fallback stays visible.
        document.querySelectorAll('.reveal').forEach(el => el.classList.add('is-pre'));

        // Reveal-on-view for every .reveal block
        document.querySelectorAll('.reveal').forEach((el) => {
            const delay = parseFloat(el.dataset.delay || '0');
            inView(el, () => {
                animate(el,
                    { opacity: [0, 1], y: [40, 0] },
                    { duration: 0.9, delay, easing: [0.16, 1, 0.3, 1] }
                );
                el.classList.remove('is-pre');
                return () => {};
            }, { amount: 0.15 });
        });

        // Scroll-linked subtle parallax on hero robot
        const robot = document.querySelector('.robot-svg');
        if (robot) {
            scroll(animate(robot, { y: [0, -80], rotate: [0, 4] }),
                   { target: robot, offset: ['start center', 'end start'] });
        }
    } // end if (animate)

    // -------------- below works with or without motion ----------------

    // Mouse-tilt on cards marked data-tilt (pure CSS transform, no motion)
    document.querySelectorAll('[data-tilt]').forEach((el) => {
        el.addEventListener('mousemove', (e) => {
            const r = el.getBoundingClientRect();
            const px = (e.clientX - r.left) / r.width;
            const py = (e.clientY - r.top)  / r.height;
            const rotY = (px - 0.5) *  8;
            const rotX = (py - 0.5) * -8;
            el.style.setProperty('--mx', `${e.clientX - r.left}px`);
            el.style.setProperty('--my', `${e.clientY - r.top}px`);
            el.style.transform = `perspective(900px) rotateX(${rotX}deg) rotateY(${rotY}deg) translateZ(0)`;
        });
        el.addEventListener('mouseleave', () => {
            el.style.transform = 'perspective(900px) rotateX(0) rotateY(0)';
        });
    });

    // ────────────────── Count-up stat numbers on view ───────────────
    // Animates from 0 to data-target. Supports decimals + suffix.
    // Uses IntersectionObserver so it only fires when the strip
    // becomes visible (saves the effect for the right moment).
    (function () {
        const counters = document.querySelectorAll('.counter');
        if (!counters.length) return;

        const animateCount = (el) => {
            const target   = parseFloat(el.dataset.target || '0');
            const decimals = parseInt(el.dataset.decimals || '0', 10);
            const suffix   = el.dataset.suffix || '';
            const dur      = 1600;
            const start    = performance.now();

            function tick(now) {
                const t = Math.min(1, (now - start) / dur);
                // ease-out-quint for a satisfying landing
                const eased = 1 - Math.pow(1 - t, 5);
                const val = (target * eased).toFixed(decimals);
                el.textContent = val + suffix;
                if (t < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        };

        const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => {
                if (e.isIntersecting) {
                    animateCount(e.target);
                    io.unobserve(e.target);
                }
            });
        }, { threshold: 0.4 });
        counters.forEach((c) => io.observe(c));
    })();

    // ────────────────────────── FAQ accordion ────────────────────────
    document.querySelectorAll('.faq-q').forEach((q) => {
        q.addEventListener('click', () => {
            const item = q.parentElement;
            const wasOpen = item.classList.contains('is-open');
            // Optional: close siblings for a "one at a time" feel.
            document.querySelectorAll('.faq-item').forEach((s) => s.classList.remove('is-open'));
            if (!wasOpen) item.classList.add('is-open');
        });
    });

    // ─────────────────── Magnetic buttons (cursor-pull) ──────────────
    // Adds a subtle "the button bends toward your cursor" feel — same
    // trick Awwwards-grade sites use to draw attention to CTAs.
    document.querySelectorAll('.magnetic').forEach((btn) => {
        const strength = 0.25;
        btn.addEventListener('mousemove', (e) => {
            const r = btn.getBoundingClientRect();
            const cx = r.left + r.width / 2;
            const cy = r.top  + r.height / 2;
            const dx = (e.clientX - cx) * strength;
            const dy = (e.clientY - cy) * strength;
            btn.style.transform = `translate(${dx}px, ${dy}px)`;
        });
        btn.addEventListener('mouseleave', () => { btn.style.transform = 'translate(0, 0)'; });
    });

    // ───────────────────── AI circular cursor ───────────────────────
    // Two-layer cursor: inner dot tracks 1:1, outer ring eases ~18%
    // toward it (satisfying lag). Hover state on interactive elements
    // grows the ring + tints it. Skipped on touch / coarse pointers
    // automatically because the CSS media query hides it there.
    (function () {
        const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        if (!canHover) return;
        const dot  = document.getElementById('aiCursorDot');
        const ring = document.getElementById('aiCursorRing');
        const ringInner = ring?.querySelector('.ai-cursor__ring');
        if (!dot || !ring || !ringInner) return;

        let mx = innerWidth / 2, my = innerHeight / 2;
        let rx = mx, ry = my;
        document.addEventListener('mousemove', (e) => {
            mx = e.clientX; my = e.clientY;
            dot.style.transform = `translate(${mx}px, ${my}px)`;
        }, { passive: true });

        (function tick() {
            rx += (mx - rx) * 0.18;
            ry += (my - ry) * 0.18;
            ring.style.transform = `translate(${rx}px, ${ry}px)`;
            requestAnimationFrame(tick);
        })();

        // Hover state — broad selector matches anything clickable.
        document.querySelectorAll('a, button, input, .bento-card, .price-card, .step-node, .testimonial-card, .compare-card, .faq-q, .magnetic').forEach((el) => {
            el.addEventListener('mouseenter', () => ringInner.classList.add('is-hover'));
            el.addEventListener('mouseleave', () => ringInner.classList.remove('is-hover'));
        });
        document.addEventListener('mousedown', () => ringInner.classList.add('is-down'));
        document.addEventListener('mouseup',   () => ringInner.classList.remove('is-down'));

        // Fade off-screen so the cursor doesn't stick in a corner.
        document.addEventListener('mouseleave', () => { dot.style.opacity = ring.style.opacity = 0; });
        document.addEventListener('mouseenter', () => { dot.style.opacity = ring.style.opacity = 1; });
    })();

    // ───────────────────── Hero particle field ──────────────────────
    // Lightweight starfield: ~120 dots drifting on a slow diagonal,
    // a few "data packets" with glow. Pure 2D canvas, GPU-friendly.
    (function () {
        const canvas = document.getElementById('hero-particles');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        let w, h, dots = [];
        const DPR = Math.min(devicePixelRatio || 1, 2);

        function resize() {
            const hero = canvas.parentElement;
            w = canvas.width  = hero.clientWidth  * DPR;
            h = canvas.height = hero.clientHeight * DPR;
            canvas.style.width  = hero.clientWidth + 'px';
            canvas.style.height = hero.clientHeight + 'px';
            const count = Math.min(160, Math.floor((w * h) / 18000));
            dots = [];
            for (let i = 0; i < count; i++) {
                dots.push({
                    x: Math.random() * w,
                    y: Math.random() * h,
                    z: Math.random() * .9 + .15,
                    a: Math.random(),
                    // every 12th dot is a "data packet" that glows brighter
                    hot: i % 12 === 0,
                });
            }
        }

        function draw() {
            ctx.clearRect(0, 0, w, h);
            const t = performance.now() * 0.00075;
            for (const d of dots) {
                const tw = (Math.sin(t * 2 + d.a * 6) + 1) * 0.5;
                const size = d.z * 1.6 * DPR * (d.hot ? 1.8 : 1);
                ctx.beginPath();
                ctx.arc(d.x, d.y, size, 0, Math.PI * 2);
                ctx.fillStyle = d.hot
                    ? `rgba(96, 165, 250, ${0.4 + tw * 0.5})`
                    : `rgba(180, 210, 255, ${0.15 + tw * 0.45 * d.z})`;
                ctx.fill();
                if (d.hot) {
                    ctx.beginPath();
                    ctx.arc(d.x, d.y, size * 3, 0, Math.PI * 2);
                    ctx.fillStyle = `rgba(59, 130, 246, ${0.05 + tw * 0.05})`;
                    ctx.fill();
                }
                d.x += d.z * 0.18;
                d.y += d.z * 0.10;
                if (d.x > w) d.x = 0;
                if (d.y > h) d.y = 0;
            }
            requestAnimationFrame(draw);
        }
        addEventListener('resize', resize);
        resize();
        draw();
    })();

    // ───────────────────── Text scramble on H1 ──────────────────────
    // Reveals headline characters with a brief "decoding" effect.
    // Runs once on load (before motion's reveal kicks in).
    (function () {
        const h1 = document.querySelector('.hero h1');
        if (!h1) return;
        const spans = h1.querySelectorAll('.grad, .accent');
        const CHARS = 'AI▮▯▒░▓01-+*•';
        spans.forEach((span) => {
            const original = span.textContent;
            let frame = 0, total = 28;
            const tick = () => {
                const progress = frame / total;
                let out = '';
                for (let i = 0; i < original.length; i++) {
                    if (i < progress * original.length) {
                        out += original[i];
                    } else if (original[i] === ' ') {
                        out += ' ';
                    } else {
                        out += CHARS[Math.floor(Math.random() * CHARS.length)];
                    }
                }
                span.textContent = out;
                if (frame++ < total) requestAnimationFrame(tick);
                else span.textContent = original;
            };
            setTimeout(() => requestAnimationFrame(tick), 200);
        });
    })();

    // Contact form micro-interaction (animate optional)
    const form = document.getElementById('ctaForm');
    const msg  = document.getElementById('ctaMsg');
    if (form) {
        form.addEventListener('submit', (e) => {
            const email = form.querySelector('input[type=email]').value.trim();
            if (!email) return;
            msg.textContent = 'Got it — check your inbox in a few seconds.';
            msg.classList.add('is-ok');
            form.querySelector('input').value = '';
            if (animate) animate(msg, { y: [-6, 0], opacity: [0, 1] }, { duration: 0.4 });
        });
    }
</script>

@include('partials.cookie-consent')
</body>
</html>
