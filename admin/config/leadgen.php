<?php

/**
 * Lead Generation & Outreach (module `leadgen`). Plan: docs/LEADGEN_PLAN.md.
 *
 * Platform-level settings only. Per-tenant settings (offer profiles,
 * templates, mailboxes) live in the tenant DB; plan limits live in the
 * billing features matrix (Ops → Billing → Features).
 */
return [

    // The places worker: voice-engine image run as its own container so heavy
    // Overture scans never share a process with live calls. Empty = the
    // Overture source shows as "not configured" and no search can start.
    'places' => [
        'url'     => env('LEADGEN_PLACES_URL'),
        'timeout' => (int) env('LEADGEN_PLACES_TIMEOUT', 240),
    ],

    // Fetching prospects' own websites (enrichment). See App\Support\SafeFetcher.
    'fetch' => [
        'user_agent'      => env('LEADGEN_FETCH_UA', 'serveAI-LeadGen/1.0 (+' . rtrim((string) env('APP_URL', ''), '/') . '/bot)'),
        'timeout'         => (int) env('LEADGEN_FETCH_TIMEOUT', 10),
        'connect_timeout' => (int) env('LEADGEN_FETCH_CONNECT_TIMEOUT', 5),
        'max_bytes'       => (int) env('LEADGEN_FETCH_MAX_BYTES', 5 * 1024 * 1024),
        'max_redirects'   => 5,
        'respect_robots'  => (bool) env('LEADGEN_RESPECT_ROBOTS', true),
        'robots_cache_ttl'=> 3600,
    ],

];
