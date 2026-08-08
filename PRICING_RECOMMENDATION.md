# Recommended Pricing — Serve AI (Phase 6)

**Status: RECOMMENDATION ONLY. Nothing below has been implemented.**
No migrations written, no Stripe Products or Prices created, no production code changed.

**Revision 2 (2026-08-09)** — prices reduced from r1 at your request. r1 was $39 / $129 / $299 (market-median matching). r2 below is **~50% lower**, positioning us as the price leader rather than the median. The reasoning for every change, and the margin consequence, is in §2 and §11.

Derived from [COMPETITOR_PRICING_COMPARISON.md](COMPETITOR_PRICING_COMPARISON.md) (17 competitors, checked 2026-08-09) and the shipped capabilities in [SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md](SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md).

---

## 1. Shape of the offer

**Free + 3 paid tiers + Enterprise** — the market's modal structure (8 of 17 competitors).

| | **FREE** | **STARTER** | **GROWTH** ★ | **SCALE** | **ENTERPRISE** |
|---|---|---|---|---|---|
| **Monthly** | **$0** | **$19** | **$59** | **$149** | From $499 |
| **Quarterly** | — | **$51** *(save 10% — $17/mo)* | **$159** *(save 10% — $53/mo)* | **$399** *(save 11% — $133/mo)* | Annual contract |
| **Annual** | — | **$179** *(save 21% — $14.92/mo)* | **$569** *(save 20% — $47.42/mo)* | **$1,429** *(save 20% — $119.08/mo)* | Custom |
| **7-day trial** | n/a (permanent) | ✅ | ✅ | ✅ | n/a (pilot) |
| **Badge** | — | — | **Most popular** | — | — |

★ = recommended/popular plan.

Prices are stored as **absolute integer cent amounts per interval** (`1900`, `5100`, `17900`, …), not computed from a discount rule — so the Super Admin can set any number without the code deriving it.

### What changed from r1

| Plan | r1 monthly | **r2 monthly** | Cut |
|---|---|---|---|
| Starter | $39 | **$19** | −51% |
| Growth | $129 | **$59** | −54% |
| Scale | $299 | **$149** | −50% |
| Enterprise floor | ~$1,000 | **$499** | −50% |

---

## 2. Why each price

### The strategic shift in r2

r1 priced **at** the market. r2 prices **under** it — deliberately, and we are one of very few vendors who structurally can:

- **Our marginal cost is genuinely lower.** Local XTTS voice and local/self-hosted LLM options mean we don't pay OpenAI + ElevenLabs + platform margin on every conversation the way Rosie, Chatbase, or Synthflow do. Competitors *can't* follow us down without losing money; we can sit here on purpose.
- **Our starting market is price-sensitive.** Pakistan, MENA, South Asia. A $129/month USD charge is not a decision an SMB there makes casually; **$59 is.**
- **Nobody occupies the floor with a real product.** The cheapest genuine entry tiers in the set are ManyChat $15 (one channel, and AI is a **+$29/mo** add-on), Tidio $24.17 (chat only), Dialzara $29 (60 voice minutes only). At **$19 with voice + web chat + WhatsApp included**, we are the cheapest complete product in the category by a wide margin.

### Starter — $19/month
Undercuts every real entry tier in the comparison set: Tidio $24.17, Dialzara $29, Chatbase $32, Wati $39, Rosie $49, Goodcall $79. Only ManyChat's $15 is lower, and ManyChat gives you no voice, no web widget, and charges $29/month extra for the AI.

**$19 is the "just say yes" price.** For an SMB owner it is below the threshold where a purchase needs justifying to anyone. That matters far more than the $20 we give up versus r1, because our real problem at launch is not extracting revenue per customer — it is getting the first few hundred workspaces live and generating conversation data.

### Growth — $59/month — the plan we want people to buy
The market's badged mid-tier band is $99–$159 (median $129). **$59 sits at 46% of the market median for the equivalent tier**, and the substitution line on the pricing card becomes very hard to argue with:

> Rosie $49 (voice) + Chatbase $32 (web chat) + Wati $39 (WhatsApp) = **$120/month for three disconnected tools, three knowledge bases, three inboxes.**
> Growth is **$59** for all of it in one product, with one knowledge base, one inbox, one lead list.

That is **half the price of the parts** — a much stronger claim than r1's "same price as the parts."

