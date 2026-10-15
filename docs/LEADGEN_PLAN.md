# Lead Generation & Outreach Automation — Phase 1 Plan

Status: **Phase 5 complete** (2026-10-16) — everything up to AI scoring and AI drafts works; nothing is sent yet. Next: Phase 6 (campaigns and sending).

**Decisions (2026-10-15):**
1. First discovery provider: **Overture Maps Places** (+ website enrichment, CSV/XLSX import).
2. CRM model: separate `lg_prospects`; "Add to CRM" creates/links a Contact via `ContactResolver` **and** a Lead; `leads.session_id` becomes nullable (additive tenant migration) so promoted prospects appear on the Leads board.
3. v1 channels: **email** via connected mailbox; **WhatsApp templates** to opted-in contacts only; **Messenger/IG** follow-ups to people with an open conversation only.
4. Overture queries run in a **separate worker container** from the voice-engine image (own entrypoint), not in the live voice-engine process.
Scope: `admin/` (Laravel 10) + a small `voice-engine/` addition. Module key: `leadgen`.

---

## 1. Existing architecture findings

| Area | What exists | Consequence for this feature |
|---|---|---|
| Tenancy | Tenant unit = **Project**; each has its own DB `ai-crm-client-{id}` (`TenantManager::useFor`). Client = workspace + billing entity. Central DB `mysql` holds clients/projects/users/roles/billing/audit. `tenant:migrate` migrates all active projects. | All lead-gen data goes in the **tenant DB** (physical isolation). A client generating leads "for their clients' businesses" uses one project per business, or multiple **offer profiles** inside a project. Usage is metered centrally per client. |
| Jobs | `QUEUE_CONNECTION=database` (redis in docker), jobs take scalar `projectId` and call `useForProjectId()` in `handle()`. No ShouldBeUnique / WithoutOverlapping / batches. Scheduler entries all `withoutOverlapping()`. Local `.env` is `sync`. | Same convention. New jobs add `WithoutOverlapping` + DB-level claim locks for idempotency. Long searches must not run on `sync` (dev must run a worker). |
| CRM | Tenant `contacts` + `contact_identities` (unique project+channel+external_id), `ContactResolver` dedups on **email/phone only, never name**; `Contact::absorb` merges. `leads` requires `session_id` (NOT NULL), statuses new/contacted/qualified/converted/disqualified, `assigned_to`, `notes`. No tags, no contact owner. | Reuse `ContactResolver` for "Save to CRM". Leads pipeline can't hold a lead with no conversation today → see decision Q2. |
| RBAC / modules | `config/modules.php` registry → Roles matrix + Ops switchboard pick it up automatically. Middleware chain `module.enabled → subscribed → plan.feature → module.access`. Owners get `*`. No per-action permissions (only module + project scoping). | Register `leadgen` module. Add **fine-grained abilities** (view/import/edit/export/approve/send) — new, since the role model only knows modules (see §5). |
| Billing | `Feature` rows with `module_key` and/or `metric_key`; `PlanFeatureService::clientLimit` (plan + grants + add-ons); `UsageLimitService::record()` atomic counters in `usage_counters`. **`allows()`/`wouldExceed()` have no callers** — nothing is pre-checked today. No reservation pattern. | Add metrics + a **reservation** layer (reserve before job, settle on finish, release on failure). Limits editable in the existing Ops → Billing → Features matrix; no new billing system. |
| AI | `AiBrain` (platform or BYOK, encrypted keys, monthly token quota) → `BrainResolver` (role `reply`/`machinery`) → `PythonClient::llm()` → voice-engine `llm_service.py` (Groq default, Anthropic/OpenAI-compat/Ollama, failover). Usage in `AiBrainUsage`. JSON mode exists only on the hard-wired `/extract`. Prompts are hard-coded. | New call types `leadgen_qualify`, `leadgen_draft` (machinery role → metered). Add a **generic `/llm/structured`** endpoint (prompt + JSON schema) to voice-engine. Store prompt templates in code, user-editable parts (offer profile, templates) in DB. |
| Meta channels | `channel_connections` (encrypted tokens, scopes, expiry, daily refresh), webhooks with HMAC + cache dedup, `GraphClient` sendText/sendTemplate/Messenger, 24h window helper, template manager UI, opt-out keywords (`ComplianceGuard`). | Reuse for **eligibility-gated** WhatsApp sends only (see §4). Gaps to fix: `sendTemplate` discards the wamid (no delivery tracking); status handler scans messages in PHP (won't scale); opt-out stored per **session**, not per contact. |
| Email | `mail-channel` package: per-project mailbox (SMTP/IMAP or Google/Microsoft OAuth, encrypted), `SmtpMailer::send`, IMAP poll every 2 min → `CrmInboundEmailHandler` (stores In-Reply-To/References). | Primary **cold-outreach channel**. Gaps: outbound Message-ID not returned, no `List-Unsubscribe`, no bounce/DSN handling. |
| Web fetching | voice-engine crawler (httpx, same-origin, depth 2, 50 pages) — **no SSRF / private-IP guard, follows cross-host redirects, ignores robots.txt**. Admin `WebhookResolver` and `testWebhook` also fetch user URLs unguarded. | Must build a shared **SafeFetcher** (DNS-resolve + block private/link-local/metadata ranges on every hop, robots.txt, size/time caps) before enriching prospect websites. Fixing the existing crawler/webhook paths is recommended but out of scope unless you approve. |
| UI | Blade + precompiled tw-starter Tailwind theme, vanilla JS / some Alpine, Chart.js, Tabulator, tom-select, toastify, lucide; React only for the Flow editor. Patterns: `tva-dt-toolbar` filters, `partials/pagination`, `partials/table-export`. No stepper or bulk-select component. | Build pages in Blade following `contacts/`/`leads/`. Add two small reusable partials: bulk-select table + stepper. Campaign builder = stepper + sequence timeline (not React Flow) for v1. |
| Notifications | Tenant users have **no** in-app notifications (bell is a placeholder); ops alerts go to the platform inbox only. | "Notify assigned user" = email (queued Mailable) in v1; in-app bell is a separate, later piece. |
| Audit / logs | `AuditLog::record('area.action', [...])` (central), dotted log keys with context. | Use for approvals, launches, exports, deletions, suppression changes. Per-campaign execution log in a tenant table. |
| Import / export | In-house `SimpleSheetReader` (CSV/TSV/XLSX) and `Xlsx::build` (all-text cells, formula-safe). No spreadsheet library. | Reuse both; no new dependency. |
| Lead-data providers | None exist. | First provider is new (see §4). |

## 2. Requirements & user flows

**Personas:** workspace owner/manager (configures, approves), sales member (reviews, edits, sends within permission), super admin (enables module, sets limits, provider keys).

1. **Offer profile** — "What do you sell / to whom": product, ICP, value proposition, exclusions, qualification questions, scoring weights, sender name, booking link, default language/tone. Many per project (agencies run one per end client).
2. **Discover** — New Search: provider, area (city/country/radius), categories, keywords, must-have website/phone/email/social, min confidence, exclude existing CRM. → scope estimate (count) → Start → background job with progress, pause/resume/cancel → results table with per-row source + "duplicate of…" flags. Save criteria; re-run.
3. **Import** — CSV/XLSX upload → column mapping → validation preview → background import through the same normaliser + dedup.
4. **Enrich** — per prospect or bulk: fetch the business's own website (SafeFetcher, robots-respecting, ≤5 pages: home, contact, about) → extract emails/phones/socials **found on that site** with provenance; MX check for email domains. Nothing inferred is shown as verified.
5. **Qualify** — AI scores each prospect against an offer profile → score 0–100, band (High/Medium/Low/Needs Review), evidence-cited explanation, hypotheses (labelled), suggested channel, next action. Review queue: approve / reject / override with reason.
6. **Lists & CRM** — save to lists, tag, assign owner; "Add to CRM" creates/links a Contact via `ContactResolver` (no duplicates).
7. **Draft** — AI drafts per prospect from templates + variables; user edits, previews, picks language/tone/length.
8. **Campaign** — stepper: list → eligibility & suppression preview (who's excluded and why) → sequence (initial + up to N follow-ups with delays, stop-on-reply) → channel/mailbox → schedule window, daily cap, per-recipient-timezone → sample review (≥5 rendered messages) → **approval** (permission `leadgen.approve`) → running. Pause/stop anytime.
9. **Monitor** — per campaign: queued / attempted / accepted / delivered / read / replied / bounced / failed / opted-out, only where the channel reports it. Execution log.
10. **Inbound** — reply detected (email In-Reply-To or sender match; WhatsApp from same number) → stop pending follow-ups, create CRM activity (session/message already created by existing handlers), notify owner; "unsubscribe" link or opt-out keyword → suppression.

## 3. Proposed architecture & data model

```
Blade UI ──▶ LeadGen controllers (thin) ──▶ App\Services\LeadGen\*
                                              ├─ Providers\ProviderRegistry ─▶ OvertureProvider (→ voice-engine /leadgen/places)
                                              │                              ├─ ImportProvider (CSV/XLSX)
                                              │                              └─ (later) GooglePlaces*, licensed vendors, Meta…
                                              ├─ ProspectNormalizer + Deduplicator
                                              ├─ Enrichment\WebsiteEnricher (SafeFetcher)
                                              ├─ Ai\Qualifier, Ai\Drafter  ─▶ BrainResolver → PythonClient (/llm/structured)
                                              ├─ Outreach\EligibilityGate, SuppressionService
                                              ├─ Outreach\Channels\EmailSender (mail-channel), WhatsAppTemplateSender (meta-channels)
                                              ├─ Campaigns\CampaignScheduler (leadgen:tick) + SendOutreachMessage job
                                              └─ Usage\LeadGenQuota (reservations over UsageLimitService)
```

**Provider contract** (`App\Services\LeadGen\Providers\LeadSource`):
`key()`, `capabilities(): Capabilities` (filters supported, pagination, estimate, fields returned, storage policy: `storable|id_only|display_only`), `validateConnection(Client): Status`, `estimate(Criteria): ?int`, `search(Criteria, ?Cursor): Page{items: NormalizedProspect[], next: ?Cursor}`, `rateLimit(): RatePolicy`. Errors: `ProviderNotConfigured`, `ProviderRateLimited(retryAfter)`, `ProviderUnsupported(capability)`, `ProviderFailed(diagnostic)`. No provider ever returns sample/fake data; an unconfigured provider renders a "configure" card.

**Tenant tables** (new migrations in `database/migrations/tenant/`, prefix `lg_`):

| Table | Key columns | Constraints / indexes |
|---|---|---|
| `lg_offer_profiles` | name, product, icp (json), value_prop, exclusions (json), questions (json), scoring (json weights), sender_name, booking_link, language, tone, created_by | |
| `lg_searches` | profile_id?, provider, criteria (json), status (queued/running/paused/cancelling/cancelled/completed/failed), cursor (json), progress (json: fetched/new/duplicates/errors), estimate, saved (bool), name, error, started/finished_at, created_by | idx (status) |
| `lg_prospects` | business_name, category, website, **domain_norm**, phone_e164, email, address (json), city, country_code, lat/lng, socials (json), **source_provider, source_record_id, source_url, source_license**, discovered_at, last_verified_at, **field_meta** (json: per-field `source`, `found_at`, `verification`=unverified/syntax_ok/mx_ok/found_on_site/user_confirmed), enrichment_status, status (new/reviewing/approved/rejected/in_crm), owner_id, tags (json), notes, contact_id?, suppressed_at, deleted_at | **unique (source_provider, source_record_id)**; idx domain_norm, phone_e164, email, status, country_code+city |
| `lg_search_results` | search_id, prospect_id, outcome (new/duplicate/excluded_crm/excluded_filter), duplicate_of? | unique (search_id, prospect_id) |
| `lg_lists`, `lg_list_items` | name, description / list_id, prospect_id, added_by | unique (list_id, prospect_id) |
| `lg_qualifications` | prospect_id, profile_id, score, band, explanation, evidence (json: field→value cited), hypotheses (json), suggested_channel, next_action, model, brain_id, reviewed_by, override_band, override_reason | idx (profile_id, band), latest-per-prospect |
| `lg_templates` | name, channel, language, subject, body (with `{{vars}}`), wa_template_name? | |
| `lg_campaigns` | profile_id, list_id, name, channel, sender ref (email_account_id / channel_connection_id), steps (json: delay, template, stop rules), schedule (json: window, days, tz mode), daily_cap, lawful_basis, status (draft/pending_approval/approved/running/paused/stopped/completed), approved_by/at, launched_at | |
| `lg_campaign_recipients` | campaign_id, prospect_id, contact_id?, state (pending/eligible/excluded/active/replied/opted_out/bounced/completed/stopped), excluded_reason, current_step, next_action_at | **unique (campaign_id, prospect_id)**; idx (state, next_action_at) |
| `lg_outreach_messages` | recipient_id, campaign_id, step, channel, subject, body, status (draft/approved/queued/sending/accepted/delivered/read/replied/bounced/failed/unknown), **idempotency_key**, provider_message_id, attempts, last_error, approved_by, queued/sent/delivered/read_at | **unique idempotency_key** (`campaign:recipient:step`), **unique provider_message_id**, idx (status) |
| `lg_suppressions` | kind (email/phone/domain), value_norm, reason (opt_out/bounce/complaint/manual/legal), source, contact_id? | **unique (kind, value_norm)** |
| `lg_events` | subject_type/id, event, payload (json), actor_id?, created_at | idx (subject_type, subject_id, created_at) — execution log |

**Central tables:** `usage_reservations` (client_id, metric, amount, ref, status reserved/settled/released, expires_at) + new `features` rows via data migration (see §8 Phase 8).

**Dedup rules** (`Deduplicator`), strongest first: same `(provider, source_record_id)` → same record; same `domain_norm` (eTLD+1, excluding shared hosts like facebook.com/instagram.com/linktr.ee/wixsite/business.site) → duplicate; same `phone_e164` **and** name similarity ≥ 0.6 → duplicate; same email → duplicate; name-only similarity → **never auto-merge** (flagged "possible duplicate" for review). Existing CRM contacts are matched by email/phone (same rule as `ContactResolver`) and by website domain stored on the contact.

**Idempotent sending:** `leadgen:tick` (every minute, `withoutOverlapping`) selects due recipients in bounded batches (≤200), inserts the message row with the unique `idempotency_key` (duplicate insert = already scheduled), dispatches `SendOutreachMessage(projectId, messageId)`. The job claims the row with a conditional `UPDATE … SET status='sending' WHERE id=? AND status='queued'` (0 rows = someone else has it → exit), re-checks suppression/eligibility/caps at send time, sends, stores `provider_message_id`. If the provider call times out after possibly accepting, status → `unknown` and **is not auto-retried** (no double send); only clearly-retryable errors (429/5xx before acceptance) are retried with backoff. Webhook/status events are applied by `provider_message_id` with monotonic status ordering.

## 4. Integrations & API limitations (verified 2026-10-15)

| Source / channel | Verdict | Notes |
|---|---|---|
| **Overture Maps Places** | ✅ **Recommended first discovery provider** | Open data, monthly releases, ~64M+ places, fields: name, taxonomy category, websites, phones, emails, socials, addresses, confidence, operating_status, sources. Licences CDLA-Permissive-2.0 / Apache-2.0 (Foursquare) / CC0 — **storage and commercial use permitted**, attribution required ("© Overture Maps Foundation"; Foursquare NOTICE kept). Queried via DuckDB directly from public S3 GeoParquet with bbox pushdown — voice-engine already ships DuckDB. Category taxonomy changed in the Sep-2026 release (store taxonomy version). Coverage: see §4a. No API cost. City→bbox geocoding can use Overture `divisions` (also open). |
| Google Places API | ⚠️ Not suitable as a CRM-feeding source | Policies allow storing **only `place_id`** indefinitely; other content can't be pre-fetched/cached/stored beyond limited exceptions, and must be attributed / shown with Google branding. Possible later as an "id_only / live-display" adapter, never bulk-stored. |
| Business's own website | ✅ Enrichment | First-party public info (contact page). SafeFetcher + robots.txt + small page cap. Emails/phones stored with `found_on_site` provenance + source URL. |
| CSV/XLSX import | ✅ Phase 3 | User attests the data's lawful origin on upload. |
| Licensed data vendors (Apollo, Foursquare paid, etc.) | Later adapters | Need contract + key; storage rights vary per vendor — adapter declares `storage_policy`. |
| Facebook / Instagram discovery | ❌ Not available | Page search needs Page Public Content Access (app review, rarely granted for lead-gen). Connected accounts only expose your own pages/inbox. |
| LinkedIn | ❌ Not available | No self-serve company/people search; Company Search restricted to selected developers; API terms exclude apps whose core relies on LinkedIn. |
| **Email outreach** (tenant's connected mailbox) | ✅ Primary send channel | Through existing `mail-channel`. Add Message-ID capture, `List-Unsubscribe` + one-click unsubscribe link, sender identity/footer, per-mailbox daily caps (Gmail/Workspace impose their own daily limits), bounce (DSN) parsing in the IMAP poller. |
| WhatsApp (Cloud API) | ⚠️ Opted-in contacts only | Business-initiated = approved **template** only and requires prior **opt-in naming the business**. Cold-messaging discovered numbers violates WhatsApp policy and risks the number's quality rating/ban. Eligibility gate requires a recorded opt-in on the contact. |
| Messenger / Instagram DM | ⚠️ Existing conversations only | User must message first (24h window; HUMAN_AGENT tag up to 7 days). Usable for follow-ups to people already in the inbox, never for cold outreach. |
| SMS | ❌ Not built | Twilio integration is voice-only today. |

### 4a. Overture coverage check

Spike run 2026-10-15 against release `2026-09-23.1`, DuckDB 1.5.4 from this dev box (Pakistan → AWS us-west-2), bbox filter only:

| Area | Places | With website | With phone | With email | Query time |
|---|---|---|---|---|---|
| Dubai | 100,997 | 68,428 | 84,995 | 52,113 | 107 s (cold: extension install + metadata) |
| Karachi | 37,959 | 18,093 | 32,212 | 21,756 | 15 s |

Real-estate categories, Dubai: `real_estate_service` 2,846 (2,070 with website, 1,969 with email), `real_estate_agent` 652, `commercial_real_estate` 111, `real_estate_investment` 96 → **~3,600 agencies, ~75% with a website**. Karachi: ~1,180 real-estate businesses.

Caveats: Overture emails come from its upstream sources and are **not verified** — they are stored as `unverified` until enrichment finds them on the business's own site or MX passes. ~4% of records have no category. City-sized queries are fine interactively; country-wide or large-radius queries go through the background job with pagination by bbox tiles.

## 5. Security, privacy & compliance

- **Tenant isolation:** physical (per-project DB) + every query still filters `project_id`; controllers resolve project through `Project::accessibleBy($user, $client)` (note: existing Contacts/Leads controllers skip this — new controllers won't). Jobs carry only `projectId` + row ids and re-derive the tenant; webhook/inbound handlers resolve tenant from the connection/mailbox, never from payload ids. Exports stream only the resolved project. Tests assert cross-project 404s.
- **Permissions:** add `config/leadgen.php` abilities `leadgen.view|search|import|edit|export|approve|send|settings`; extend `Role` with an optional `abilities` json (null = all abilities of allowed modules → backward compatible). Owners bypass. Gate via `Gate::define` + middleware on routes.
- **Secrets:** reuse encrypted casts on `email_accounts`/`channel_connections`; tokens never reach Blade, prompts, or logs. Provider config (future vendors) stored per client with `encrypted:array`.
- **SSRF:** SafeFetcher resolves DNS and rejects private, loopback, link-local, CGNAT, multicast, metadata (169.254.169.254), and IPv6 equivalents on **every redirect hop**; http(s) only, ports 80/443, 5 MB/10 s caps, robots.txt honoured, identifying User-Agent.
- **AI safety:** prompts contain only prospect fields + offer profile (no tokens/PII beyond business contact), output validated against JSON schema, explanations must cite fields; "hypothesis" label enforced in schema; drafts forbidden from claiming prior contact (system rule + post-check).
- **Compliance controls (not legal advice):** per-campaign **lawful basis** declaration (e.g. B2B legitimate interest / consent) and sender jurisdiction; mandatory unsubscribe link + sender identity & postal address footer for email; global per-project suppression list checked at schedule **and** send time; opt-out keywords and unsubscribes write suppressions (and the contact-level opt-out, fixing the per-session gap); prefer role addresses (info@, sales@) over personal ones; frequency caps per recipient; data retention setting with scheduled purge of unconverted prospects; delete/suppress actions (delete keeps a hashed suppression so the record isn't re-imported). You should get legal review for each target market (UAE/KSA/PK/EU/UK/US differ).
- **Rate limits:** named limiters for search starts, AI bulk ops, exports; audit log on approve/launch/export/delete/suppression edits.

## 6. Implementation phases & dependencies

| Phase | Deliverable | Depends on |
|---|---|---|
| 2 | Module registration, abilities, tenant migrations, models + policies, SafeFetcher, `/llm/structured` engine endpoint, test harness for tenant DB | — |
| 3 | Provider contract + **Overture provider** (voice-engine `/leadgen/places/search` + `/estimate` + city lookup), search jobs (start/pause/resume/cancel/progress), CSV/XLSX import with mapping | 2 |
| 4 | Normaliser + dedup, website enrichment, search history/saved searches, lists, tags/owner, "Add to CRM", export (`Xlsx::build`) | 3 |
| 5 | Offer profiles, AI qualification + review queue, templates, AI drafts (multi-language, tone, length), editor/preview | 4 |
| 6 | Campaign builder (stepper), eligibility/suppression preview, approval, `leadgen:tick` + idempotent email sending, unsubscribe endpoint, WhatsApp template sending for opted-in contacts (+ wamid fix) | 5 |
| 7 | Follow-up sequences, reply detection (email + WhatsApp), bounce parsing, stop-on-reply, CRM activity + owner email notification | 6 |
| 8 | Dashboard metrics, analytics + export, plan features/metrics + reservations, Ops provider settings, execution logs UI | 6 (can start in parallel for quotas) |
| 9 | Security review, tenant-isolation + idempotency test sweep, performance (10k prospects/project), docs | all |

**Vertical slice target (end of Phase 5):** search Dubai real-estate agencies via Overture → dedup → enrich from websites → qualify → edit a draft. Sending arrives in Phase 6.

## 7. Files expected

**New (admin):** `config/leadgen.php`; `app/Services/LeadGen/**` (Providers, Normalizer, Deduplicator, Enrichment, Ai, Outreach, Campaigns, Usage); `app/Support/SafeFetcher.php`; `app/Models/LeadGen/*` (12 models); `app/Jobs/LeadGen/*` (RunSearch, ImportProspects, EnrichProspect, QualifyProspects, DraftMessages, SendOutreachMessage); `app/Console/Commands/LeadGenTick.php`, `LeadGenPurge.php`; `app/Http/Controllers/LeadGen/*`; `app/Policies/LeadGen/*`; `resources/views/leadgen/**`; `resources/views/partials/{bulk-table,stepper}.blade.php`; `routes/leadgen.php`; `database/migrations/tenant/2026_10_*_create_lg_*`; central migrations (`usage_reservations`, `roles.abilities`, features data migration); `tests/Feature/LeadGen/**`, `tests/Unit/LeadGen/**`.
**New (voice-engine):** `app/api/routes/leadgen.py`, `app/services/overture_places.py`, `app/api/routes/structured.py` (+ tests).
**Modified:** `config/modules.php`, `routes/web.php` (include), `layouts/sidebar.blade.php`, `layouts/mobile-menu.blade.php`, `app/Console/Kernel.php` (tick + purge), `app/Models/Role.php` (abilities), `BrainResolver.php` (call types), `PythonClient.php` (structured + places), `packages/mail-channel` (`SmtpMailer` returns Message-ID, List-Unsubscribe headers, DSN parsing), `packages/meta-channels/GraphClient::sendTemplate` (return wamid), `app/Meta/CrmMessageStatusHandler.php` (indexed lookup), `app/Meta/ComplianceGuard.php` + `CrmInboundEmailHandler.php` (write suppressions / reply hooks), `voice-engine/app/api/http.py` (register routes), `.env.example`, `docs/`.

## 8. Testing strategy & acceptance criteria

- **Unit:** normaliser (phone E.164, domain eTLD+1, shared-host exclusion), dedup matrix (incl. "same name, different businesses must not merge"), SafeFetcher (private IPs, redirect-to-private, DNS rebinding via mocked resolver), template variable rendering, eligibility gate rules, status monotonicity.
- **Feature/integration:** search job lifecycle with a fake `LeadSource` (test-only, never registered in prod), import mapping, qualification with mocked `PythonClient`, campaign approval required before any send, send job idempotency (dispatch twice / overlapping ticks / retried job → exactly one SMTP call), reply stops follow-ups, unsubscribe → suppressed at send time, quota reservation/settle/release on failure.
- **Authorization & tenant isolation:** every route with another project's/client's hashed id → 404; role without `leadgen.send` can't approve/launch; exports scoped.
- **voice-engine:** pytest for `/leadgen/places/*` against a local fixture parquet; `/llm/structured` schema validation.
- **E2E (manual + scripted smoke):** real Overture search for one city, real send to a test mailbox, reply from it, unsubscribe click.
- Tests run on an **isolated** `DB_DATABASE` (other sessions share the test DB; 12 known pre-existing billing failures).
- Acceptance = the "Definition of done" in the brief, plus: no send without approval; no send to suppressed/ineligible; metrics show only channel-reported states.

## 9. Pre-existing issues found (not in scope unless approved)

1. voice-engine crawler + admin `WebhookResolver`/`testWebhook`: no SSRF protection.
2. Meta webhook signature check **fails open** when no app secret is configured.
3. `GraphClient::sendTemplate` drops the wamid → template deliveries are untrackable.
4. `CrmMessageStatusHandler` finds messages by scanning 24h of rows in PHP.
5. Opt-out stored per session; a new session forgets it.
6. `ContactWebController`/`LeadWebController` don't apply `Project::accessibleBy` (members restricted to one project can switch `project_id`).
7. `Project.db_password` / `api_key` / `project_api_secret` stored in plaintext.
8. `UsageLimitService::allows()` never called — metered quotas aren't enforced anywhere.
9. `/llm/stream` ignores brain/BYOK overrides.

Items 3, 4, 5 are fixed as part of Phase 6–7 (the feature needs them). 1 is partly addressed by SafeFetcher (new code); retrofitting the old paths is a small follow-up.

## 10. Places worker (Phase 3)

`uvicorn app.places.app:app --port 8010` — voice-engine image, own container (`places` in docker-compose). Internal only, `X-Internal-Secret`.

| Endpoint | Purpose |
|---|---|
| `POST /places/geocode {country, q}` | Country index of named divisions (built once per country per release, ~1–1.5 min), then instant lookups. Ranks exact name, prefix, then searchable size. |
| `POST /places/area {bbox}` | Loads every place in the box from S3 into a local Parquet file (once per area per release; ~1–2 min for a city). Returns `loading` until ready. |
| `GET /places/area/{id}/categories` | Categories present in the area, with counts — the search form's category picker. |
| `POST /places/search {area_id, filters, offset, limit}` | Local filtered paging (~60 ms). |

- Cache: `PLACES_CACHE_DIR/<release>/{areas,divisions}/*.parquet`; key includes `AREA_SCHEMA` and the release, so a running search pages one release consistently. Disposable.
- `OVERTURE_RELEASE` pins a release; empty = latest from `stac.overturemaps.org`.
- Names: Overture's `names.primary` is often the local-language name (Arabic, Urdu); the English `names.common['en']` is preferred for display, the local name is kept in `source_meta.local_name`.
- Live check 2026-10-15: "Dubai" → Dubai Emirate; area load 128 s; 2,126 real-estate businesses with a website (confidence ≥ 0.5).

Admin side: `RunSearch` job processes ≤5 pages × 100 per run then re-queues itself; state on the search row (status, cursor, progress) so pause / resume / cancel / crash-retry resume exactly. `ProspectWriter` is idempotent (insertOrIgnore on unique keys). Imports reuse the same job via `ImportSource` (content-hash source ids, max 10,000 rows, rights attestation recorded).

## 11. Dedup, enrichment, CRM (Phase 4)

**Dedup** (`Deduplicator`, applied in `ProspectWriter`): strong = same website host, same email, or same phone + similar name → the record is folded into the existing prospect (empty fields filled with provenance; nothing overwritten; other source kept in `source_meta.also_from`). Same phone + different name → stored separately with `duplicate_of` set as a *possible* duplicate for a person to judge. Name alone never matches; the prospect page lists similar names in the same city.

**Do-not-contact** (`lg_suppressions`): `deleted` (from "Delete — don't add again") makes future searches/imports skip the business; `opt_out` / `bounce` / `complaint` / `manual` keep it visible but marked and excluded from CRM-add and export.

**Enrichment** (`WebsiteEnricher`, `EnrichProspect` job): homepage + ≤4 contact/about pages on the business's own site via SafeFetcher. Fills an empty email only with a generic inbox on the site's own domain (info@, sales@ …); other addresses (named people, free-mail) are listed as alternatives, never auto-selected. Phones only from `tel:` links. MX check on the email domain; no mailbox probing. Verification only moves up the ladder. Live check 2026-10-16 (madaproperties.com → .sa redirect) found and fixed a text-gluing bug.

**Add to CRM** (`CrmSync`): Contact via `ContactResolver::resolve()` with the email (or phone) as the handle — the same identity an inbound reply resolves to — plus a session-less Lead (`fields.source = Lead Generation`) on the existing Leads board. Idempotent via `lg_prospects.lead_id`.

**Export**: XLSX via `App\Support\Xlsx` (all cells text), current filters, do-not-contact rows excluded, source + licence + verification columns, audit-logged.

## 12. AI scoring and drafts (Phase 5)

- **What the AI sees** (`Ai\ProspectFacts`): name, category (+ path), brand, city, country, whether website/email/phone/socials exist **and how verified**, listing status/confidence, tags. Never notes, owners, ids, raw payloads, or the email address itself. Values are flattened to one bounded line (no prompt-like markup).
- **Scoring** (`Ai\Qualifier`, call type `leadgen_qualify` → machinery brain, metered): schema-checked JSON; then in PHP — evidence must name a supplied fact and quote its value, else dropped; no evidence / "insufficient" / invalid schema → **needs review**; band derived from score (≥70 high, ≥40 medium); **score capped at 85** because listing data shows fit, never need (a live model scored a listing 100 "perfect fit"); WhatsApp never suggested (needs opt-in), email only with an address, none for do-not-contact. Possible needs stored as labelled hypotheses. History kept; one current row per prospect. Human override with reason.
- **Drafts** (`Ai\Drafter`, call type `leadgen_draft` → reply brain): language (incl. Roman Urdu), tone, length, optional template. After generation: false-familiarity phrases → regenerate once, then saved with a warning; `{{variables}}` filled only from real data, missing/unknown ones reported; drafts are `status=draft` and never sent from here. Template mode fills a template with no AI.
- **Live check 2026-10-16** (Gemini `gemini-3.5-flash-lite`, real prompts, real prospect): all three calls schema-valid; private note never in a prompt; no false familiarity. Fixed from what it showed: score cap, "never tie a problem to this business or its website", "keep business and product names exactly as written" (Arabic draft had mistranslated the name), sender labelled as a person.
