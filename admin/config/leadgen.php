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

    // Languages outreach drafts can be written in (code => name the model is told).
    'languages' => [
        'en' => 'English', 'ar' => 'Arabic', 'ur' => 'Urdu', 'ur-Latn' => 'Roman Urdu (Urdu written in English letters)',
        'hi' => 'Hindi', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German', 'it' => 'Italian',
        'pt' => 'Portuguese', 'tr' => 'Turkish', 'nl' => 'Dutch', 'ru' => 'Russian', 'zh' => 'Chinese',
    ],

    // Default qualification criteria (an offer profile can re-weight or replace them).
    'criteria' => [
        'industry_fit'   => ['label' => 'Industry / category fits who we sell to', 'weight' => 35],
        'location_fit'   => ['label' => 'Location is one we serve',                'weight' => 20],
        'online_presence'=> ['label' => 'Has a working website / online presence', 'weight' => 15],
        'contactability' => ['label' => 'We have a verified way to reach them',    'weight' => 20],
        'signals'        => ['label' => 'Other signals from the profile',          'weight' => 10],
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