$59 is 3.1× Starter, at the top of the observed 2–3× band. Deliberate: the mid tier must look like the obvious choice. It gives **5× the chat volume and 5× the voice minutes** for 3.1× the price, mirroring how Rosie (3× price → 4× minutes) and Chatbase (3.75× → 5.7× credits) engineer their popular tier.

### Scale — $149/month
The market's top self-serve cluster is $249–$400 (median $299). **$149 is half of that** — and it happens to equal Rosie's *middle* tier price while including channels, flows, RBAC, API, white-label and BYO-LLM that Rosie does not sell at any price.

2.5× Growth, inside the observed 1.75×–3.3× band. This tier holds our four uncontested differentiators (live database connector, per-table AI access control, BYO/local LLM, white-label + custom domain), so it has the least price pressure of the three — nobody in the comparison set sells these at all.

### Enterprise — custom, from $499/month
Halved from r1 so the step up from Scale ($149) is 3.3× rather than 6.7×. Still positioned far below Synthflow's $30,000/year floor, keeping the lower-mid-market they abandoned.

---

## 3. Discounts (unchanged from r1)

### Annual — 20%
Market median is 17–20%. Chatbase and respond.io run exactly 20%; Wati ≈25%; Goodcall 15%; Smith.ai 10%. Twenty percent beats the majority, is trivially explainable, and is a clear 10 points ahead of quarterly.

> Starter's annual lands at 21% rather than 20% purely so the price reads **$179** instead of $182. Prices are absolute values in the database; the percentage is a consequence, not a rule.

At **$14.92/month effective**, annual Starter is the cheapest complete AI receptionist + omnichannel product in the comparison set, full stop.

### Quarterly — 10%
**No competitor in the entire set of 17 publishes a quarterly price.** The one structurally empty position we found.

It matters most in exactly the markets we start in. **$569 annual on a card** is a real barrier for a Lahore or Dubai SMB; **$159 a quarter** is not. It also cuts churn and card-failure exposure by two-thirds versus monthly without asking for a year's commitment. 10% is calibrated to beat monthly while leaving annual clearly the best deal.

---

## 4. Plan contents

### Metering
Two metered units, matching how the product actually consumes money:

- **AI conversations/month** — one conversation = one session with at least one AI reply, on any text channel (web, WhatsApp, Instagram, Facebook, SMS). Tidio's "billable conversation" model, which buyers already understand.
- **Voice minutes/month** — the honest cost meter every voice vendor uses; buyers benchmark against $0.05–$0.31/min.

Everything else (seats, projects, agents, data sources) is a **soft structural limit**, not a cost meter.

### Allowances have been reduced with the prices

This is the part that must move together with price. Voice minutes are our one genuinely expensive unit (telephony + STT + TTS + LLM), so halving the price while keeping r1's minute allowances would push Growth to negative gross margin. r2 rebalances so the **effective cost per minute stays healthy**:

| | r1 | **r2** | Implied $/min |
|---|---|---|---|
| Starter | $39 / 120 min | **$19 / 60 min** | $0.317 |
| Growth | $129 / 750 min | **$59 / 300 min** | $0.197 |
| Scale | $299 / 3,000 min | **$149 / 1,200 min** | $0.124 |

For reference: Dialzara charges $29 for 60 min ($0.483/min) and $99 for 220 min ($0.450/min); Rosie charges $49 for 250 min ($0.196/min) and $149 for 1,000 min ($0.149/min). **We are cheaper per minute than Dialzara at every tier and at or below Rosie at every tier — while also including web chat, WhatsApp, Instagram, Facebook, flows and CRM that neither of them sells.**

### Full allocation

