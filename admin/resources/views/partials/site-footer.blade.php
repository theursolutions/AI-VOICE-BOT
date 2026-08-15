{{--
    Reusable public-site footer. Used by the landing page (welcome.blade.php)
    and every legal/marketing page (layouts/public.blade.php). Self-contained
    styles scoped under .site-footer; relies on the theme CSS variables
    (--neon, --text-dim, --line, --panel …) defined by the host page.

    All copy + contact details are editable in /admin/content
    (config/site.php → content.*).
--}}
@php
    $brand    = tva_setting('content.brand_name', 'Serve AI');
    $tagline  = tva_setting('content.footer_tagline', 'The AI receptionist and CRM that answers every call, chat and message — 24/7, in your own voice.');
    $phone    = tva_setting('content.contact_phone', '');
    $email    = tva_setting('content.contact_email', '');
    $address  = tva_setting('content.contact_address', '');
    $socials  = array_filter([
        'x'         => tva_setting('content.social_twitter', ''),
        'linkedin'  => tva_setting('content.social_linkedin', ''),
        'facebook'  => tva_setting('content.social_facebook', ''),
        'instagram' => tva_setting('content.social_instagram', ''),
    ]);
    $bottom   = tva_setting('content.footer_text', '');
    $telHref  = $phone ? 'tel:' . preg_replace('/[^\d+]/', '', $phone) : '';
@endphp

