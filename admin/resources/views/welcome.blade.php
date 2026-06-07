<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="NueraBot — AI receptionist + CRM that never sleeps. Voice calls, web chat, lead capture. Drop your data, watch it work.">
    <title>NueraBot — your AI receptionist that never sleeps</title>
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
            width: 28px; height: 28px; border-radius: 8px;
            background: radial-gradient(circle at 30% 30%, var(--neon), #1e3a8a);
            box-shadow: 0 0 14px rgba(59, 130, 246, .5), inset 0 0 8px rgba(255, 255, 255, .25);
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
        @media (max-width: 720px) {
            .nav { padding: 12px 16px; }
            .nav__links { display: none; }
        }

        /* ─── Layout container ───────────────────────────────────────── */
        .wrap { position: relative; z-index: 2; max-width: 1240px; margin: 0 auto; padding: 0 28px; }
        @media (max-width: 540px) { .wrap { padding: 0 18px; } }

        /* ─── Hero ───────────────────────────────────────────────────── */
        .hero { position: relative; padding: 130px 0 80px; min-height: 100vh; display: flex; align-items: center; }
        .hero__grid {
            display: grid; grid-template-columns: 1.05fr .95fr; gap: 60px; align-items: center; width: 100%;
        }
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
            font-size: clamp(34px, 5.4vw, 64px);
            font-weight: 800; letter-spacing: -0.02em; line-height: 1.04;
            margin: 0 0 18px;
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

        /* ─── Footer ─────────────────────────────────────────────────── */
        footer {
            padding: 50px 0 40px; border-top: 1px solid var(--line);
            margin-top: 80px; color: var(--text-dim); font-size: 13px;
            text-align: center;
        }
        footer a { color: var(--text-dim); }
        footer a:hover { color: var(--neon); }

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
<nav class="nav">
    <div class="nav__brand">
        <div class="nav__brand-mark"></div>
        NueraBot
    </div>
    <div class="nav__links">
        <a href="#how">How it works</a>
        <a href="#caps">Capabilities</a>
        <a href="#trust">Why us</a>
        <a href="{{ route('login') }}">Sign in</a>
    </div>
    <a href="{{ url('/register') }}" class="nav__cta" data-cursor="open">Get started free</a>
</nav>

<!-- ── HERO ────────────────────────────────────────────────────────── -->
<section class="hero">
    <div class="wrap hero__grid">
        <div class="reveal">
            <div class="hero__eyebrow">Live · AI Mission Console</div>
            <h1>Your AI receptionist that <span class="accent">never sleeps.</span></h1>
            <p class="sub">
                NueraBot answers your calls and chats 24/7 in your own cloned voice,
                qualifies leads on the spot, and drops them straight into your CRM.
                Drop your data — watch it work.
            </p>

            <form id="callForm" class="callbar" autocomplete="off">
                <div class="callbar__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </div>
                <input type="tel" name="phone" placeholder="+1 (555) 010-0100" required data-cursor="call">
                <button type="submit" data-cursor="call">Call me now →</button>
            </form>
            <div id="callMsg" class="callbar__msg">Our AI agent will call you in under 10 seconds.</div>

            <div class="hero__meta">
                <div class="hero__meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    No credit card
                </div>
                <div class="hero__meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Your data stays in your DB
                </div>
                <div class="hero__meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    Set up in 90 seconds
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
        <div class="section__eyebrow reveal">Mission Console</div>
        <h2 class="reveal">Every call. Every chat. Every lead — in real time.</h2>
        <p class="lead reveal">
            Watch a call come in, the agent transcribe + respond live, and a fresh lead land in your CRM —
            three glass panels you'll see every day inside NueraBot.
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
        <div class="section__eyebrow reveal">Launch sequence</div>
        <h2 class="reveal">From signup to first call in 90 seconds.</h2>
        <p class="lead reveal">Four steps. No code. No engineers. Done before your coffee cools.</p>

        <div class="steps">
            <div class="step reveal tilt" data-cursor="step">
                <div class="step__num mono">01</div>
                <div class="step__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </div>
                <div class="step__title">Drop your data</div>
                <div class="step__body">Paste a URL, upload a CSV, or connect your DB. We index it in 30 seconds.</div>
            </div>
            <div class="step reveal tilt" data-cursor="step">
                <div class="step__num mono">02</div>
                <div class="step__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                </div>
                <div class="step__title">Pick a voice</div>
                <div class="step__body">Choose from 30+ voices or clone yours from a 10-second sample.</div>
            </div>
            <div class="step reveal tilt" data-cursor="step">
                <div class="step__num mono">03</div>
                <div class="step__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </div>
                <div class="step__title">Connect a number</div>
                <div class="step__body">Bring a Twilio number, or get one of ours. Route per skill or agent.</div>
            </div>
            <div class="step reveal tilt" data-cursor="step">
                <div class="step__num mono">04</div>
                <div class="step__icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                </div>
                <div class="step__title">Go live</div>
                <div class="step__body">Embed the chat widget on your site. Watch your dashboard fill with leads.</div>
            </div>
        </div>
    </div>
</section>

<!-- ── CAPABILITIES ───────────────────────────────────────────────── -->
<section class="section" id="caps" style="position: relative;">
    <div class="mini-3d" id="mini3dCaps" style="top:8%; left:-60px; width:220px; height:220px;"></div>
    <div class="mini-3d" id="mini3dCaps2" style="bottom:8%; right:-40px; width:180px; height:180px;"></div>
    <div class="wrap">
        <div class="section__eyebrow reveal">Capabilities</div>
        <h2 class="reveal">One bot. Every channel. Every question.</h2>
        <p class="lead reveal">Voice, chat, SMS. Knowledge from your docs, database, and CRM — answered in your voice.</p>

        <div class="caps">
            <div class="cap reveal tilt" data-cursor="explore">
                <div class="cap__icon">📞</div>
                <h3>Inbound + outbound calls</h3>
                <p>Twilio Media Streams piped to faster-whisper and Coqui TTS. Sub-second latency on a single GPU.</p>
            </div>
            <div class="cap reveal tilt" data-cursor="explore">
                <div class="cap__icon">💬</div>
                <h3>Embeddable web chat</h3>
                <p>Drop one script tag on your site. Theming, voice replies, per-project CORS allowlist included.</p>
            </div>
            <div class="cap reveal tilt" data-cursor="explore">
                <div class="cap__icon">🧠</div>
                <h3>Multi-source knowledge</h3>
                <p>Website crawl, document upload, live database queries (text-to-SQL), webhook fetch, CRM lookup.</p>
            </div>
            <div class="cap reveal tilt" data-cursor="explore">
                <div class="cap__icon">🎭</div>
                <h3>Multi-agent + skills</h3>
                <p>Different personas (sales / support / billing). Route by phone number or skill pool. Each agent gets its own voice.</p>
            </div>
            <div class="cap reveal tilt" data-cursor="explore">
                <div class="cap__icon">🔌</div>
                <h3>Swap brains in one click</h3>
                <p>Groq, Anthropic, Gemini, or local Ollama. CPU or GPU. Switch live without a redeploy.</p>
            </div>
            <div class="cap reveal tilt" data-cursor="explore">
                <div class="cap__icon">🏠</div>
                <h3>Your DB, your rules</h3>
                <p>Multi-tenant — each customer's data sits in its own database. Nothing pooled, nothing shared.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── TRUST STRIP ────────────────────────────────────────────────── -->
<section class="section" id="trust" style="padding-top: 40px;">
    <div class="wrap">
        <div class="trust reveal">
            <div class="trust__item"><span class="trust__num">&lt;1s</span> first-byte response</div>
            <div class="trust__item"><span class="trust__num">99.9%</span> call delivery</div>
            <div class="trust__item"><span class="trust__num">30+</span> voices · 13 languages</div>
            <div class="trust__item"><span class="trust__num">SOC 2</span> roadmap Q3</div>
        </div>
    </div>
</section>

<!-- ── FINAL CTA ──────────────────────────────────────────────────── -->
<section class="section">
    <div class="wrap">
        <div class="cta reveal">
            <h2>Ready to never miss a lead again?</h2>
            <p>Spin up your agent in 90 seconds. Cancel anytime. We won't sleep on a single call.</p>
            <a href="{{ url('/register') }}" class="btn" data-cursor="open">Start free — no card required →</a>
        </div>
    </div>
</section>

<footer>
    <div class="wrap">
        &copy; {{ date('Y') }} NueraBot · <a href="{{ route('login') }}">Sign in</a> · <a href="#how">How it works</a> · <a href="#caps">Capabilities</a>
    </div>
</footer>

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
    <iframe id="tvaIframe" class="tva-iframe-frame" src="{{ $tvaLegacyIframe }}" title="Chat with NueraBot" loading="lazy"></iframe>
    <button id="tvaLauncher" class="tva-launcher-floating" aria-label="Open chat" data-cursor="chat">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    </button>
@endif

<!-- ── Three.js + GSAP via CDN ────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>

<script>
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
    gsap.utils.toArray('.reveal').forEach(function (el, i) {
        gsap.to(el, {
            opacity: 1, y: 0, duration: 0.9, ease: 'power3.out',
            scrollTrigger: { trigger: el, start: 'top 88%', toggleActions: 'play none none reverse' },
        });
    });

    // Console panels — 3D tilt on scroll
    gsap.utils.toArray('.console').forEach(function (el, i) {
        gsap.fromTo(el,
            { rotateY: i === 0 ? -12 : (i === 2 ? 12 : 0), opacity: 0, y: 40 },
            {
                rotateY: 0, opacity: 1, y: 0, duration: 1.1, ease: 'power3.out',
                scrollTrigger: { trigger: el, start: 'top 90%' },
            }
        );
    });
})();

