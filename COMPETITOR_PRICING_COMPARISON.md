# Competitor Pricing & Feature Comparison (Phases 2–5)

**Product:** Serve AI (`AI-CRM-AGENT`)
**All pricing checked:** **2026-08-09** (single research pass)
**Currency:** all figures **USD** unless noted.
**Method:** each vendor's own public pricing page was fetched directly. Where a page did not expose numbers to an automated fetch, that is stated explicitly and the gap is marked — **nothing in this document is estimated or invented.**

---

## Phase 2 — What our product actually is

### Evidence used
- [admin/config/site.php](admin/config/site.php) — the marketing site's own feature grid, use-cases and FAQ
- [admin/config/modules.php](admin/config/modules.php) — the 17 shipped admin modules
- [README.md](README.md) — architecture and data-source tiers
- [admin/routes/web.php](admin/routes/web.php) — every shipped surface

### Shipped capability set

| Capability | Evidence |
|---|---|
| **Inbound/outbound voice calls** with cloned or stock voices (XTTS-v2 local, ElevenLabs opt-in), Twilio numbers per project | `telephony`, `voices`, `agent-voices` modules; `voice-engine/` |
| **Website chat widget**, embeddable, customisable | `widget` module, `widget/` package |
| **WhatsApp / Instagram / Facebook Messenger** via official Meta Cloud API, with a shared agent inbox, 24-h window handling, templates, media, flows | `channels` + `messages` modules; `packages/meta-channels` |
| **RAG over the customer's own data** — website crawl, PDF/CSV/DOCX upload, live database connector, snapshots | `data_sources` module; DuckDB/BM25 layer |
| **Per-table / per-column AI access control** | `data-sources.access` routes |
| **Automatic lead extraction → CRM** | `leads` module, `Lead` model |
| **Visual conversation flow builder** with live test runner | `flows` module |
| **Multi-agent personas + skill routing** | `agents`, `skills` modules |
| **Teams, custom roles, per-project permissions** | `team` module, `roles` table |
| **Conversation history, transcripts, analytics** | `conversations` module |
| **Multi-tenant isolation** — a separate database per project | `TenantManager` |
| **Choice of AI engine** — cloud provider or fully local model | `bot_strategy` / `brain-settings` |
| **Multi-language** — auto-detect and reply in kind | memory: multi-language responses |

### Target customer (from the site's own use-case section)
Shops & online stores, clinics & salons, real estate, restaurants & hospitality, services & trades, agencies & B2B — i.e. **SMB and lower-mid-market**, non-technical buyers, self-serve, with an agency/multi-workspace angle. Home market Pakistan, selling internationally in USD.

### Category verdict

> **AI receptionist / AI voice agent + omnichannel AI chat inbox with built-in lead capture & CRM, for SMBs.**

This sits at the **intersection of three markets**, which is why the competitor set below is deliberately drawn from all three plus the developer platforms that set the underlying cost floor:

| Segment | Why it's relevant | Competitors chosen |
|---|---|---|
| **A. SMB AI receptionist / phone answering** | Our voice module competes here head-on; these vendors define what an SMB will pay per month | Rosie, Goodcall, Dialzara, Simple Phones, Slang.ai, Smith.ai |
| **B. AI chat agent / support automation** | Our web widget + RAG + lead capture competes here | Tidio (Lyro), Chatbase, Intercom (Fin), Zoho SalesIQ |
| **C. Omnichannel / WhatsApp business messaging** | Our Meta channels + shared inbox competes here | Wati, respond.io, ManyChat |
| **D. Voice-AI developer platforms** | Not a sales competitor, but they set the **cost floor and the per-minute reference price** every buyer now benchmarks against | Vapi, Retell AI, Bland AI, Synthflow |

Deliberately **excluded** as irrelevant to our segment: HubSpot, Salesforce, Zendesk, Freshdesk (full CRM/helpdesk suites, different buyer), Sierra / PolyAI / Parloa (enterprise-only contact centre AI, six-figure contracts).

---

## Phase 3 & 4 — Master pricing comparison

### Source register

