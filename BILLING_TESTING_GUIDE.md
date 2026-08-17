# How to test the subscription system

Everything below runs against **Stripe test mode**. No real money moves, and no
test card in this document can be charged for real.

There are three levels. Do them in order — each one rules out a class of
problem, so if level 3 misbehaves you already know it isn't the code.

| Level | What it proves | Needs Stripe keys? | Time |
|---|---|---|---|
| 1. Automated suite | The logic is correct | No | ~2 min |
| 2. Offline walkthrough | The screens work | No | ~10 min |
| 3. Live test mode | Stripe agrees | Yes (test keys) | ~30 min |

---

## Level 1 — the automated suite

```bash
cd admin
php artisan test --testsuite=Feature --filter=Billing
```

Expect **all green**. This suite never touches the network: Stripe is a
container-bound double, geolocation is forced off, and exchange rates are
seeded. Run the whole app suite before any deploy:

```bash
php artisan test
```

Current state: **347 passed (1333 assertions)**.

What it already covers, so you don't need to re-test it by hand: webhook
signature rejection and replay, free-window expiry, grandfathering when a price
changes, plan-feature gating, quota hard stops, usage recording, add-on
quantities, regional price display, and every Super Admin billing screen.

---

## Level 2 — click through it with billing switched off

This is the state the app ships in today. Purchase CTAs are hidden; everything
else is live.

```env
BILLING_CHECKOUT_ENABLED=false
```

Check, in this order:

1. **`/` and `/pricing`** — four plan cards plus the Enterprise band, prices
   read from the database, no purchase buttons anywhere.
2. **Change a price in Super Admin** → `/admin/billing/plans` → edit Growth →
   set monthly to `$49` → save. Reload the home page: it says $49. No deploy,
   no cache clear. **Then change it back.**
3. **Register a new workspace** → you get the 7-day free window. Sidebar shows
   only what Free includes; phone/telephony is absent, web chat works.
4. **`/c/{workspace}/billing`** — current plan, the free-window countdown,
   usage meters, no nagging about payment methods.
5. **`/c/{workspace}/billing/plans`** — all plans, current one pre-selected,
   add-ons listed with *"Choose a plan first"*.
6. **Expire the free window by hand**, then confirm the read-only state:

   ```bash
   php artisan tinker
   >>> $c = App\Models\Client::where('slug','your-slug')->first();
   >>> $c->currentSubscription()->update(['free_ends_at' => now()->subDay()]);
   ```

   Reload: writes are refused, data is still visible, billing stays reachable.
   (`grantsAccess()` is what flips here.) Set `free_ends_at` forward again to
   undo.

---

## Level 3 — live against Stripe test mode

### 3.1 Keys

Stripe Dashboard → toggle **Test mode** on (top right) → Developers → API keys.

```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_API_VERSION=
BILLING_CHECKOUT_ENABLED=true
```

Two things that are easy to get wrong here:

- **`STRIPE_KEY` must start with `pk_test_`.** It's the *publishable* key, and it's the one Stripe.js loads in the browser. Dropping the prefix when pasting still lets `billing:sync-stripe` work (that uses the secret) and then fails at the card form with "Invalid API Key".
- **Leave `STRIPE_API_VERSION` blank** unless you have deliberately pinned your account to a version. Blank follows the installed `stripe-php`. A hand-written date rots: pinning `2024-06-20` against stripe-php v21 makes Stripe reject *every* request with "You are using an outdated API version".

### 3.2 Create the Stripe products

Your plans exist in **your** database; Stripe needs matching Products and
Prices. One command creates them and writes the refs back:

```bash
php artisan billing:sync-stripe
```

Run it again after adding the add-ons — the two add-on prices are new:

```bash
php artisan billing:sync-stripe
```

Verify in Stripe → Product catalogue: Starter, Growth, Scale, Extra team seat,
Extra AI agent — each with a monthly and an annual price.

> If a price shows "not synced" in Super Admin, checkout will refuse it on
> purpose rather than charge the wrong amount.

### 3.3 Webhooks — do this before paying for anything

**Nothing marks a workspace as paid except a webhook.** The browser returning
from checkout is not proof of payment. If you skip this step, payments will
succeed in Stripe and your app will never notice.

```bash
stripe login
stripe listen --forward-to http://localhost/stripe/webhook
```

It prints `whsec_...`. Put that in `.env` — this value is *specific to the
`stripe listen` session* and changes each time you restart it:

```env
STRIPE_WEBHOOK_SECRET=whsec_...
```

Leave that terminal running for the whole test session.

### 3.4 Test cards

| Card | What it does |
|---|---|
| `4242 4242 4242 4242` | Succeeds immediately |
| `4000 0025 0000 3155` | **Requires 3-D Secure** — the important one |
| `4000 0000 0000 9995` | Declined (insufficient funds) |
| `4000 0000 0000 0341` | Attaches fine, then fails when charged |

Any future expiry, any CVC, any postcode.

### 3.5 The walkthrough

**A. First payment**

