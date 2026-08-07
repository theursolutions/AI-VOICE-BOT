{{--
    Public marketing-site <head> tags, driven entirely by the SEO console
    (/admin/seo → site_settings, defaults in config/site.php). Include this
    inside the <head> of any public page:  @include('partials.seo-head')
--}}
@php
    $seo = tva_seo_all();

    $canonical    = rtrim((string) ($seo['canonical_url'] ?? config('app.url')), '/');
    $allowIndex   = (bool) ($seo['allow_indexing'] ?? true);
    $ogImage      = $seo['og_image'] ?? '';
    $twImage      = $seo['twitter_image'] ?: $ogImage;
    $social       = is_array($seo['social_links'] ?? null) ? $seo['social_links'] : [];

    $currentUrl = request()->fullUrl();
@endphp

{{-- Per-page overrides: pass metaTitle / metaDescription to the include
     (e.g. @include('partials.seo-head', ['metaTitle' => 'Privacy — Brand']).
     Falls back to the global SEO defaults when not provided. --}}
@php
    $pageTitle = ($metaTitle ?? '') !== '' ? $metaTitle : ($seo['meta_title'] ?? '');
    $pageDesc  = ($metaDescription ?? '') !== '' ? $metaDescription : ($seo['meta_description'] ?? '');
@endphp
<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDesc }}">
@if (!empty($seo['meta_keywords']))
<meta name="keywords" content="{{ $seo['meta_keywords'] }}">
@endif
@if (!empty($seo['author']))
<meta name="author" content="{{ $seo['author'] }}">
@endif
@if (!empty($seo['theme_color']))
<meta name="theme-color" content="{{ $seo['theme_color'] }}">
@endif
<link rel="canonical" href="{{ $currentUrl }}">

@if (! $allowIndex)
<meta name="robots" content="noindex, nofollow">
@else
<meta name="robots" content="index, follow, max-image-preview:large">
@endif

{{-- ── Icons (auto-prefer uploaded brand logo, else built-in SVG) ─── --}}
@php
    $faviconHref = !empty($seo['favicon_url']) ? $seo['favicon_url'] : serveai_icon();
    $appleHref   = !empty($seo['apple_touch_icon']) ? $seo['apple_touch_icon'] : $faviconHref;
@endphp
<link rel="icon" href="{{ $faviconHref }}">
<link rel="shortcut icon" href="{{ $faviconHref }}">
<link rel="apple-touch-icon" href="{{ $appleHref }}">

{{-- ── Open Graph ────────────────────────────────────────────────── --}}
<meta property="og:title" content="{{ ($metaTitle ?? '') !== '' ? $metaTitle : ($seo['og_title'] ?: ($seo['meta_title'] ?? '')) }}">
<meta property="og:description" content="{{ ($metaDescription ?? '') !== '' ? $metaDescription : ($seo['og_description'] ?: ($seo['meta_description'] ?? '')) }}">
<meta property="og:type" content="{{ $seo['og_type'] ?: 'website' }}">
<meta property="og:url" content="{{ $currentUrl }}">
@if (!empty($seo['og_site_name']))
<meta property="og:site_name" content="{{ $seo['og_site_name'] }}">
@endif
@if (!empty($ogImage))
<meta property="og:image" content="{{ $ogImage }}">
@endif

{{-- ── Twitter / X ───────────────────────────────────────────────── --}}
<meta name="twitter:card" content="{{ $seo['twitter_card'] ?: 'summary_large_image' }}">
<meta name="twitter:title" content="{{ ($metaTitle ?? '') !== '' ? $metaTitle : ($seo['og_title'] ?: ($seo['meta_title'] ?? '')) }}">
<meta name="twitter:description" content="{{ ($metaDescription ?? '') !== '' ? $metaDescription : ($seo['og_description'] ?: ($seo['meta_description'] ?? '')) }}">
@if (!empty($seo['twitter_site']))
<meta name="twitter:site" content="{{ $seo['twitter_site'] }}">
@endif
@if (!empty($twImage))
<meta name="twitter:image" content="{{ $twImage }}">
@endif

{{-- ── Search-engine verification ────────────────────────────────── --}}
@if (!empty($seo['google_site_verification']))
<meta name="google-site-verification" content="{{ $seo['google_site_verification'] }}">
@endif
@if (!empty($seo['bing_site_verification']))
<meta name="msvalidate.01" content="{{ $seo['bing_site_verification'] }}">
@endif

{{-- ── Structured data (JSON-LD) ─────────────────────────────────── --}}
@if (($seo['structured_data'] ?? true) && !empty($seo['org_name']))
@php
    $orgLd = array_filter([
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => $seo['org_name'] ?? null,
        'url'      => $canonical ?: null,
        'logo'     => $seo['org_logo'] ?: null,
        'email'    => $seo['org_email'] ?: null,
        'sameAs'   => !empty($social) ? array_values($social) : null,
    ]);
    if (!empty($seo['org_phone'])) {
        $orgLd['contactPoint'] = [
            '@type'       => 'ContactPoint',
            'telephone'   => $seo['org_phone'],
            'contactType' => 'customer service',
        ];
    }
    $siteLd = array_filter([
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => $seo['og_site_name'] ?: ($seo['org_name'] ?? null),
        'url'      => $canonical ?: null,
    ]);
@endphp
<script type="application/ld+json">{!! json_encode([$orgLd, $siteLd], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

{{-- ── Google Tag Manager ────────────────────────────────────────── --}}
@if (!empty($seo['gtm_id']))
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{{ $seo['gtm_id'] }}');</script>
@endif

{{-- ── Google Analytics 4 ────────────────────────────────────────── --}}
@if (!empty($seo['ga4_id']))
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $seo['ga4_id'] }}"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ $seo['ga4_id'] }}');</script>
@endif

{{-- ── Meta (Facebook) Pixel ─────────────────────────────────────── --}}
@if (!empty($seo['facebook_pixel']))
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{{ $seo['facebook_pixel'] }}');fbq('track','PageView');</script>
@endif

{{-- ── Raw custom head HTML (advanced) ───────────────────────────── --}}
@if (!empty($seo['custom_head_html']))
{!! $seo['custom_head_html'] !!}
@endif
