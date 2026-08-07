<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Initialize your workspace — Serve AI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #050609; --bg-2: #0a0d14;
            --panel: rgba(15, 21, 35, .65);
            --line: rgba(120, 180, 220, .12);
            --line-hot: rgba(0, 255, 200, .35);
            --text: #e6edf3; --text-dim: #8b96a8; --text-dim2: #5a6478;
            --neon: #00ffc8; --neon-2: #4cffea;
            --hot: #ff5e87;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0; padding: 0;
            background: var(--bg); color: var(--text);
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh; overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; }
        ::selection { background: var(--neon); color: #000; }

        /* Background — same starfield + radial we use on the landing. */
        #stars {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background: radial-gradient(ellipse at 50% 0%, #0d1a2e 0%, #050609 55%, #000 100%);
        }

        /* Top sliver — brand + signout */
        .topbar {
            position: relative; z-index: 5;
            display: flex; align-items: center;
            padding: 18px 26px;
            border-bottom: 1px solid var(--line);
            background: rgba(5, 6, 9, .55);
            backdrop-filter: blur(8px);
        }
        .topbar__brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 16px; }
        .topbar__mark {
            width: 26px; height: 26px; border-radius: 7px;
            background: radial-gradient(circle at 30% 30%, var(--neon), #006e58);
            box-shadow: 0 0 12px rgba(0,255,200,.5);
        }
        .topbar__right { margin-left: auto; display: flex; align-items: center; gap: 16px; font-size: 13px; color: var(--text-dim); }
        .topbar__logout {
            background: transparent; border: 1px solid var(--line);
            padding: 6px 12px; border-radius: 7px; color: var(--text-dim);
            font-size: 12px; cursor: pointer; font-family: inherit;
        }
        .topbar__logout:hover { color: var(--text); border-color: var(--line-hot); }

        /* Page layout */
        .stage {
            position: relative; z-index: 2;
            min-height: calc(100vh - 64px);
            display: flex; align-items: center; justify-content: center;
            padding: 60px 24px;
        }
        .panel {
            width: 100%; max-width: 720px;
            background: var(--panel); border: 1px solid var(--line);
            border-radius: 20px; padding: 38px 42px;
            backdrop-filter: blur(10px);
            box-shadow: 0 30px 80px -20px rgba(0,0,0,.7),
                        0 0 0 1px rgba(0,255,200,.06),
                        0 0 60px -10px rgba(0, 255, 200, .15);
            position: relative;
            animation: panelIn .7s cubic-bezier(.2,.8,.2,1);
        }
        @keyframes panelIn {
            from { opacity: 0; transform: translateY(20px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .panel::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, var(--neon), transparent);
            opacity: .7;
        }

        @media (max-width: 600px) { .panel { padding: 26px 22px; border-radius: 16px; } }

        .eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 10.5px; font-weight: 700; color: var(--neon);
            text-transform: uppercase; letter-spacing: .16em;
            background: rgba(0, 255, 200, .08);
            border: 1px solid rgba(0, 255, 200, .25);
            border-radius: 999px; padding: 5px 12px;
            margin-bottom: 18px;
        }
        .eyebrow::before {
            content:''; width:6px; height:6px; border-radius:50%;
            background:var(--neon); box-shadow:0 0 10px var(--neon);
            animation:pulse 1.5s infinite;
        }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.3;transform:scale(.6);} }

        h1 {
            font-size: clamp(26px, 4vw, 34px);
            font-weight: 800; letter-spacing: -0.02em; line-height: 1.1;
            margin: 0 0 10px;
        }
        h1 .accent {
            background: linear-gradient(90deg, var(--neon), var(--neon-2) 60%, #b3fff0);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        p.sub { font-size: 15px; color: var(--text-dim); margin: 0 0 28px; line-height: 1.55; }

        .field { margin-bottom: 18px; }
        .field label {
            display: block; font-size: 11px; font-weight: 700;
            color: var(--text-dim); text-transform: uppercase; letter-spacing: .1em;
            margin-bottom: 7px;
        }
        .field label .req { color: var(--neon); margin-left: 3px; }
        .field input, .field textarea, .field select {
            width: 100%;
            background: rgba(0, 0, 0, .35); color: var(--text);
            border: 1px solid var(--line);
            border-radius: 10px; padding: 12px 14px;
            font-family: inherit; font-size: 14px;
            transition: border-color .15s, box-shadow .15s;
            outline: none;
        }
        .field input:focus, .field textarea:focus, .field select:focus {
            border-color: var(--neon);
            box-shadow: 0 0 0 3px rgba(0, 255, 200, .15);
        }
        .field input::placeholder, .field textarea::placeholder { color: var(--text-dim2); }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 540px) { .field-row { grid-template-columns: 1fr; } }
        .field__help { font-size: 11px; color: var(--text-dim2); margin-top: 6px; }

        .err {
            background: rgba(255, 94, 135, .08);
            border: 1px solid rgba(255, 94, 135, .35);
            color: #ffc4d0;
            padding: 12px 14px; border-radius: 10px; font-size: 13px;
            margin-bottom: 18px;
        }
        .err strong { color: var(--hot); }

        .cta-row { display: flex; align-items: center; gap: 14px; margin-top: 26px; flex-wrap: wrap; }
        .btn {
            background: var(--neon); color: #001712; border: none;
            padding: 12px 22px; border-radius: 11px;
            font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit;
            box-shadow: 0 0 22px rgba(0, 255, 200, .35);
            transition: transform .15s, box-shadow .15s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 0 32px rgba(0, 255, 200, .55); }
        .btn:disabled { opacity: .55; cursor: not-allowed; transform: none; box-shadow: none; }
        .secondary-link { font-size: 12px; color: var(--text-dim); }
        .secondary-link a { color: var(--neon); }

        /* Provisioning overlay — shown after submit */
        .prov {
            position: fixed; inset: 0; z-index: 100;
            background: rgba(5, 6, 9, .92);
            backdrop-filter: blur(16px);
            display: none; align-items: center; justify-content: center;
            padding: 20px;
        }
        .prov.is-on { display: flex; }
        .prov__card {
            text-align: center; max-width: 520px;
        }
        .prov__orb {
            width: 140px; height: 140px; margin: 0 auto 28px;
            border-radius: 50%; position: relative;
            background:
                radial-gradient(circle at 30% 30%, rgba(0, 255, 200, .55), transparent 60%),
                conic-gradient(from 0deg, rgba(0, 255, 200, .25), rgba(0, 255, 200, .02), rgba(0, 255, 200, .25));
            box-shadow: 0 0 80px rgba(0,255,200,.4), inset 0 0 40px rgba(0,255,200,.2);
            animation: orbSpin 3s linear infinite;
        }
        @keyframes orbSpin { to { transform: rotate(360deg); } }
        .prov__orb::after {
            content:''; position:absolute; inset:14%; border-radius:50%;
            border: 2px solid rgba(0, 255, 200, .6);
            animation: orbPulse 1.5s ease-in-out infinite;
        }
        @keyframes orbPulse { 0%,100%{ transform:scale(1); opacity:1; } 50%{ transform:scale(1.08); opacity:.6; } }
        .prov__title { font-size: 22px; font-weight: 800; margin-bottom: 10px; }
        .prov__step {
            font-family: 'JetBrains Mono', monospace; font-size: 13px;
            color: var(--neon); margin: 8px 0;
            opacity: 0; transform: translateY(6px); transition: all .35s;
        }
        .prov__step.is-shown { opacity: 1; transform: translateY(0); }
        .prov__step.is-done::before { content:'[\2713] '; color: var(--neon); }
        .prov__step.is-active::before { content:'[…] '; color: var(--warn, #ffcb6b); }

        /* Decorative scanlines on the panel edge */
        .panel::after {
            content:''; position: absolute; bottom: -1px; left: 0; right: 0; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(0,255,200,.4), transparent);
        }
    </style>
</head>
<body>

<canvas id="stars" aria-hidden="true"></canvas>

<header class="topbar">
    <div class="topbar__brand">
        <div class="topbar__mark"></div>
        Serve AI
    </div>
    <div class="topbar__right">
        <span class="mono">{{ auth()->user()?->email }}</span>
        <form method="POST" action="{{ route('logout') }}" style="margin:0;">
            @csrf
            <button type="submit" class="topbar__logout">Sign out</button>
        </form>
    </div>
</header>

<main class="stage">
    <div class="panel">
        <div class="eyebrow">Workspace · Initialize</div>
        <h1>Boot your <span class="accent">Serve AI mission.</span></h1>
        <p class="sub">
            We're spinning up an isolated database just for you — your data never mixes with anyone else's.
            One quick form, ~5 seconds to provision, and you'll be on the dashboard.
        </p>

        @if ($errors->any())
            <div class="err">
                <strong>Provisioning hit a snag.</strong>
                <div style="margin-top:6px;">
                    @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
                </div>
            </div>
        @endif

        <form id="setupForm" method="POST" action="{{ route('setup.store', ['client' => $client->slug]) }}" autocomplete="off">
            @csrf

            <div class="field">
                <label>Project name <span class="req">*</span></label>
                <input type="text" name="name" required maxlength="120"
                       value="{{ old('name', $client->name) }}"
                       placeholder="Acme Receptionist">
                <div class="field__help">What this project is internally called. You can rename it anytime.</div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label>Public website</label>
                    <input type="url" name="website" maxlength="255"
                           value="{{ old('website') }}"
                           placeholder="https://acme.com">
                </div>
                <div class="field">
                    <label>Industry</label>
                    <input type="text" name="industry" maxlength="120"
                           value="{{ old('industry') }}"
                           placeholder="Healthcare / SaaS / Retail">
                </div>
            </div>

            <div class="field">
                <label>About — what does this business do?</label>
                <textarea name="about" rows="3" maxlength="1000"
                          placeholder="Acme runs an 8-chair dental clinic and needs an after-hours receptionist to capture appointment requests.">{{ old('about') }}</textarea>
                <div class="field__help">Fed into your AI agent's system prompt so it stays in character.</div>
            </div>

            <div class="cta-row">
                <button type="submit" class="btn" id="submitBtn">
                    Initialize workspace
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                    </svg>
                </button>
                <span class="secondary-link">
                    Hit a wall? <a href="mailto:support@serveai.io">support@serveai.io</a>
                </span>
            </div>
        </form>
    </div>
</main>

<!-- Provisioning overlay (shown on form submit) -->
<div class="prov" id="provOverlay" aria-hidden="true">
    <div class="prov__card">
        <div class="prov__orb"></div>
        <div class="prov__title">Spinning up your mission control…</div>
        <div class="prov__step" id="provStep1">Reserving private database</div>
        <div class="prov__step" id="provStep2">Installing schema (10 tables)</div>
        <div class="prov__step" id="provStep3">Linking your workspace</div>
        <div class="prov__step" id="provStep4">Almost there…</div>
    </div>
</div>

<script>
/* Starfield (same as landing) */
(function () {
    var canvas = document.getElementById('stars');
    var ctx = canvas.getContext('2d');
    var stars = [], w, h;
    function resize() {
        w = canvas.width  = window.innerWidth  * window.devicePixelRatio;
        h = canvas.height = window.innerHeight * window.devicePixelRatio;
        canvas.style.width  = window.innerWidth  + 'px';
        canvas.style.height = window.innerHeight + 'px';
        var density = Math.min(180, Math.floor((w * h) / 14000));
        stars = [];
        for (var i = 0; i < density; i++) {
            stars.push({ x: Math.random()*w, y: Math.random()*h, z: Math.random()+0.2, a: Math.random() });
        }
    }
    function draw() {
        ctx.clearRect(0, 0, w, h);
        var t = performance.now() * 0.0008;
        for (var i = 0; i < stars.length; i++) {
            var s = stars[i];
            var tw = (Math.sin(t * 2 + s.a * 6) + 1) * 0.5;
            var sz = s.z * 1.4 * window.devicePixelRatio;
            ctx.beginPath(); ctx.arc(s.x, s.y, sz, 0, Math.PI*2);
            ctx.fillStyle = 'rgba(200,220,255,' + (0.2 + tw * 0.5 * s.z) + ')';
            ctx.fill();
            s.y += s.z * 0.15; if (s.y > h) s.y = 0;
        }
        requestAnimationFrame(draw);
    }
    window.addEventListener('resize', resize); resize(); draw();
})();

/* Provisioning overlay reveal sequence — purely cosmetic; the
   actual backend work runs synchronously during the POST. We just
   want the user to see *something* during the ~3-5s server wait
   instead of a frozen button. */
(function () {
    var form = document.getElementById('setupForm');
    var overlay = document.getElementById('provOverlay');
    var btn = document.getElementById('submitBtn');
    if (!form) return;

    var steps = ['provStep1', 'provStep2', 'provStep3', 'provStep4'];

    form.addEventListener('submit', function () {
        if (btn) btn.disabled = true;
        overlay.classList.add('is-on');
        overlay.setAttribute('aria-hidden', 'false');

        steps.forEach(function (id, i) {
            setTimeout(function () {
                var el = document.getElementById(id);
                if (!el) return;
                // Mark previous as done
                if (i > 0) {
                    var prev = document.getElementById(steps[i-1]);
                    if (prev) { prev.classList.remove('is-active'); prev.classList.add('is-done'); }
                }
                el.classList.add('is-shown', 'is-active');
            }, 400 + i * 850);
        });
    });
})();
</script>

</body>
</html>
