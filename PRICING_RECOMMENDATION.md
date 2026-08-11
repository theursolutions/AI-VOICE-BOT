# Recommended Pricing & Feature Allocation — Serve AI (Phase 6)

**Status: RECOMMENDATION ONLY. Nothing below has been implemented.**
No migrations written, no Stripe Products or Prices created, no production code changed.

**Revision history**
- **r1** — $39 / $129 / $299, monthly + quarterly + annual, 5 plan cards. (Market-median matching.)
- **r2** — prices cut ~50% to $19 / $59 / $149. (Price-leader positioning.)
- **r3 (current, 2026-08-09)** — per your direction: **monthly + annual only** (quarterly dropped from the offer, kept in the architecture), **4 plan cards** (Free + 3 paid), and a decided answer on **feature allocation** (§3).

Derived from [COMPETITOR_PRICING_COMPARISON.md](COMPETITOR_PRICING_COMPARISON.md) (17 competitors, checked 2026-08-09) and the shipped capabilities in [SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md](SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md).

---

## 1. The offer — 4 plans, 2 billing intervals

| | **FREE** | **STARTER** | **GROWTH** ★ | **SCALE** |
|---|---|---|---|---|
| **Monthly** | **$0** | **$19** | **$59** | **$149** |
| **Annual** | — | **$190** *(2 months free — $15.83/mo)* | **$590** *(2 months free — $49.17/mo)* | **$1,490** *(2 months free — $124.17/mo)* |
| **7-day trial** | n/a (permanent) | ✅ | ✅ | ✅ |
| **Badge** | — | — | **Most popular** | — |

Below the cards, a slim band — not a fifth pricing card:

> **Need more?** Unlimited projects, SSO, dedicated infrastructure, custom SLA. **Talk to us →**

### Annual discount: "2 months free" (16.7%)

Changed from r2's flat 20%. Reasons:

- **The numbers are clean**: $19 → $190, $59 → $590, $149 → $1,490. On a pricing page that legibility is worth more than 3.3 percentage points.
- **It is the market's most common formulation** — Tidio, Rosie and Zoho SalesIQ all express annual as "2 months free" rather than a percentage. Buyers parse it instantly.
- 16.7% sits inside the market's 15–25% band (Goodcall 15%, Chatbase 20%, respond.io 20%, Wati ≈25%).

If you prefer the bigger headline number, 20% would be **$179 / $569 / $1,429** — same architecture, one Super-Admin edit. I recommend the round numbers.

### Quarterly: dropped from the offer, kept in the architecture

You're right that monthly + annual is the simpler offer, and the research backs it: **zero of 17 competitors publish a quarterly price.** Two intervals is what buyers expect and what the toggle should show.

But your original brief asked that "the architecture should allow additional intervals later," and that costs nothing to honour: `plan_prices` stores one row per interval, so quarterly is a **row, not a code path**. If cash-flow feedback from Pakistan/MENA customers later says annual-in-USD is too big a jump, you add a quarterly price from the admin panel and the toggle grows a third button — **no migration, no deploy**. The interval enum ships with `monthly | quarterly | annually` from day one; only monthly and annual get rows.

---

## 2. Why these prices (unchanged from r2)

| Plan | Price | Positioning |
|---|---|---|
| **Starter $19** | Undercuts every real entry tier: Tidio $24, Dialzara $29, Chatbase $32, Wati $39, Rosie $49, Goodcall $79. Only ManyChat's $15 is lower — and it has no voice, no web widget, and charges **+$29/mo for the AI**. $19 is below the threshold where an SMB owner has to justify a purchase to anyone. |
| **Growth $59** | The market's badged mid-tier median is $129. At $59 the substitution line becomes: *Rosie $49 (voice) + Chatbase $32 (chat) + Wati $39 (WhatsApp) = $120/mo for three disconnected tools with three knowledge bases* — **we are half the price of the parts, in one product.** 3.1× Starter, at the top of the observed 2–3× step band, so the middle tier looks like the obvious choice. |
| **Scale $149** | Half the top-tier market median ($299 across Goodcall/respond.io/Wati/Rosie/Tidio/Dialzara/Chatbase). Equals Rosie's *middle* tier while including flows, RBAC, API, white-label and BYO-LLM that Rosie doesn't sell at any price. 2.5× Growth. |
| **Enterprise from $499** | Far below Synthflow's $30,000/year floor, keeping the lower-mid-market they abandoned. |

We can hold this floor because our marginal cost is structurally lower — local XTTS voice and local/self-hosted LLM, versus competitors paying OpenAI + ElevenLabs + platform margin on every conversation. **Competitors can't follow us down without losing money.**

---

## 3. Feature allocation — the direct answer

> **Should all features go to all customers? No — but all *core* features should.**

