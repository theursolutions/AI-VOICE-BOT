# Feature & Settings Inventory — for plan allocation

**Generated from the live codebase + database:** 2026-08-12
**Purpose:** one place to see everything the product can do, what is currently sold, and what isn't wired up — so plan allocation can be decided from facts rather than memory.

Everything here was read from `config/modules.php`, `route:list`, the `features` / `plan_features` tables and the controllers. Nothing is assumed.

---

> ## ✅ Decisions applied — 2026-08-16
>
> | Decision | Result |
> |---|---|
> | Team Assistant | **Starter+** — new `assistant_access` feature gating the `assistant` module |
> | CRM connectors | **Growth+** — new `crm_connectors` feature, enforced at OAuth `start` |
> | Compute Mesh | **All plans** — left ungated, confirmed by test |
> | Voices | **Starter+** — Free's `voice_cloning = "0"` row removed (a row meant "granted") |
> | Bot Strategy | **Starter+** — module split from Brain Settings, new `bot_strategy` feature |
> | Brain Settings (BYO LLM) | **Scale+** — unchanged tier, now its own module |
>
> Also fixed in the same pass — the five features that were **sold but enforced
> nowhere** (§B3): `database_connector`, `remove_branding`, `api_access` and the
> new `crm_connectors` now have real gates. `white_label`, `audit_export` and
> `sso` remain display-only because no surface exists for them yet.
>
> Rolled out by `2026_08_16_100000_allocate_assistant_crm_and_bot_strategy`
> (idempotent; never overwrites a hand-edited value) and mirrored in
> `BillingSeeder` for fresh installs. **313 tests passing.**
>
> **Skills → every plan except Free** (added 2026-08-16, migration `…100010`).
>
> ### Metering now records (§A3 closed)
>
> `UsageRecorder` is called from the real hot paths, so allowances are no
> longer advisory:
>
> | Metric | Recorded at |
> |---|---|
> | `conversations` | first AI reply in a session — `ConversationManager` (HTTP/all text channels) + `InternalTurnController` (WebSocket) |
> | `voice_messages` | same seams, when the customer's last message carried audio and the channel isn't a phone call |
> | `telephony_minutes` | Twilio terminal status webhook, `CallDuration` rounded **up** to the minute |
> | `indexed_pages` / `storage_mb` | **reconciled hourly** by `billing:reconcile-usage`, not evented — indexing is asynchronous, so no moment in PHP knows the final page count. The command measures real state (DuckDB row counts, bytes on disk) and `setAbsolute()`s it, which makes it re-runnable and self-healing after any missed callback. |
>
> Structural counts are hard-stopped at creation: **seats · agents · flows ·
> channels · phone numbers**.
>
> ### Add-ons (added 2026-08-16)
>
> Extra capacity without a tier change: **Extra team seat** $5/mo · $50/yr and
> **Extra AI agent** $9/mo · $90/yr. Each is a `type = 'addon'` plan whose own
> `plan_features` row says what one unit grants, so the effective limit is
> `base + (unit × quantity)` and every existing enforcement site honours it
> with no changes. Sold as a Stripe subscription item on the existing
> subscription (one invoice, Stripe prorates), interval forced to match.
> Surfaced on the customer's Billing and Choose-a-plan pages; never public.
>
> **Still undecided:** Contacts · a `storage_mb` cap · whether data-snapshot and
> webhook source types should be tiered.

---

## A. What exists

### A1. Admin modules — 18 total

These are the gateable sections in `config/modules.php`. **11 are gated by a plan feature, 7 are not.**

| Module | Label | Gated by | Notes |
|---|---|---|---|
| `conversations` | Conversations | `transcripts` | ✅ |
| `messages` | Messages (shared inbox) | `shared_inbox` | ✅ |
| `leads` | Leads | `lead_capture` | ✅ |
| `channels` | Channels (WhatsApp/IG/FB) | `channels_meta` | ✅ |
| `data_sources` | Data Sources | `knowledge_base` | ✅ |
| `flows` | Flow Builder | `flow_builder` | ✅ |
| `telephony` | Telephony | `telephony` | ✅ |
| `widget` | Widget | `web_widget` | ✅ |
| `team` | Team & Roles | `team_roles` | ✅ |
| `voices` | Voices | `voice_cloning` | ⚠️ see B1 |
| `bot_strategy` | Bot Strategy + Brain Settings | `byo_llm` | ⚠️ see B2 |
| **`dashboard`** | Dashboard | — | free to all (correct) |
| **`profile`** | Project Profile | — | free to all (probably correct) |
| **`agents`** | Agents | — | count is metered, section is open |
| **`assistant`** | Team Assistant | — | ⚠️ **costs LLM money, ungated** |
| **`skills`** | Skills | — | ⚠️ intended Growth+, not enforced |
| **`compute`** | Compute Mesh | — | ⚠️ ops-ish visualisation, ungated |
| **`contacts`** | Contacts | — | ⚠️ **new module, never allocated** |

