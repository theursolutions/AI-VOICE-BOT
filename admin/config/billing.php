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
    /*
    |--------------------------------------------------------------------------
    | Safepay (Pakistan)
    |--------------------------------------------------------------------------
    |
    | Regulated by the State Bank of Pakistan. Hosted checkout: the customer is
    | handed to Safepay's page and pays by card, bank account, JazzCash or
    | Easypaisa there, so every method they support works without a form per
    | method and no card number reaches this server.
    |
    | THREE SECRETS, three jobs, and mixing them up fails in ways that look like
    | something else:
    |
    |   api_key         public, `sec_…`. Identifies the merchant on a session.
    |   v1_secret       server-side. Signs the redirect back from checkout.
    |   webhook_secret  server-side. Signs the X-SFPY-SIGNATURE webhook header.
    |
    | AMOUNTS ARE SENT IN RUPEES, not paisa. Confirmed empirically: a session
    | created with amount = 7500 renders as "Rs 7,500" on Safepay's own checkout
    | page, so the field is whole rupees. Our prices are stored in minor units,
    | so a Rs 75 plan is 7500 and must be divided by 100 on the way out.
    |
    | This was the single most dangerous line in the file, and it was wrong until
    | a sandbox page was actually read. Sending minor units would have charged
    | every Pakistani customer a hundred times the price — Rs 7,500 for a Rs 75
    | plan — and nothing in the code, the tests or the API response would have
    | said so: Safepay echoes back whatever number it is given.
    |
    | Safepay's docs describe amounts as "in the lowest denomination", which is
    | what made paisa look right. It is not true of this endpoint. Re-check with
    | safepay:doctor after any API change rather than trusting the prose.
    */
    /*
    |--------------------------------------------------------------------------
    | Paddle — international
    |--------------------------------------------------------------------------
    |
    | Paddle is a MERCHANT OF RECORD, not a payment processor. It sells to the
    | customer in its own name, then pays us — which means Paddle owns the sales
    | tax, VAT and GST obligation in every country it sells into, and owns the
    | invoice the customer receives. That is the whole reason to choose it over
    | a processor: a business with no foreign entity cannot register for VAT in
    | forty jurisdictions, and Paddle removes the need to.
    |
    | Two credentials, and they are NOT interchangeable:
    |
    |   api_key       server-side, secret. Creates transactions. Never rendered.
    |   client_token  public, safe in HTML. Opens the checkout overlay.
    |
    | Sending the API key to the browser would let anyone create transactions,
    | issue refunds and read every customer, so the two live in separate keys
    | rather than one "paddle key" that could be printed in the wrong place.
    |
    */
    'paddle' => [
        'api_key'        => env('PADDLE_API_KEY'),
        'client_token'   => env('PADDLE_CLIENT_TOKEN'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),

        // Sandbox unless explicitly told otherwise — the opposite default would
        // let a missing env var take real money.
        'sandbox' => (bool) env('PADDLE_SANDBOX', true),

        'base_url' => [
            'sandbox'    => env('PADDLE_SANDBOX_URL', 'https://sandbox-api.paddle.com'),
            'production' => env('PADDLE_PRODUCTION_URL', 'https://api.paddle.com'),
        ],

        // Paddle.js, which draws the overlay. Version-pinned in the path by
        // Paddle themselves; there is no other build.
        'js_url' => env('PADDLE_JS_URL', 'https://cdn.paddle.com/paddle/v2/paddle.js'),

        'timeout' => (int) env('PADDLE_TIMEOUT', 20),

        // Which tax rules Paddle applies as Merchant of Record.
        //
        // `saas` — hyphenated-style, NOT `software_as_a_service`, which is what
        // the API reference page implies and what Paddle rejects. The full set
        // Paddle accepts is: digital-goods, ebooks, implementation-services,
        // professional-services, saas, software-programming-services, standard,
        // software-programming-services, training-services, website-hosting.
        //
        // Configurable because this is the value most likely to be revised, and
        // a rejected category should be an env change rather than a deploy.
        'tax_category' => env('PADDLE_TAX_CATEGORY', 'saas'),

        /*
         | How long after Paddle signed a webhook we still accept it.
         |
         | Paddle's own documentation suggests five SECONDS. Do not copy that
         | number: a retry is re-sent with the ORIGINAL timestamp and signature,
         | so a five-second window rejects every retry Paddle makes — which is
         | precisely the delivery you most need to accept, because it only
         | happens when the first one failed. Wide enough to survive retries and
         | a clock a little out of step, narrow enough that a captured request
         | is not replayable tomorrow.
         */
        'signature_tolerance' => (int) env('PADDLE_SIGNATURE_TOLERANCE', 86400),
    ],

    'safepay' => [
        'api_key'        => env('SAFEPAY_API_KEY'),
        'v1_secret'      => env('SAFEPAY_V1_SECRET'),
        'webhook_secret' => env('SAFEPAY_WEBHOOK_SECRET'),

        // Sandbox unless explicitly told otherwise — the opposite default would
        // let a missing env var take real money.
        'sandbox' => (bool) env('SAFEPAY_SANDBOX', true),

        // The API and the CHECKOUT PAGE are on different hosts, which cost an
        // hour to discover: api.getsafepay.com serves the session endpoint but
        // 404s the checkout page, and the sandbox serves both. Deriving one from
        // the other produces a URL that 301s to the marketing site, which looks
        // like a bad tracker rather than a wrong host.
        'base_url' => [
            'sandbox'    => env('SAFEPAY_SANDBOX_URL', 'https://sandbox.api.getsafepay.com'),
            'production' => env('SAFEPAY_PRODUCTION_URL', 'https://api.getsafepay.com'),
        ],

        // Where the customer is sent. Taken from Safepay's own PHP SDK
        // (Base::CHECKOUT_ROUTE with SANDBOX_BASE_URL / PRODUCTION_BASE_URL)
        // rather than from a blog post — the hosts genuinely differ per
        // environment, and /embedded/ serves a page that looks right and cannot
        // complete a payment.
        'checkout_url' => [
            'sandbox'    => env('SAFEPAY_SANDBOX_CHECKOUT_URL', 'https://sandbox.api.getsafepay.com/checkout/pay'),
            'production' => env('SAFEPAY_CHECKOUT_URL', 'https://getsafepay.com/checkout/pay'),
        ],

        // /order/v1/init is what the hosted flow uses — confirmed against
        // Safepay's own SDK (Base::TRANSACTION_ENDPOINT), which sends exactly
        // client, amount, currency and environment.
        //
        // /order/payments/v3/ also works and returns a richer tracker carrying
        // intent, mode and a capabilities list, but it belongs to the ADVANCED
        // (embedded) integration where the merchant drives each next_action.
        // A tracker from it is not what the hosted checkout page expects.
        'paths' => [
            'session' => env('SAFEPAY_SESSION_PATH', '/order/v1/init'),
        ],

        // rupees | paisa — see the warning above.
        'amount_unit' => env('SAFEPAY_AMOUNT_UNIT', 'rupees'),

        'timeout' => (int) env('SAFEPAY_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | PayFast (Pakistan)
    |--------------------------------------------------------------------------
    |
    | Avanza Premier Payment Services, State Bank of Pakistan commercially
    | licensed since 2021. Used for customers paying in Pakistan; Stripe stays
    | for everyone else, and the two coexist rather than one replacing the other.
    |
    | HOSTED CHECKOUT, not the direct API. The customer is handed to PayFast's
    | own page and picks their method there — Visa, Mastercard, bank account,
    | JazzCash, Easypaisa — so every method they support is available without us
    | building a form per method, and no card ever touches this server. The
    | direct API would mean handling PAN entry and OTP ourselves for a narrower
    | set of methods.
    |
    | WHAT PAYFAST DOES NOT DO, which shapes everything downstream: there is no
    | saved-card token, no subscription object, no invoice API and no proration.
    | It authorises one payment at a time. A recurring plan on PayFast is
    | therefore OUR periodic charge against a fresh checkout, not a mandate the
    | gateway honours — see PayFastGateway.
    */
    /*
    |--------------------------------------------------------------------------
    | Renewal notices
    |--------------------------------------------------------------------------
    |
    | Only for customers on a gateway that CANNOT bill them itself. On Stripe a
    | renewal simply happens and reminding someone about it is noise; on a
    | Pakistani gateway with no reusable card token the customer has to act, so
    | silence means the subscription lapses.
    |
    | Three notices at widening gaps, on both email and WhatsApp. The first is
    | information, the second is a nudge, the third is the last chance — sending
    | all three the day before would be three copies of one message rather than
    | an escalation.
    |
    | WHATSAPP NEEDS AN APPROVED TEMPLATE. A renewal notice is always outside
    | Meta's 24-hour service window — the customer has not messaged us — so it
    | can only be sent as a template Meta has approved. With no template name
    | configured the WhatsApp leg is skipped and email still goes, which is the
    | right failure: a missing template must not silence the reminder entirely.
    */
    'renewals' => [
        'days_before' => [7, 3, 1],

        'whatsapp' => [
            'enabled'  => (bool) env('BILLING_RENEWAL_WHATSAPP', true),
            // Approved template name and locale, from WhatsApp Manager.
            'template' => env('BILLING_RENEWAL_TEMPLATE'),
            'language' => env('BILLING_RENEWAL_TEMPLATE_LANG', 'en'),
        ],

        'email' => [
            'enabled' => (bool) env('BILLING_RENEWAL_EMAIL', true),
        ],

        // How long a renewal payment link stays valid. Longer than the last
        // notice, so a customer who acts on the final reminder still has a
        // working link.
        'link_ttl_days' => (int) env('BILLING_RENEWAL_LINK_DAYS', 10),
    ],

    'payfast' => [
        'merchant_id'   => env('PAYFAST_MERCHANT_ID'),
        'merchant_name' => env('PAYFAST_MERCHANT_NAME', env('APP_NAME')),
        'secured_key'   => env('PAYFAST_SECURED_KEY'),

        // Live unless explicitly told otherwise. The opposite default would let
        // a missing env var take real money through a sandbox that silently
        // approves everything.
        'sandbox'       => (bool) env('PAYFAST_SANDBOX', false),

        'endpoints' => [
            'live' => [
                'token'    => env('PAYFAST_TOKEN_URL', 'https://ipg1.apps.net.pk/Ecommerce/api/Transaction/GetAccessToken'),
                'checkout' => env('PAYFAST_CHECKOUT_URL', 'https://ipg1.apps.net.pk/Ecommerce/api/Transaction/PostTransaction'),
            ],
            'sandbox' => [
                'token'    => env('PAYFAST_SANDBOX_TOKEN_URL', 'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/GetAccessToken'),
                'checkout' => env('PAYFAST_SANDBOX_CHECKOUT_URL', 'https://ipguat.apps.net.pk/Ecommerce/api/Transaction/PostTransaction'),
            ],
        ],

        // Their access token is short-lived and fetched per checkout. Cached
        // only long enough to serve one request rather than kept, because a
        // token reused past its life fails the checkout with an error the
        // customer cannot act on.
        'token_ttl' => (int) env('PAYFAST_TOKEN_TTL', 60),

        'timeout' => (int) env('PAYFAST_TIMEOUT', 20),
    ],

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Pin the API version so a Stripe-side upgrade can never silently
        // change payload shapes underneath the webhook handler.
        //
        // Defaults to the version the INSTALLED stripe-php was generated for,
        // rather than a hard-coded date. A hand-written date rots: pinning
        // 2024-06-20 against stripe-php v21 made Stripe reject every request
        // with "You are using an outdated API version", so not one Product
        // could be created. Tying it to the library means `composer update`
        // moves both together, and an explicit STRIPE_API_VERSION still wins.
        'api_version'    => env('STRIPE_API_VERSION') ?: \Stripe\Util\ApiVersion::CURRENT,

        // Seconds of tolerance for webhook timestamp skew.
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing email
    |--------------------------------------------------------------------------
    |
    | Receipts come from their own address rather than the global
    | MAIL_FROM_ADDRESS (no-reply@). A receipt is the one transactional message
    | customers actually reply to — "charge the other card", "we need a tax
    | number on this" — so it must arrive from somewhere a human reads.
    |
    | The sending domain still has to be verified with the mail provider
    | (Resend, here). `reply_to` is optional: set it when the from-address is
    | an alias that forwards elsewhere.
    |
    */
    'mail' => [
        'from_address' => env('BILLING_MAIL_FROM', 'billing@serveai.com.pk'),
        'from_name'    => env('BILLING_MAIL_FROM_NAME'),
        'reply_to'     => env('BILLING_MAIL_REPLY_TO'),

        // Master switch, so receipts can be silenced in staging without
        // touching the webhook handler.
        'receipts'     => (bool) env('BILLING_EMAIL_RECEIPTS', true),
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
        // The metered unit. One AI reply = one message, which is the unit our
        // cost is actually incurred in: a reply costs four LLM calls whether it
        // is the first of a session or the two hundredth.
        'messages'          => ['label' => 'AI messages', 'unit' => 'message'],
        // Recorded alongside, as a statistic. Plans are still SOLD in
        // conversations because that is the unit a customer can picture; whether
        // it also CAPS anything depends on a feature row claiming
        // metric_key = conversations, which is data, not code.
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
            //
            // api.country.is: free, no signup, no key, HTTPS, and it answers
            // with `{"ip":"…","country":"PK"}` and nothing else. Chosen over
            // ipapi.co — which this used to default to — because ipapi.co rate
            // limits almost immediately on the free tier, and a geo lookup that
            // returns "RateLimited" leaves every visitor seeing the wrong
            // currency with no indication why.
            //
            // ipwho.is is an equally free alternative if this one ever stops:
            //   GEOIP_HTTP_ENDPOINT=https://ipwho.is/{ip}
            //   GEOIP_HTTP_COUNTRY_PATH=country_code
            'endpoint'     => env('GEOIP_HTTP_ENDPOINT', 'https://api.country.is/{ip}'),
            'country_path' => env('GEOIP_HTTP_COUNTRY_PATH', 'country'),

            // Short on purpose. This sits in front of a page a buyer is looking
            // at, and a slow provider must cost them a moment, not a page load.
            // Failing means USD, which is always a correct page.
            'timeout'      => (int) env('GEOIP_HTTP_TIMEOUT', 2),
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
    /*
    |--------------------------------------------------------------------------
    | Smallest amount worth charging
    |--------------------------------------------------------------------------
    |
    | Per currency, in MINOR UNITS, because a single number cannot serve both:
    | 5000 is fifty dollars and fifty rupees, which are not remotely the same
    | decision. Getting that wrong once already refused two $5 seats as "too
    | little to charge for".
    |
    | Below this a top-up is not billed at all — the customer is told to buy it
    | next period instead. The floor exists because gateways reject trivial
    | amounts and because a fee on a twenty-cent charge costs more than the
    | charge collects.
    |
    */
    'minimum_charge' => [
        'USD' => (int) env('BILLING_MIN_CHARGE_USD', 50),      // $0.50
        'PKR' => (int) env('BILLING_MIN_CHARGE_PKR', 10000),   // Rs 100
        '*'   => (int) env('BILLING_MIN_CHARGE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local selling prices
    |--------------------------------------------------------------------------
    |
    | A price a customer is actually CHARGED in their own currency — which is a
    | different thing from the approximate figure `fx` renders beside a dollar
    | amount, and must never be confused with it. An FX rate moves daily; a
    | selling price does not, and a customer quoted Rs 21,500 must be charged
    | Rs 21,500 whether or not a rate provider answered this morning.
    |
    | So these rates are used ONCE, by `billing:local-prices`, to MINT rows in
    | `plan_prices` — after which the row is the price and this config is only
    | the recipe that produced it. Changing the rate here does not reprice
    | anything until the command is run again, and existing subscribers are
    | grandfathered by `changePrice()` as always.
    |
    | `step` keeps the result a number a human would quote: Rs 21,500, never
    | Rs 21,372. Rounded UP, because rounding a selling price down is a discount
    | nobody decided to give.
    |
    */
    'local_pricing' => [
        // Only currencies a gateway actually settles in belong here.
        'PKR' => [
            // Deliberately above the interbank rate: the gateway's fee, the
            // spread and a month of drift all come out of this number, and a
            // rate set exactly at spot means every sale is slightly short.
            'rate' => (float) env('BILLING_PKR_RATE', 300),
            'step' => (int) env('BILLING_PKR_STEP', 500),
        ],
    ],

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

        /*
         * KEEP THE WHOLE PURCHASE INSIDE THE PRODUCT.
         *
         * true  — every purchase runs through our own Stripe Elements form
         *         (BillingService::subscribeWithElements), and the hosted
         *         Checkout redirect plus the hosted Billing Portal are refused.
         *         The customer never leaves the app, so they never see a second
         *         brand mid-payment and never land somewhere our own session
         *         does not follow them.
         * false — the hosted paths are available again.
         *
         * The Elements path is not a reimplementation: it already existed
         * alongside the redirect and handles 3DS. This switch decides which of
         * the two the product actually uses, and it refuses at the ENDPOINT
         * rather than only hiding the buttons, because a hidden button in front
         * of a live POST route is not disabled.
         *
         * Retiring the portal costs the customer nothing they cannot do here:
         * cards are managed in-app, invoices are rendered by us, and billing
         * details have their own form. Anything genuinely Stripe-only — a
         * disputed charge, say — is an operator task, not a self-serve one.
         */
        'in_app_only' => (bool) env('BILLING_IN_APP_ONLY', true),

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

        // false — sections the plan doesn't include stay in the sidebar under a
        //         padlock, and clicking one raises the upgrade dialog. Nobody
        //         upgrades to reach a feature they have never seen, so this is
        //         the default: the same menu becomes the shortest sales pitch
        //         available, at no cost to a paying customer who sees no locks.
        // true  — they vanish, so the menu matches exactly what was bought.
        // Either way the route gate (EnsurePlanFeature) is unchanged; this only
        // controls visibility.
        'hide_locked_modules' => (bool) env('BILLING_HIDE_LOCKED_MODULES', false),
        'enterprise_cta_url'  => '/contact',
        'enterprise_from'     => 499,     // USD/mo "from" anchor on the page
    ],
];
