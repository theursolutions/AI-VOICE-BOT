# Subscription & Billing — Implementation Reference

**Product:** Serve AI (`AI-CRM-AGENT`)
**Built:** 2026-08-11 · Laravel 10 / PHP 8.1+ · `stripe/stripe-php ^21`
**Status:** complete and green — **165 tests, 673 assertions passing** (`php artisan test`)

Companion documents:
- [SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md](SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md) — pre-build audit of the existing app
- [COMPETITOR_PRICING_COMPARISON.md](COMPETITOR_PRICING_COMPARISON.md) — 17 competitors, market analysis
- [PRICING_RECOMMENDATION.md](PRICING_RECOMMENDATION.md) — the approved pricing and its reasoning
- [SUPER_ADMIN_BILLING_GUIDE.md](SUPER_ADMIN_BILLING_GUIDE.md) — how to run pricing day to day, no code

---

## 1. The five rules everything else follows

1. **USD is the only billing currency.** Every amount is an integer count of USD cents. Local-currency figures are display-only, never persisted against a subscription, never sent to Stripe, never read back from a client.
2. **The browser submits `plan` (a slug) and `interval` (a name) — nothing else.** Amounts and Stripe price references are resolved server-side by `PlanService::resolvePrice()`. There is no request field carrying money anywhere in the system, so price tampering is structurally impossible rather than merely validated against.
3. **Stripe is authoritative for paid state.** Only the signature-verified webhook writes it. The Checkout success page is a reassurance page, not an activation.
4. **Prices are append-only.** Changing an amount creates a new `plan_prices` row and a new Stripe Price and archives the old one. Existing subscribers are grandfathered automatically.
5. **Degrade, never lock out.** A lapsed free week or exhausted dunning makes the workspace read-only — login, data and export keep working. Billing routes are always reachable.

---

## 2. Architecture

```
Blade (pricing page · billing page · ops console)
   │ receives finished strings only ("$59", "≈ Rs 16,500", "Save 17%")
PricingPresenter ─── ExchangeRateService ─── GeoLocationService
   │                     (drivers)              (drivers → IpLocator)
PlanService · PlanFeatureService · UsageLimitService
   │
SubscriptionService ────► BillingService ────► Stripe API
   │  (state machine)      (the only egress)
   │                              ▲
subscriptions table  ◄──── StripeWebhookController (signed, idempotent)
```

No Blade template and no controller touches Stripe, FX or geolocation directly.

### Why not Laravel Cashier

Cashier assumes a `User`-shaped billable with standard datetime timestamps. Our billable is `clients`, a legacy table with `public $timestamps = false` and **integer unix** `created_at`/`updated_at`/`deleted_at`. Fighting that would have cost more than the ~400 lines of Stripe glue in `BillingService` + `StripeSyncService`, and keeping Stripe behind one class is what makes the provider swappable — which Cashier could never be, since it is Stripe-only by definition. We also needed our own idempotency ledger regardless (Cashier does not dedupe events).

### The billable is the Client (workspace)

Not the User (they can belong to several workspaces) and not the Project (a workspace holds several). The `Client` is the only boundary that already matches access control, roles and the `/c/{client:slug}` URL scheme. Billing behaviour lives in `App\Models\Concerns\Billable`, used by `App\Models\Client`.

---

## 3. Database

Eleven migrations, all `2026_08_11_1000xx`, all on the master `mysql` connection. Every billing model pins `protected $connection = 'mysql'` — `TenantManager` swaps the `tenant` and `client` connections per request and billing must never follow it.

| Table | Purpose | Notes |
|---|---|---|
| `plans` | name, slug, type, badge, CTA, order, visibility, `trial_days`, `free_window_days` | carries **no price**; soft-deletes |
| `plan_prices` | one row per plan × interval; `unit_amount` (int cents), `stripe_price_ref` | **append-only in spirit** — see §7 |
| `features` | the catalogue: `value_type`, `unit`, `module_key`, `metric_key`, `group` | definition only |
| `plan_features` | the value a plan grants for a feature | **a missing row means NOT granted** |
| `subscriptions` | one per workspace; covers the free window *and* paid state | `status` is our superset of Stripe's |
| `stripe_events` | idempotency ledger; `stripe_event_id` UNIQUE | the unique index *is* the guarantee |
| `usage_counters` | metered usage per workspace/metric/period | unique on (client, metric, period_start) |
| `trial_fingerprints` | free-window abuse control | values stored **sha256-hashed** |
| `exchange_rates` | USD→X, display only | the durable fallback tier |
| `clients` (+10 cols) | `stripe_customer_ref`, `pm_*`, `billing_status`, `access_state` | derived cache, see below |
| *backfill* | grandfathers pre-existing workspaces | see §9 |