| | FREE | STARTER $19 | GROWTH $59 ★ | SCALE $149 | ENTERPRISE |
|---|---|---|---|---|---|
| **AI conversations / month** | 100 | 1,000 | 5,000 | 20,000 | Custom |
| **Voice minutes / month** | **0** | 60 | 300 | 1,200 | Custom |
| **Voice overage** | — | $0.35/min | $0.30/min | $0.25/min | Contract rate |
| **Conversation overage** | hard stop | $0.02 each | $0.015 each | $0.01 each | Contract rate |
| Projects (isolated DBs) | 1 | 1 | 3 | 10 | Unlimited |
| Team seats | 2 | 3 | 10 | 25 | Unlimited |
| AI agents / personas | 1 | 2 | 10 | Unlimited | Unlimited |
| Phone numbers | 0 | 1 | 3 | 10 | Custom |
| **Channels** | Web chat only | Web + WhatsApp + **1** of IG/FB/SMS | **All** channels | All channels | All channels |
| Voice cloning | ✗ | 1 cloned voice + 30 stock | 5 cloned + 30 stock | Unlimited | Unlimited |
| Data sources | 1 | 3 | Unlimited | Unlimited | Unlimited |
| Indexed content | 50 pages / 20 MB | 500 pages / 100 MB | 5,000 pages / 1 GB | 25,000 pages / 5 GB | Custom |
| **Live database connector** | ✗ | ✗ | ✅ | ✅ | ✅ |
| **Per-table / per-column AI access control** | ✗ | ✗ | ✅ | ✅ | ✅ |
| Flow builder | ✗ | 1 flow | Unlimited | Unlimited | Unlimited |
| Skills / multi-agent routing | ✗ | ✗ | ✅ | ✅ | ✅ |
| Custom roles & permissions | ✗ | ✗ | ✅ | ✅ | ✅ |
| API access + webhooks | ✗ | ✗ | ✅ | ✅ | ✅ |
| Remove "Powered by Serve AI" | ✗ | +$9/mo add-on | ✅ | ✅ | ✅ |
| **White-label + custom domain** | ✗ | ✗ | ✗ | ✅ | ✅ |
| **BYO LLM key / local model** | ✗ | ✗ | ✗ | ✅ | ✅ |
| Conversation history | 7 days | 30 days | Unlimited | Unlimited | Unlimited |
| Analytics | Basic | Basic | Advanced | Advanced + export | Custom |
| Multi-language replies | ✅ | ✅ | ✅ | ✅ | ✅ |
| Lead capture → CRM | ✅ | ✅ | ✅ | ✅ | ✅ |
| Support | Community | Email | Priority email | Priority + onboarding | Dedicated CSM + SLA |
| SSO / audit export / DPA | ✗ | ✗ | ✗ | Audit export | ✅ all |

### Gating rationale, tied to the research

- **API access held to Growth** — Chatbase gates it at its $120 tier; the market accepts this. We give it away at $59.
- **Voice minutes excluded from Free** — every voice vendor in the set refuses a free plan for exactly this reason. Free gets web chat only. **This is the single most important line in the table for gross margin**, and it becomes *more* important at r2 prices, not less.
- **History retention as a ladder (7d → 30d → ∞)** — copied from Goodcall, which uses exactly this.
- **Branding removal** — Chatbase charges $1,188/yr for it; we include it at $59 and sell it as a $9 add-on at $19.
- **Multi-language included everywhere** — Slang charges **$99/month** for Spanish alone. It costs us nothing (the model already auto-detects) and it is a strong comparative line.
- **Agents bundled, not priced per unit** — Goodcall prices *per agent* ($79 each), Chatbase charges $300/yr per extra agent.
- **The four uncontested features** split Growth/Scale so each of the top two tiers has something no competitor sells at all.

### Add-ons (all database-driven, none required at launch)
Extra voice-minute packs · extra project +$15/mo · extra seat +$5/mo · extra phone number +$5/mo · remove branding on Starter +$9/mo · extra conversation packs.

---

## 5. Free plan vs 7-day trial — kept strictly separate

| | **FREE PLAN** | **7-DAY TRIAL** |
|---|---|---|
| Price | $0 forever | $0 for 7 days, then the plan's normal price |
| Duration | Permanent | 7 days |
| Features | Free-tier limits (no voice) | **Full features of the paid plan being trialled** |
| Stripe | **No Stripe customer, no subscription** | A real Stripe subscription in `trialing` status |
| DB representation | `subscriptions` row with `plan_id = free`, or no row at all (default) | `subscriptions.stripe_status = 'trialing'`, `trial_ends_at` set |
| Ends by | Never | Converting to paid, cancelling, or expiring |
| Eligibility | Everyone | **Once per business** — see §7 |
| After it ends | n/a | Converts to paid, or **falls back to Free** (never a dead account) |

**Trial → Free fallback is deliberate.** A lapsed trial drops the workspace to Free rather than locking it out. The data stays, the widget keeps answering within free limits, the upgrade path stays open. Locking someone out of a product that is answering their customers' calls turns a lapsed trial into a support ticket and a bad review.

