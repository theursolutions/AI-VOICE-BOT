<?php

namespace App\Support;

/**
 * URL canonicalisation helpers for the public marketing site.
 *
 * Everything a crawler reads must agree on ONE spelling of every URL:
 * one scheme, one host, no trailing slash, no tracking parameters. The
 * site is reachable on several spellings today (http/https, with and
 * without a trailing slash, with ?utm_* on ad clicks) and `request()->
 * fullUrl()` — what the head partial used to emit — faithfully echoes
 * whichever one the visitor happened to use. That turns one page into
 * many "different" pages as far as Google is concerned.
 *
 * The origin comes from the SEO console (`seo.canonical_url`) and falls
 * back to `config('app.url')`, so staging never advertises production
 * URLs and vice versa.
 */
class Seo
{
    /** Query parameters that are pure tracking noise — never canonical. */
    public const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'twclid', 'ttclid',
        'igshid', 'mc_cid', 'mc_eid', 'ref', 'referrer', '_ga', '_gl', 'yclid', 'li_fat_id',
    ];

    /** Canonical origin, e.g. "https://serveai.com.pk" (never a trailing slash). */
    public static function origin(): string
    {
        $url = (string) tva_setting('seo.canonical_url', config('app.url'));
        $url = trim($url) !== '' ? trim($url) : (string) config('app.url');

        // Keep only scheme + host (+ port) — an operator who pastes a full
        // page URL into the console must not poison every canonical tag.
        $parts = parse_url($url);
        if (! empty($parts['host'])) {
            $scheme = $parts['scheme'] ?? 'https';
            $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
            return $scheme . '://' . $parts['host'] . $port;
        }

        return rtrim($url, '/');
    }

    /**
     * Normalise a path to its canonical spelling: leading slash, no
     * trailing slash, no index.php, no query string. Root stays "/".
     */
    public static function path(?string $path = null): string
    {
        $path = $path ?? request()->getPathInfo();
        $path = '/' . ltrim((string) $path, '/');
        $path = preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * Absolute canonical URL for a path (defaults to the current request).
     * The homepage keeps its trailing slash — that is the spelling Google
     * treats as the site root and the one we advertise in the sitemap.
     */
    public static function canonical(?string $path = null): string
    {
        $p = self::path($path);

        return $p === '/' ? self::origin() . '/' : self::origin() . $p;
    }

    /**
     * Turn a possibly-relative asset reference into an absolute URL on the
     * canonical origin. Schema.org and Open Graph both require absolute
     * URLs — a relative `logo` silently invalidates the Organization markup.
     */
    public static function absolute(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        if (preg_match('#^(https?:)?//#i', $url)) {
            // Already absolute. If it points at the host that served this
            // request — which is what asset() returns — re-home it on the
            // canonical origin so og:image and JSON-LD never advertise an
            // internal hostname or an http:// variant of our own site.
            $host = parse_url($url, PHP_URL_HOST);
            if ($host && request() && $host === request()->getHost()) {
                $path  = (string) parse_url($url, PHP_URL_PATH);
                $query = parse_url($url, PHP_URL_QUERY);

                return self::origin() . '/' . ltrim($path, '/') . ($query ? '?' . $query : '');
            }

            return $url;
        }

        return self::origin() . '/' . ltrim($url, '/');
    }

    /**
     * Does this request carry query parameters that should never appear in
     * the index? Used to decide between a self-referencing canonical and a
     * canonical that points at the clean URL.
     */
    public static function hasTrackingParams(): bool
    {
        foreach (array_keys(request()->query()) as $key) {
            if (in_array(strtolower((string) $key), self::TRACKING_PARAMS, true)) {
                return true;
            }
        }

        return false;
    }
}