**Timestamp convention:** new billing tables use standard Laravel `datetime` timestamps, deliberately *not* the legacy integer-unix convention on `clients`/`projects`/`roles`. The columns added to `clients` are datetimes with explicit casts. This boundary is intentional and documented in the migrations.

**`clients.billing_status` / `clients.access_state` are a derived cache.** `subscriptions` is authoritative. They exist so the high-volume inbound widget/API path can gate on one already-loaded column instead of joining on every customer message. `SubscriptionService::syncClientCache()` is the only writer.

**Money is never a float.** `PlanPricesController::toCents()` converts via string formatting, because `(int) (19.99 * 100)` truncates to `1998` — a cent in our favour is a billing dispute. Covered by a test.

---

## 4. Plans, prices, features

### Feature philosophy (three buckets)

| Bucket | How it's sold | Examples |
|---|---|---|
| **1 — never gated** | on every plan, including Free | voice cloning, 13 languages, lead capture, web widget, RAG, transcripts |
| **2 — metered** | same feature, different volume | conversations, telephony minutes, voice messages, seats, projects, storage, history |
| **3 — gated on/off** | exactly **eight** switches | telephony · Meta channels · flow builder · team roles · API · DB connector + per-table AI access control · remove branding · white-label/BYO-LLM/audit export |

Bucket 3 is deliberately small: gating anything in bucket 1 makes the entry plan feel like a demo, which is the mistake the competitor research flagged repeatedly (a rival charging +$29/mo for AI on an AI product, another $99/mo for one extra language).

### Two separate voice meters

| Metric | What it is | Free plan |
|---|---|---|
| `telephony_minutes` | a phone call — Twilio number rental + carrier per-minute | **0** |
| `voice_messages` | a mic message in the web widget, local Whisper + XTTS | **50** |

Collapsing these into one "voice" number would have forced Free to choose between having no microphone at all and giving away phone calls. This split is why a voice-capable product can offer a free tier at all — no competitor in the research does.

### Feature wiring

- `features.module_key` → a key from `config/modules.php`. `EnsurePlanFeature` gates the matching routes, so plan entitlements and the RBAC roles matrix share one vocabulary and can't drift.
- `features.metric_key` → a key from `config/billing.php` `metrics`. `UsageLimitService` enforces the numeric value as a quota.
- Neither set → display-only marketing copy.

**`null` means unlimited, `0` means none.** Distinguishing them matters: `if (!$limit) deny()` would lock out every unlimited plan. Enforced by a test.

---

## 5. The free window (approved model)

Free is a **7-day, no-card window** — not a permanent tier. The free week *is* the trial; paid plans ship with `trial_days = 0`.

| | Free window | (Optional) paid trial |
|---|---|---|
| Duration | `plans.free_window_days` (7); **NULL = permanent** | `plans.trial_days` |
| Stripe | **no customer, no subscription** | real subscription, `trialing` |
| Card | none | `trial_requires_payment_method` |
| Eligibility | once per business (§6) | same |

Started by `SubscriptionService::startFreeWindow()`, called inside the registration transaction in `RegisteredUserController`. It returns `null` (never throws) when no free plan is configured, so a fresh install can always register.

**Access is decided live**, by `Subscription::grantsAccess()` comparing `free_ends_at` to the clock on every request. `billing:lifecycle` is a janitor, not a gate — if it doesn't run, nobody gets access they shouldn't; only the status column, emails and purge queue lag.

### On expiry (day 8)

`read_only` (configurable: `widget_only`, `lockout`):
- reads pass, flagged via `billing_read_only` so the layout can banner it
- writes redirect to `/billing` (or `402` for JSON)
- the widget stops answering end customers
- data kept 30 days, with warnings at 7 and 1 days before purge
- **`/billing`, `/setup`, `/profile`, `/workspace`, logout always stay open** — a paywall you cannot pay through is just an outage