| # | Competitor | Segment | Pricing page | Date checked | Currency | Notes on data quality |
|---|---|---|---|---|---|---|
| 1 | Rosie | A | https://heyrosie.com/pricing | 2026-08-09 | USD | Complete; annual stated as "2 months free" without absolute figures |
| 2 | Goodcall | A | https://www.goodcall.com/pricing | 2026-08-09 | USD | Complete; free-trial **duration not stated** on page |
| 3 | Dialzara | A | https://dialzara.com/pricing | 2026-08-09 | USD | Complete; **no annual pricing disclosed** |
| 4 | Simple Phones | A | https://www.simplephones.ai/pricing | 2026-08-09 | USD | **Partial** — higher tiers rendered as images, not readable |
| 5 | Slang.ai | A | https://www.slang.ai/pricing | 2026-08-09 | USD (CAD/AUD selectable) | Partial — **no annual, trial, minutes or overage disclosed** |
| 6 | Smith.ai | A | https://smith.ai/pricing | 2026-08-09 | USD | Complete (human + AI hybrid, not pure AI) |
| 7 | Tidio (Lyro) | B | https://www.tidio.com/pricing/ | 2026-08-09 | USD | Complete |
| 8 | Chatbase | B | https://www.chatbase.co/pricing | 2026-08-09 | USD | Complete |
| 9 | Intercom (Fin) | B | https://www.intercom.com/pricing | 2026-08-09 | USD | Complete for annual; **monthly per-seat rates not itemised** on page |
| 10 | Zoho SalesIQ | B | https://www.zoho.com/salesiq/pricing.html | 2026-08-09 | USD/INR/EUR/GBP/… | **Prices not exposed** to fetch — limits captured, dollar figures NOT captured |
| 11 | Wati | C | https://www.wati.io/pricing/ | 2026-08-09 | USD (INR PAYG option) | Complete |
| 12 | respond.io | C | https://respond.io/pricing | 2026-08-09 | USD | Complete |
| 13 | ManyChat | C | https://manychat.com/pricing | 2026-08-09 | USD | **Page returned HTTP 403** to automated fetch; figures below come from third-party 2026 pricing guides and are marked as such |
| 14 | Vapi | D | https://vapi.ai/pricing | 2026-08-09 | USD | Complete |
| 15 | Retell AI | D | https://www.retellai.com/pricing | 2026-08-09 | USD | Complete |
| 16 | Bland AI | D | https://www.bland.ai/pricing | 2026-08-09 | USD | Complete |
| 17 | Synthflow | D | https://synthflow.ai/pricing | 2026-08-09 | USD | Complete — page is now **Enterprise-only** |