The failure mode to avoid is gating things that make the product feel incomplete. The research has a clear cautionary example: ManyChat charges **+$29/month for AI on an AI product**, and it is written up in every third-party review as a hidden fee. Slang charges **$99/month for Spanish**. Chatbase charges **$1,188/year to remove a badge**. Each of those makes the entry plan feel like a demo.

So every capability in the product falls into exactly one of three buckets, and the bucket decides how it is sold.

### Bucket 1 — Never gated. Every plan, including Free.

These are what make Serve AI *Serve AI*. Gating any of them makes us look worse than a $29 competitor and destroys the reason someone chooses us.

| Capability | Why it's never gated |
|---|---|
| **Voice cloning** (own voice from a 10s sample) + 30 stock voices | Our headline differentiator. Rosie offers 10 stock voices and no cloning **at any price** |
| **Multi-language auto-detect & reply** (13 languages) | Slang charges **$99/mo** for Spanish alone. It costs us nothing — the model already detects |
| **Automatic lead capture → CRM** | This is the product's whole purpose |
| **Website chat widget** | The zero-friction entry point; must work on Free |
| **RAG over website + uploaded documents** | Without it the agent has nothing to say |
| **Transcripts, call/chat summaries, notifications** | Table stakes; every competitor includes these at entry |
| Dashboard, conversations, leads views | Basic app |

### Bucket 2 — Metered, not gated. Same feature everywhere, different volume.

These cost us real money per unit, so **every tier gets the feature and pays for the volume**. This is where the tiers actually differentiate, and it's the model buyers already understand from Rosie, Dialzara, Tidio and Chatbase.

Voice minutes · AI conversations · indexed pages & storage · projects · team seats · AI agents/personas · phone numbers · data sources · history retention.

### Bucket 3 — Genuinely gated on/off. Deliberately only eight.

Each one is here because it **either costs us money, or is something a larger customer will happily pay for** — and none of them makes the entry plan feel crippled.

| # | Gated capability | Free | Starter | Growth | Scale | Why gated |
|---|---|---|---|---|---|---|
| 1 | **Voice / telephony** | ✗ | ✅ | ✅ | ✅ | Real cost per minute. This one line is the single biggest protector of gross margin — **no voice vendor in the market offers a free plan**, for exactly this reason |
| 2 | **WhatsApp / Instagram / Facebook** | ✗ | WhatsApp + 1 | All | All | Meta conversation fees + per-channel setup cost |
| 3 | **Flow builder** | ✗ | 1 flow | Unlimited | Unlimited | Power feature; Goodcall gates identically (1 / 3 / 25 logic flows) |
| 4 | **Team roles & permissions (RBAC)** | ✗ | ✗ | ✅ | ✅ | Only matters once there's a team; a solo shop never notices |
| 5 | **API access + webhooks** | ✗ | ✗ | ✅ | ✅ | Chatbase gates it at $120 — we give it at $59 |
| 6 | **Live DB connector + per-table AI access control** | ✗ | ✗ | ✅ | ✅ | **Nobody in the market sells this at any price.** Our strongest upgrade lever |
| 7 | **Remove "Powered by Serve AI"** | ✗ | +$9/mo | ✅ | ✅ | Chatbase charges $1,188/yr; we include it at $59 |
| 8 | **White-label + custom domain · BYO LLM key · audit export** | ✗ | ✗ | ✗ | ✅ | Agency/compliance features; also keeps expensive-model users on the $149 tier |

**That's it. Eight switches.** Everything else is either always-on or a number.

This maps cleanly onto the existing `config/modules.php` registry — a plan's entitlements are a subset of the same 17 module keys the RBAC matrix already uses, so `EnsurePlanFeature` slots straight into the middleware chain with no new concepts.

---

## 4. Full plan table