`billing:lifecycle` **reports** the purge queue but does not delete tenant data. Deleting a tenant database is irreversible and destroys a customer's entire conversation history; it needs a human decision through the existing audited, recoverable Ops → Clients flow, not an unattended nightly cron.

---

## 6. Abuse control

A user can create unlimited workspaces, so a per-workspace free week would be trivially farmed. `trial_fingerprints` records four identities, all sha256-hashed:

1. owner `user_id`
2. **normalised email** — lowercased, `+tags` stripped, dots removed **for Google-family domains only** (dots are significant elsewhere; merging them would block distinct people)
3. **Stripe PaymentMethod fingerprint** — stable for the same physical card across different Customers. The strongest signal, and the main reason collecting a card is worth doing. Captured from `payment_method.attached`.
4. business website domain

A hit never blocks the purchase — the customer goes straight to paid checkout with no free window. Super Admin has an audited waive action, because shared offices, family emails and agencies onboarding clients all trip this legitimately.

---

## 7. Stripe

### Objects
Products per plan, Prices per `plan_prices` row, created by `StripeSyncService`. Quarterly maps to `interval=month, interval_count=3` (`config/billing.intervals.stripe_map`).

### Price immutability — the grandfathering guarantee

A Stripe Price can never have its amount changed, so `StripeSyncService` has **no update path for an amount, by design**. `PlanService::changePrice()`:

1. creates a new `plan_prices` row at the new amount
2. mints a new Stripe Price
3. deactivates + archives the old row and archives the old Stripe Price
4. leaves every `subscriptions.plan_price_id` pointing at the **old** row

Existing subscribers therefore keep their original price until deliberately migrated. This asymmetry is what makes launching low safe: raising a price later cannot touch anyone who already signed up, while launching high and cutting later would mean refunds or customers quietly overpaying.

### Webhook — `POST /stripe/webhook`

Registered in `routes/stripe.php` with **no middleware group at all** (same treatment as `routes/crawler.php`): no session, no CSRF token to present, nothing to bind. Inside the `web` group every delivery would 419 and Stripe would retry for days.

Three guarantees, in priority order:

1. **Authenticity** — `\Stripe\Webhook::constructEvent` against `STRIPE_WEBHOOK_SECRET` with a 300s tolerance, before any part of the body is trusted. Without it this route is an unauthenticated "make me a subscriber" API. Unverifiable → `400`, nothing recorded.
2. **Idempotency** — `StripeEvent::claim()` **inserts** the event id against a UNIQUE index and treats the duplicate-key violation as "already seen". Insert-then-catch, not check-then-insert: two concurrent deliveries would both pass an existence check.
3. **Correct ACKs** — handled, duplicate and don't-care all return `200`. Only genuine retryable failures return `500`, because a `500` makes Stripe retry with backoff for days.

Handled events: `checkout.session.completed` · `customer.subscription.created|updated|deleted|paused|resumed|trial_will_end` · `invoice.paid` · `invoice.payment_failed` · `payment_method.attached`.

`invoice.paid` re-reads the subscription from Stripe rather than patching period dates from the invoice, so one code path owns period dates — and it clears `past_due_since`, `read_only_since` and `purge_after` together, so recovery is never half-applied.

### Dunning
First failure stamps `past_due_since` and **keeps access** for `BILLING_PAST_DUE_GRACE_DAYS` (7). A bounced card should not instantly silence a customer's phone line.

### Proration
Default `create_prorations`: Stripe credits the unused part and charges the new price pro rata **on the next invoice**, rather than billing immediately. An unexpected mid-month charge is the most common complaint about self-serve upgrades. Switching interval also sets `billing_cycle_anchor: now`, otherwise the anchor stays on the old cadence and the first invoice date is confusing. Configurable via `BILLING_PRORATION`.

---

## 8. Regional display pricing

### Geolocation — reuses the existing `IpLocator`

`GeoLocationService` is driver-based; the default `iplocator` driver delegates to `App\Support\IpLocator`, the lookup the visitor-analytics feature already uses. `config/visitors.php` and `config/billing.php` share `GEOIP_DATABASE_PATH`, so **one downloaded `.mmdb` serves both features** (City edition — a superset of Country).

