# Super Admin — Billing Guide

**Who this is for:** whoever owns pricing at Serve AI. **No code, no developer, no deploy.**

Everything below is done in the Ops Console at **`/admin/billing`**. You need a super-admin account (`users.is_super_admin`). Every action is written to the audit log.

The only things you *can't* change here are the Stripe secret keys and the webhook secret — those live in `.env` because they're infrastructure credentials.

---

## The three screens

| Screen | Where | What it's for |
|---|---|---|
| **Plans & Pricing** | Ops → Billing → Plans & Pricing | plans, prices, Stripe sync |
| **Features & Limits** | Ops → Billing → Features & Limits | what each plan includes, and how much of it |
| **Subscriptions** | Ops → Billing → Subscriptions | who's on what, what's failing, support overrides |

---

## The one thing to understand before you change a price

**Stripe prices can never be edited.** So when you change $59 to $89, the system does *not* edit the existing price. It:

1. creates a **new** price at $89,
2. creates a **new** price in Stripe,
3. retires the old one (kept, not deleted),
4. and leaves **every existing subscriber on $59**.

**Existing customers keep their old price forever, until you deliberately move them.** New signups pay the new price.

That means:

> ✅ **Launching low is safe.** If $19/$59/$149 turns out to be too cheap in six months, raise it here. Every early customer keeps their original price — a genuine loyalty reward — and new customers pay the new rate.
>
> ⚠️ **The reverse is not safe.** If you launch high and cut later, early customers are stuck overpaying until you migrate them by hand.

The confirmation dialog tells you how many subscribers will be grandfathered before you commit.

---

## Change a price

1. Ops → Billing → **Plans & Pricing**
2. Find the plan, find the interval row (Monthly / Annual)
3. Type the new amount in dollars into the box → **Change price**
4. Confirm

The flash message tells you the new price and how many existing subscribers stayed on the old one.

**Notes**
- Amounts are in **US dollars**. USD is the only billing currency — there is no way to charge in another one, by design.
- Decimals work (`19.99`). They're stored as exact cents, no rounding drift.
- The new Stripe price is created automatically. If Stripe keys aren't set, the price is saved locally and flagged **not synced** — it can't be sold until you sync it.

---

## Add a billing interval to a plan

1. Plans & Pricing → find the plan
2. In the row under its price table, pick the interval, type the amount → **Add price**

Only intervals the plan doesn't already have are offered.

**Quarterly** is fully supported but **not shown on the public pricing page** — the approved offer is monthly + annual. Adding a quarterly price here stores it correctly; to display it, the `billing.intervals.offered` list needs `quarterly` added (a one-line config change — the only item in this guide that isn't self-serve). Everything else about quarterly already works.

---

## Create a plan

1. Plans & Pricing → **New plan**
2. Fill in name, type, tagline → **Create plan**
3. You land on the edit screen — now add prices (back on the Plans list) and set its limits

**Type matters:**

| Type | Behaviour |
|---|---|
| **Standard** | a normal paid, self-serve plan |
| **Free** | can never have a price; the only type that uses the free window |
| **Enterprise** | rendered as a "talk to us" band on the pricing page, not a price card |
| **Custom** | a private negotiated plan — set it to **not public** so it's link-only |

The **slug** is permanent. It's the identifier checkout forms submit and it's stamped into Stripe metadata, so it can't be renamed without breaking in-flight checkout sessions. To rename a plan, change its display name (that's editable any time).

---

## Disable a plan

Plans & Pricing → **Hide** on the plan.

- disappears from the public pricing page and from new signups
- **existing subscribers keep their subscription and keep being billed** — pulling a plan out from under paying customers isn't something the system will do

**Activate** puts it back on sale.

---

## Mark a plan as "most popular"

Plans & Pricing → **Make popular**.

Only one plan can hold the badge — setting it clears the others automatically. Change the badge text itself on the plan's edit screen (`Badge` field).

Every competitor we researched puts this badge on the **middle** tier, and it's currently on Growth.

---

## Add or change a feature

Ops → Billing → **Features & Limits**.

### Change a limit on an existing feature
Edit the cell in the plan × feature grid → **Save all limits**. One submit saves the whole matrix.

- a **number** = the ceiling (`5000`)
- **`-1`** = unlimited
- **blank** = the plan does **not** include this feature at all

> **Blank and `0` are different.** Blank withholds the feature entirely. `0` grants the feature with a zero allowance — which is how "has the telephony section, but no minutes" would be expressed.

