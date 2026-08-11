{{--
    Public marketing-site <head> tags, driven by the SEO console
    (/admin/seo → site_settings, defaults in config/site.php).

    Include it inside the <head> of any public page:

        @include('partials.seo-head')

    Per-page overrides (all optional, passed as include data or set by the
    parent view before the include):

        metaTitle        string   full <title> — plain text, no HTML
        metaDescription  string   meta description for this page
        canonicalPath    string   canonical path if it differs from the URL
        pageNoindex      bool     force noindex on this page
        ogType           string   'website' (default) | 'article' | …
        jsonLd           array    extra Schema.org nodes for this page
                                  (e.g. FAQPage, SoftwareApplication)
        breadcrumbs      array    [['name' => 'About', 'url' => '/about'], …]
--}}
@php
    use App\Support\Seo;

    $seo = tva_seo_all();

    // ── Canonical ────────────────────────────────────────────────────
    // Built from the configured origin + the normalised path, NEVER from
    // request()->fullUrl(): that echoed back whatever spelling the visitor
    // arrived on, so /about, /about/ and /about?utm_source=fb each declared
    // themselves canonical and competed with each other in the index.
    $canonicalUrl = Seo::canonical($canonicalPath ?? null);

    // ── Indexability ─────────────────────────────────────────────────
    // Three ways a page becomes noindex: the global kill-switch (staging),
    // the noindex_paths list (duplicates, thin pages, auth forms), or an
    // explicit flag from the view.
    $noindexPaths = array_map(fn ($p) => Seo::path($p), (array) config('site.seo.noindex_paths', []));
    $isNoindex    = ! (bool) ($seo['allow_indexing'] ?? true)
                    || ($pageNoindex ?? false)
                    || in_array(Seo::path($canonicalPath ?? null), $noindexPaths, true);

    // ── Title / description ──────────────────────────────────────────
    // strip_tags because page headings carry markup (<span class="accent">)
    // and a title tag full of escaped HTML is what search results show.
    $pageTitle = trim(strip_tags((string) ($metaTitle ?? '')));
    $pageTitle = $pageTitle !== '' ? $pageTitle : (string) ($seo['meta_title'] ?? '');
    $pageDesc  = trim(strip_tags((string) ($metaDescription ?? '')));
    $pageDesc  = $pageDesc !== '' ? $pageDesc : (string) ($seo['meta_description'] ?? '');

    // ── Images (absolute — relative URLs are invalid in OG and JSON-LD) ─
    $ogImage = Seo::absolute($seo['og_image'] ?: serveai_icon());
    $twImage = Seo::absolute($seo['twitter_image'] ?: ($seo['og_image'] ?: serveai_icon()));

    // ── Social profiles: SEO console list + the footer links ─────────
    $social = array_values(array_filter(array_unique(array_merge(
        is_array($seo['social_links'] ?? null) ? $seo['social_links'] : [],
        [
            (string) tva_setting('content.social_twitter', ''),
            (string) tva_setting('content.social_linkedin', ''),
            (string) tva_setting('content.social_facebook', ''),
            (string) tva_setting('content.social_instagram', ''),
        ]
    ))));

    // Sized variants, not the 850×887 source: a browser downloads the
    // favicon on every first visit, and iOS wants exactly 180×180.
    $faviconHref = !empty($seo['favicon_url']) ? $seo['favicon_url'] : serveai_icon_sized(64);
    $appleHref   = !empty($seo['apple_touch_icon']) ? $seo['apple_touch_icon'] : serveai_icon_sized(180);
    $siteName    = $seo['og_site_name'] ?: ($seo['org_name'] ?? 'Serve AI');
@endphp
<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDesc }}">
{{-- A page may override the site-wide keywords ($metaKeywords). Worth being
     honest about: Google has ignored this tag since 2009, so it is here for
     consistency and for other engines, not as a ranking lever. --}}
@php $keywords = trim((string) ($metaKeywords ?? '')) ?: (string) ($seo['meta_keywords'] ?? ''); @endphp
@if ($keywords !== '')
<meta name="keywords" content="{{ $keywords }}">
@endif
@if (!empty($seo['author']))
<meta name="author" content="{{ $seo['author'] }}">
@endif
@if (!empty($seo['theme_color']))
<meta name="theme-color" content="{{ $seo['theme_color'] }}">
@endif
<link rel="canonical" href="{{ $canonicalUrl }}">

@if ($isNoindex)
{{-- follow, not nofollow: Google still has to crawl the links (and re-read
     this tag) for the page to actually leave the index. --}}
<meta name="robots" content="noindex, follow">
@else
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
@endif

{{-- ── Icons (auto-prefer uploaded brand logo, else built-in SVG) ─── --}}
<link rel="icon" href="{{ $faviconHref }}">
<link rel="shortcut icon" href="{{ $faviconHref }}">
<link rel="apple-touch-icon" href="{{ $appleHref }}">

{{-- ── Open Graph ────────────────────────────────────────────────── --}}
<meta property="og:title" content="{{ ($metaTitle ?? '') !== '' ? $pageTitle : ($seo['og_title'] ?: $pageTitle) }}">
<meta property="og:description" content="{{ ($metaDescription ?? '') !== '' ? $pageDesc : ($seo['og_description'] ?: $pageDesc) }}">
<meta property="og:type" content="{{ $ogType ?? ($seo['og_type'] ?: 'website') }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:locale" content="en_US">
@if (!empty($siteName))
<meta property="og:site_name" content="{{ $siteName }}">
@endif
@if (!empty($ogImage))
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:image:alt" content="{{ $siteName }}">
@php
    // Dimensions let Facebook/LinkedIn lay the card out on first scrape
    // instead of showing a blank box until their crawler fetches the file.
    $ogLocal = public_path(ltrim((string) parse_url($ogImage, PHP_URL_PATH), '/'));
    $ogSize  = is_file($ogLocal) ? @getimagesize($ogLocal) : false;