### A2. Billable features — 30 defined

| Group | Features |
|---|---|
| **Volume** (10) | `conversations` · `telephony_minutes` · `voice_messages` · `projects` · `seats` · `agents` · `phone_numbers` · `data_sources` · `indexed_pages` · `history_days` |
| **Included on every plan** (6) | `voice_cloning` · `multi_language` · `lead_capture` · `web_widget` · `knowledge_base` · `transcripts` |
| **Channels & power** (11) | `telephony` · `channels_meta` · `shared_inbox` · `flow_builder` · `team_roles` · `api_access` · `database_connector` · `remove_branding` · `white_label` · `byo_llm` · `audit_export` |
| **Support** (3) | `support` · `overage_voice` · `sso` |

### A3. Usage meters — 5 defined, all now recorded

| Metric | Capped by | Recorded? |
|---|---|---|
| `conversations` | `conversations` | ✅ `ConversationManager` + `InternalTurnController` |
| `telephony_minutes` | `telephony_minutes` | ✅ Twilio status webhook (`CallDuration`, ceil to minute) |
| `voice_messages` | `voice_messages` | ✅ same seams as conversations, when the user's turn had audio |
| `indexed_pages` | `indexed_pages` | ✅ hourly `billing:reconcile-usage` |
| `storage_mb` | ⚠️ **no plan sets a cap** | ✅ hourly `billing:reconcile-usage` |

> Closed since the first pass. Every recorder call is wrapped in `safely()`, so a
> metering failure can never break a live conversation — it logs and moves on.
> `storage_mb` is measured and displayed but still capped by nothing; that's a
> pricing decision, not missing code.

### A4. Capabilities *inside* modules (not currently sellable separately)

These are real product surfaces that plan allocation could use but currently can't, because no feature represents them:

| Area | Options available |
|---|---|
| **Data source types** | website crawl · document upload · **live database** · **CRM OAuth** · data snapshot · webhook · agent |
| **CRM connectors** | HubSpot · Salesforce · Pipedrive · Zoho |
| **LLM providers** | Ollama (local) · Gemini · Groq · Anthropic — plus CPU/GPU device choice |
| **Widget settings** (15) | primary/accent colour · bot name · welcome title & message · position · `show_voice` · `show_emoji` · `show_attach` · `show_theme_toggle` · `show_reply_toggle` · `show_expand_button` · `show_visitor_modes` · `show_history_tab` · **`show_powered_by`** |
| **Skills** | 10 prebuilt tool/action templates |
| **Data privacy** | per-table and per-column AI access control |
| **Tenancy** | one isolated database per project |

### A5. Super-admin-only surfaces (not customer features — no plan impact)

`analytics` · `audit` · `billing` · `blog` · `clients` · `content` · `impersonate` · `modules` · `projects` · `seo` · `testimonials` · `users` · `visitors`

---

## B. Problems found — these need a decision

### B1. 🔴 Free loses the entire Voices section

`voices` is gated by `voice_cloning`, and Free has `voice_cloning = 0`. So a Free user cannot open Voices **at all** — but the intent was "Free gets 30 stock voices, just not cloning".

**Fix options:** (a) split into `voices_access` (all plans) + `voice_cloning` (Starter+), or (b) ungate the module and enforce cloning inside the page.

### B2. 🔴 Starter and Growth lose Bot Strategy entirely

The `bot_strategy` module covers **two different screens**:
- **Bot Strategy** — knowledge-tier toggles (which sources the bot may use)
- **Brain Settings** — LLM provider + CPU/GPU choice, i.e. bring-your-own-model

It's gated by `byo_llm`, which is **Scale-only** — so Starter and Growth can't reach the knowledge-tier toggles either. Almost certainly wrong.

**Fix:** split the module, or gate on a new `bot_strategy` feature (Growth+) and keep `byo_llm` enforcing only the provider picker.

### B3. 🟠 Six features are sold but enforce nothing

These appear on the pricing page and in the plan matrix, but no code checks them:

| Feature | What should enforce it |
|---|---|
| `api_access` | the project-API middleware (`project.apikey`) |
| `database_connector` | `DataSourceWebController::storeDatabase` |
| `remove_branding` | the widget's `show_powered_by` setting |
| `white_label` | custom-domain / branding surface |
| `audit_export` | audit-log export action |
| `sso` | no SSO implementation exists yet |

A customer on Free could today connect a live database or turn off the "Powered by" badge.

### B4. 🟠 Team Assistant is free and costs real money

`assistant` is the in-app AI. Every question burns LLM tokens, and it's ungated and unmetered on **every plan including Free**.

### B5. 🟡 Contacts module never allocated

New module, no feature, no decision recorded. Currently free to all.

### B6. 🟡 `storage_mb` has no cap

The meter exists; nothing limits it. `indexed_pages` covers page count but not file size.

### B7. 🟡 Skills / Compute Mesh ungated