1. `/c/{workspace}/billing/plans` → pick Growth monthly → **Continue**.
2. On checkout, the card form is our own styling with Stripe Elements inside
   it. Pay with `4242 4242 4242 4242`.
3. A success modal appears; the `stripe listen` terminal shows
   `customer.subscription.created` and `invoice.paid`.
4. `/c/{workspace}/billing` now shows Growth, the renewal date, and the card
   ending 4242.
5. Sidebar: Growth modules appeared without a re-login.

**B. 3-D Secure** — the path most likely to break

Repeat A with `4000 0025 0000 3155`. A challenge window must appear; approve
it. The subscription only becomes active after you complete it. Then run it
again and **cancel** the challenge: you must land on an error, and the
workspace must *not* be marked paid.

**C. Saved card**

Go back to checkout. The saved card is pre-selected — no re-entry. Add a
second card, make it default, delete the first.

**D. Add-ons** ← new

0. From `/c/{workspace}/billing`, press **Add seats or agents**. You should land
   straight on the add-ons page — *not* the plan list. Confirm the summary
   panel quotes a **Due today** figure that changes with the quantity, and that
   it matches the proration Stripe shows on the subscription.
1. Extra team seat → stepper to `3` → **Save**.
2. Stripe → the subscription now has a second line item, 3 × $5, prorated.
3. **This is the assertion that matters:** Team & Roles now allows 3 more
   members than the plan alone did. Growth is 10 seats; you now have 13.
4. Change the quantity to `1` → Stripe prorates down. Set it to `0` → the line
   is removed, the next invoice is credited, and the seat limit drops back
   to 10.
5. Add-ons follow the plan's interval — on an annual plan the seat is $50/yr,
   not $5/mo, because Stripe refuses to mix cadences on one subscription.

**E. Upgrade and downgrade**

Growth → Scale: access widens immediately, Stripe shows a proration.
Scale → Starter: Stripe schedules the change; check nothing is lost mid-cycle.

**F. Invoices**

`/c/{workspace}/billing` → an invoice → our branded invoice page. Print it. The
Stripe PDF link should also work. Then try another workspace's invoice id in
the URL — you must get a 404, not somebody else's invoice.

**G. Usage meters**

Have a few conversations in the widget and make a phone call. Within a minute
the billing page's meters and percentages move. `indexed_pages` and
`storage_mb` are reconciled hourly rather than live:

```bash
php artisan billing:reconcile-usage
```

**H. Cancel and resume**

Cancel → "ends on {date}", access continues. Resume → the pending cancellation
is cleared.

**I. Failed payment**

Stripe Dashboard → the subscription → Actions → **Advance clock** (or use a
card from the table above that fails on charge). Confirm the app moves to
`past_due`, keeps access through the grace window
(`BILLING_PAST_DUE_GRACE_DAYS`, default 7), then restricts.

### 3.6 Things that should FAIL

Confirm each of these is refused — they're the security boundary:

| Try | Expected |
|---|---|
| `POST /stripe/webhook` with no signature | 400 |
| Replay a webhook you already sent | 200, but no duplicate effect |
| Post a modified `amount` from the browser | Ignored — the server prices from the DB |
| A non-owner member posting to `/billing/subscribe` or `/billing/addons` | 403 |
| Another workspace's invoice id in the URL | 404 |

---

## Level 4 — going live

Only after level 3 is fully green.

1. Swap in live keys (`pk_live_` / `sk_live_`).
2. Create a **real** webhook endpoint: Stripe → Developers → Webhooks → Add
   endpoint → `https://yourdomain.com/stripe/webhook` → select
   `customer.subscription.*`, `invoice.*`, `checkout.session.completed`. Copy
   *that* signing secret into `STRIPE_WEBHOOK_SECRET` — it is **not** the same
   as the `stripe listen` one.
3. `php artisan billing:sync-stripe` — live mode creates its own Products;
   test-mode refs do not carry over.
4. Confirm the scheduler is running (`billing:lifecycle`,
   `billing:refresh-rates`, `billing:reconcile-usage`).
5. Buy one real subscription on a real card, then refund it.

---

## When something looks wrong

| Symptom | Where to look first |
|---|---|
| Paid in Stripe, still not subscribed | `stripe listen` running? Right `whsec_`? `/admin/billing/events` |
| "This price isn't synced" | `php artisan billing:sync-stripe` |
| Sync fails: "You are using an outdated API version" | Blank `STRIPE_API_VERSION`, then `php artisan config:clear` |
| Card form says "Invalid API Key" | `STRIPE_KEY` isn't the `pk_test_…` publishable key |
| Price change not visible | It is cached for an hour; saving in Super Admin flushes it — a direct SQL edit does not |
| Add-on bought but the limit didn't move | The add-on's `plan_features` row is what grants capacity — check `/admin/billing/features` |
| Local currency looks wrong | Display only, never charged. `php artisan billing:refresh-rates` |

`/admin/billing/events` shows every webhook received, in order, with its
payload — start there for anything payment-shaped.
