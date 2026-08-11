<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passive visitor analytics
    |--------------------------------------------------------------------------
    |
    | Records every public page open, built only from data the browser already
    | sends with the request: IP, User-Agent, Accept-Language and Referer.
    | No script runs in the visitor's browser and no cookie or other client
    | storage is touched, so there is nothing to prompt about.
    |
    | That is a technical statement, not a legal one: an IP address is personal
    | data under the GDPR/UK-GDPR, so if you serve EU/UK traffic this belongs
    | in your privacy notice with a stated lawful basis and retention period.
    | `anonymize_ip` and `retention_days` below are the two knobs for that.
    |
    */

    'enabled' => (bool) env('VISITOR_TRACKING_ENABLED', true),

    // Drop the last octet of IPv4 (and the interface half of IPv6) before
    // storing. Keeps country/city-level accuracy while making the address no
    // longer identify one household. Off by default — full addresses are kept.
    'anonymize_ip' => (bool) env('VISITOR_ANONYMIZE_IP', false),

    // Log requests from crawlers and scripts. They are flagged is_bot and
    // filtered out of the ops list by default, so this only decides whether
    // the rows exist at all.
    'track_bots' => (bool) env('VISITOR_TRACK_BOTS', true),

    // Delete page-view rows (and visitors with none left) older than this.
    // Only acts when `php artisan visitors:prune` runs. 0 = keep forever.
    'retention_days' => (int) env('VISITOR_RETENTION_DAYS', 365),

    // Path prefixes never recorded. The public marketing pages are the point;
    // the admin console, APIs, widget assets and health checks are noise.
    'ignore_paths' => [
        'admin', 'api', 'c', 'dashboard', 'widget', 'storage', 'build',
        'livewire', 'up', 'telescope', 'horizon',
        'login', 'register', 'password', 'logout', 'email', 'connect',
        'profile', 'workspace', 'impersonate',
        'robots.txt', 'sitemap.xml', 'favicon.ico',
    ],

    /*
    |--------------------------------------------------------------------------
    | Geolocation
    |--------------------------------------------------------------------------
    |
    | Prefers a local MaxMind GeoLite2 file — no network call, nothing about
    | your visitors leaves the server, and it is fast enough to resolve during
    | the request. Download either edition (City gives city + coordinates,
    | Country gives country only) and point `database_path` at it.
    |
    | With no local file we fall back to a free JSON endpoint. Those are
    | rate-limited, so they are never called while a visitor is waiting: rows
    | are written `pending` and filled in by `visitors:geolocate` or the
    | "Resolve locations" button in the ops console.
    |
    */

    'geo' => [
        // Shares the path used by config/billing.php so one downloaded file
        // serves both features.
        'database_path' => env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),

        // {ip} is substituted. ipwho.is is HTTPS, keyless and free.
        'http_endpoint' => env('VISITOR_GEO_ENDPOINT', 'https://ipwho.is/{ip}'),
        'http_timeout'  => (int) env('VISITOR_GEO_TIMEOUT', 4),

        // Location-by-IP is stable; a day of caching saves a lot of lookups.
        'cache_ttl' => (int) env('VISITOR_GEO_CACHE_TTL', 86400),

        // Addresses resolved per batch when the operator clicks
        // "Resolve locations". Kept well under the free tiers' per-minute
        // ceilings so a click can't get the server blocked.
        'batch_size' => (int) env('VISITOR_GEO_BATCH', 25),
    ],

];