One deliberate difference: `IpLocatorDriver` calls `canResolveOffline()` first and returns `null` rather than letting `IpLocator` fall back to its HTTP endpoint. That fallback is right for a queued backfill and wrong for a page a buyer is waiting on. Local currency is a nicety; pricing-page speed is not.

There is **no Cloudflare** in front of this app (Caddy is the public TLS edge and deliberately doesn't trust inbound `X-Forwarded-For`), so `CF-IPCountry` isn't available. `TrustProxies` is already scoped to RFC-1918, so `$request->ip()` is the genuine client address.

Detection order: `?country=` → cookie → IP → configured fallback → **null (USD only)**. Explicit beats inferred so VPN users can self-correct, and it makes the feature testable without fixture IPs. Private/loopback addresses are never looked up.

### Exchange rates

`ExchangeRateService`, driver-based via `config('billing.fx.driver')`. Read path:

```
cache  →  exchange_rates table  →  null (render USD only)
```

The middle tier matters: a deploy clears the cache, and a cache-only design plus a provider outage at that moment would blank every local price. A rate older than `FX_MAX_AGE_HOURS` (72) is refused — a wrong-looking number is worse than no number.

**The pricing page never makes an outbound FX call.** `billing:refresh-rates` (scheduled every 6h) is the only writer, and on provider failure it is a **no-op** that leaves existing rows untouched, so a bad response can never wipe good data.

### Rounding — approximations must look approximate

`Rs 5,384.83` reads like a quote a customer might hold us to; `≈ Rs 5,400` reads like the estimate it is. Steps scale with magnitude and are tuned to keep drift under ~1.5%, because **reading higher than we actually charge is the one direction an approximation must not err in**. A test asserts the drift bound across all six price points in PKR and AED.

Symbol spacing is derived, not configured: a letter-based symbol gets a separating space (`Rs 5,400`, `AED 70`), a glyph hugs the number (`£15`, `$19`). This replaced a bug that rendered `Rs5,400`.

Every page carries: *"Prices are charged in USD. Local currency amounts are approximate."*

---

## 9. Access control

The workspace route group now runs six gates, in this order:

```
workspace.provisioned → module.enabled → subscribed → plan.feature → module.access → email.verified.gate
```

| Gate | Question | Fails open when |
|---|---|---|
| `module.enabled` | switched on platform-wide? | route maps to no module |
| **`subscribed`** | is this workspace paid up? | **no subscription row** (pre-billing) |
| **`plan.feature`** | does their PLAN include it? | no feature declares the module |
| `module.access` | does this MEMBER's role allow it? | owner (bypasses) |

**An owner does NOT bypass `plan.feature`.** Being the owner says nothing about what the workspace has paid for. Asserted by a test.

`plan.feature` returns a **402 upsell page** (`errors/plan-upgrade`) naming the feature and the cheapest plan that unlocks it, with a direct checkout button — not a 403. This is a sales moment, not an error.

### Grandfathering (`2026_08_11_100100_backfill_grandfathered_subscriptions`)

The most dangerous migration in the set. Putting existing workspaces on the free plan would start a 7-day countdown on every pre-billing customer and switch them all off a week after deploy. Instead they get:

- `status = 'free'`, **`free_ends_at = NULL`** → permanent (`freeWindowHasElapsed()` treats NULL as never)
- **`plan_id = NULL`** → `PlanFeatureService` fails open, so they keep every feature they had yesterday
- `metadata.grandfathered = true` so they can be found and migrated deliberately later

`down()` deletes only rows it created, never a customer who has since subscribed.

---

## 10. Surfaces

| Surface | Route | Notes |
|---|---|---|
| Public pricing | `GET /pricing` | hero → interval toggle → cards → comparison → FAQ → trust → CTA; FAQPage JSON-LD generated from the visible FAQ |
| Start checkout | `POST /pricing/checkout` | no auth; stashes intent and resumes after registration |
| Customer billing | `GET /c/{client}/billing` | plan, status, usage bars, invoices, upgrade/downgrade/cancel/resume |
| Stripe portal | `POST /c/{client}/billing/portal` | cards, addresses, invoice PDFs — not rebuilt by us |
| Ops → Plans | `/admin/billing/plans` | prices, versioning, Stripe sync |
| Ops → Features | `/admin/billing/features` | plan × feature matrix |
| Ops → Subscriptions | `/admin/billing/subscriptions` | MRR, filters, extend-free, waive, reconcile |
| Ops → Events | `/admin/billing/events` | every webhook and its outcome |
| Webhook | `POST /stripe/webhook` | no middleware group |

The interval toggle is progressive enhancement: each tab is a real link (`?billing=annually`), the server renders the chosen interval, and JS only makes switching instant. The page works fully with JS disabled.

`/pricing` is wired into the nav, the footer, `config/site.php` `page_views` and `sitemap_urls` (priority 0.9 — "<product> pricing" is one of the highest-intent queries there is).

**Ops has no "mark as active" button, deliberately.** Paid state comes from Stripe only; a manual override would create a workspace with access and nothing to reconcile against. Comping a customer is done with a Stripe coupon or a private plan.

---

## 11. Environment variables

Secrets only. Prices, plans, limits, features and trial lengths are all database-driven.

```
STRIPE_KEY=                 STRIPE_SECRET=              STRIPE_WEBHOOK_SECRET=
STRIPE_API_VERSION=2024-06-20                           STRIPE_AUTOMATIC_TAX=false

BILLING_FREE_WINDOW_DAYS=7  BILLING_FREE_ON_EXPIRY=read_only
BILLING_FREE_PURGE_DAYS=30  BILLING_PAST_DUE_GRACE_DAYS=7
BILLING_PRORATION=create_prorations

GEOIP_DRIVER=iplocator      GEOIP_DATABASE_PATH=        GEOIP_FALLBACK_COUNTRY=
MAXMIND_ACCOUNT_ID=         MAXMIND_LICENSE_KEY=

FX_DRIVER=erapi             FX_API_KEY=                 FX_ENABLED=true
FX_CACHE_TTL=21600          FX_MAX_AGE_HOURS=72
```

## 12. Stripe Dashboard setup

1. **API keys** → copy publishable + secret into `STRIPE_KEY` / `STRIPE_SECRET`.
2. **Webhooks → Add endpoint**
   - URL `https://<APP_DOMAIN>/stripe/webhook`
   - Events: `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.subscription.trial_will_end`, `invoice.paid`, `invoice.payment_failed`, `payment_method.attached`
   - Copy the **signing secret** into `STRIPE_WEBHOOK_SECRET`. Without it every event is rejected and subscriptions never activate.
3. **Customer Portal → Configure** → enable payment-method updates, invoice history, and (optionally) cancellation.
4. Leave Products/Prices alone — create them with `php artisan billing:sync-stripe` so local rows and Stripe stay linked.

> **Test vs live mode matters.** `plan_prices.stripe_livemode` records which mode minted each reference. Checking out against a test Price with a live key fails at Stripe, so `billing:sync-stripe` and the Plans screen both surface mismatches rather than letting a real customer hit it.

## 13. Deployment

```bash
composer install --no-dev -o
php artisan migrate --force          # includes the grandfathering backfill
php artisan db:seed --class=BillingSeeder --force   # first deploy only; non-destructive on re-run
php artisan billing:sync-stripe      # creates Stripe Products/Prices
php artisan geoip:update             # optional; without it, pricing shows USD only
php artisan billing:refresh-rates    # optional; without it, pricing shows USD only
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Ensure the scheduler runs (`php artisan schedule:run` each minute). Registered in `app/Console/Kernel.php`:

| Command | Cadence | Purpose |
|---|---|---|
| `billing:refresh-rates` | every 6h | FX rates (6h TTL, so a failed run leaves no gap) |
| `billing:lifecycle` | daily 06:15 | warn → expire → warn → report purge queue |
| `geoip:update` | weekly Tue 04:10 | shared GeoLite2 file; no-ops if <6 days old or no licence key |

The seeder is **non-destructive**: it uses `firstOrCreate` on plans, adds a price only when that interval has none, and seeds feature values only for a plan that has none. Re-running it can never revert an operator's pricing decisions.

---

## 14. Testing

`php artisan test` → **165 passed, 673 assertions** (~76s). Nine billing test files under `tests/Feature/Billing/`.

**No test touches the network.** Stripe is a container-bound double, geolocation is forced to the null driver, and FX rates are seeded directly. A billing suite needing live keys is a suite nobody runs.

| Requirement from the brief | Where |
|---|---|
| Free plan · 7-day window · expiry · read-only · abuse | `FreeWindowTest` |
| Checkout · price tampering · unsynced/free/enterprise refusal · non-owner · anonymous funnel · success page ≠ activation | `CheckoutTest` |
| Signature rejection · stale timestamp · **duplicate webhook** · unhandled type · failed payment · grace expiry · recovery · cancellation · card fingerprint | `WebhookTest` |
| Monthly/quarterly/annual · upgrade · downgrade · interval switch · cancel · resume · renewal resets quotas | `SubscriptionLifecycleTest` |
| Feature limits · unlimited≠zero · missing row · the eight gates · owner doesn't bypass · overage split · hard stop | `PlanLimitsTest` |
| Super-admin pricing changes · **Stripe price versioning + grandfathering** · float-free cents · plan CRUD · matrix · hashid regression | `SuperAdminPricingTest` |
| Conversion · rounding drift · unknown country · FX failure · stale rate · USD fallback · IP detection · override | `RegionalPricingTest` |
| Every ops + customer billing screen renders in every state | `OpsBillingViewsTest` |

### Test-harness notes worth keeping
- `BillingTestCase` has **no `tearDown()` override**. An earlier version called `Mockery::close()` before `parent::tearDown()`; an unmet `->once()` expectation made it throw, `RefreshDatabase` never rolled back, and every later test died on a lock-wait timeout — one real failure looked like a dozen deadlocks and the suite took 486s instead of 53s.
- `StripeClient` is **subclassed**, not Mockery-mocked. Mockery overrides `__get()` on any class that declares one, so a mocked client silently returns `null` for `->checkout` and the failure surfaces three layers away.
- Bind Stripe doubles **before the first request** in a test.
- `SiteSetting` memoises the whole table in a **static** property that survives `RefreshDatabase`; `BillingTestCase::setUp()` flushes it.
- Gate tests use `project-profile.index`, not the dashboard: the dashboard queries the tenant DB, which the suite doesn't provision.

---

## 15. Bugs this work found and fixed

| Bug | Impact |
|---|---|
| `Billable::stripeEmail()` ran `SELECT DISTINCT users.email … ORDER BY users.id` | MySQL rejects it under `ONLY_FULL_GROUP_BY` — **would have 500'd the very first checkout in production.** Now goes through `billingOwner()`. |
| `ExchangeRateService::format()` rendered `Rs5,400` | Letter symbols had no separating space. Now derived from the symbol. |
| Display rounding overstated `$59` as `Rs 17,000` | +1.7% — made us look more expensive than we are. Bands tightened to <1.5% drift, with a test. |
| `EnsureSubscribed` had unreachable `addBanner()`/`passThrough()` stubs | Dead code that couldn't work (`$next` isn't reachable there). Rewritten. |
| `SubscriptionService::startFreeWindow()` threw with no free plan | Would have broken registration on a fresh install. Now returns `null` and logs. |
| `BillingController::cancel()` used the `void` return of `forgetSubscription()` | Wrong `$endsAt` in the flash message. |

### Pre-existing issues noted, not changed
- `projects` has no `api_key` column despite `Project::$fillable` listing one, and its update column is misspelled `update_at`.
- `payment_plans` + `App\Models\PaymentPlan` are dead code (zero usages, float money, misspelt `desctiption`). Left untouched; safe to drop in a separate cleanup.
- `DecodeHashids` rewrites **any** request key matching `*_id`. All billing fields avoid that shape (`plan`, `interval`, `stripe_price_ref`); there is a regression test.

---

## 16. Known limits / next steps

- **Overage is recorded, not yet invoiced.** `usage_counters.overage` is accurate and split correctly at the allowance boundary, but reporting it to Stripe as metered usage is not wired. Until it is, published overage rates are advisory.
- **Purge is report-only** (§5) — by choice.
- **Quarterly ships supported but not offered.** Add a `quarterly` price and put `quarterly` in `billing.intervals.offered` to show it; no code change.
- **Add-ons** (extra seats/projects/minutes) have prices in the recommendation but no implementation; the schema supports them via `subscriptions.type`.
- **Usage recording call sites**: `UsageLimitService::record()` is complete and tested but is not yet called from the conversation/telephony paths. Wire it where sessions and calls complete.
- **`billing.intent`** is stashed and resumed to `/billing`; it does not auto-open Stripe Checkout.
