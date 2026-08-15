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
        // OAuth scopes requested per provider.
        //
        // Env-overridable on purpose: Meta renames and re-scopes permissions
        // regularly, and an "Invalid Scopes" error should be fixable by
        // editing .env and restarting rather than by shipping code.
        //
        // Two things to know when one of these is rejected:
        //
        //  1. A permission is only VALID for an app that has the matching
        //     PRODUCT added in the Meta dashboard. Requesting
        //     `instagram_manage_messages` from an app with no Instagram
        //     product returns "Invalid Scopes" even though the permission
        //     itself exists.
        //  2. Valid is not the same as granted. Every permission below needs
        //     Advanced Access via App Review before it works for anyone
        //     without a role on the app.
        //
        // Note there is no `email` or `public_profile` here. Socialite's
        // Facebook driver adds `email` by default and the controller uses
        // setScopes() to suppress it — a Business app using Facebook Login
        // for Business rejects it outright.
        'scopes' => [
            // pages_manage_metadata is what allows subscribing the app to a
            // Page's webhooks. Without it a Page connects and then silently
            // never delivers a message.
            // pages_user_profile is what returns a sender's NAME and PHOTO.
            // Without it the inbox can only show a 16-digit PSID, and the
            // failure is silent: Meta returns an empty profile rather than a
            // permissions error, so it reads as our bug rather than a missing
            // scope. It was absent here, which meant the profile lookup could
            // never have worked even once App Review granted it.
            'facebook_page' => env('META_SCOPES_FACEBOOK',
                'pages_show_list,pages_messaging,pages_manage_metadata,pages_read_engagement,pages_user_profile,business_management'),

            // Instagram messaging via Facebook Login goes through the linked
            // Page, so the Page permissions are required too. Requires the
            // Instagram product on the app.
            'instagram'     => env('META_SCOPES_INSTAGRAM',
                'instagram_basic,instagram_manage_messages,pages_show_list,pages_manage_metadata,business_management'),

            'whatsapp'      => env('META_SCOPES_WHATSAPP',
                'whatsapp_business_management,whatsapp_business_messaging,business_management'),
        ],
    ],

    /**
     * Instagram API with Instagram Login — a SEPARATE product from the
     * Facebook-Login path above, not a variant of it.
     *
     * Meta ships two ways to do Instagram messaging and they share almost
     * nothing:
     *
     *                     Facebook Login              Instagram Login
     *   authorize on      facebook.com                instagram.com
     *   exchange on       graph.facebook.com          api.instagram.com
     *   call Graph on     graph.facebook.com          graph.instagram.com
     *   credentials       META_APP_ID/SECRET          its own id/secret
     *   scope names       instagram_manage_messages   instagram_business_*
     *   requires          a linked Facebook Page      nothing
     *
     * The last row is why this exists: Instagram Login works for a business
     * account with no Facebook Page attached, which is most of them.
     *
     * The credentials are NOT the Meta app id/secret. Find them under
     * App dashboard → Instagram → API setup with Instagram login →
     * "Instagram app ID" / "Instagram app secret".
     */
    'instagram' => [
        'app_id'     => env('INSTAGRAM_APP_ID'),
        'app_secret' => env('INSTAGRAM_APP_SECRET'),

        'authorize_base' => env('INSTAGRAM_AUTHORIZE_BASE', 'https://www.instagram.com'),
        'api_base'       => env('INSTAGRAM_API_BASE', 'https://api.instagram.com'),
        'graph_base'     => env('INSTAGRAM_GRAPH_BASE', 'https://graph.instagram.com'),
        'graph_version'  => env('INSTAGRAM_GRAPH_VERSION', env('META_GRAPH_VERSION', 'v21.0')),

        // instagram_business_manage_comments is included because comment
        // ingestion rides the same subscription; drop it from env if the app
        // has not been approved for it, or consent will fail wholesale.
        'scopes' => env('INSTAGRAM_SCOPES',
            'instagram_business_basic,instagram_business_manage_messages,instagram_business_manage_comments'),

        // Webhook fields subscribed per IG account after onboarding.
        'webhook_fields' => env('INSTAGRAM_WEBHOOK_FIELDS', 'messages,messaging_postbacks'),
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
