# Subscription & Billing — Requirements Analysis (Phase 1)

**Repo:** `AI-CRM-AGENT` (product name: **Serve AI**)
**Branch inspected:** `development`
**Date of inspection:** 2026-08-09
**Status:** research only — **no production code was modified while producing this document.**

---

## 1. Existing architecture

### 1.1 Stack

| Layer | Technology | Evidence |
|---|---|---|
| Backend framework | **Laravel 10** (`laravel/framework: ^10.10`) | [admin/composer.json](admin/composer.json) |
| PHP | **^8.1** | [admin/composer.json](admin/composer.json) |
| Frontend | **Blade + Tailwind (Midone-style admin theme) + Vite**. No React/Vue/Inertia/Livewire. | [admin/vite.config.js](admin/vite.config.js), [admin/tailwind.config.js](admin/tailwind.config.js), `resources/views/**` |
| Auth | **Laravel Breeze** (`routes/auth.php`) + **Sanctum** (API tokens) + **Socialite** (Meta OAuth) + custom 6-digit **email OTP** verification | [admin/routes/auth.php](admin/routes/auth.php), [admin/app/Models/User.php:24](admin/app/Models/User.php#L24) |
| Queue | `database` driver | [admin/.env.example](admin/.env.example) |
| Cache | `file` driver by default (prod may swap to redis; redis config present) | [admin/.env.example](admin/.env.example) |
| Tests | **PHPUnit 10**, MySQL test schema `ai-crm-config-testing` (deliberately *not* sqlite) | [admin/phpunit.xml](admin/phpunit.xml) |
| Edge / infra | Caddy (TLS) → HAProxy → PHP container; Docker Compose; Contabo VPS | [deploy/production/Caddyfile](deploy/production/Caddyfile), [docker-compose.yml](docker-compose.yml) |
| Other services | `voice-engine` (Python FastAPI: STT/TTS/LLM), `widget` (embeddable chat), `query-agent` | [README.md](README.md) |

### 1.2 Multi-tenancy — three DB roles

| Connection | Database | Holds |
|---|---|---|
| `mysql` (master) | `ai-crm-config` | `users`, `clients`, `projects`, `project_users`, `roles`, `invitations`, `site_settings`, `audit_log`, `data_sources`, `agents`, `contact_leads`, `jobs`, **`payment_plans` (legacy, unused)** |
| `tenant` | `ai-crm-client-{project_id}` | `sessions`, `messages`, `leads`, `voices`, `summaries` |
| `client` | customer-supplied | the customer's own CRM (read-only AI SQL target) |

`TenantManager::useFor($project)` swaps connections at request time.

**Billing must live entirely on the `mysql` master connection.** Every new billing model must pin `protected $connection = 'mysql';` — this codebase already does that on `Client`, `Project`, `SiteSetting` precisely because tenant connection swapping otherwise breaks cross-connection relations.

### 1.3 Account model — who is the billing subject?

```
User ──< project_users >── Client (workspace / "agency")
                              └──< Project (each has its own tenant DB)
```

- **`Client`** = the workspace. It carries the slug used in every authenticated URL (`/c/{client:slug}/...`), owns `roles`, owns `projects`, and is what `EnsureActiveClient` resolves. [admin/app/Models/Client.php](admin/app/Models/Client.php)
- **`User`** can belong to several clients; `users.active_client_id` picks the current one.
- **`Project`** is the unit that consumes AI resources (its own tenant DB, its own agents/voices/channels/data sources).

> **Conclusion: the `Client` is the correct billing subject** (one subscription per workspace, seats and projects as plan limits). Billing on `User` would break multi-member workspaces; billing on `Project` would break the "one workspace, several projects" model that the plan limits should instead meter.

### 1.4 Roles / permissions (existing RBAC)

- `roles` table: per-client custom roles, `modules` JSON (`["*"]` for owner), `is_owner` flag. [admin/database/migrations/2026_06_27_000000_create_roles_and_member_roles.php](admin/database/migrations/2026_06_27_000000_create_roles_and_member_roles.php)
- Module registry: [admin/config/modules.php](admin/config/modules.php) — 17 keys (`dashboard`, `messages`, `leads`, `agents`, `channels`, `data_sources`, `flows`, `voices`, `telephony`, `team`, …), each mapping route-name prefixes to a module.
- `User::canModule()`, `User::allowedModules()`, `User::isOwnerOf()`. [admin/app/Models/User.php:130-187](admin/app/Models/User.php#L130-L187)

### 1.5 Gating middleware chain (already in place)

Workspace routes run through, in order:

```
auth → active.client → workspace.provisioned → module.enabled → module.access → email.verified.gate
```

[admin/routes/web.php:145-162](admin/routes/web.php#L145-L162), aliases in [admin/app/Http/Kernel.php:66-86](admin/app/Http/Kernel.php#L66-L86)

- `module.enabled` — **platform-wide** super-admin switchboard (`App\Support\Modules`, `site_settings["modules.disabled"]`). Off → branded "under development" page for everyone.
- `module.access` — **per-role** RBAC. Owners bypass.

**This is the exact seam where plan entitlements belong**: a third gate between the two.

### 1.6 Super Admin (Ops Console)

- Route group: `middleware(['auth','super-admin'])->prefix('admin')->name('ops.')`. [admin/routes/web.php:57-119](admin/routes/web.php#L57-L119)
- Gate: `users.is_super_admin` boolean → `IsSuperAdmin` middleware.
- Layout: `resources/views/layouts/ops.blade.php` + `ops-sidebar.blade.php` (sectioned: Analytics / Activity / Resources / Platform / Marketing Site).
- Established controller pattern (read → validate → mutate → `AuditLog::record()` → `back()->with('success')`): [admin/app/Http/Controllers/SuperAdmin/ModulesController.php](admin/app/Http/Controllers/SuperAdmin/ModulesController.php)
- **Impersonation** already exists — useful for debugging a customer's billing state.

### 1.7 Settings & audit primitives (reusable as-is)

- `SiteSetting` — JSON key/value store on master, in-request memoised, defaults in `config/site.php`. [admin/app/Models/SiteSetting.php](admin/app/Models/SiteSetting.php)
- `AuditLog::record($action, [...])` — used by every ops mutation.
- `App\Support\Hashid` — public ids are hashids in web/ops routes.
- `tva_setting()` / `tva_seo_all()` helpers in `app/Helpers/Function.php`.

### 1.8 Public marketing site

- Routes: `/`, `/about`, `/contact`, `/privacy`, `/terms`, `/refund-policy`, `/cookies`, `/security`. [admin/routes/web.php:28-35](admin/routes/web.php#L28-L35)
- **There is no `/pricing` route or page today.**
- All copy is DB-overridable via `site_settings` with defaults in [admin/config/site.php](admin/config/site.php); SEO (`sitemap_urls`, `page_views`, `noindex_paths`) is driven from the same file and edited at `/admin/seo` and `/admin/content`.
- Landing copy already promises billing behaviour: *"Start free — no credit card required"*, *"Upgrade when you're ready, cancel anytime"*, *"No long-term contracts, no lock-in"* (`content.faq6_a`, `content.cta_button`, `content.hero_meta1`). **The pricing model must honour these promises or the copy must change.**

---

## 2. Existing billing functionality

**There is none.** Concretely:

| Thing | State |
|---|---|
| Stripe SDK (`stripe/stripe-php`) | ❌ not installed |
| Laravel Cashier (`laravel/cashier`) | ❌ not installed |
| Any Stripe key / webhook / config | ❌ zero references anywhere in `app/`, `config/`, `routes/`, `resources/`, `.env.example` |
| Any payment provider at all | ❌ none |
| Subscription / invoice / customer tables | ❌ none |
| Plan entitlement checks in code | ❌ none — access is purely RBAC + module switchboard |
| Usage metering / quotas | ❌ none (no counters on messages, minutes, projects, seats) |
| `payment_plans` table + `PaymentPlan` model | ⚠️ **legacy dead code** — see below |

### 2.1 The legacy `payment_plans` table

[admin/database/migrations/2025_06_18_142608_create_payment_plans_table.php](admin/database/migrations/2025_06_18_142608_create_payment_plans_table.php)

```php
id, name, price (float), discount_type, discount, currency,
desctiption /* sic */, is_active enum('Yes','No'),
created_at/updated_at/deleted_at (integer unix timestamps)
```

`App\Models\PaymentPlan` has no `$fillable`, no relations, no scopes.
**Grep confirms zero usages** anywhere outside the model file and its own migration.

**Recommendation:** leave it alone during implementation (dropping it is a separate, reversible cleanup), build the new schema under new table names, and delete `payment_plans` + its model in a follow-up migration once the new system is live. It is not a viable base: `float` money, no interval, no Stripe columns, misspelt column, string enum booleans.

---

## 3. What can be reused

| Existing asset | Reuse for billing |
|---|---|
| `Client` model + `/c/{client:slug}` scoping | The **billable entity**. Attach `stripe_id`, `trial_ends_at`, `plan_id`. |
| Ops Console (`ops.*` routes, `layouts/ops`, `ops-sidebar`) | Super Admin → Billing → Plans / Prices / Subscriptions, as a new sidebar section. Zero new UI framework. |
| `ModulesController` pattern | Template for `PlansController` / `PlanPricesController` (validate → mutate → `AuditLog` → flash). |
| `AuditLog::record()` | Mandatory trail for every price change, Stripe sync, plan activation. |
| `SiteSetting` + `config/site.php` | Billing **configuration** that isn't a secret (trial length default, "require card for trial" toggle, FX provider choice, cache TTL, approximate-price disclaimer text). |
| `App\Support\Modules` + `config/modules.php` | Feature keys for plan entitlements map 1:1 onto module keys — a plan grants a subset of the same 17 keys the RBAC matrix already uses. |
| Middleware chain + `Kernel` aliases | Add `subscription.active` / `plan.feature` aliases into the same group. |
| `IsSuperAdmin` middleware | Authorises the whole billing admin area. |
| Impersonation | Support can inspect a customer's billing page as them. |
| Marketing site + SEO plumbing | `/pricing` slots into `config/site.php` (`page_views`, `sitemap_urls`) and the existing public layout/footer. |
| `layouts/public.blade.php`, `partials/seo-head` | Pricing page shell, no new design system needed. |
| Email layout `resources/views/emails/layout.blade.php` | Dunning / trial-ending / receipt emails reuse the branded shell. |
| `IntSoftDeletes` concern, `Hashid`, flash-message partials | Consistency with the rest of the admin. |
| Queue (`database`) + jobs pattern | Async Stripe sync, FX refresh, dunning emails. |

---

## 4. What needs to be built

### 4.1 Packages
- `stripe/stripe-php` (required either way)
- **`laravel/cashier` ^14** (Laravel 10 / PHP 8.1 compatible) — recommended, see §6.
- `geoip2/geoip2` **or** an HTTP geo provider (see §6.4)

### 4.2 Database (new tables, all on `mysql`)
`plans`, `plan_prices`, `features`, `plan_features`, `subscriptions` (+ `subscription_items` if Cashier), `stripe_events` (idempotency ledger), `usage_counters`, `trial_fingerprints` (abuse prevention), plus columns on `clients` (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`).

### 4.3 Services
`PlanService`, `PlanFeatureService`, `SubscriptionService`, `BillingService` (Stripe facade wrapper), `UsageLimitService`, `StripeSyncService`, `GeoLocationService` (driver-based), `ExchangeRateService` (driver-based), `PricingPresenter` (USD + approximate local, one place).

### 4.4 HTTP surface
- Public: `GET /pricing`, `POST /billing/checkout` (auth), Stripe success/cancel returns.
- Customer: `/c/{client}/billing` (plan, status, trial, invoices, portal link, upgrade/downgrade/cancel/resume).
- Ops: `/admin/billing/plans`, `/admin/billing/plans/{id}/prices`, `/admin/billing/features`, `/admin/billing/subscriptions`, `/admin/billing/stripe/sync`.
- Webhook: `POST /stripe/webhook` — **must be CSRF-exempt and outside `auth`**, signature-verified, idempotent.

### 4.5 Middleware
`EnsureSubscribed` (blocks on `canceled`/`unpaid`/`expired`), `EnsurePlanFeature` (entitlement per module key), inserted **between** `module.enabled` and `module.access`.

### 4.6 Tests
Everything listed in the brief's TESTING section, on the existing PHPUnit/MySQL harness, with the Stripe client mocked (no live API calls in CI).

---

## 5. Potential conflicts & risks (found during inspection)

| # | Risk | Detail | Mitigation |
|---|---|---|---|
| **C1** | **`DecodeHashids` mangles `*_id` request keys** | [admin/app/Http/Middleware/DecodeHashids.php](admin/app/Http/Middleware/DecodeHashids.php) rewrites **any** request key matching `id` / `*_id` / `*Id` through `Hashid::decode()` and replaces it with an integer when it decodes. A Super-Admin form posting `stripe_price_id=price_1Abc…` or a checkout POST carrying `price_id` is in scope. This has already caused a production bug once (string validation → 422). | Name Stripe-facing form fields **`stripe_price_ref` / `stripe_product_ref`**, and name the checkout selector **`plan_slug` + `interval`** (never `price_id`). Add a unit test asserting a Stripe price string survives the middleware. |
| **C2** | **Mixed timestamp conventions** | Legacy master tables (`clients`, `projects`, `roles`, `payment_plans`) store `created_at`/`updated_at`/`deleted_at` as **integer unix timestamps** with `public $timestamps = false` + `IntSoftDeletes`. Laravel/Cashier defaults are `datetime`. | New billing tables use **standard `datetime` timestamps** (Cashier requires it). Document the boundary. Do not add `IntSoftDeletes` to billing models. When adding `trial_ends_at` to `clients`, use `datetime` and cast explicitly — do **not** inherit the int convention. |
| **C3** | **`Client` has `public $timestamps = false`** | Cashier's `Billable` writes nothing to `clients` timestamps, so this is benign — but `Client::create()` in Cashier flows must not assume `updated_at`. | Keep `$timestamps = false`; set `updated_at = time()` manually where the existing code already does. |
| **C4** | **Webhook route vs. global middleware** | `web` group applies CSRF + session; `VerifyCsrfToken` must exempt `stripe/webhook`. `TrustProxies` is already correct (RFC-1918 only) so `$request->ip()` is the real client IP behind Caddy→HAProxy. | Register the webhook in `routes/api.php` or a dedicated group without `web`, plus `$except = ['stripe/webhook']` in `VerifyCsrfToken` as belt-and-braces. |
| **C5** | **No Cloudflare at the edge** | Caddy is the public TLS edge ([deploy/production/Caddyfile](deploy/production/Caddyfile) — comment explicitly says it does *not* trust inbound `X-Forwarded-For`). So **`CF-IPCountry` is not available**; country must come from our own lookup. | MaxMind GeoLite2 local DB (see §6.4). |
| **C6** | **Marketing copy already commits to a pricing posture** | `config/site.php` promises free tier, no credit card, cancel anytime, no contracts. | Either the approved pricing honours this, or `/admin/content` copy is updated in the same release. Flagged for the pricing decision. |
| **C7** | **Module switchboard can hide a paid feature** | A super-admin disabling a module platform-wide would silently remove a feature customers are paying for. | `EnsurePlanFeature` runs *after* `module.enabled`; add an ops warning when disabling a module that is a paid entitlement on any active plan. |
| **C8** | **`RefreshDatabase` drops the dev DB if `phpunit.xml` env is edited** | Documented in-file with a "this has already happened once" warning. | Billing tests must not touch the env block; they run on `ai-crm-config-testing`. |
| **C9** | **Trial abuse via multiple workspaces** | A `User` can create/join many `Client` workspaces; a naive per-client trial gives unlimited trials. | Trial eligibility keyed on a fingerprint set: owner `user_id`, normalised email, and Stripe customer/payment-method fingerprint. See §6.5. |
| **C10** | **Existing `payment_plans` name collision** | New table must not be called `payment_plans`. | Use `plans` / `plan_prices`. |
| **C11** | **Cache driver is `file`** | FX rates cached via `Cache::remember` work fine on `file`, but multi-replica containers each keep their own copy. Voice replicas are pinned to 1, app replicas may not be. | Acceptable (rates are display-only); prefer `redis` in prod, and persist the last good rate row in DB as the failure fallback so a cold cache never shows nothing. |
| **C12** | **`float` money must never reappear** | Legacy `payment_plans.price` is a float. | All money stored as **integer minor units** (`unit_amount` in cents), matching Stripe. |

---

## 6. Recommended architecture

### 6.1 Layering

```
Blade (pricing page / billing page / ops)
        │  reads only view-models
PricingPresenter ── ExchangeRateService ── GeoLocationService
        │                (drivers)             (drivers)
PlanService / PlanFeatureService / UsageLimitService
        │
SubscriptionService ──► BillingService (Stripe boundary) ──► Stripe API
        │                          ▲
   subscriptions table  ◄── StripeWebhookController (idempotent, signed)
```

Blade never touches Stripe, FX, or geo directly. Controllers never compute prices.

### 6.2 Data model shape (final columns settled in Phase 8)

- **`plans`** — `id, name, slug, description, type, is_active, is_public, is_featured, sort_order, badge, cta_label, trial_days, metadata(json), timestamps`
- **`plan_prices`** — `id, plan_id, interval(monthly|quarterly|annually), currency('usd'), unit_amount(int cents), stripe_price_id, stripe_product_id, is_active, effective_from, effective_to, metadata, timestamps` — **append-only in spirit**: changing a price creates a new row + a new Stripe Price and deactivates the old one; existing subscribers keep their `stripe_price_id`.
- **`features`** — `id, key, name, description, value_type(boolean|numeric|unlimited|text), unit, sort_order`
- **`plan_features`** — `plan_id, feature_id, value` (string; interpreted by `value_type`, `-1`/`null` = unlimited)
- **`subscriptions`** — Cashier-shaped: `client_id, type, stripe_id, stripe_status, stripe_price, quantity, trial_ends_at, ends_at, timestamps` + our `plan_id`, `plan_price_id`.
- **`stripe_events`** — `id, stripe_event_id (unique), type, payload, processed_at` → idempotency.
- **`usage_counters`** — `client_id, project_id?, metric, period_start, period_end, used` → quota enforcement.

Money is **integer cents, USD only**. There is no second currency column, by design.

### 6.3 Stripe integration — Cashier vs raw SDK

**Recommendation: `laravel/cashier` ^14**, wrapped by our own `BillingService`.

*Why:* it already solves customer creation, Checkout sessions, subscription state sync, trials, proration, the Customer Portal, invoice retrieval, and payment-method storage — and it is interval-agnostic (quarterly is just a Stripe Price with `interval=month, interval_count=3`, which Cashier passes through untouched).

*Why the wrapper:* the brief demands provider swappability and DB-driven plans. Cashier stays an implementation detail behind `BillingService`; application code calls `SubscriptionService`, never `$client->newSubscription(...)` directly.

*What we add on top of Cashier:* our own `stripe_events` idempotency ledger (Cashier does not dedupe), our own `plan_id`/`plan_price_id` columns on `subscriptions`, and our own webhook listener classes for the events Cashier ignores.

### 6.4 Geolocation — recommended implementation

Since there is **no existing IP-geolocation capability** in the app and no Cloudflare edge:

**Primary: MaxMind GeoLite2-Country local database** via `geoip2/geoip2`.
- Free (account + license key), ~6 MB `.mmdb`, refreshed weekly by a scheduled command.
- **Zero network latency and zero per-request cost** — decisive for a public pricing page.
- No customer IP leaves our infrastructure (privacy + GDPR posture, which the marketing site already sells).

**Fallback driver: HTTP provider** (`ipapi.co` / `ipinfo.io` free tier) behind the same interface, for local dev where the DB file is absent.

**Contract:**

```php
interface GeoLocationDriver {
    public function locate(string $ip): ?GeoResult;   // country, countryCode, currency, symbol
}
```

- Currency/symbol resolved from a **static country→currency map in config** (`config/billing.php`) — not from a paid API. This is stable data.
- Per-IP result cached (e.g. 24 h). Unknown IP / private IP / localhost → `null` → **USD-only display**. Never an error page.
- Overridable by an explicit `?country=` query param and a user preference cookie (VPN users, and it makes the feature testable).

### 6.5 Exchange rates — recommended implementation

```php
interface ExchangeRateProvider {
    public function ratesFor(string $base): array;   // ['PKR' => 283.41, ...]
}
```

- Config-selected driver (`config/billing.php: fx.provider`), so the provider is swappable without touching callers.
- **One scheduled job** (`billing:refresh-rates`, hourly or 6-hourly) fetches USD-base rates and writes them to an `exchange_rates` table **and** the cache. **The public pricing page never triggers an outbound HTTP call.**
- Three-level fallback: cache → last good DB row (with `fetched_at` shown as "rates updated X ago") → **USD only, no local line**. An FX outage must never break the pricing page or checkout.
- Display rounding is deliberately coarse (e.g. nearest 100 PKR) so an approximate figure never looks like a quote.
- **Conversion is presentation-only.** Checkout accepts `plan_slug` + `interval`; the server resolves the active `plan_prices` row and uses **its** `stripe_price_id`. No amount, currency, or converted value from the client is ever read.

### 6.6 Access-control seam

```
auth → active.client → workspace.provisioned
   → module.enabled          (platform switchboard — existing)
   → subscription.active     (NEW: free/trialing/active pass; past_due grace; canceled/unpaid → /billing)
   → plan.feature            (NEW: module key must be in the plan's entitlements)
   → module.access           (per-role RBAC — existing)
   → email.verified.gate
```

Subscription state is read from our `subscriptions` table, which is written **only** by the webhook handler and by explicit service calls — Stripe remains the authority.

### 6.7 Configuration split

| Where | What |
|---|---|
| `.env` (secrets) | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `MAXMIND_LICENSE_KEY`, `FX_API_KEY` |
| `config/billing.php` (code) | driver names, cache TTLs, country→currency map, rounding rules, grace-period length |
| `site_settings` (Super Admin, no deploy) | default trial days, "card required for trial" toggle, disclaimer copy, whether the local-currency line is shown at all |
| **`plans` / `plan_prices` / `plan_features` (Super Admin, no deploy)** | **every price, every limit, every feature, plan order, popular badge, trial length per plan** |

This satisfies the brief's final rule: changing $29 → $39, adding a plan, changing a limit, or changing trial duration is a Super Admin action with **no code change and no deploy** — only Stripe secrets stay in `.env`.

---

## 7. Open decisions that need your input

These are settled at the pricing-approval gate (Phase 7), not now:

1. **Billing subject** — confirm **Client (workspace)**, not User or Project. *(Analysis strongly recommends Client.)*
2. **Card required for the 7-day trial?** — see the recommendation and reasoning in `PRICING_RECOMMENDATION.md`.
3. **What the Free plan actually allows** — this interacts with GPU/voice cost per conversation, which is real money per free user.
4. **Whether the marketing copy's "no credit card required" promise stays** (C6).
5. **Seat-based vs flat pricing** — see the market analysis.

---

*Next: `COMPETITOR_PRICING_COMPARISON.md` (Phases 2–5) and `PRICING_RECOMMENDATION.md` (Phase 6).*
