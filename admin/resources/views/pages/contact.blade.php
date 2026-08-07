@php
    $brand   = tva_setting('content.brand_name', 'Serve AI');
    $email   = tva_setting('content.contact_email', 'hello@serveai.com');
    $phone   = tva_setting('content.contact_phone', '');
    $addr    = tva_setting('content.contact_address', '');
    $telHref = $phone ? 'tel:' . preg_replace('/[^\d+]/', '', $phone) : '';
    $socials = array_filter([
        'X / Twitter' => tva_setting('content.social_twitter', ''),
        'LinkedIn'    => tva_setting('content.social_linkedin', ''),
        'Facebook'    => tva_setting('content.social_facebook', ''),
        'Instagram'   => tva_setting('content.social_instagram', ''),
    ]);
@endphp
@extends('layouts.public', [
    'pageEyebrow'     => 'We’d love to hear from you',
    'pageTitle'       => 'Contact <span class="accent">' . $brand . '</span>',
    'pageSubtitle'    => 'Questions, demos, or just curious whether it fits your business? Reach out — a real person will get back to you.',
    'metaDescription' => 'Get in touch with ' . $brand . ' — call, email, or request a callback from our AI agent.',
])

@push('head')
<style>
    .contact-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; align-items: start; }
    @media (max-width: 820px) { .contact-grid { grid-template-columns: 1fr; } }
    .contact-card {
        background: var(--panel); border: 1px solid var(--line);
        border-radius: 18px; padding: 32px clamp(20px,3vw,36px); backdrop-filter: blur(8px);
    }
    .contact-card h2 { font-size: 20px; font-weight: 800; margin: 0 0 18px; }
    .contact-row { display: flex; align-items: flex-start; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--line); }
    .contact-row:last-of-type { border-bottom: none; }
    .contact-row__icon {
        width: 40px; height: 40px; flex-shrink: 0; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        background: rgba(59,130,246,.1); color: var(--neon); border: 1px solid rgba(59,130,246,.25);
    }
    .contact-row__icon svg { width: 18px; height: 18px; }
    .contact-row__k { font-size: 12px; text-transform: uppercase; letter-spacing: .1em; color: var(--text-dim2); margin-bottom: 2px; }
    .contact-row__v { font-size: 15.5px; color: var(--text); font-weight: 500; }
    .contact-row__v a:hover { color: var(--neon-2); }
    .contact-social { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 18px; font-size: 14px; }
    .contact-social a { color: var(--neon-2); }
    .contact-social a:hover { color: var(--neon); }

    .cform label { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .1em; color: var(--text-dim); margin: 16px 0 7px; }
    .cform input, .cform textarea {
        width: 100%; background: rgba(0,0,0,.3); border: 1px solid var(--line);
        border-radius: 11px; padding: 12px 14px; color: var(--text); font-family: inherit; font-size: 15px; outline: none;
        transition: border-color .15s;
    }
    .cform input:focus, .cform textarea:focus { border-color: var(--line-hot); }
    .cform input::placeholder, .cform textarea::placeholder { color: var(--text-dim2); }
    .cform button {
        margin-top: 20px; width: 100%; background: var(--neon); color: #fff; border: none;
        padding: 14px; border-radius: 11px; font-weight: 700; font-size: 15px; cursor: pointer;
        box-shadow: 0 0 26px rgba(59,130,246,.4); transition: transform .15s, box-shadow .15s;
    }
    .cform button:hover { transform: translateY(-1px); box-shadow: 0 0 36px rgba(59,130,246,.6); }
    .cform__msg { margin-top: 12px; font-size: 13px; min-height: 18px; color: var(--text-dim); }
    .cform__msg.is-ok { color: var(--neon-2); }
    .cform__msg.is-err { color: #ff5e87; }
    .cform__hint { font-size: 13px; color: var(--text-dim); margin: 0 0 4px; }
</style>
@endpush

@section('content')
<section class="article">
    <div class="wrap" style="max-width: 980px;">
        <div class="contact-grid">

            {{-- Contact details --}}
            <div class="contact-card">
                <h2>Get in touch</h2>

                @if ($phone)
                <div class="contact-row">
                    <div class="contact-row__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </div>
                    <div>
                        <div class="contact-row__k">Phone</div>
                        <div class="contact-row__v"><a href="{{ $telHref }}">{{ $phone }}</a></div>
                    </div>
                </div>
                @endif

                <div class="contact-row">
                    <div class="contact-row__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <div>
                        <div class="contact-row__k">Email</div>
                        <div class="contact-row__v"><a href="mailto:{{ $email }}">{{ $email }}</a></div>
                    </div>
                </div>

                @if ($addr)
                <div class="contact-row">
                    <div class="contact-row__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    </div>
                    <div>
                        <div class="contact-row__k">Office</div>
                        <div class="contact-row__v">{{ $addr }}</div>
                    </div>
                </div>
                @endif

                <div class="contact-row">
                    <div class="contact-row__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="contact-row__k">Hours</div>
                        <div class="contact-row__v">Our AI answers 24/7. Our humans reply within one business day.</div>
                    </div>
                </div>

                @if (!empty($socials))
                <div class="contact-social">
                    @foreach ($socials as $name => $href)
                        <a href="{{ $href }}" target="_blank" rel="noopener noreferrer">{{ $name }} ↗</a>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Callback form (uses the live demo-call endpoint) --}}
            <div class="contact-card">
                <h2>Request a callback</h2>
                <p class="cform__hint">Leave your number and our AI agent will call you in seconds — so you can hear it for yourself.</p>
                <form id="contactCallForm" class="cform" autocomplete="off">
                    <label for="cf-name">Your name</label>
                    <input type="text" id="cf-name" name="name" placeholder="Jane Doe">

                    <label for="cf-phone">Phone number *</label>
                    <input type="tel" id="cf-phone" name="phone" placeholder="+1 (555) 010-0100" required>

                    <label for="cf-msg">What can we help with? (optional)</label>
                    <textarea id="cf-msg" name="message" rows="3" placeholder="Tell us about your business…"></textarea>

                    <button type="submit">Call me now →</button>
                    <div id="contactCallMsg" class="cform__msg">No spam. We’ll only use your number to call you back.</div>
                </form>
                <p class="cform__hint" style="margin-top:16px;">Prefer email? Write to <a href="mailto:{{ $email }}" style="color:var(--neon-2);">{{ $email }}</a> and we’ll take it from there.</p>
            </div>

        </div>
    </div>
</section>

<script>
(function () {
    var f = document.getElementById('contactCallForm');
    var msg = document.getElementById('contactCallMsg');
    if (!f) return;
    f.addEventListener('submit', function (e) {
        e.preventDefault();
        var phone = f.querySelector('input[name="phone"]').value.trim();
        if (!phone) return;
        msg.textContent = 'Connecting…';
        msg.className = 'cform__msg';
        fetch('{{ url('/api/demo-call') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ phone: phone })
        })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .then(function (body) {
            msg.textContent = body.message || 'Thanks — we’ll call you shortly.';
            msg.className = 'cform__msg is-ok';
            f.reset();
        })
        .catch(function () {
            msg.textContent = 'Couldn’t reach our servers. Please email us instead.';
            msg.className = 'cform__msg is-err';
        });
    });
})();
</script>
@endsection