Secondary sources used only where a primary page was unreadable (#13) or where a documented change needed corroboration (#17):
[Chatarmin ManyChat pricing 2026](https://chatarmin.com/en/blog/manychat-pricing) ·
[Layer3Labs ManyChat guide](https://www.layer3labs.io/guides/manychat-pricing) ·
[Flowgent ManyChat pricing](https://flowgent.ai/blog/manychat-pricing) ·
[CloudTalk: Synthflow pricing](https://www.cloudtalk.io/synthflow-pricing/) ·
[Squawkvoice: Synthflow pricing 2026](https://www.squawkvoice.ai/blog/synthflow-pricing-plans-usage-costs-and-how-it-compares-to-squawkvoice) ·
[pxlpeak: Synthflow pricing guide](https://pxlpeak.com/blog/ai-tools/synthflow-pricing-guide)

---

### A. Master table

| Competitor | Free | Trial | Monthly (entry → top self-serve) | Quarterly | Annual | Pricing model | Main limits | Key features | Popular plan |
|---|---|---|---|---|---|---|---|---|---|
| **Rosie** | ✗ | **7 days** | $49 → $149 → $299 | ✗ | "2 months free" (≈16.7%) | Flat tiers by **minutes** | 250 / 1,000 / 2,000 min per month | AI answering, call summaries, transfers, calendar booking, EN+ES, Zapier, free web chat | **Scale $149** |
| **Goodcall** | ✗ | Yes (**length not stated**) | $79 → $129 → $249 **per agent** | ✗ | **15% off** ($66/$108/$208) | Per **agent**, unlimited minutes, capped by **unique customers** | 100 / 250 / 500 unique callers/mo, then $0.50 each; 3/9/50 team members; 1/3/25 logic flows; 7d/30d/∞ history | Unlimited minutes & tokens, Zapier, directory, phone number per agent | **Growth $129** |
| **Dialzara** | ✗ | **7 days** | $29 → $99 → $199 → $349 | ✗ | **Not disclosed** | Flat tiers by **minutes** + overage | 60 / 220 / 500 / 1,000 min; overage $0.48 / $0.45 / $0.40 / $0.35 per min | Inbound voice; AI SMS agent ($19/mo + $0.05/msg); AI web chatbot ($39/mo standalone, 100 free sessions bundled then $0.15); outbound $750–$1,500/mo; white-label program | Not marked |
| **Simple Phones** | ✗ | **14 days** | from **$97** (100 calls) | ✗ | Not disclosed | Flat tiers by **calls** | 100 calls/mo at entry | Phone line + agent build included; Zapier + custom | Not readable |
| **Slang.ai** | ✗ | Not disclosed | $399 → $599 **per location** | ✗ | Not disclosed | Flat, **per location** (restaurant vertical) | Not disclosed | Reservations (OpenTable/SevenRooms/Yelp), VIP handling, AI texting, bilingual, Smart Inbox | Not marked |
| **Smith.ai** | ✗ | ✗ (30-day money-back, ≤$1,000) | $300 → $810 → $2,100 | ✗ | **10% off** (12-mo commit) | Per **call** blocks + overage | 30 / 90 / 300 calls; overage $11.50 / $10.50 / $8.50 per call | **Human + AI** receptionists, lead screening, CRM integration (1 free, +$0.50/call each), intake | Not marked |
| **Tidio (Lyro)** | ✅ 50 conversations, 10 seats, 50 lifetime Lyro AI convos | **7 days**, no card | $24.17 → $49.17 → $300+ (annual rates) | ✗ | **2 months free** (≈16.7%): $290 / $590 per year | Tiered by **billable conversations** + AI conversations | 50 / 100 / up-to-2,000 conversations; 10 seats | Live chat, ticketing, email, Flows, Lyro AI agent; Premium sells a **guaranteed 50% AI resolution rate** and pay-per-resolution | **Growth $49.17** |
| **Chatbase** | ✅ 50 credits/mo, 1 agent, 1 seat (deleted after 14 days idle) | ✗ | $32 → $120 → $400 | ✗ | **20% off**: $384 / $1,440 / $4,800 per year | Tiered by **message credits** | 700 / 4,000 / 15,000 credits; 2 / 3 / 5 seats | Advanced models, integrations; **voice + telephony + API + outbound only from $120**; add-ons: $40/1k credits, +$300/yr per extra agent, remove branding $1,188/yr | **Standard $120** |
| **Intercom (Fin)** | ✗ | **14 days**, no card | $29 → $85 → $132 **per seat** (annual) | ✗ | Annual rates shown; monthly is higher (not itemised) | **Per seat + per outcome** | Seats; Fin billed per resolution | Fin AI agent **$0.99 per resolution**, shared inbox, help centre, workflows, SSO/HIPAA at Expert; Copilot +$29/agent/mo | Advanced $85 |
| **Zoho SalesIQ** | ✅ 3 operators, 10K visitors/mo, 100 chat sessions, **no chatbot** | **15 days**, no card | **Not captured** | ✗ | "**2+ months free**" (≈16.7%+) | Per **operator** + visitor/session tiers | Basic 50K visitors / 1,000 sessions / 1 bot (25K bot sessions); Pro 100K / ∞ / 5 bots; Ent 200K / ∞ / 10 bots | Live chat, Zobot chatbots, visitor tracking; **multi-currency pricing (USD/INR/EUR/GBP/JPY/SGD/AUD)** | Professional |
| **Wati** | ✗ | 24 hours (Pro) | $39 → $99 → $299 | ✗ | **≈25% off**: ≈$29 / $74 / $224 per month | Flat tiers + **Meta per-conversation fees passed through** | 3 / 5 / 5 users (+$24 / +$69 per extra); 15k → unlimited broadcasts; 1,000 / 2,000 / 5,000 automation triggers; 250–1,500 AI credits | WhatsApp Business API, broadcasts, shared inbox, AI co-pilot, Shopify $4.99/mo | **Pro $99** ("Best Value") |
| **respond.io** | ✗ | **7 days**, no card | $79 → $159 → $279 | ✗ | **20% off**: $948 / $1,908 / $3,348 per year | Flat tiers by **monthly active contacts** | 5 / 10 / 10 users (+$12–24 each); MAC from 1,000; AI credits 5,000 / 10,000 / 20,000 | WhatsApp, IG, FB, TikTok, SMS, email in one inbox; **AI Agents included at no extra cost** on Growth+ | **Growth $159** |
| **ManyChat** *(3rd-party sourced)* | ✅ up to 1,000 contacts | ✗ | Pro from **$15**, scales with contacts ($15/500 → $25/1k → $45/2.5k → $65/5k); Elite custom | ✗ | **None — monthly billing only** | Per **contact**, auto-upgrading | Contacts | IG/FB/WhatsApp/TikTok automation; **AI features are a +$29/mo add-on, not included** | Pro |
| **Vapi** | Free credits ("60+ minutes") | Implied | **$0.05/min** + model cost at pass-through; Scale = annual contract | ✗ | Scale is annual-commit | **Pure usage** | 10 concurrent lines included, +$10/line/mo; 14-day call history | Dev platform; HIPAA +$2,000/mo, ZDR +$1,000/mo, SOC2/SSO/RBAC on Scale | — |
| **Retell AI** | $10 free credits, no card | — | **$0.07–$0.31/min** all-in; chat from $0.002/msg | ✗ | Enterprise contracts | **Pure usage** | 20 free concurrent calls; +$8/concurrency/mo | Voice infra $0.055/min, TTS $0.015/min, LLM metered (GPT-5.5 $0.16/min, Claude 4.6 Sonnet $0.08/min, GPT-5 nano $0.003/min); numbers $2/mo; KB $8/mo (first 10 free); branded calls +$0.10/call; PII removal +$0.01/min | — |
| **Bland AI** | 2 free credits + inbound number, no card | — | Start **$0 platform + $0.14/min** → Build **$299/mo + $0.12/min** → Scale **$499/mo + $0.11/min** | ✗ | Enterprise contracted | **Platform fee + usage** | 10 / 50 / 100 concurrency; 100 / 2,000 / 5,000 calls per day | LLM + transcription + premium voices included, no token charges; transfers $0.03–0.05/min | Build $299 |
| **Synthflow** | ✗ | — | **Enterprise only: from $30,000/year.** Self-serve tiers (Starter $29, Pro, Growth, Agency) have been **retired**; PAYG modular ≈$0.15–0.24/min | ✗ | Annual contracts | Enterprise contract / modular PAYG | Scoped per contract | Native telephony/SIP, CRM+calendar integrations, MSA/DPA, implementation & onboarding | — |

---

### B. Feature comparison — only features Serve AI actually has

Legend: ✅ included in entry paid tier · 🔸 higher tier / paid add-on · ✗ not offered · ⬜ not disclosed

| Feature (ours) | Rosie | Goodcall | Dialzara | Smith.ai | Slang | Tidio | Chatbase | Intercom | Wati | respond.io | ManyChat | Retell | Bland |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Inbound AI voice calls | ✅ | ✅ | ✅ | ✅ | ✅ | ✗ | 🔸 ($120) | ⬜ | ✗ | ✗ | ✗ | ✅ | ✅ |
| Outbound AI voice calls | ⬜ | ⬜ | 🔸 ($750+/mo) | ⬜ | ⬜ | ✗ | 🔸 | ✗ | ✗ | ✗ | ✗ | ✅ | ✅ |
| **Voice cloning** | ✗ (10+ stock) | ⬜ | ⬜ | ✗ | ⬜ | ✗ | ⬜ | ✗ | ✗ | ✗ | ✗ | 🔸 | ✅ (stock) |
| Website chat widget | ✅ (free add-on) | ✗ | 🔸 ($39 or bundled) | 🔸 | ✗ | ✅ | ✅ | ✅ | ✗ | ✅ | 🔸 | 🔸 | ✗ |
| WhatsApp Business API | ✗ | ✗ | ✗ | ✗ | ✗ | 🔸 | ✗ | 🔸 | ✅ | ✅ | 🔸 | ✗ | ✗ |
| Instagram + Facebook DMs | ✗ | ✗ | ✗ | ✗ | ✗ | 🔸 | ✗ | ✗ | ✗ | ✅ | ✅ | ✗ | ✗ |
| **Unified human-agent inbox** | ✗ | ✗ | ✗ | n/a (human) | 🔸 (Premium) | ✅ | 🔸 (helpdesk $120) | ✅ | ✅ | ✅ | 🔸 | ✗ | ✗ |
| RAG on website / documents | ✅ | ✅ | ✅ | n/a | ⬜ | ✅ | ✅ | ✅ | 🔸 | 🔸 | ✗ | 🔸 ($8/mo KB) | ✅ |
| **Live database connector** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| **Per-table / per-column AI access control** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Automatic lead capture → CRM | ✅ | ✅ | ✅ | ✅ | 🔸 | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | 🔸 | 🔸 |
| Visual flow builder | ✅ (logic flows: 1) | ✅ (1/3/25) | ⬜ | ✗ | 🔸 (Ent) | ✅ (Flows) | ✅ | 🔸 (Advanced) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Multi-agent personas + skill routing | ✗ | 🔸 (per-agent price) | ⬜ | ✗ | ⬜ | ✗ | 🔸 (+$300/yr per agent) | ✗ | ✗ | ✗ | ✗ | ✅ | ✅ |
| Multi-language / auto-detect | ✅ (EN/ES) | ⬜ | ⬜ | ⬜ | 🔸 (+$99/mo) | ✅ | ✅ | 🔸 (Advanced) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Team seats & custom roles | 🔸 | ✅ (3/9/50) | ⬜ | ⬜ | ⬜ | ✅ (10) | 🔸 (2/3/5) | ✅ (per seat) | ✅ (3/5/5) | ✅ (5/10) | 🔸 | ⬜ | ⬜ |
| API access + webhooks | 🔸 (Zapier) | 🔸 (Zapier) | ⬜ | ⬜ | ⬜ | 🔸 | 🔸 ($120) | ✅ | ✅ | ✅ | 🔸 | ✅ | ✅ |
| White-label / remove branding | ✗ | ✗ | 🔸 (partner) | ✗ | 🔸 (Premium) | 🔸 (Plus) | 🔸 ($1,188/yr) | 🔸 (Expert) | ⬜ | 🔸 | ⬜ | 🔸 | ⬜ |
| **Bring-your-own LLM key / local model** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | 🔸 | ✗ |
| **Per-tenant isolated database** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Conversation transcripts & analytics | ✅ | ✅ (7d/30d/∞) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Local-currency price display** | ✗ | ✗ | ✗ | ✗ | 🔸 (CAD/AUD) | ✗ | ✗ | ✗ | 🔸 (INR PAYG) | ✗ | ✗ | ✗ | ✗ |

Four rows are **empty across the entire market**: live database connector, per-table AI access control, per-tenant isolated database, and BYO/local LLM. Those are our defensible differentiators, and they sit naturally in the upper tiers.

---

## Phase 5 — Market analysis

### 1. Average entry-level price
Across the 12 vendors with a public entry-level paid tier (Dialzara $29, Rosie $49, Goodcall $79, Simple Phones $97, Smith.ai $300, Slang $399, Tidio $24.17, Chatbase $32, Intercom $29/seat, respond.io $79, Wati $39, ManyChat $15):

- **Mean $97.60, median $44.**
- Excluding the two structural outliers (Smith.ai — human staff; Slang — per-restaurant-location vertical): **mean $47.20, median $35.50.**
- **Conclusion: the SMB entry point is $25–$49, clustering hard on $29–$49.**

### 2. Typical monthly price range
- Entry: **$29 – $49**
- Mid / "most popular": **$99 – $159** (median ≈ **$129**)
- Top self-serve: **$249 – $400** (median ≈ **$299**) — a remarkably tight cluster
- Above that, everything goes to "contact sales".

### 3. Typical annual discount
Wati ≈25% · Chatbase 20% · respond.io 20% · Tidio ≈16.7% ("2 months free") · Rosie ≈16.7% ("2 months free") · Zoho "2+ months free" · Goodcall 15% · Smith.ai 10% · ManyChat **0%**.

- **Median ≈ 17–20%.** "2 months free" is the single most common *formulation*; 20% is the most common *number*.

### 4. Quarterly billing
**Zero of the 17 competitors publish a quarterly price.** The market is monthly-vs-annual only. This is a genuine, unoccupied position — see §Differentiation.

### 5. Free-plan strategy
A clean split by marginal cost:

| Marginal cost per unit of usage | Free plan? | Who |
|---|---|---|
| Near zero (text chat) | **Common** | Tidio, Chatbase, ManyChat, Zoho SalesIQ |
| Real money (voice minutes, telephony, TTS) | **Never** — trial only | Rosie, Goodcall, Dialzara, Simple Phones, Slang, Smith.ai |
| Developer platforms | Free **credits**, not a free plan | Retell $10, Bland 2 credits, Vapi 60+ min |

Free plans are always crippled on the *metered* unit, not on features: Tidio 50 conversations, Chatbase 50 credits, Zoho 100 sessions, ManyChat 1,000 contacts.

### 6. Trial strategy
- **7 days is the modal trial** (Dialzara, Rosie, Tidio, respond.io) — which validates the 7-day trial already specified in our brief.
- 14 days: Intercom, Simple Phones. 15 days: Zoho. 24 hours: Wati.
- **No card** is advertised loudly by the chat/SaaS vendors (Tidio, Intercom, respond.io, Zoho, Retell, Bland) whose trial costs them almost nothing.
- The **voice** vendors that give away real minutes do not advertise "no credit card" — the card is the abuse control.
- Smith.ai replaces the trial entirely with a **30-day money-back guarantee** — a legitimate alternative when the service has high delivery cost.

### 7. Most common number of plans
**Three paid tiers + Enterprise** is the mode (Goodcall, Rosie, Chatbase, respond.io, Wati, Smith.ai, Intercom, Bland). Dialzara runs four. Slang runs two. **Free + 3 paid + Enterprise = 5 rows on the page is the safe, conventional shape.**

### 8. Feature-gating strategy
Consistently **included in the entry tier**: the core AI agent, one channel, basic knowledge training, transcripts & summaries, email notifications, Zapier, a phone number (voice vendors).

Consistently **held back for higher tiers**:
1. **API access & webhooks** (Chatbase: $120 tier)
2. **Voice / telephony** when the vendor is chat-first (Chatbase: $120 tier)
3. **Extra seats / team members** (Goodcall 3→9→50; Chatbase 2→3→5)
4. **History retention** (Goodcall 7 days → 30 days → unlimited)
5. **White-label / remove branding** (Chatbase $1,188/yr; Slang Premium; Dialzara partner program)
6. **Advanced analytics & reporting**
7. **Extra agents / personas** (Chatbase +$300/yr each; Goodcall charges *per agent*)
8. **Multi-language** (Slang charges **$99/mo** for Spanish)
9. SSO / audit / HIPAA / SLA → always Enterprise

### 9. Usage-limit strategy
Four competing meters, each an attempt to charge closer to value:

| Meter | Used by | Comment |
|---|---|---|
| **Minutes** | Rosie, Dialzara, Vapi, Retell, Bland, Synthflow | The honest cost-based meter for voice; buyers now benchmark everything against $0.05–0.31/min |
| **Calls / conversations** | Simple Phones, Smith.ai, Tidio | Easier to understand, riskier margin |
| **Unique customers / contacts** | Goodcall, ManyChat, respond.io (MAC) | Charges for reach, not chatter — Goodcall can advertise "unlimited minutes" precisely *because* the unique-caller cap does the real limiting |
| **Resolutions / outcomes** | Intercom ($0.99), Tidio Premium | The emerging premium model; only credible with a stated resolution guarantee |

**Note the trick worth stealing:** "unlimited minutes" reads far better on a pricing page than "250 minutes", and Goodcall makes it safe with a *different* cap (100 unique callers/mo, $0.50 each after).

### 10. Popular-plan positioning
The badge is on the **middle tier** every single time it appears: Goodcall Growth $129, Rosie Scale $149, Chatbase Standard $120, respond.io Growth $159, Tidio Growth $49, Wati Pro $99. **The badged plan is 2–3× the entry price.** Step ratios observed: 1.6×–3.8× entry→mid, 1.75×–3.3× mid→top; **2–3× is the norm.**

### 11. Enterprise strategy
Universal. Always custom-priced, always the same feature bundle (SSO, SLA, security review, DPA, custom concurrency, dedicated CSM, implementation). Synthflow is the cautionary tale: it **deleted its entire self-serve range** and now starts at $30,000/year, abandoning the SMB funnel our product is built for.

---

### Answers to the strategic questions

**What pricing structure is most common?**
Free (chat-first vendors only) + three flat monthly tiers + Enterprise; annual discount ~17–20%; the middle tier badged "most popular"; a single metered unit (minutes or conversations) with published overage; seats and channels as secondary limits.

**What appears to convert well?**
1. A **badged middle tier at 2.5–3× entry** with a visibly disproportionate jump in the metered unit (Rosie: $49→$149 is 3× price for **4×** minutes; Chatbase: $32→$120 is 3.75× price for **5.7×** credits). The mid tier is engineered to look like the only rational choice.
2. **Published overage rates** rather than hard stops — Dialzara shows the per-minute overage falling as the tier rises, which is itself an upgrade argument.
3. **"Unlimited" on the scary meter, capped on a quieter one** (Goodcall).
4. **Everything AI included** (respond.io: "AI usage is included at no extra cost… 99% of businesses never exceed their included credits") — versus ManyChat's +$29/mo AI add-on, which is widely written up as a hidden fee.
5. A **free plan or a no-card trial** for chat, a **card-required trial** for voice.

**Which features do competitors reserve for higher tiers?**
API/webhooks, telephony (for chat-first vendors), seats, history retention, white-label, advanced analytics, extra agents, multi-language, SSO/compliance.

**Which features are commonly included in entry plans?**
Core AI agent, one channel, knowledge training from website/documents, transcripts & summaries, lead capture, a phone number, Zapier, email notifications.

**Where can our product differentiate?**

1. **Bundle arbitrage — the strongest single argument.** A shop today buys Rosie ($49, voice) + Chatbase ($32, web chat) + Wati ($39, WhatsApp) = **$120/month for three disconnected tools with three separate knowledge bases**. We are one product, one knowledge base, one inbox, one lead list. Our middle tier should be priced *at or just above* that sum and explicitly sell the substitution.
2. **Four capabilities nobody else in the set offers at all**: live database connector, per-table/per-column AI access control, per-tenant isolated database, BYO/local LLM. All four are trust and compliance features — which is exactly what an SMB owner hesitates over before handing an AI their customer data. These belong in the upper tiers and in Enterprise.
3. **Structurally lower marginal cost.** Local XTTS voice and local/self-hosted LLM options mean our cost per conversation is far below vendors paying OpenAI + ElevenLabs + platform margin. That buys either better margins at market price, or a genuinely useful free tier — and it is the only reason we *can* offer a free tier on a voice-capable product.
4. **Quarterly billing — an empty square on the board.** No competitor offers it. In our home and adjacent markets (Pakistan, India, MENA, LATAM), an annual USD prepayment is a real cash-flow barrier while monthly card churn is high. Quarterly is a middle rung nobody is offering.
5. **Local-currency display.** Only Slang (CAD/AUD) and Zoho (multi-currency) do anything here, and neither serves PKR/AED/SAR on the pricing page. Showing "≈ PKR 11,000/month" beside "$39" removes a mental-conversion hurdle for exactly the buyers we start with.
6. **Voice cloning at SMB prices.** Rosie offers 10+ stock voices; Slang charges $99/mo for a second language. We clone the owner's own voice from a 10-second sample, in 13 languages, at entry level.

**What pricing mistakes should we avoid?**

| Mistake | Who demonstrates it | Why it hurts |
|---|---|---|
| **"Unlimited minutes" with no second cap** | Goodcall *does this safely* — the unique-caller cap is the real limit | Copying the headline without the hidden cap is a direct route to negative gross margin on voice |
| **Abandoning self-serve for enterprise** | Synthflow (self-serve retired, now $30k/yr floor) | Kills the SMB funnel our entire product and marketing site are built for |
| **Pure per-seat pricing on an automation product** | Intercom (now retreating to $0.99/resolution) | Customers buy us to *avoid* hiring seats; charging per seat prices against our own value story |
| **Charging extra for AI on an AI product** | ManyChat (+$29/mo AI add-on) | Reads as bait-and-switch; respond.io's "AI included" is the better posture |
| **No annual option at all** | ManyChat | Forfeits cash up front and the churn reduction annual prepay buys |
| **A free plan with real marginal cost and no hard ceiling** | — (nobody makes this mistake in voice) | Free voice minutes would be a direct cash leak. Our free tier must be text-only |
| **Hiding overage rates** | Goodcall/Slang partially | Buyers who can't predict the bill don't buy; Dialzara publishing declining overage per tier is the pattern to copy |
| **Too many tiers** | Dialzara (4 + add-ons) | Above 3 paid tiers, choice paralysis outweighs coverage |
| **Trial with no abuse control** | — | With workspace creation free, per-workspace trials are trivially farmed |

---

*Pricing recommendation follows in [PRICING_RECOMMENDATION.md](PRICING_RECOMMENDATION.md). No pricing has been implemented — awaiting approval.*