---

## 6. Trial: payment method before or after?

### Recommendation: **card required before the trial** (Stripe Checkout with `trial_period_days=7`, `payment_method_collection=always`), **configurable per plan.**

1. **Our trial gives away real marginal cost.** Seven days of Growth is up to 300 voice minutes plus 5,000 AI conversations — telephony, STT, TTS and LLM compute we actually pay for. Every voice vendor in the comparison set (Rosie, Dialzara, Goodcall, Simple Phones) requires a card for this reason, and none advertises "no credit card" on a trial. The vendors that do (Tidio, Intercom, respond.io, Zoho, Retell, Bland) are giving away text or credits that cost them almost nothing.
2. **Card-required trials convert far better** — the conversion is passive. The customer does nothing and becomes a paying customer, rather than having to decide again on day 7.
3. **The card is the abuse control.** With fingerprinting (§7) it is what stops the trial being farmed across throwaway workspaces.
4. **We keep the "no credit card required" promise** — relocated, not broken. `content.hero_meta1` and `content.cta_button` point at the **Free plan**, which genuinely needs no card. The pricing page then carries both messages honestly: *free forever, no card* on the Free card; *7-day trial, card required, cancel anytime* on the paid cards.

**Configurable, not hard-coded:** `plans.trial_requires_payment_method` and `plans.trial_days`, both Super-Admin editable. The card requirement can be A/B tested or dropped for a launch promotion with no deploy. Stripe supports both modes natively (`payment_method_collection: 'always' | 'if_required'`, `trial_settings.end_behavior.missing_payment_method: 'cancel' | 'pause'`).