@endphp
@if ($ogSize)
<meta property="og:image:width" content="{{ $ogSize[0] }}">
<meta property="og:image:height" content="{{ $ogSize[1] }}">
@endif
@endif

{{-- ── Twitter / X ───────────────────────────────────────────────── --}}
<meta name="twitter:card" content="{{ $seo['twitter_card'] ?: 'summary_large_image' }}">
<meta name="twitter:title" content="{{ ($metaTitle ?? '') !== '' ? $pageTitle : ($seo['og_title'] ?: $pageTitle) }}">
<meta name="twitter:description" content="{{ ($metaDescription ?? '') !== '' ? $pageDesc : ($seo['og_description'] ?: $pageDesc) }}">
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

{{-- ── Structured data (JSON-LD) ─────────────────────────────────────
     One @graph per page: who we are (Organization), what the site is
     (WebSite), what this page is (WebPage + BreadcrumbList), plus any
     page-specific nodes the view passed in ($jsonLd) — FAQPage on the
     homepage, ContactPage on /contact, and so on.

     Everything marked up here is visible on the page. Nothing is invented.
--}}
@if (($seo['structured_data'] ?? true) && !empty($seo['org_name']))
@php
    $orgId  = Seo::origin() . '/#organization';
    $siteId = Seo::origin() . '/#website';

    $org = array_filter([
        '@type'  => 'Organization',
        '@id'    => $orgId,
        'name'   => $seo['org_name'] ?? null,
        'url'    => Seo::origin() . '/',
        'logo'   => Seo::absolute($seo['org_logo'] ?: serveai_icon()),
        'image'  => $ogImage,
        'email'  => $seo['org_email'] ?: null,
        'sameAs' => !empty($social) ? $social : null,
    ]);

    if (!empty($seo['org_phone'])) {
        $org['telephone']    = $seo['org_phone'];
        $org['contactPoint'] = [[
            '@type'       => 'ContactPoint',
            'telephone'   => $seo['org_phone'],
            'email'       => $seo['org_email'] ?: null,
            'contactType' => 'customer service',
            'areaServed'  => 'Worldwide',
            'availableLanguage' => ['English', 'Urdu'],
        ]];
        $org['contactPoint'][0] = array_filter($org['contactPoint'][0]);
    }

    // Postal address — only when a real one is configured and shown in the
    // footer / on the contact page. Prefers the split fields; falls back to
    // the single display string so an install that hasn't set them still
    // emits something valid.
    $street = trim((string) tva_setting('content.contact_street', ''));
    $addr   = $street !== '' ? $street : trim((string) tva_setting('content.contact_address', ''));
    if ($addr !== '') {
        $org['address'] = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => $addr,
            'addressLocality' => (string) tva_setting('content.contact_city', ''),
            'addressRegion'   => (string) tva_setting('content.contact_region', ''),
            'postalCode'      => (string) tva_setting('content.contact_postal_code', ''),
            'addressCountry'  => (string) tva_setting('content.contact_country', 'PK'),
        ]);
    }

    $website = array_filter([
        '@type'     => 'WebSite',
        '@id'       => $siteId,
        'name'      => $siteName,
        'url'       => Seo::origin() . '/',
        'publisher' => ['@id' => $orgId],
        'inLanguage' => 'en',
    ]);

    $webPage = array_filter([
        '@type'      => $pageSchemaType ?? 'WebPage',
        '@id'        => $canonicalUrl . '#webpage',
        'url'        => $canonicalUrl,
        'name'       => $pageTitle,
        'description' => $pageDesc !== '' ? $pageDesc : null,
        'isPartOf'   => ['@id' => $siteId],
        'about'      => ['@id' => $orgId],
        'inLanguage' => 'en',
    ]);

    // Breadcrumbs — mirrors the visible trail rendered by layouts.public.
    $breadcrumbNode = null;
    if (!empty($breadcrumbs) && is_array($breadcrumbs)) {
        $items = [];
        $pos   = 1;
        foreach (array_merge([['name' => 'Home', 'url' => '/']], $breadcrumbs) as $crumb) {
            $items[] = array_filter([
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => strip_tags((string) ($crumb['name'] ?? '')),
                'item'     => !empty($crumb['url']) ? Seo::canonical($crumb['url']) : null,
            ]);
        }
        $breadcrumbNode = [
            '@type'           => 'BreadcrumbList',
            '@id'             => $canonicalUrl . '#breadcrumb',
            'itemListElement' => $items,
        ];
        $webPage['breadcrumb'] = ['@id' => $canonicalUrl . '#breadcrumb'];
    }

    $graph = [$org, $website, $webPage];
    if ($breadcrumbNode) {
        $graph[] = $breadcrumbNode;
    }

    // Page-specific nodes supplied by the view.
    foreach ((array) ($jsonLd ?? []) as $node) {
        if (is_array($node) && !empty($node)) {
            $graph[] = $node;
        }
    }

    $ld = ['@context' => 'https://schema.org', '@graph' => array_values($graph)];
@endphp
<script type="application/ld+json">{!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
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
