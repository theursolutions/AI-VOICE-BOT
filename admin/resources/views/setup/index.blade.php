{{-- Workspace provisioning wizard.

     Was a standalone <html> document with its own teal palette, which made it
     look like a different product from every other admin screen. Now it runs on
     layouts.master like the rest of the app: same sidebar, same topbar, same
     --tva-gradient accent. The sidebar's module links are veiled here (see
     layouts/sidebar.blade.php) because `workspace.provisioned` refuses them
     until this form is submitted. --}}
@extends('layouts.master')

@section('content')
<style>
    .tva-setup-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-setup-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }
    /* Full width so it lines up with the hero above it, exactly like
       .tva-flow-card on the flows page. The form column inside is what's
       constrained — a 1400px-wide text input is unreadable. */
    .tva-setup-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:26px 28px; }
    html.dark .tva-setup-card { background:#1e293b; border-color:#334155; }
    /* Centred column. Labels/inputs stay left-aligned inside it — centring the
       text as well would make a form that's hard to scan. */
    .tva-setup-inner { max-width:760px; margin:0 auto; }

    .tva-setup-eyebrow {
        display:inline-flex; align-items:center; gap:7px;
        font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.14em;
        color:var(--tva-accent); background:rgba(59,130,246,.09);
        border:1px solid rgba(59,130,246,.28); border-radius:999px;
        padding:5px 12px; margin-bottom:14px;
    }
    .tva-setup-eyebrow::before {
        content:''; width:6px; height:6px; border-radius:50%;
        background:var(--tva-accent); box-shadow:0 0 8px var(--tva-accent);
        animation:tvaSetupPulse 1.6s infinite;
    }
    @keyframes tvaSetupPulse { 0%,100%{opacity:1;transform:scale(1);} 50%{opacity:.35;transform:scale(.6);} }

    .tva-setup-card h2 { font-size:22px; font-weight:700; margin:0 0 8px; color:#0f172a; }
    html.dark .tva-setup-card h2 { color:#f1f5f9; }
    .tva-setup-card h2 .accent { color:var(--tva-accent); }
    .tva-setup-lead { font-size:14px; color:#64748b; margin:0 0 24px; line-height:1.6; }
    html.dark .tva-setup-lead { color:#94a3b8; }

    .tva-setup-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    @media (max-width:640px) { .tva-setup-row { grid-template-columns:1fr; } }
    .tva-setup-help { font-size:11.5px; color:#94a3b8; margin-top:6px; }
    .tva-setup-actions {
        display:flex; align-items:center; gap:14px; flex-wrap:wrap;
        margin-top:22px; padding-top:20px; border-top:1px solid #e2e8f0;
    }
    html.dark .tva-setup-actions { border-color:#334155; }
    .tva-setup-note { font-size:12px; color:#94a3b8; }

    /* Provisioning overlay — the POST does the work synchronously and takes a
       few seconds, so this stands in for a frozen button. Cosmetic only. */
    .tva-prov {
        position:fixed; inset:0; z-index:9999;
        background:rgba(15,23,42,.93); backdrop-filter:blur(10px);
        display:none; align-items:center; justify-content:center; padding:20px;
    }
    .tva-prov.is-on { display:flex; }
    .tva-prov__card { text-align:center; max-width:520px; }
    .tva-prov__orb {
        width:120px; height:120px; margin:0 auto 26px; border-radius:50%;
        position:relative; background:var(--tva-gradient);
        box-shadow:0 0 70px -6px var(--tva-accent);
        animation:tvaOrbSpin 3s linear infinite;
    }
    @keyframes tvaOrbSpin { to { transform:rotate(360deg); } }
    .tva-prov__orb::after {
        content:''; position:absolute; inset:14%; border-radius:50%;
        border:2px solid rgba(255,255,255,.55);
        animation:tvaOrbPulse 1.5s ease-in-out infinite;
    }
    @keyframes tvaOrbPulse { 0%,100%{transform:scale(1);opacity:1;} 50%{transform:scale(1.08);opacity:.55;} }
    .tva-prov__title { font-size:20px; font-weight:700; color:#f1f5f9; margin-bottom:12px; }
    .tva-prov__step {
        font-family:ui-monospace,'JetBrains Mono',monospace; font-size:13px;
        color:#93c5fd; margin:7px 0;
        opacity:0; transform:translateY(6px); transition:all .35s;
    }
    .tva-prov__step.is-shown { opacity:1; transform:translateY(0); }
    .tva-prov__step.is-done::before   { content:'[\2713] '; color:#4ade80; }
    .tva-prov__step.is-active::before { content:'[…] ';     color:#fcd34d; }
</style>

<div class="content">
    <div class="tva-setup-hero mt-6">
        <div class="tva-setup-hero__icon">🚀</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Initialize your workspace</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                One quick form and <b>{{ $client->name }}</b> gets its own isolated database.
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4 flex items-start" style="max-width:820px;">
            <i data-lucide="alert-triangle" class="w-4 h-4 mr-2 mt-0.5 flex-shrink-0"></i>
            <div>
                <div class="font-medium">Provisioning hit a snag.</div>
                @foreach ($errors->all() as $e)
                    <div class="text-xs mt-1">{{ $e }}</div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="intro-y tva-setup-card">
      <div class="tva-setup-inner">
        <div class="tva-setup-eyebrow">Workspace · Initialize</div>
        <h2>Boot your <span class="accent">Serve AI</span> mission.</h2>
        <p class="tva-setup-lead">
            We're spinning up an isolated database just for you — your data never mixes with
            anyone else's. Roughly five seconds to provision, then you're on the dashboard.
        </p>

        <form id="setupForm" method="POST"
              action="{{ route('setup.store', ['client' => $client->slug]) }}" autocomplete="off">
            @csrf

            <div>
                <label class="form-label" for="setup-name">
                    Project name <span class="text-danger">*</span>
                </label>
                <input id="setup-name" type="text" name="name" required maxlength="120"
                       class="form-control @error('name') border-danger @enderror"
                       value="{{ old('name', $client->name) }}" placeholder="Acme Receptionist">
                <div class="tva-setup-help">What this project is internally called. You can rename it anytime.</div>
            </div>

            <div class="tva-setup-row mt-4">
                <div>
                    <label class="form-label" for="setup-website">Public website</label>
                    <input id="setup-website" type="url" name="website" maxlength="255"
                           class="form-control" value="{{ old('website') }}" placeholder="https://acme.com">
                </div>
                <div>
                    <label class="form-label" for="setup-industry">Industry</label>
                    <input id="setup-industry" type="text" name="industry" maxlength="120"
                           class="form-control" value="{{ old('industry') }}" placeholder="Healthcare / SaaS / Retail">
                </div>
            </div>

            <div class="mt-4">
                <label class="form-label" for="setup-about">About — what does this business do?</label>
                <textarea id="setup-about" name="about" rows="3" maxlength="1000" class="form-control"
                          placeholder="Acme runs an 8-chair dental clinic and needs an after-hours receptionist to capture appointment requests.">{{ old('about') }}</textarea>
                <div class="tva-setup-help">Fed into your AI agent's system prompt so it stays in character.</div>
            </div>

            <div class="tva-setup-actions">
                <button type="submit" class="btn btn-primary py-2 px-4" id="submitBtn">
                    <i data-lucide="rocket" class="w-4 h-4 mr-2"></i> Initialize workspace
                </button>
                @php
                    // Single source of truth: the same address the public site
                    // and footer use, editable in Ops → Page Content.
                    $supportEmail = tva_setting('content.contact_email', 'info@serveai.com.pk');
                @endphp
                <span class="tva-setup-note">
                    Hit a wall? <a href="mailto:{{ $supportEmail }}" style="color:var(--tva-accent);">{{ $supportEmail }}</a>
                </span>
            </div>
        </form>
      </div>{{-- .tva-setup-inner --}}
    </div>
</div>

{{-- Provisioning overlay --}}
<div class="tva-prov" id="provOverlay" aria-hidden="true">
    <div class="tva-prov__card">
        <div class="tva-prov__orb"></div>
        <div class="tva-prov__title">Spinning up your mission control…</div>
        <div class="tva-prov__step" id="provStep1">Reserving private database</div>
        <div class="tva-prov__step" id="provStep2">Installing schema (10 tables)</div>
        <div class="tva-prov__step" id="provStep3">Linking your workspace</div>
        <div class="tva-prov__step" id="provStep4">Almost there…</div>
    </div>
</div>

<script>
(function () {
    var form    = document.getElementById('setupForm');
    var overlay = document.getElementById('provOverlay');
    var btn     = document.getElementById('submitBtn');
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
                if (i > 0) {
                    var prev = document.getElementById(steps[i - 1]);
                    if (prev) { prev.classList.remove('is-active'); prev.classList.add('is-done'); }
                }
                el.classList.add('is-shown', 'is-active');
            }, 400 + i * 850);
        });
    });
})();
</script>
@endsection
