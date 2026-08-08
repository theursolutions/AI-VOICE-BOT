<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434/api/generate'),
        'model' => env('OLLAMA_MODEL', 'llama3'),
    ],
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'python' => [
        'base_url'        => env('PYTHON_BASE_URL', 'http://127.0.0.1:8000'),
        'ws_url'          => env('PYTHON_WS_URL', 'ws://127.0.0.1:8000/ws/turn'),
        'internal_secret' => env('PYTHON_INTERNAL_SECRET'),
        'jwt_secret'      => env('PYTHON_JWT_SECRET'),
        'token_ttl'       => (int) env('PYTHON_TOKEN_TTL', 3600),
        // Control-plane reasoning model (text-to-SQL, source router, tool
        // picker). These tasks need a capable model even when chat runs on
        // a small local model. Empty = use the server's chat LLM_PROVIDER.
        // Only the question + table/column NAMES are sent here — never the
        // row data (chat + SQL execution stay on the configured providers).
        'reasoning_provider' => env('LLM_REASONING_PROVIDER', ''),
        // Optional model override for those reasoning calls. Lets chat stay on
        // a fast small model (e.g. llama3.2:1b) while SQL/routing run on a
        // capable local one (e.g. qwen2.5:7b) — all on Ollama, no cloud. Empty
        // = use the provider's configured default model.
        'reasoning_model' => env('LLM_REASONING_MODEL', ''),
    ],

    // Where auto-tabulated data-snapshot uploads (CSV/XLSX/JSON) are
    // materialised as SQL tables so they can be queried with text-to-SQL
    // instead of semantic RAG. Defaults to the app's own MySQL server, in
    // a dedicated database. One table per snapshot (snap_<project>_<source>).
    'snapshot_db' => [
        'host'     => env('SNAPSHOT_DB_HOST', env('DB_HOST', '127.0.0.1')),
        'port'     => (int) env('SNAPSHOT_DB_PORT', env('DB_PORT', 3306)),
        'name'     => env('SNAPSHOT_DB_NAME', 'ai_crm_snapshots'),
        'user'     => env('SNAPSHOT_DB_USER', env('DB_USERNAME', 'root')),
        'password' => env('SNAPSHOT_DB_PASSWORD', env('DB_PASSWORD', '')),
    ],

    // Meta WhatsApp/Instagram/Facebook config now lives in the
    // msd/meta-channels package (config/meta.php, key: `meta`).

    // Laravel Socialite — Facebook driver, used for the OAuth handshake of
    // channel onboarding (the per-provider scopes + redirect are set at
    // call time in ChannelOnboardController).
    'facebook' => [
        'client_id'     => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET', env('META_WHATSAPP_APP_SECRET')),
        'redirect'      => env('META_OAUTH_REDIRECT'),
    ],

    // Google sign-in (Socialite). Set GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET
    // in .env to enable the "Continue with Google" button. The redirect is
    // set per-request in SocialAuthController, so the value here is only a
    // fallback for `php artisan` contexts.
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    // Cloudflare Turnstile — CAPTCHA on the public forms (login, register,
    // forgot-password, landing-page contact). Verified server-side by
    // App\Rules\Turnstile; the widget alone stops nothing.
    //
    // Keys: Cloudflare dashboard -> Turnstile -> your site.
    // The SITE key is public (rendered in HTML). The SECRET key must only ever
    // live in .env. When both are blank, verification is SKIPPED so local dev
    // and existing deployments keep working until they're configured.
    'turnstile' => [
        'site_key'   => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

    'twilio' => [
        'account_sid'  => env('TWILIO_ACCOUNT_SID'),
        'auth_token'   => env('TWILIO_AUTH_TOKEN'),
        'phone_number' => env('TWILIO_PHONE_NUMBER'),
        // Public base URL where Twilio reaches our webhooks. For local
        // dev this is your ngrok URL; in production it's the deployed
        // Laravel hostname. NO trailing slash.
        'webhook_base' => env('TWILIO_WEBHOOK_BASE'),
        // Public URL Twilio uses to fetch <Play> audio (cached welcome
        // wavs etc). Same ngrok tunnel that maps to Python's :8002.
        // Defaults to PYTHON_WS_URL with scheme swapped so we don't
        // need a separate env var when it's the same tunnel.
        'python_public_url' => env('PYTHON_PUBLIC_URL'),
    ],

    // "Call me now" demo on the public landing page. When `enabled` is
    // false the endpoint logs the lead and returns a friendly message
    // instead of actually placing a call — safe to ship before the
    // outbound-call wiring is finished.
    'demo_call' => [
        'enabled' => env('DEMO_CALL_ENABLED', false),
    ],

    // Public landing page — embed the real production widget via the
    // standard loader.js snippet (same one customers paste on their
    // own sites). Set this to the project_api_key of whichever project
    // you want shown on the marketing site. Find the key on:
    //   /c/{client}/widget-settings → "Embed on customer's site" card.
    //
    // Legacy LANDING_WIDGET_URL is still honoured if set (manual iframe
    // fallback) — leave it unset to use the loader.js path.
    'landing' => [
        'demo_key'   => env('LANDING_DEMO_KEY', null),
        'widget_url' => env('LANDING_WIDGET_URL', null),
    ],

    // Base URL of the public widget folder (the directory that contains
    // webchat-app.php). Used by the widget-settings live preview to
    // iframe the real widget. Defaults to swapping /admin/public out of
    // app.url for /widget, which matches the local laragon layout.
    'widget' => [
        'base_url' => env('WIDGET_BASE_URL') ?:
            preg_replace('#/admin/?public/?$#', '/widget', rtrim((string) env('APP_URL', ''), '/')),
    ],

    'voice' => [
        'default_provider' => env('VOICE_DEFAULT_PROVIDER', 'coqui'),
        // Absolute directory where speaker reference WAVs are stored.
        // Python voice-engine reads files from this same path.
        'speakers_dir' => env('VOICE_ENGINE_SPEAKERS_DIR',
            base_path('../voice-engine/voice_outputs/speakers')),
        'default_language' => env('VOICE_DEFAULT_LANGUAGE', 'en'),
        // 17 languages XTTS-v2 supports
        'supported_languages' => [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'pl' => 'Polish',
            'tr' => 'Turkish',
            'ru' => 'Russian',
            'nl' => 'Dutch',
            'cs' => 'Czech',
            'ar' => 'Arabic',
            'zh-cn' => 'Chinese',
            'hu' => 'Hungarian',
            'ko' => 'Korean',
            'ja' => 'Japanese',
            'hi' => 'Hindi',
        ],
        'elevenlabs' => [
            'api_key' => env('ELEVENLABS_API_KEY'),
        ],
    ],

    'tenant' => [
        'host'        => env('TENANT_DB_HOST', '127.0.0.1'),
        'port'        => env('TENANT_DB_PORT', '3306'),
        'username'    => env('TENANT_DB_USERNAME', 'root'),
        'password'    => env('TENANT_DB_PASSWORD', ''),
        'name_prefix' => env('TENANT_DB_NAME_PREFIX', 'ai-crm-client-'),
    ],

    'hubspot' => [
        'client_id'     => env('HUBSPOT_CLIENT_ID'),
        'client_secret' => env('HUBSPOT_CLIENT_SECRET'),
        'redirect_uri'  => env('HUBSPOT_REDIRECT_URI', 'http://127.0.0.1:8001/oauth/hubspot/callback'),
    ],

    'salesforce' => [
        'client_id'     => env('SALESFORCE_CLIENT_ID'),
        'client_secret' => env('SALESFORCE_CLIENT_SECRET'),
        'redirect_uri'  => env('SALESFORCE_REDIRECT_URI', 'http://127.0.0.1:8001/oauth/salesforce/callback'),
        'login_host'    => env('SALESFORCE_LOGIN_HOST', 'https://login.salesforce.com'),
    ],

    'pipedrive' => [
        'client_id'     => env('PIPEDRIVE_CLIENT_ID'),
        'client_secret' => env('PIPEDRIVE_CLIENT_SECRET'),
        'redirect_uri'  => env('PIPEDRIVE_REDIRECT_URI', 'http://127.0.0.1:8001/oauth/pipedrive/callback'),
    ],

    'zoho' => [
        'client_id'     => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),
        'redirect_uri'  => env('ZOHO_REDIRECT_URI', 'http://127.0.0.1:8001/oauth/zoho/callback'),
        'auth_host'     => env('ZOHO_AUTH_HOST', 'https://accounts.zoho.com'),
    ],
];