Changes take effect **immediately** — the entitlement cache is cleared on save, not on a timer.

### Add a new feature
Bottom of the Features & Limits screen. Three fields decide how it behaves:

| Field | Effect |
|---|---|
| **Value type** | Yes/No · Number (a limit) · Always unlimited · Free text |
| **Gate a module** | picks an admin section (Telephony, Channels, Flow Builder…). Plans without this feature get a 402 upsell page when they open that section. Uses the same module list as Roles & Permissions, so entitlements and permissions can't drift apart. |
| **Cap a usage meter** | turns the number into an enforced quota (conversations, phone minutes, widget voice messages, indexed pages, storage) |

Both are optional — a feature with neither is display-only marketing copy for the pricing page.

Also: **In comparison table** (shows in the /pricing comparison grid) and **Bullet on the plan card** (shows in the short list on the card itself).

A new feature is **not granted to any plan** until you give it a value. That's deliberate — adding a feature can never accidentally hand it to every existing plan.

### Delete a feature
Feature catalogue → trash icon. Removes it from every plan. The `key` can't be renamed because application code and the cache reference it — change the display name instead.

---

## The two voice meters (don't merge them)

| Meter | What it costs us | Free plan |
|---|---|---|
| **Phone call minutes** (`telephony_minutes`) | real money — number rental + carrier per-minute | **0** |
| **Widget voice messages** (`voice_messages`) | almost nothing — runs on our own speech models | **50** |

Giving the free plan phone minutes is the fastest way to lose money on this product. No competitor we researched offers a free plan on a voice product, and this split is the only reason we can.

---

## Manage the free plan

Plans & Pricing → **Edit** on the Free plan.

| Field | Meaning |
|---|---|
| **Free window (days)** | days of no-card access before the workspace goes read-only. Currently **7**. |
| *(blank)* | **a permanent free tier** — no expiry at all |

What happens on day 8 (`BILLING_FREE_ON_EXPIRY`, default `read_only`):
- the customer keeps their login, dashboard, leads, transcripts and export
- their agent stops answering new customers
- writes are blocked
- data is kept 30 days, with warning emails
- paying restores everything instantly

Set the free window's **limits** in Features & Limits, same as any other plan.

---

## Manage a trial

Plans & Pricing → **Edit** on a paid plan:

- **Trial (days)** — currently `0` on every paid plan, because the 7-day free window *is* the trial. Set a number here to switch a Stripe trial back on for that plan.
- **Require a card to start the trial** — recommended on. The card is also the strongest abuse control we have (Stripe's card fingerprint is stable across customers).

Both take effect on the next checkout. No deploy.

---

## Add-ons — extra seats and AI agents

Customers can buy extra capacity without moving up a tier. Two ship today:

| Add-on | Price | What one unit grants |
|---|---|---|
| Extra team seat | $5/mo · $50/yr | `seats` +1 |
| Extra AI agent | $9/mo · $90/yr | `agents` +1 |

They appear on the customer's **Billing** page and on their **Choose a plan**
page. They are never on the public pricing page — an add-on attaches to a live
subscription, so there is nothing to sell a visitor.

**An add-on is just a plan** with type `addon`, so everything you already know
applies:

- **Change its price** — Plans & Pricing → the add-on → *Change price*. Same
  rule as any plan: existing holders are grandfathered on the old price, new
  buyers get the new one. Then **Sync to Stripe**.
- **Change what one unit grants** — Features & Limits → the add-on's column →
  set `seats` to `2` and every seat sold from then on is worth two. Existing
  holders get the new value too, because the limit is computed live as
  `base + (unit value × quantity)`.
- **Stop selling one** — set it inactive. Customers who already hold it keep
  it; nobody new can buy it.

**Both a monthly and an annual price are required.** An add-on has to match
whatever interval the customer's subscription is on — Stripe refuses to put a
monthly and an annual price on the same subscription. If an annual customer
tries to buy an add-on that has no annual price, they're told to contact you
rather than being charged the wrong cadence.

### Creating a new add-on

1. Plans & Pricing → **Create plan** → set **Type** to `addon`. Leave *public*
   off.
2. Add a **monthly** and an **annual** price.
3. Features & Limits → give it exactly **one** numeric feature with the value
   one unit grants (usually `1`). That single row is what the add-on tops up —
   the first positive numeric feature is the one used.
4. **Sync to Stripe.**

> If an add-on has no feature row, it will happily bill the customer and grant
> them nothing. The seeder guards the two shipped add-ons against this; a
> hand-made one is your responsibility — check it on the Features & Limits
> screen before you sell it.

---

## Sync to Stripe

Plans & Pricing → **Sync all to Stripe** creates a Stripe Product/Price for anything that doesn't have one.

Safe to press any time — it skips anything already synced.

**A price with no Stripe reference cannot be sold.** The banner tells you how many are unsynced, and each row shows either its Stripe price id or a **not synced** flag.

### Archive an old price
Row → **Archive**. Blocks new checkouts against it; existing subscribers keep renewing on it. This is what `Change price` does automatically to the old price, so you rarely need it by hand.

### "Wrong mode" warning
Stripe has separate test and live worlds with different ids. If a price was created in one mode while the app now runs the other, checkout would fail *for a real customer at the moment they try to pay*. The banner and the row flag it instead. Fix: **Archive** the flagged price and **Add price** again in the current mode.

The header always tells you which mode you're in: **Stripe is connected in TEST/LIVE mode.**

---

## Subscriptions & support

Ops → Billing → **Subscriptions**.

Top row: estimated **MRR** (annual plans normalised to a month, so it's committed monthly revenue) plus counts by status. Filter by status, plan or workspace.

Three support actions per row:

| Action | What it does |
|---|---|
| **Extend free access** (calendar icon) | adds N days and clears the read-only/purge flags. Measured from today, so it always grants the full N days. |
| **Re-pull from Stripe** (refresh icon) | re-reads the subscription from Stripe when local state looks stale |
| **Waive trial blocks** (unlock icon) | lets a workspace start a fresh free period. Use when the abuse check caught someone unfairly — shared offices, family emails and agencies onboarding clients all trip it legitimately. |

### There is no "mark as active" button — on purpose
Paid state comes from Stripe and only from Stripe. A manual override would create a workspace with access and no money behind it, and nothing would ever reconcile it back.

**To comp a customer**, do one of:
- a 100%-off coupon in the Stripe Dashboard (best — keeps the subscription real), or
- a **Custom**, not-public plan at $0 that you link them to directly, or
- **Extend free access** repeatedly, if it's genuinely temporary.

---

## When something looks wrong

**A customer paid but is still showing as free**
→ Ops → Billing → **Stripe events**. If the list is empty, the webhook isn't reaching us: check the endpoint URL (`/stripe/webhook`) and the signing secret in the Stripe Dashboard. If an event shows **failed**, the payload is stored — pass it to a developer. Meanwhile, **Re-pull from Stripe** fixes that one customer immediately.

**Checkout says "not synced to Stripe"**
→ Plans & Pricing → **Sync all to Stripe**.

**Checkout fails only for real customers**
→ Look for the **wrong mode** warning on Plans & Pricing.

**The pricing page shows no local currency**
→ Expected and harmless: USD always displays. Local amounts need the GeoLite2 file and exchange rates, both maintained automatically when configured. Everyone still sees correct USD prices.

**A price change didn't affect an existing customer**
→ That's correct. See the top of this guide.

---

## Quick reference — what's editable where

| Change | Where | Deploy needed? |
|---|---|---|
| Any price, any interval | Plans & Pricing | No |
| Add / remove a plan | Plans & Pricing | No |
| Add a billing interval to a plan | Plans & Pricing | No |
| Hide or activate a plan | Plans & Pricing | No |
| Popular badge, badge text, button text, order | Plans & Pricing → Edit | No |
| Plan name, tagline, description | Plans & Pricing → Edit | No |
| Public or private (link-only) | Plans & Pricing → Edit | No |
| Free-window length (or make it permanent) | Plans & Pricing → Edit | No |
| Trial length, card-required-for-trial | Plans & Pricing → Edit | No |
| Any usage limit | Features & Limits | No |
| Add / remove / re-gate a feature | Features & Limits | No |
| Add-on price, or what one unit grants | Plans & Pricing / Features & Limits | No |
| Create or retire an add-on | Plans & Pricing (type `addon`) | No |
| Extend a customer's free access | Subscriptions | No |
| Waive an abuse block | Subscriptions | No |
| Show quarterly on the pricing page | `billing.intervals.offered` | Yes (one line) |
| Stripe keys, webhook secret | `.env` | Yes |