| | **FREE** | **STARTER** $19/mo · $190/yr | **GROWTH** ★ $59/mo · $590/yr | **SCALE** $149/mo · $1,490/yr |
|---|---|---|---|---|
| **AI conversations / month** | 100 | 1,000 | 5,000 | 20,000 |
| **Voice minutes / month** | **0** | 60 | 300 | 1,200 |
| Voice overage | — | $0.35/min | $0.30/min | $0.25/min |
| Conversation overage | hard stop | $0.02 each | $0.015 each | $0.01 each |
| Projects (isolated DBs) | 1 | 1 | 3 | 10 |
| Team seats | 2 | 3 | 10 | 25 |
| AI agents / personas | 1 | 2 | 10 | Unlimited |
| Phone numbers | 0 | 1 | 3 | 10 |
| Data sources | 1 | 3 | Unlimited | Unlimited |
| Indexed content | 50 pages / 20 MB | 500 pages / 100 MB | 5,000 pages / 1 GB | 25,000 pages / 5 GB |
| History retention | 7 days | 30 days | Unlimited | Unlimited |
| **— Never gated —** | | | | |
| Voice cloning + 30 stock voices | ✅ (stock only) | ✅ | ✅ | ✅ |
| Multi-language (13) | ✅ | ✅ | ✅ | ✅ |
| Lead capture → CRM | ✅ | ✅ | ✅ | ✅ |
| Website chat widget | ✅ | ✅ | ✅ | ✅ |
| RAG: website + documents | ✅ | ✅ | ✅ | ✅ |
| Transcripts & summaries | ✅ | ✅ | ✅ | ✅ |
| **— The eight gates —** | | | | |
| Voice / telephony | ✗ | ✅ | ✅ | ✅ |
| WhatsApp / IG / FB | ✗ | WhatsApp + 1 | All | All |
| Flow builder | ✗ | 1 | Unlimited | Unlimited |
| Team roles & permissions | ✗ | ✗ | ✅ | ✅ |
| API + webhooks | ✗ | ✗ | ✅ | ✅ |
| Live DB connector + access control | ✗ | ✗ | ✅ | ✅ |
| Remove branding | ✗ | +$9/mo | ✅ | ✅ |
| White-label · BYO LLM · audit export | ✗ | ✗ | ✗ | ✅ |
| **Support** | Community | Email | Priority email | Priority + onboarding |

Voice-minute allowances are calibrated so the effective rate stays healthy — **$0.317 / $0.197 / $0.124 per minute**. For reference: Dialzara $0.45–0.48/min, Rosie $0.149–0.196/min. We are cheaper than Dialzara at every tier and at or below Rosie at every tier, *while also bundling web chat, WhatsApp, Instagram, Facebook, flows and CRM that neither of them sells.*

### Add-ons (database-driven, none required at launch)
Extra voice-minute packs · extra conversation packs · extra project +$15/mo · extra seat +$5/mo · extra phone number +$5/mo · remove branding on Starter +$9/mo.

---

## 5. Free plan vs 7-day trial — kept strictly separate

| | **FREE PLAN** | **7-DAY TRIAL** |
|---|---|---|
| Price | $0 forever | $0 for 7 days, then the plan's price |
| Features | Free-tier limits, **no voice** | **Full features of the paid plan being trialled** |
| Stripe | **No customer, no subscription** | Real subscription, `trialing` status |
| DB | `plan_id = free` (or no row) | `stripe_status = 'trialing'`, `trial_ends_at` set |
| Eligibility | Everyone | **Once per business** — §7 |
| When it ends | Never | Converts to paid, or **falls back to Free** |

**Trial → Free fallback is deliberate.** A lapsed trial drops to Free rather than locking the account. Data stays, the widget keeps answering within free limits, the upgrade path stays open. Locking someone out of a product that is answering their customers' calls turns a lapsed trial into a support ticket and a bad review.

---

## 6. Trial: card required, configurable per plan

**Recommendation: card required up front** (Stripe Checkout, `trial_period_days=7`, `payment_method_collection=always`).

1. **The trial gives away real cost** — 7 days of Growth is up to 300 voice minutes plus 5,000 conversations. Every voice vendor in the set (Rosie, Dialzara, Goodcall, Simple Phones) requires a card for this reason; none advertises "no credit card" on a trial. The vendors that do (Tidio, Intercom, respond.io, Zoho) are giving away text that costs them almost nothing.
2. **Card-required trials convert far better** — conversion is passive; the customer does nothing and becomes a paying customer.
3. **The card is the abuse control** (§7).
4. **We keep the "no credit card required" promise** — relocated to the **Free plan**, where it is literally true. The pricing page then says both things honestly: *free forever, no card* on the Free card; *7-day trial, card required, cancel anytime* on the paid cards.

**Configurable, not hard-coded:** `plans.trial_requires_payment_method` and `plans.trial_days`, both Super-Admin editable, so this can be A/B tested or dropped for a launch promo with no deploy. Stripe supports both natively.