`skills` (multi-agent routing) was described as Growth+ in the seeder comments but has no feature. `compute` is a live scaling visualisation — arguably Scale-only, or hide it from customers entirely.

---

## C. Current allocation (what's live today)

| Feature | Free | Starter | Growth | Scale | Enterprise |
|---|---|---|---|---|---|
| **conversations** | 100 | 1,000 | 5,000 | 20,000 | ∞ |
| **telephony_minutes** | — | 60 | 300 | 1,200 | ∞ |
| **voice_messages** | 50 | 500 | 3,000 | ∞ | ∞ |
| **projects** | 1 | 1 | 3 | 10 | ∞ |
| **seats** | 2 | 3 | 10 | 25 | ∞ |
| **agents** | 1 | 2 | 10 | ∞ | ∞ |
| **phone_numbers** | — | 1 | 3 | 10 | ∞ |
| **data_sources** | 1 | 3 | ∞ | ∞ | ∞ |
| **indexed_pages** | 50 | 500 | 5,000 | 25,000 | ∞ |
| **history_days** | 7 | 30 | ∞ | ∞ | ∞ |
| voice_cloning | — | ✓ | ✓ | ✓ | ✓ |
| multi_language | ✓ | ✓ | ✓ | ✓ | ✓ |
| lead_capture | ✓ | ✓ | ✓ | ✓ | ✓ |
| web_widget | ✓ | ✓ | ✓ | ✓ | ✓ |
| knowledge_base | ✓ | ✓ | ✓ | ✓ | ✓ |
| transcripts | ✓ | ✓ | ✓ | ✓ | ✓ |
| telephony | — | ✓ | ✓ | ✓ | ✓ |
| channels_meta | — | 2 | ∞ | ∞ | ∞ |
| shared_inbox | — | ✓ | ✓ | ✓ | ✓ |
| flow_builder | — | 1 | ∞ | ∞ | ∞ |
| team_roles | — | — | ✓ | ✓ | ✓ |
| api_access | — | — | ✓ | ✓ | ✓ |
| database_connector | — | — | ✓ | ✓ | ✓ |
| remove_branding | — | — | ✓ | ✓ | ✓ |
| white_label | — | — | — | ✓ | ✓ |
| byo_llm | — | — | — | ✓ | ✓ |
| audit_export | — | — | — | ✓ | ✓ |
| sso | — | — | — | — | ✓ |
| support | Community | Email | Priority | Priority + onboarding | CSM + SLA |
| overage_voice | — | $0.35/min | $0.30/min | $0.25/min | Contract |

---

## D. Decisions needed

For each row: what should Free get, and which paid tier unlocks it? My recommendation is in the last column — overwrite anything you disagree with.

### D1. New features to create

| # | Capability | Currently | Free? | Recommended tier |
|---|---|---|---|---|
| 1 | **Voices access** (stock voices, no cloning) | broken on Free (B1) | **Yes** | All plans; cloning stays Starter+ |
| 2 | **Bot Strategy** (knowledge-tier toggles) | Scale-only by accident (B2) | No | **Growth+** |
| 3 | **Team Assistant** (in-app AI) | free to all (B4) | Limited? | **Starter+**, or Free with a small monthly cap |
| 4 | **Assistant questions** (new meter) | unmetered | e.g. 20/mo | metered on all paid tiers |
| 5 | **Skills / multi-agent routing** | ungated (B7) | No | **Growth+** |
| 6 | **Contacts** | ungated (B5) | ? | probably all plans |
| 7 | **Compute Mesh** | ungated (B7) | No | **Scale+**, or hide from customers |
| 8 | **CRM connectors** (HubSpot/Salesforce/Pipedrive/Zoho) | part of data_sources | No | **Growth+** or **Scale+** — this is a classic upsell |
| 9 | **Storage (MB)** | uncapped (B6) | 20 MB | scale with tier |
| 10 | **Data snapshot / webhook sources** | part of data_sources | ? | Growth+? |

### D2. Enforcement to wire up (no pricing decision, just work)

`api_access` · `database_connector` · `remove_branding` · `white_label` · `audit_export` — all sold, none enforced (B3).

### D3. Bigger questions

1. **Should Free include the shared inbox?** Currently no — Free is web-widget-only, so conversations arrive but there's no team inbox. Deliberate, worth confirming.
2. **Is `agents: 1` on Free right**, given the Agents *section* is open to everyone?
3. **Compute Mesh** — is this a customer feature at all, or an internal diagnostic that should move to Ops?
4. **CRM connectors** are arguably the strongest upsell in the product and are currently bundled into "unlimited data sources" on Growth. Split them out?

---

## E. How to change any of this

Everything in section C is editable at **Ops → Billing → Features & limits** with no deploy. Creating the new features in D1 is also self-serve — each one needs a name, a value type, and optionally a module to gate or a meter to cap.

The only items needing code are **D2** (enforcement call sites) and **A3** (the usage meters aren't being recorded yet).
