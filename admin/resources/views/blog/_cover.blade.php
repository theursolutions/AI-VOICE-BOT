{{--
    A post's cover image, or a designed stand-in when it has none.

    Params:
      post   BlogPost
      eager  bool    true for the above-the-fold hero (loads immediately,
                     high priority); false for everything else (lazy)
      sizes  string  responsive sizes hint for the browser

    Why a partial: every cover on the site then shares the same alt-text
    rules, the same lazy-loading policy and the same aspect handling. Covers
    are the only images on the marketing site, so getting this one file right
    is most of the image-SEO work.
--}}
@php
    $eager = $eager ?? false;
    $sizes = $sizes ?? '100vw';

    // Alt text: the author's explicit value wins. If they left it blank we
    // fall back to the title, which is nearly always a fair description of
    // the cover. Never keyword-stuffed, never "image123.jpg".
    $alt = trim((string) $post->cover_alt) !== ''
        ? $post->cover_alt
        : $post->title;

    // Stable per-post gradient for the no-cover case: hashing the slug means
    // the same post always gets the same colours, so the grid looks
    // deliberate rather than random on every page load.
    $hash = crc32((string) $post->slug);
    $h1   = $hash % 360;
    $h2   = ($h1 + 42) % 360;
    $initial = mb_strtoupper(mb_substr(trim((string) $post->title), 0, 1)) ?: '·';
@endphp

@if (trim((string) $post->cover_url) !== '')
    <img src="{{ $post->cover_url }}"
         alt="{{ $alt }}"
         {{-- Intrinsic dimensions reserve the space before the file lands,
              which is what keeps Cumulative Layout Shift at zero. The
              container crops with object-fit, so these are a ratio hint
              rather than a promise about the file. --}}
         width="1200" height="675"
         sizes="{{ $sizes }}"
         @if ($eager)
             fetchpriority="high" decoding="async"
         @else
             loading="lazy" decoding="async"
         @endif
    >
@else
    <div class="blog-ph"
         style="background:linear-gradient(135deg, hsl({{ $h1 }} 62% 26%) 0%, hsl({{ $h2 }} 55% 12%) 100%);"
         role="img" aria-label="{{ $alt }}">
        <span aria-hidden="true">{{ $initial }}</span>
    </div>
@endif
