<?php

/**
 * Meta channels configuration. Published/merged under the `meta` key by
 * MetaChannelsServiceProvider. All values come from env so the package
 * stays portable across host apps.
 */
return [
    // Meta app credentials + OAuth (Facebook Login / Embedded Signup) used
    // to onboard channels by redirecting the user to Facebook, letting them
    // pick pages/accounts, and importing them on the way back.
    'app' => [
        'id'            => env('META_APP_ID'),
        'secret'        => env('META_APP_SECRET', env('META_WHATSAPP_APP_SECRET')),
        'graph_base'    => env('META_GRAPH_BASE', 'https://graph.facebook.com'),
        'login_base'    => env('META_LOGIN_BASE', 'https://www.facebook.com'),
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        // Optional WhatsApp Embedded Signup config id (JS flow); not required
        // for the redirect-based onboarding implemented here.
        'wa_config_id'  => env('META_WA_CONFIG_ID'),
        // OAuth scopes requested per provider. Some require App Review.
        'scopes' => [
            'facebook_page' => 'pages_show_list,pages_messaging,business_management',
            'instagram'     => 'instagram_basic,instagram_manage_messages,pages_show_list,pages_messaging,business_management',
            'whatsapp'      => 'whatsapp_business_management,whatsapp_business_messaging,business_management',
        ],
    ],

    'whatsapp' => [
        'access_token'        => env('META_WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id'     => env('META_WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('META_WHATSAPP_BUSINESS_ACCOUNT_ID'),
        // App secret — verifies the X-Hub-Signature-256 header on webhooks.
        'app_secret'          => env('META_WHATSAPP_APP_SECRET'),
        // Random string also entered in the Meta webhook config (GET verify).
        'verify_token'        => env('META_WHATSAPP_VERIFY_TOKEN'),
        'graph_version'       => env('META_GRAPH_VERSION', 'v21.0'),
        'graph_base'          => env('META_GRAPH_BASE', 'https://graph.facebook.com'),
        // Single-number fallback: the project that owns the configured
        // number when no ChannelConnection row matches. Multi-number setups
        // leave this null and onboard each number on the Channels page.
        'project_id'          => env('META_WHATSAPP_PROJECT_ID'),
    ],
];