<footer class="site-footer">
    <div class="site-footer__wrap">
        <div class="site-footer__top">
            {{-- Brand + tagline + social --}}
            <div class="site-footer__brandcol">
                <a href="{{ url('/') }}" class="site-footer__brand">
                    @php $footIconWebp = serveai_icon_sized(64, 'webp'); @endphp
                    <picture>
                        @if ($footIconWebp)<source srcset="{{ $footIconWebp }}" type="image/webp">@endif
                        <img class="site-footer__mark" src="{{ serveai_icon_sized(64) }}" alt="{{ $brand }} logo"
                             width="28" height="28" loading="lazy" decoding="async">
                    </picture>{{ $brand }}
                </a>
                <p class="site-footer__tagline">{{ $tagline }}</p>

                @if (!empty($socials))
                    <div class="site-footer__social">
                        @foreach ($socials as $kind => $href)
                            <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" aria-label="{{ ucfirst($kind) }}">
                                @switch($kind)
                                    @case('x')
                                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                        @break
                                    @case('linkedin')
                                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.13 1.45-2.13 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13zM7.12 20.45H3.56V9h3.56v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"/></svg>
                                        @break
                                    @case('facebook')
                                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07c0 6.02 4.39 11.01 10.13 11.93v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.69.24 2.69.24v2.97h-1.52c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.08 24 18.09 24 12.07z"/></svg>
                                        @break
                                    @case('instagram')
                                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.7 3.7 0 0 1-1.38-.9 3.7 3.7 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.3-1.46.72-2.12 1.38C1.36 2.67.94 3.34.63 4.14.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.3.8.72 1.46 1.38 2.12.66.66 1.33 1.08 2.12 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.86 5.86 0 0 0 2.12-1.38 5.86 5.86 0 0 0 1.38-2.12c.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.86 5.86 0 0 0-1.38-2.12A5.86 5.86 0 0 0 19.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84A6.16 6.16 0 1 0 18.16 12 6.16 6.16 0 0 0 12 5.84zm0 10.16A4 4 0 1 1 16 12a4 4 0 0 1-4 4zm6.41-10.4a1.44 1.44 0 1 1-1.44-1.44 1.44 1.44 0 0 1 1.44 1.44z"/></svg>
                                        @break
                                @endswitch
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Link columns --}}
            <div class="site-footer__links">
                <div class="site-footer__col">
                    <h3>Product</h3>
                    <a href="{{ url('/') }}#platform">Features</a>
                    <a href="{{ url('/') }}#channels">Channels</a>
                    <a href="{{ url('/') }}#cases">Who it's for</a>
                    <a href="{{ url('/pricing') }}">Pricing</a>
                    <a href="{{ url('/security') }}">Security</a>
                    <a href="{{ url('/') }}#faq">FAQ</a>
                    @auth
                        <a href="{{ url('/dashboard') }}">Dashboard</a>
                    @else
                        <a href="{{ url('/register') }}">Get started free</a>
                    @endauth
                </div>
                <div class="site-footer__col">
                    <h3>Company</h3>
                    {{-- Label is configurable (content.blog_label) — the URL stays
                         /blog, which is the path Google expects. --}}
                    <a href="{{ url('/blog') }}">{{ tva_setting('content.blog_label', 'Insights') }}</a>
                    <a href="{{ url('/about') }}">About us</a>
                    <a href="{{ url('/contact') }}">Contact</a>
                    {{-- Signed in → back into the app; no point offering a
                         second account to someone who already has one. --}}
                    @auth
                        <a href="{{ url('/dashboard') }}">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}">Sign in</a>
                        <a href="{{ url('/register') }}">Create account</a>
                    @endauth
                </div>
                <div class="site-footer__col">
                    <h3>Legal</h3>
                    <a href="{{ url('/privacy') }}">Privacy Policy</a>
                    <a href="{{ url('/terms') }}">Terms of Service</a>
                    <a href="{{ url('/refund-policy') }}">Refund Policy</a>
                    <a href="{{ url('/cookies') }}">Cookie Policy</a>
                    {{-- Meta requires the deletion instructions to be reachable
                         from the site, not only from the app dashboard. --}}
                    <a href="{{ url('/data-deletion') }}">Data Deletion</a>
                </div>
                <div class="site-footer__col site-footer__col--contact">
                    <h3>Get in touch</h3>
                    @if ($phone)
                        <a href="{{ $telHref }}" class="site-footer__contact">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <span>{{ $phone }}</span>
                        </a>
                    @endif
                    @if ($email)
                        <a href="mailto:{{ $email }}" class="site-footer__contact">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <span>{{ $email }}</span>
                        </a>
                    @endif
                    @if ($address)
                        <div class="site-footer__contact site-footer__contact--static">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span>{{ $address }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="site-footer__bottom">
            <div class="site-footer__copy">
                @if ($bottom !== '')
                    {{ $bottom }}
                @else
                    &copy; {{ date('Y') }} {{ $brand }}. All rights reserved.
                @endif
            </div>
            <div class="site-footer__legal-mini">
                <a href="{{ url('/privacy') }}">Privacy</a>
                <a href="{{ url('/terms') }}">Terms</a>
                <a href="{{ url('/refund-policy') }}">Refunds</a>
                <a href="{{ url('/cookies') }}">Cookies</a>
            </div>
        </div>
    </div>
</footer>

<style>
    .site-footer {
        position: relative; z-index: 2;
        text-align: left;
        margin-top: 90px;
        padding: 64px 0 32px;
        border-top: 1px solid var(--line, rgba(120,180,220,.12));
        background: linear-gradient(180deg, rgba(8,11,18,.4), rgba(5,6,9,.85));
        color: var(--text-dim, #8b96a8);
        font-size: 14px;
    }
    .site-footer__wrap { max-width: 1240px; margin: 0 auto; padding: 0 28px; }
    .site-footer__top {
        display: grid; grid-template-columns: 1.3fr 2fr; gap: 48px;
        padding-bottom: 44px; border-bottom: 1px solid var(--line, rgba(120,180,220,.12));
    }
    .site-footer__brand {
        display: inline-flex; align-items: center; gap: 10px;
        font-weight: 800; font-size: 18px; color: var(--text, #e6edf3);
        margin-bottom: 14px;
    }
    .site-footer__mark {
        width: 28px; height: 28px; display: block; object-fit: contain;
        filter: drop-shadow(0 0 10px rgba(59,130,246,.4));
    }
    .site-footer__tagline { margin: 0 0 20px; max-width: 340px; line-height: 1.6; }
    .site-footer__social { display: flex; gap: 10px; }
    .site-footer__social a {
        width: 36px; height: 36px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid var(--line, rgba(120,180,220,.12));
        background: var(--panel, rgba(15,21,35,.55));
        color: var(--text-dim, #8b96a8);
        transition: color .2s, border-color .2s, transform .2s;
    }
    .site-footer__social a:hover { color: var(--neon, #3b82f6); border-color: var(--line-hot, rgba(59,130,246,.35)); transform: translateY(-2px); }
    .site-footer__social svg { width: 16px; height: 16px; }

    .site-footer__links {
        display: grid; grid-template-columns: repeat(4, 1fr); gap: 28px;
    }
    .site-footer__col { display: flex; flex-direction: column; gap: 12px; }
    .site-footer__col h3 {
        margin: 0 0 4px; font-size: 12px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .12em;
        color: var(--text, #e6edf3);
    }
    .site-footer__col a { color: var(--text-dim, #8b96a8); transition: color .18s; }
    .site-footer__col a:hover { color: var(--neon, #3b82f6); }
    .site-footer__contact {
        display: flex; align-items: flex-start; gap: 9px; line-height: 1.45;
    }
    .site-footer__contact svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 3px; color: var(--neon, #3b82f6); }
    .site-footer__contact--static { color: var(--text-dim, #8b96a8); }

    .site-footer__bottom {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 14px; padding-top: 26px; font-size: 13px;
    }
    .site-footer__legal-mini { display: flex; gap: 18px; flex-wrap: wrap; }
    .site-footer__legal-mini a { color: var(--text-dim, #8b96a8); }
    .site-footer__legal-mini a:hover { color: var(--neon, #3b82f6); }

    @media (max-width: 860px) {
        .site-footer__top { grid-template-columns: 1fr; gap: 36px; }
        .site-footer__links { grid-template-columns: repeat(2, 1fr); gap: 28px 20px; }
    }
    @media (max-width: 480px) {
        .site-footer { padding: 48px 0 28px; }
        .site-footer__bottom { flex-direction: column; align-items: flex-start; }
    }

    /* Light mode keeps a DARK footer. Placed here rather than on the
       homepage because this partial is shared — the blog, pricing and legal
       pages include it too, and a navy footer on one page and a white one
       on the next reads as two different sites.

       Tokens are redefined on the element, so every rule below that reads
       --text-dim / --line follows without being touched. */
    html:not(.dark) .site-footer {
        --text:      #f2f7fd;
        --text-dim:  #b3c7de;
        --text-dim2: #93aac6;
        --line:      rgba(255,255,255,.14);
        --neon:      #7db1ff;
        background: #16304f;
        border-top-color: rgba(255,255,255,.10);
        color: var(--text-dim);
    }
</style>
