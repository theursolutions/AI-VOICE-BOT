<?php

/*
|--------------------------------------------------------------------------
| Billing, subscriptions, geolocation & currency display
|--------------------------------------------------------------------------
|
| INFRASTRUCTURE ONLY. Nothing in this file is a price, a plan, a limit or
| a feature — all of that is database-driven and edited by super-admins at
| /admin/billing/plans with no deploy. See SUPER_ADMIN_BILLING_GUIDE.md.
|
| What lives here: secrets (via env), driver selection, cache TTLs, and the
| static reference data (country -> currency) that never changes.
|
| Hard rule enforced throughout the codebase:
|   USD is the ONLY billing currency. Every `plan_prices.unit_amount` is
|   integer USD cents. Local-currency amounts are DISPLAY ONLY and are never
|   read back from the client, never sent to Stripe, and never persisted
|   against a subscription.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | Secrets only — never editable from the admin UI. `webhook_secret` is what
    | makes the webhook endpoint trustworthy; without it we reject every event.
    |
    | We use stripe/stripe-php directly rather than laravel/cashier. Cashier
    | assumes a User-shaped billable with datetime timestamps; our billable is
    | `clients`, which has `public $timestamps = false` and integer unix
    | timestamps (see SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §5 C2/C3). Owning
    | the ~400 lines of Stripe glue is cheaper than fighting those assumptions,
    | and it keeps Stripe behind BillingService so the provider stays swappable.
    |
    */
    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Pin the API version so a Stripe-side upgrade can never silently
        // change payload shapes underneath the webhook handler.
        'api_version'    => env('STRIPE_API_VERSION', '2024-06-20'),

        // Seconds of tolerance for webhook timestamp skew.
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing currency — authoritative, not a preference
    |--------------------------------------------------------------------------
    */
    'currency' => 'usd',

    /*
    |--------------------------------------------------------------------------
    | Billing intervals
    |--------------------------------------------------------------------------
    |
    | `supported` is what the schema and Stripe mapping understand.
    | `offered` is what the pricing-page toggle renders.
    |
    | Quarterly ships supported-but-not-offered on purpose: the approved offer
    | is monthly + annual, but the brief requires that adding an interval later
    | costs no code change. A super-admin adds a quarterly `plan_prices` row and
    | flips it into `offered` — no migration, no deploy.
    |
    */
    'intervals' => [
        'supported' => ['monthly', 'quarterly', 'annually'],
        'offered'   => ['monthly', 'annually'],

        // interval => [Stripe recurring.interval, Stripe recurring.interval_count]
        'stripe_map' => [
            'monthly'   => ['month', 1],
            'quarterly' => ['month', 3],
            'annually'  => ['year', 1],
        ],

        'labels' => [
            'monthly'   => 'Monthly',
            'quarterly' => 'Quarterly',
            'annually'  => 'Annual',
        ],

        // Suffix rendered after a price, e.g. "$19 /mo".
        'suffixes' => [
            'monthly'   => '/mo',
            'quarterly' => '/qtr',
            'annually'  => '/yr',
        ],

        // Months per interval — used to derive the "effective monthly" figure
        // and the savings percentage shown against the monthly price.
        'months' => [
            'monthly'   => 1,
            'quarterly' => 3,
            'annually'  => 12,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The free plan
    |--------------------------------------------------------------------------
    |
    | APPROVED MODEL: Free is a 7-day, no-card window — not a permanent tier.
    | The free week IS the trial; paid plans ship with trial_days = 0.
    |
    | These are fallbacks. `plans.free_window_days` overrides the duration
    | per-plan from the admin UI.
    |
    */
    'free' => [
        'slug'         => 'free',
        'window_days'  => (int) env('BILLING_FREE_WINDOW_DAYS', 7),

        // What happens on day 8 without payment.
        //   read_only     — widget stops answering; owner keeps login, data, export
        //   widget_only   — only the public widget is disabled, admin untouched
        //   lockout       — everything redirects to /billing
        'on_expiry'    => env('BILLING_FREE_ON_EXPIRY', 'read_only'),

        // Days after expiry before tenant data is purged. 0 disables purging.
        'purge_after_days' => (int) env('BILLING_FREE_PURGE_DAYS', 30),

        // Days-before-expiry on which to email the owner. Day 7 fires on the
        // last free day; day 23 is a week before the purge.
        'warn_before_expiry_days' => [3, 1],
        'warn_before_purge_days'  => [7, 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription lifecycle
    |--------------------------------------------------------------------------
    */
    'lifecycle' => [
        // Statuses that grant full application access.
        'active_statuses' => ['active', 'trialing'],

        // `past_due` keeps access for this many days after the first failed
        // payment so a bounced card doesn't instantly silence someone's phone
        // line. After that it degrades to the on_expiry behaviour above.
        'past_due_grace_days' => (int) env('BILLING_PAST_DUE_GRACE_DAYS', 7),

        // Statuses that revoke access immediately.
        'blocked_statuses' => ['canceled', 'unpaid', 'incomplete_expired'],

        // Where a blocked workspace is redirected.
        'redirect_route' => 'billing.index',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trial (currently unused — the free week replaces it)
    |--------------------------------------------------------------------------
    |
    | Kept wired so a super-admin can switch a paid-plan trial back on by
    | setting `plans.trial_days` > 0. No code change required.
    |
    */
    'trial' => [
        'default_days'             => 0,
        'requires_payment_method'  => true,

        // Stripe: what to do if the trial ends with no payment method on file.
        // 'cancel' | 'pause'
        'missing_payment_method_behavior' => 'cancel',

        // Fingerprints checked before granting a trial. See TrialFingerprint.
        'fingerprint_on' => ['user', 'email', 'card', 'domain'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage metering
    |--------------------------------------------------------------------------
    |
    | Metric keys are referenced by UsageLimitService and by `features.key`
    | for the numeric limits. Two distinct voice meters, deliberately:
    |
    |   telephony_minutes — real money (Twilio number rental + carrier per-min).
    |                       ZERO on the free plan.
    |   voice_messages    — a mic message in the web widget. Runs on local
    |                       Whisper + XTTS, near-zero marginal cost, so the
    |                       free plan can include it.
    |
    */
    'metrics' => [
        'conversations'     => ['label' => 'AI conversations', 'unit' => 'conversation'],
        'telephony_minutes' => ['label' => 'Phone call minutes', 'unit' => 'minute'],
        'voice_messages'    => ['label' => 'Widget voice messages', 'unit' => 'message'],
        'indexed_pages'     => ['label' => 'Indexed pages', 'unit' => 'page'],
        'storage_mb'        => ['label' => 'Storage', 'unit' => 'MB'],
    ],

    // Metrics that reset each billing period vs. those that measure a
    // standing total (storage doesn't "reset", it's a high-water mark).
    'metric_resets' => [
        'conversations'     => 'period',
        'telephony_minutes' => 'period',
        'voice_messages'    => 'period',
        'indexed_pages'     => 'absolute',
        'storage_mb'        => 'absolute',
    ],

    /*
    |--------------------------------------------------------------------------
    | Geolocation — country detection for DISPLAY pricing only
    |--------------------------------------------------------------------------
    |
    | Never gates access, never affects what Stripe charges. A total failure
    | here degrades to USD-only pricing, which is always correct.
    |
    | Driver order of preference:
    |   iplocator — DEFAULT. Delegates to App\Support\IpLocator, the lookup the
    |               visitor-analytics feature already uses: local GeoLite2
    |               .mmdb, handles both City and Country editions, cached per
    |               IP. config/visitors.php shares GEOIP_DATABASE_PATH with the
    |               path below, so ONE downloaded file serves both features.
    |               Our wrapper adds an offline guard — it will not let a
    |               pricing-page render block on IpLocator's HTTP fallback.
    |   http      — our own direct JSON lookup, for dev boxes with no .mmdb
    |               where you explicitly accept the latency.
    |   null      — always returns null; USD everywhere. The test default.
    |
    | NOTE: there is no Cloudflare in front of this app (Caddy is the public
    | TLS edge and deliberately does not trust inbound X-Forwarded-For), so
    | the CF-IPCountry shortcut is not available to us. See the Caddyfile.
    |
    */
    'geo' => [
        'driver' => env('GEOIP_DRIVER', 'iplocator'),

        'maxmind' => [
            // Shared with config/visitors.php `geo.database_path` — the same
            // env var, so one `php artisan geoip:update` serves both features.
            // City edition by default because it is a superset of Country and
            // the visitor analytics wants city-level data.
            'database_path' => env('GEOIP_DATABASE_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),
            'license_key'   => env('MAXMIND_LICENSE_KEY'),
            'account_id'    => env('MAXMIND_ACCOUNT_ID'),
            'edition'       => env('MAXMIND_EDITION', 'GeoLite2-City'),
        ],

        'http' => [
            // {ip} is substituted. Response is JSON; `country_path` is a dot
            // path to the ISO-3166 alpha-2 code within it.
            'endpoint'     => env('GEOIP_HTTP_ENDPOINT', 'https://ipapi.co/{ip}/json/'),
            'country_path' => env('GEOIP_HTTP_COUNTRY_PATH', 'country_code'),
            'timeout'      => (int) env('GEOIP_HTTP_TIMEOUT', 3),
        ],

        // Per-IP cache. Country-by-IP is extremely stable.
        'cache_ttl' => (int) env('GEOIP_CACHE_TTL', 86400),

        // Returned when detection fails, is skipped, or the IP is private.
        // null = show USD only (no local line at all). Set to e.g. 'PK' to
        // assume a home market instead.
        'fallback_country' => env('GEOIP_FALLBACK_COUNTRY') ?: null,

        // Lets a visitor override detection (VPN users) and makes the whole
        // feature testable with a query string: /pricing?country=PK
        'allow_query_override'  => true,
        'query_parameter'       => 'country',
        'cookie'                => 'serveai_country',
        'cookie_days'           => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange rates — approximate local pricing, DISPLAY ONLY
    |--------------------------------------------------------------------------
    |
    | The pricing page NEVER triggers an outbound HTTP call. A scheduled job
    | (billing:refresh-rates) fetches USD-base rates and writes them to both
    | the `exchange_rates` table and the cache. Read path is:
    |
    |     cache -> last good DB row -> null (USD only, no local line)
    |
    | so an FX provider outage can never break the page or block a checkout.
    |
    */
    'fx' => [
        'driver' => env('FX_DRIVER', 'erapi'),

        'drivers' => [
            // open.er-api.com — free, no API key, USD base, ~daily updates.
            'erapi' => [
                'endpoint'   => env('FX_ERAPI_ENDPOINT', 'https://open.er-api.com/v6/latest/USD'),
                'rates_path' => 'rates',
                'api_key'    => null,
                'timeout'    => 8,
            ],

            // exchangerate.host — free tier, key via ?access_key=
            'exchangerate_host' => [
                'endpoint'   => 'https://api.exchangerate.host/live?source=USD',
                'rates_path' => 'quotes',
                'api_key'    => env('FX_API_KEY'),
                'timeout'    => 8,
                // This provider prefixes pairs: USDPKR instead of PKR.
                'key_prefix' => 'USD',
            ],

            // openexchangerates.org — free tier 1,000 calls/month.
            'openexchangerates' => [
                'endpoint'   => 'https://openexchangerates.org/api/latest.json',
                'rates_path' => 'rates',
                'api_key'    => env('FX_API_KEY'),
                'timeout'    => 8,
            ],
        ],

        // How long a fetched rate stays in cache. Longer than the refresh
        // interval so a failed refresh doesn't create a gap.
        'cache_ttl' => (int) env('FX_CACHE_TTL', 21600),   // 6 hours

        // A stored rate older than this is considered too stale to display.
        // We fall back to USD-only rather than show a misleading number.
        'max_age_hours' => (int) env('FX_MAX_AGE_HOURS', 72),

        // Master switch for the whole local-currency line.
        'enabled' => (bool) env('FX_ENABLED', true),

        'disclaimer' => 'Prices are charged in USD. Local currency amounts are approximate.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Display rounding for converted amounts
    |--------------------------------------------------------------------------
    |
    | Approximate prices must LOOK approximate. PKR 5,432.19 reads like a
    | quote; "≈ PKR 5,400" reads like an estimate — which is what it is.
    |
    | Rule: round to a "nice" step scaled to the magnitude of the number.
    |
    | Steps are tuned so the rounding error stays under ~1.5% at every
    | magnitude. Coarser steps look tidier but start overstating the price,
    | and reading higher than we actually charge is the one direction an
    | approximation must not err in.
    |
    */
    'rounding' => [
        // [threshold => step]. First matching threshold wins (ascending).
        'steps' => [
            10      => 1,
            100     => 5,
            1000    => 10,
            10000   => 100,
            100000  => 500,
            1000000 => 5000,
        ],
        'default_step' => 50000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency presentation
    |--------------------------------------------------------------------------
    */
    'currencies' => [
        'USD' => ['symbol' => '$',    'decimals' => 2, 'position' => 'before'],
        'PKR' => ['symbol' => 'Rs',   'decimals' => 0, 'position' => 'before'],
        'INR' => ['symbol' => '₹',    'decimals' => 0, 'position' => 'before'],
        'GBP' => ['symbol' => '£',    'decimals' => 0, 'position' => 'before'],
        'EUR' => ['symbol' => '€',    'decimals' => 0, 'position' => 'before'],
        'AED' => ['symbol' => 'AED ', 'decimals' => 0, 'position' => 'before'],
        'SAR' => ['symbol' => 'SAR ', 'decimals' => 0, 'position' => 'before'],
        'QAR' => ['symbol' => 'QAR ', 'decimals' => 0, 'position' => 'before'],
        'KWD' => ['symbol' => 'KWD ', 'decimals' => 2, 'position' => 'before'],
        'BHD' => ['symbol' => 'BHD ', 'decimals' => 2, 'position' => 'before'],
        'OMR' => ['symbol' => 'OMR ', 'decimals' => 2, 'position' => 'before'],
        'CAD' => ['symbol' => 'C$',   'decimals' => 0, 'position' => 'before'],
        'AUD' => ['symbol' => 'A$',   'decimals' => 0, 'position' => 'before'],
        'NZD' => ['symbol' => 'NZ$',  'decimals' => 0, 'position' => 'before'],
        'SGD' => ['symbol' => 'S$',   'decimals' => 0, 'position' => 'before'],
        'MYR' => ['symbol' => 'RM',   'decimals' => 0, 'position' => 'before'],
        'IDR' => ['symbol' => 'Rp',   'decimals' => 0, 'position' => 'before'],
        'PHP' => ['symbol' => '₱',    'decimals' => 0, 'position' => 'before'],
        'THB' => ['symbol' => '฿',    'decimals' => 0, 'position' => 'before'],
        'VND' => ['symbol' => '₫',    'decimals' => 0, 'position' => 'after'],
        'BDT' => ['symbol' => '৳',    'decimals' => 0, 'position' => 'before'],
        'LKR' => ['symbol' => 'Rs',   'decimals' => 0, 'position' => 'before'],
        'NPR' => ['symbol' => 'Rs',   'decimals' => 0, 'position' => 'before'],
        'AFN' => ['symbol' => '؋',    'decimals' => 0, 'position' => 'before'],
        'IRR' => ['symbol' => '﷼',    'decimals' => 0, 'position' => 'before'],
        'TRY' => ['symbol' => '₺',    'decimals' => 0, 'position' => 'before'],
        'EGP' => ['symbol' => 'E£',   'decimals' => 0, 'position' => 'before'],
        'ZAR' => ['symbol' => 'R',    'decimals' => 0, 'position' => 'before'],
        'NGN' => ['symbol' => '₦',    'decimals' => 0, 'position' => 'before'],
        'KES' => ['symbol' => 'KSh',  'decimals' => 0, 'position' => 'before'],
        'GHS' => ['symbol' => 'GH₵',  'decimals' => 0, 'position' => 'before'],
        'MAD' => ['symbol' => 'MAD ', 'decimals' => 0, 'position' => 'before'],
        'JPY' => ['symbol' => '¥',    'decimals' => 0, 'position' => 'before'],
        'CNY' => ['symbol' => '¥',    'decimals' => 0, 'position' => 'before'],
        'KRW' => ['symbol' => '₩',    'decimals' => 0, 'position' => 'before'],
        'HKD' => ['symbol' => 'HK$',  'decimals' => 0, 'position' => 'before'],
        'TWD' => ['symbol' => 'NT$',  'decimals' => 0, 'position' => 'before'],
        'CHF' => ['symbol' => 'CHF ', 'decimals' => 0, 'position' => 'before'],
        'SEK' => ['symbol' => 'kr ',  'decimals' => 0, 'position' => 'before'],
        'NOK' => ['symbol' => 'kr ',  'decimals' => 0, 'position' => 'before'],
        'DKK' => ['symbol' => 'kr ',  'decimals' => 0, 'position' => 'before'],
        'PLN' => ['symbol' => 'zł ',  'decimals' => 0, 'position' => 'before'],
        'CZK' => ['symbol' => 'Kč ',  'decimals' => 0, 'position' => 'before'],
        'RON' => ['symbol' => 'lei ', 'decimals' => 0, 'position' => 'before'],
        'HUF' => ['symbol' => 'Ft ',  'decimals' => 0, 'position' => 'before'],
        'UAH' => ['symbol' => '₴',    'decimals' => 0, 'position' => 'before'],
        'RUB' => ['symbol' => '₽',    'decimals' => 0, 'position' => 'before'],
        'BRL' => ['symbol' => 'R$',   'decimals' => 0, 'position' => 'before'],
        'MXN' => ['symbol' => 'MX$',  'decimals' => 0, 'position' => 'before'],
        'ARS' => ['symbol' => 'AR$',  'decimals' => 0, 'position' => 'before'],
        'CLP' => ['symbol' => 'CL$',  'decimals' => 0, 'position' => 'before'],
        'COP' => ['symbol' => 'CO$',  'decimals' => 0, 'position' => 'before'],
        'ILS' => ['symbol' => '₪',    'decimals' => 0, 'position' => 'before'],
        'JOD' => ['symbol' => 'JOD ', 'decimals' => 2, 'position' => 'before'],
        'IQD' => ['symbol' => 'IQD ', 'decimals' => 0, 'position' => 'before'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Country (ISO-3166 alpha-2) -> currency (ISO-4217)
    |--------------------------------------------------------------------------
    |
    | Static reference data, deliberately not an API call. Anything absent
    | falls through to USD-only display.
    |
    */
    'country_currency' => [
        // South & Central Asia
        'PK' => 'PKR', 'IN' => 'INR', 'BD' => 'BDT', 'LK' => 'LKR', 'NP' => 'NPR',
        'AF' => 'AFN', 'BT' => 'INR', 'MV' => 'USD',

        // Gulf & Middle East
        'AE' => 'AED', 'SA' => 'SAR', 'QA' => 'QAR', 'KW' => 'KWD', 'BH' => 'BHD',
        'OM' => 'OMR', 'JO' => 'JOD', 'IQ' => 'IQD', 'IR' => 'IRR', 'IL' => 'ILS',
        'LB' => 'USD', 'TR' => 'TRY', 'EG' => 'EGP',

        // Eurozone
        'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR',
        'BE' => 'EUR', 'AT' => 'EUR', 'PT' => 'EUR', 'IE' => 'EUR', 'FI' => 'EUR',
        'GR' => 'EUR', 'SK' => 'EUR', 'SI' => 'EUR', 'LT' => 'EUR', 'LV' => 'EUR',
        'EE' => 'EUR', 'LU' => 'EUR', 'MT' => 'EUR', 'CY' => 'EUR', 'HR' => 'EUR',

        // Rest of Europe
        'GB' => 'GBP', 'CH' => 'CHF', 'SE' => 'SEK', 'NO' => 'NOK', 'DK' => 'DKK',
        'PL' => 'PLN', 'CZ' => 'CZK', 'RO' => 'RON', 'HU' => 'HUF', 'BG' => 'EUR',
        'UA' => 'UAH', 'RU' => 'RUB',

        // Americas
        'US' => 'USD', 'CA' => 'CAD', 'MX' => 'MXN', 'BR' => 'BRL', 'AR' => 'ARS',
        'CL' => 'CLP', 'CO' => 'COP', 'PE' => 'USD', 'EC' => 'USD', 'PA' => 'USD',

        // Asia-Pacific
        'AU' => 'AUD', 'NZ' => 'NZD', 'SG' => 'SGD', 'MY' => 'MYR', 'ID' => 'IDR',
        'PH' => 'PHP', 'TH' => 'THB', 'VN' => 'VND', 'JP' => 'JPY', 'CN' => 'CNY',
        'KR' => 'KRW', 'HK' => 'HKD', 'TW' => 'TWD',

        // Africa
        'ZA' => 'ZAR', 'NG' => 'NGN', 'KE' => 'KES', 'GH' => 'GHS', 'MA' => 'MAD',
        'TZ' => 'USD', 'UG' => 'USD', 'ET' => 'USD',
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    */
    'checkout' => [
        /*
         * MASTER SWITCH for buying anything.
         *
         * false — plan cards render as INFORMATION ONLY: prices, limits and
         *         features all show, but paid plans get NO call to action at
         *         all (no button, no placeholder) and the checkout endpoints
         *         refuse. Free signup (/register) and the Enterprise
         *         "Talk to us" link keep working, because neither takes money.
         * true  — fully live.
         *
         * Gating the SERVER as well as the buttons is deliberate: a hidden
         * button in front of a working public POST endpoint is not disabled,
         * it is just less discoverable, and a stray request could take a real
         * payment before you are ready to support one.
         *
         * Set BILLING_CHECKOUT_ENABLED=true in your LOCAL .env to test the
         * whole flow while production stays informational.
         */
        'enabled' => (bool) env('BILLING_CHECKOUT_ENABLED', false),

        'success_route' => 'billing.checkout.success',
        'cancel_route'  => 'billing.checkout.cancel',

        'allow_promotion_codes' => true,
        'collect_billing_address' => 'auto',      // 'auto' | 'required'
        'automatic_tax'         => (bool) env('STRIPE_AUTOMATIC_TAX', false),

        // Proration when changing plan mid-cycle.
        //   create_prorations — Stripe default; credits/debits the difference
        //   always_invoice    — bills the difference immediately
        //   none              — no proration at all
        'proration_behavior' => env('BILLING_PRORATION', 'create_prorations'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Super-admin editable settings (site_settings overrides)
    |--------------------------------------------------------------------------
    |
    | Read via tva_setting('billing.<key>'); these are the fallbacks.
    |
    */
    'settings' => [
        'show_local_currency' => true,
        'pricing_page_enabled' => true,

        // true  — sections the plan doesn't include vanish from the sidebar, so
        //         the menu matches what the customer bought (no dead ends).
        // false — they stay visible and clicking lands on the 402 upsell page,
        //         which advertises the feature instead of hiding it.
        // Either way the route gate (EnsurePlanFeature) is unchanged; this only
        // controls visibility.
        'hide_locked_modules' => (bool) env('BILLING_HIDE_LOCKED_MODULES', true),
        'enterprise_cta_url'  => '/contact',
        'enterprise_from'     => 499,     // USD/mo "from" anchor on the page
    ],
];