**Required either way:** email at trial start, day-5 reminder (Stripe's `trial_will_end` fires 3 days out), days-remaining banner in-app, one-click cancel.

---

## 7. Trial abuse prevention

A user can create unlimited workspaces, so a per-workspace trial is trivially farmed. Eligibility keys on a fingerprint set in `trial_fingerprints`:

1. Owner `user_id`
2. **Normalised email** — lowercase, strip `+tags`, strip dots in Gmail-family domains
3. **Stripe PaymentMethod fingerprint** — stable per card across customers; the strongest signal, and the main reason to take the card up front
4. Business website domain (optional, from project profile)

A repeat fingerprint doesn't block the purchase — it goes **straight to paid checkout with no trial**, with a clear message. Super Admin gets an audited "grant trial" override.

---

## 8. Regional display pricing

Charged in USD, always. Local amount is informational and rounded coarsely so it can never read as a quote.

| Plan | USD (charged) | Pakistan ≈ | UAE ≈ | UK ≈ |
|---|---|---|---|---|
| Starter monthly | $19 | PKR 5,400/mo | AED 70/mo | £14/mo |
| Growth monthly | $59 | PKR 16,600/mo | AED 217/mo | £44/mo |
| Growth annual | $590 | PKR 166,000/yr | AED 2,167/yr | £437/yr |
| Scale monthly | $149 | PKR 41,900/mo | AED 547/mo | £111/mo |

> **Illustrative format only** — real values come from `plan_prices.unit_amount` × the cached rate from `ExchangeRateService` at render time.

Required on the page: **"Prices are charged in USD. Local currency amounts are approximate."** If geolocation or FX is unavailable, the local line simply doesn't render and USD stands alone.

At ≈ PKR 5,400/month, Starter costs less than a single day of a part-time receptionist in Lahore. That comparison belongs on the pricing page.

---

## 9. Marketing-site impact

| Existing copy (`config/site.php`) | Status |
|---|---|
| `hero_meta1` "No credit card" · `cta_button` "Start free — no card required" | ✅ both true of the Free plan; keep them next to the free CTA |
| `faq6_a` "Start free… upgrade when ready, cancel anytime… no lock-in" | ✅ all four claims hold; worth adding one line naming the 7-day trial and that it takes a card |
| Footer / nav | ⚠️ needs a `/pricing` link — no pricing route exists today |
| `seo.sitemap_urls`, `seo.page_views` | ⚠️ need a `/pricing` entry each |

---

## 10. Everything here is Super-Admin editable

Changeable at `/admin/billing/plans` with **no code change, no migration, no deploy**: any price on any interval · add/remove a plan or a whole billing interval (including switching quarterly back on) · every usage limit and feature value · trial length and whether a card is required · plan order, name, description, badge, CTA · popular flag · active/inactive · public/private.

Not editable by design: Stripe secret key, webhook secret, geo/FX API keys. Those stay in `.env`.

### The Stripe caveat, and why it protects you

Stripe Prices are **immutable**. Changing $19 → $29 doesn't edit anything — the system creates a **new** Stripe Price and archives the old one. **Existing subscribers keep the price they signed up at** until explicitly migrated.

> Launch at $19 / $59 / $149. If that's too cheap in six months, raise it from the admin panel. Every early customer keeps their original price forever — a real loyalty reward — and new customers pay the new rate.

The reverse isn't true: launching high and cutting later means refunds or customers quietly overpaying. **The asymmetry favours starting low.**

---

## 11. Honest risk

At $59, Growth's margin depends on the voice allowance holding and on the local-model path (XTTS + Ollama/Groq) staying the default. If a meaningful share of Growth customers push 300 minutes through a premium cloud LLM plus ElevenLabs voices, that tier gets thin. Three things in the design keep it safe: **allowances reduced alongside the prices** (§4), **published overage** that turns heavy users into revenue rather than loss, and **BYO-LLM gated to Scale**, so the customers most likely to want expensive models are the ones paying $149 or bringing their own key. Launch at these numbers, watch real cost-per-workspace for 60 days, and correct upward from the admin panel if needed — grandfathering makes that painless.

---

## 12. What I'm asking you to approve

1. **4 plans** — Free, Starter, Growth, Scale — plus an Enterprise "talk to us" band (not a pricing card).
2. **2 billing intervals shown** — monthly + annual. Quarterly stays supported in the schema, switched off.
3. **Prices** — $0 / $19 / $59 / $149 monthly; $190 / $590 / $1,490 annual (**2 months free**).
4. **Popular plan** — Growth ($59), badged.
5. **Feature philosophy** — all *core* features on every plan; differentiate on **volume** plus exactly **eight** on/off gates (§3).
6. **Free plan** — 100 conversations, **zero voice minutes**, web chat only, 1 project, 2 seats, 7-day history.
7. **Trial** — 7 days on all three paid plans, card required by default but configurable, lapses to Free rather than lockout.
8. **Trial abuse** — fingerprint on user + normalised email + card fingerprint + business domain.
9. **Billing subject** — the **Client (workspace)**.

### Two open items

- **Free-tier voice:** I recommend **zero** minutes. A one-off taste (e.g. 10 minutes lifetime, not monthly) is possible but changes the abuse model and the cost floor.
- **Overage vs hard stop:** I recommend published overage on paid tiers, hard stop on Free. Hard stops everywhere is simpler to build but caps revenue and means the phone stops being answered mid-month.

---

**Nothing proceeds until you approve.** On approval I implement Phase 8 in full: schema, Stripe Billing, Super Admin → Billing → Plans, 7-day trial, free plan, `GeoLocationService` + `ExchangeRateService`, the public pricing page, customer billing area, idempotent webhooks, tests, and the two final documents.