/* ─────────────────── Fake live transcript animator ──────────────── */
(function () {
    var box = document.getElementById('mockTrans');
    if (!box) return;
    var script = [
        { who: 'caller', text: 'Hi, I saw your post about AI receptionists?' },
        { who: 'bot',    text: 'Yes! NueraBot handles 24/7 calls + chats. What\'s your use case?' },
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

    // Step cards fly in from alternating sides.
    gsap.utils.toArray('.steps .step').forEach(function (el, i) {
        var fromX = (i % 2 === 0) ? -60 : 60;
        gsap.fromTo(el,
            { x: fromX, opacity: 0, rotateY: (i % 2 === 0) ? -15 : 15 },
            {
                x: 0, opacity: 1, rotateY: 0,
                duration: 1, ease: 'power3.out',
                scrollTrigger: { trigger: el, start: 'top 90%' },
            }
        );
    });

    // Capability cards stagger-pop with a slight rotate.
    gsap.utils.toArray('.caps .cap').forEach(function (el, i) {
        gsap.fromTo(el,
            { y: 60, opacity: 0, rotateX: 18 },
            {
                y: 0, opacity: 1, rotateX: 0,
                duration: 0.9, delay: (i % 3) * 0.08, ease: 'power3.out',
                scrollTrigger: { trigger: el, start: 'top 92%' },
            }
        );
    });
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
</script>

</body>
</html>