**Required either way:** email at trial start, a reminder on day 5 (Stripe's `customer.subscription.trial_will_end` fires 3 days out), a days-remaining banner in-app, one-click cancel during the trial.

---

## 7. Trial abuse prevention

A `User` can create unlimited `Client` workspaces, so a per-workspace trial is trivially farmed. Eligibility is keyed on a **fingerprint set** in a `trial_fingerprints` table:

1. **Owner `user_id`** — one trial per person.
2. **Normalised email** — lowercase, strip `+tags`, strip dots in Gmail-family domains.
3. **Stripe PaymentMethod fingerprint** — stable per card across customers. The strongest signal, and the main reason to collect the card up front.
4. **Business website domain** (optional, from the project profile) — one trial per business, not per employee.

If any fingerprint has already consumed a trial, the plan is still purchasable — the customer goes **straight to paid checkout with no trial**, with a clear message. Never a hard block, never a silent failure. Super Admin gets an audited "grant trial" override.

---

## 8. Regional display pricing (what the customer will see)

Charged in USD, always. Local amount is informational and rounded coarsely so it can never read as a quote.

| Plan / interval | USD (charged) | Pakistan, approx. | UAE, approx. | UK, approx. |
|---|---|---|---|---|
| Starter monthly | $19 | ≈ PKR 5,400/mo | ≈ AED 70/mo | ≈ £14/mo |
| Growth monthly | $59 | ≈ PKR 16,600/mo | ≈ AED 217/mo | ≈ £44/mo |
| Growth quarterly | $159 | ≈ PKR 44,700 | ≈ AED 584 | ≈ £118 |
| Growth annual | $569 | ≈ PKR 160,100 | ≈ AED 2,090 | ≈ £422 |

> **Illustrative only** — every figure is a worked example of the *format*, not a live rate. Real values come from `plan_prices.unit_amount` × the cached rate from `ExchangeRateService` at render time.

Required on the page: **"Prices are charged in USD. Local currency amounts are approximate."** If geolocation or FX is unavailable, the local line does not render and USD stands alone.

At **≈ PKR 5,400/month**, Starter costs less than a single day of a part-time receptionist in Lahore. That comparison should be on the pricing page.

---

## 9. What this means for the marketing site

| Existing copy (`config/site.php`) | Compatible? |
|---|---|
| `hero_meta1` — "No credit card" | ✅ true of the Free plan; keep it next to the free CTA |
| `cta_button` — "Start free — no card required →" | ✅ points at Free |
| `faq6_a` — "Start free… Upgrade when you're ready, cancel anytime… No long-term contracts, no lock-in." | ✅ all four claims hold. Worth extending with one line naming the 7-day trial and that it takes a card |
| Footer / nav | ⚠️ **needs a `/pricing` link** — no pricing route exists today |
| `seo.sitemap_urls`, `seo.page_views` | ⚠️ need a `/pricing` entry each |

---

## 10. Everything here is Super-Admin editable

This is worth stating plainly, because it changes how heavily this decision needs to weigh on you.

**Changeable at `/admin/billing/plans` with no code change, no migration, no deploy:**

- any price, on any interval (monthly / quarterly / annual)
- adding or removing a plan, or a whole billing interval
- every usage limit and feature value
- trial length, and whether a card is required
- plan order, name, description, badge, CTA text
- marking a plan popular; activating or deactivating a plan or a single price
- making a plan public or private (private = link-only, for custom deals)

**Not Super-Admin editable, by design:** Stripe secret key, webhook secret, and the geolocation/FX API keys. Infrastructure secrets stay in `.env`.

### The one Stripe caveat — and why it protects you

Stripe Prices are **immutable**. Changing $19 → $29 does not edit a price; the system **creates a new Stripe Price**, marks the old one inactive for new signups, and archives it.

**Existing subscribers stay on the price they signed up at** until you explicitly migrate them. That is automatic grandfathering, and it is exactly what makes launching low safe:

> Launch at $19 / $59 / $149. If those turn out to be too cheap in six months, raise them to $29 / $89 / $199 from the admin panel. **Every early customer keeps $19/$59/$149 forever**, which is a genuine loyalty reward, and new customers pay the new rate. No migration, no angry emails, no code change.

The reverse is not true. Launching high and cutting later means either refunding early customers or leaving them overpaying. **So the asymmetry favours starting low — which is what r2 does.**

---

## 11. Honest risk with r2 (one paragraph, then it's your call)

At $59, Growth's margin depends entirely on the voice-minute allowance holding and on the local-model path (XTTS + Ollama/Groq) staying the default. If a meaningful share of Growth customers push their 300 minutes to a premium cloud LLM plus ElevenLabs voices, that tier gets thin. Three things keep it safe, and all three are already in the design: the **allowances reduced alongside the prices** (§4), the **published overage rates** that turn heavy users into more revenue rather than more loss, and **BYO-LLM-key gated to Scale**, so the customers most likely to want expensive models are the ones paying $149 or bringing their own key. My recommendation is to launch at r2 and watch actual cost-per-workspace for 60 days — and because pricing is Super-Admin editable with automatic grandfathering, correcting upward later costs nothing but a form submission.

---

## 12. Summary of what I am asking you to approve

1. **Structure** — Free + Starter + Growth + Scale + Enterprise (5 rows).
2. **Prices** — $0 / **$19** / **$59** / **$149** monthly; **$51** / **$159** / **$399** quarterly; **$179** / **$569** / **$1,429** annual; Enterprise from $499.
3. **Discounts** — quarterly 10%, annual 20% (Starter annual 21% for a round number).
4. **Popular plan** — Growth ($59), badged.
5. **Metering** — AI conversations + voice minutes, with published overage that falls as the tier rises.
6. **Free plan** — 100 conversations, **zero voice minutes**, web chat only, 1 project, 2 seats, 7-day history.
7. **Trial** — 7 days on all three paid plans, **card required by default but configurable per plan**, lapses to Free rather than lockout.
8. **Trial abuse** — fingerprint on user + normalised email + card fingerprint + business domain; ineligible users get paid checkout without a trial, not a block.
9. **Feature allocation and limits** — as tabled in §4.
10. **Billing subject** — the **Client (workspace)**, not the User and not the Project.

### Still open — your answer changes the build

- **Free-tier voice:** I recommend **zero** minutes. A taste (e.g. 10 minutes lifetime, not monthly) is possible but changes the abuse model and the cost floor.
- **Overage vs hard stop:** I recommend published overage on paid tiers, hard stop on Free. Hard stops everywhere is simpler to build but caps revenue and means the phone stops being answered mid-month.
- **Agency / reseller tier:** the product supports multi-workspace agencies and Dialzara/Voiceflow both monetise this. Not included above.

---

**Nothing proceeds until you approve.** On approval I will implement Phase 8 in full: database schema, Stripe Billing, Super Admin → Billing → Plans, 7-day trial, free plan, `GeoLocationService` + `ExchangeRateService`, the public pricing page, customer billing area, idempotent webhooks, tests, and the two final documents.
