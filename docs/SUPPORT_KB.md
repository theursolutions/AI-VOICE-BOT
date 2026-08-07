# Support Knowledge Base — AI Voice CRM

**Audience:** IT company support team (helpdesk / NOC / on-call engineers).
**Purpose:** The single playbook a support agent follows to take a ticket from
intake → triage → resolution → escalation for the AI Voice CRM platform.

> Read [§3 Support model](#3-support-model-tiers--roles) and
> [§5 Severity & SLA](#5-severity-classification--sla) first — they decide how
> fast you must act. Then use [§8 Issue catalog](#8-issue-catalog-symptom--fix)
> as your day-to-day lookup. Companion docs:
> [DEPLOYMENT.md](DEPLOYMENT.md), [SCALING.md](SCALING.md),
> [API_CONTRACT.md](API_CONTRACT.md),
> [WHATSAPP_CALLING_INTEGRATION.md](WHATSAPP_CALLING_INTEGRATION.md).

---

## 1. How to use this document

1. **Log the ticket** and collect intake info ([§6](#6-intake-checklist-collect-before-you-touch-anything)).
2. **Classify severity** ([§5](#5-severity-classification--sla)) — this starts the SLA clock.
3. **Triage** with the decision tree ([§7](#7-triage-decision-tree)) to find the failing component.
4. **Resolve** using the matching article in the issue catalog ([§8](#8-issue-catalog-symptom--fix)).
5. **Escalate** if out of scope or past the SLA threshold ([§9](#9-escalation)).
6. **Communicate & close** with the templates ([§10](#10-communication-templates)) and write a new KB note if it was novel ([§13](#13-keeping-this-kb-alive)).

---

## 2. Platform at a glance

A multi-tenant AI Voice/Chat CRM. Customers (tenants) get an AI agent that
answers chat, WhatsApp/Instagram/Facebook, and voice, backed by their own
knowledge base, with human-agent handoff.

### Components (what breaks, and where to look)

| Service | Tech | Role | Health check | Logs |
|---------|------|------|--------------|------|
| `caddy` | Caddy 2 | TLS + reverse proxy (ports 80/443) | `curl -I https://crm.<domain>` | `docker compose logs caddy` |
| `app` | Laravel (nginx+PHP-FPM) | Admin UI + HTTP API | `curl -I https://crm.<domain>` | `docker compose logs app` |
| `queue` | Laravel worker | Inbound messages, lead extraction, RAG sync | `docker compose ps queue` | `docker compose logs queue` |
| `scheduler` | Laravel `schedule:work` | Cron-style jobs | `docker compose ps scheduler` | `docker compose logs scheduler` |
| `voice-engine` | FastAPI (Python) | STT (Whisper) → LLM → TTS (XTTS), RAG ingest | `curl https://voice.<domain>/healthz` → `{"status":"ok"}` | `docker compose logs voice-engine` |
| `qdrant` | Qdrant | Vector store for RAG / knowledge base | internal | `docker compose logs qdrant` |
| `mysql` | MySQL 8 | Central DB `ai-crm-config` + per-tenant chat DBs | internal | `docker compose logs mysql` |
| `redis` | Redis 7 | Cache + sessions + queue backend | internal | `docker compose logs redis` |

> Only **Caddy** is exposed to the internet (80/443). Everything else talks over
> the private `aicrm` Docker network by service name. The voice-engine listens
> on **:8000** inside Docker (dev/local installs use **:8002**). Health route is
> **`/healthz`**, not `/health`.

### Request flow (mental model)

```
Customer ─► Caddy ─► app (Laravel)
                       │  text turn  ─► queue worker ─► LLM provider (Groq/Gemini/Anthropic)
                       │  voice turn ─► voice-engine (WS /ws/turn) ─► STT → LLM → TTS
                       │  knowledge  ─► voice-engine ─► qdrant (RAG retrieval)
                       └─ WhatsApp/IG/FB ─► /api/whatsapp/webhook ─► queue ─► ConversationManager
voice-engine ─► app (turn-completed webhook, shared PYTHON_INTERNAL_SECRET)
```

### Key concepts (so tickets make sense)

- **Tenant / Project** — each customer has its own chat DB `ai-crm-client-<project_id>`. "Unknown database" almost always means a tenant wasn't provisioned.
- **Bot Agent** — the AI persona. Can be `type=ai` or `type=human` (a real team member who takes handoffs).
- **Skill / Data Source / Flow** — what the AI can do, what it knows, and scripted flows.
- **Human handoff** — AI escalates to a human agent (least-busy, skill-matched) or queues; bot pauses for that conversation.
- **24-hour window (Meta)** — outside WhatsApp's 24h service window you can only send approved templates, not free-form messages.
- **Compute Mesh** — live scaling dashboard (queue depth, active calls, desired worker/voice fleet). Shows *desired* scale; actual provisioning is the orchestrator's job.

---

## 3. Support model (tiers & roles)

| Tier | Who | Owns | Typical actions |
|------|-----|------|-----------------|
| **Tier 1 — Helpdesk** | First responders | Intake, classification, known fixes, status comms | Verify health checks, restart a service, follow a catalog article, reset a user, re-run provisioning |
| **Tier 2 — Platform engineer** | App/DevOps on-call | Anything Tier 1 can't fix from the catalog | Read logs in depth, DB queries, config/env changes, queue/worker scaling, RAG re-ingest |
| **Tier 3 — Engineering / vendor** | Product/dev team & external providers | Bugs, data corruption, provider outages, code changes | Patches, hotfixes, schema fixes, provider tickets (Groq/Meta/host) |

**Golden rules**
- Never run a destructive command (`down -v`, `DROP`, `tenant` re-provision over existing data, force-push) without Tier 2+ approval and a fresh backup.
- Customer/tenant data is confidential. Don't export, screenshot, or paste chat content into external tools.
- Change `.env`/config in production only via the approved change process ([§11](#11-change--maintenance-procedures)).
- Always reproduce/verify before declaring resolved.

---

## 4. Operating hours & on-call

> Fill in for your org — placeholders below.

- **Helpdesk hours:** ____ (e.g. Mon–Fri 09:00–18:00 local)
- **After-hours on-call:** ____ (Tier 2 rotation, pager/phone)
- **Escalation manager:** ____
- **Status page / customer comms channel:** ____

---

## 5. Severity classification & SLA

Classify by **business impact**, not by how hard it looks.

| Sev | Definition | Examples | Target response | Target resolution / workaround |
|-----|-----------|----------|-----------------|-------------------------------|
| **S1 – Critical** | Platform down or unusable for all/most tenants; data loss/breach | Site down (502/all), MySQL down, all voice/chat failing, security incident | 15 min | 4 h (or continuous effort) |
| **S2 – High** | Major feature down for one tenant or a channel platform-wide | One tenant can't chat, WhatsApp inbound dead, voice replies failing, queue stuck | 30 min | 8 h |
| **S3 – Medium** | Degraded/partial; workaround exists | Slow voice replies, one data source not syncing, intermittent errors | 4 business h | 3 business days |
| **S4 – Low** | Cosmetic, question, config request | UI glitch, "how do I…", add a user, doc request | 1 business day | Best effort |

**SLA clock starts at ticket creation.** If you'll miss the resolution target,
escalate one tier *before* the breach, not after. Set up auto-alerts:
S1 pages on-call immediately.

---

## 6. Intake checklist (collect before you touch anything)

Capture in the ticket — saves a round-trip and lets Tier 2 act fast:

- [ ] **Tenant / project name + project_id** (which customer?)
- [ ] **What** they were doing (channel: web chat / WhatsApp / IG / FB / voice call)
- [ ] **Exact symptom** + error text / screenshot (verbatim)
- [ ] **When it started** + is it ongoing or intermittent
- [ ] **Scope**: one user, one tenant, or everyone?
- [ ] **Recent changes**: deploy, config edit, DNS, provider key, new data source
- [ ] **Repro steps** (can support reproduce it?)
- [ ] **Affected URL** (crm.\<domain\> vs voice.\<domain\>) and approx timestamp (for log correlation)

> **First move for any "it's broken":** run the health checks in
> [§12 Diagnostics](#12-diagnostics-cheat-sheet). 80% of triage is "which box is
> red."

---

## 7. Triage decision tree

```
Is the website (crm.<domain>) reachable?
├─ No  → curl -I returns nothing / timeout
│        → Caddy up? DNS resolving? ports 80/443 open?         → §8.1 / §8.2
│        → 502 from Caddy?  app still booting or crashed        → §8.3
│
└─ Yes → Can users log in / use the admin UI?
         ├─ No (errors, 500)   → app/DB issue                   → §8.3 / §8.4
         └─ Yes → What's failing?
                  ├─ Text/chat AI not replying                  → §8.5 (queue/LLM)
                  ├─ Voice call broken / silent / slow          → §8.6 (voice-engine)
                  ├─ Knowledge base answers wrong/empty         → §8.7 (RAG/Qdrant)
                  ├─ WhatsApp/IG/FB not sending/receiving       → §8.8 (Meta)
                  ├─ "Unknown database ai-crm-client-N"         → §8.9 (tenant)
                  ├─ Human handoff / agent console issues       → §8.10
                  └─ One user account / login / permission      → §8.11
```

---

## 8. Issue catalog (symptom → fix)

Each article: **Symptom → Likely cause → Tier-1 fix → Escalate when.**
Run commands from the deployment directory (where `docker-compose.yml` lives).

### 8.1 Website completely unreachable (timeout, no response)
- **Likely cause:** Caddy down, server down, DNS, or firewall (ports 80/443).
- **Tier-1 fix:**
  1. `docker compose ps` — is `caddy` up/healthy? If not: `docker compose up -d caddy`.
  2. From outside, `nslookup crm.<domain>` — does it point at the server IP?
  3. Confirm ports 80/443 open at firewall/security group.
  4. Server itself reachable (ping/SSH)? If host is down → infra/hosting issue.
- **Escalate (Tier 2)** if host is down, or Caddy won't start.

### 8.2 Caddy can't get / renew a TLS certificate
- **Symptom:** browser cert warning, or `docker compose logs caddy` shows ACME errors.
- **Likely cause:** DNS not pointing at the server yet, or ports 80/443 blocked (Let's Encrypt can't validate).
- **Tier-1 fix:** confirm DNS resolves to the server IP and 80/443 are open; then `docker compose restart caddy` and watch logs. New DNS can take time to propagate.
- **Escalate** if DNS/ports are correct but issuance still fails.

### 8.3 502 / 503 Bad Gateway from Caddy
- **Likely cause:** upstream (`app` or `voice-engine`) still booting or crashed.
- **Tier-1 fix:**
  1. `docker compose ps` — find the unhealthy service.
  2. Just after a deploy/restart this is **normal for a few minutes** (MySQL migrate, model load). Wait, re-check.
  3. `docker compose logs --tail=100 app` (or `voice-engine`) for the real error.
  4. If a service is in a restart loop → [§8.4](#84-app-container-wont-start--500-errors) / [§8.6](#86-voice-broken-silent-no-reply-or-very-slow).
- **Escalate** if it stays 502 after services report healthy.

### 8.4 `app` container won't start / 500 errors
- **Likely causes & fixes:**
  - **"APP_KEY is empty"** → key never generated. Tier 2: `docker compose run --rm app php artisan key:generate --show`, paste into `.env` `APP_KEY=`, `docker compose up -d`.
  - **Can't connect to MySQL** → mysql not healthy yet, or wrong `DB_PASSWORD`. Check `docker compose ps mysql` and `logs mysql`.
  - **Changed `.env` but no effect** → config is cached at container start; `docker compose up -d` to recreate.
  - **Generic 500** → `docker compose logs --tail=200 app`; in production `APP_DEBUG=false` hides detail, so the log is the source of truth.
- **Escalate (Tier 2)** for any code-level exception or migration failure.

### 8.5 Chat AI doesn't reply (text channels)
- **Likely cause (in order):**
  1. **Queue worker down/stuck** — inbound messages and replies run on the queue. `docker compose ps queue`; `docker compose logs --tail=100 queue`. Fix: `docker compose up -d queue`. Backlog? scale: `docker compose up -d --scale queue=4`.
  2. **LLM provider error / rate limit (429)** — logs show upstream/LLM errors. Confirm the provider key is set and valid (`GROQ_API_KEY` by default). For sustained 429s, Tier 2 sets `LLM_FALLBACK_PROVIDER` + a second key, or raises the provider tier (see [SCALING.md](SCALING.md)).
  3. **Wrong/empty provider key** — e.g. `LLM_PROVIDER=anthropic` with an empty `ANTHROPIC_API_KEY` → every turn fails. Default working provider is **groq**.
- **Tier-1 fix:** restart the queue; verify provider key present; check the Compute Mesh page for queue depth / failed jobs.
- **Escalate** if keys are valid and provider is up but replies still fail.

### 8.6 Voice broken (silent, no reply, or very slow)
- **First:** `curl https://voice.<domain>/healthz` → `{"status":"ok"}`? and `/metrics` for model readiness + LLM provider.
- **Likely causes & fixes:**
  - **`/stt` or `/tts` return 503** → voice models failed to load (out of RAM or download failed). `docker compose logs voice-engine`; ensure **≥8 GB RAM**. Restart: `docker compose restart voice-engine`.
  - **Unhealthy for ~10 min on first boot** → **normal**: it's downloading XTTS (~1.8 GB) + Whisper. Watch `docker compose logs -f voice-engine`; the healthcheck has a 10-min grace.
  - **Replies very slow** → **known constraint**: CPU TTS (XTTS) is ~1s/character. Voice must use the **WS streaming path** (`/ws/turn`), which synthesizes sentence-by-sentence. A handful of concurrent calls saturates one CPU box. Fixes: move to GPU host, or enable **ElevenLabs** (cloud TTS, per-project) to remove the CPU bottleneck. See [SCALING.md](SCALING.md).
  - **"empty_input / no transcribable audio"** → client-side mic capture (browser AudioContext suspended); server STT itself is fine. Have the user hard-refresh and retry; on the widget side this is a known fixed bug — confirm they're on the current widget build.
- **Escalate** for persistent 503 after restart with adequate RAM, or capacity (concurrent-call) limits → Tier 2/3 (GPU / cloud TTS decision).

### 8.7 Knowledge base / RAG gives wrong or empty answers
- **Likely cause:** documents not ingested, ingest failed, or Qdrant unavailable.
- **Tier-1 fix:**
  1. `docker compose ps qdrant` healthy?
  2. Was a data source recently added? Ingestion runs async on the queue — check `docker compose logs queue` for ingest errors.
  3. Re-trigger ingest / sync for that data source from the admin UI (Data Sources → re-sync).
- **Escalate (Tier 2)** to re-ingest manually or inspect the Qdrant collection (`QDRANT_COLLECTION=crm_chunks`).
- **Data snapshots (CSV/JSON/XLSX)** use the same ingest pipeline as documents and should flip `pending → active` on upload. If one is stuck at `pending`, click **Resync** on the data source (the voice-engine must be reachable). If it still won't activate, the `/rag/ingest` call is failing — check voice-engine logs.

### 8.8 WhatsApp / Instagram / Facebook not sending or receiving
- **Inbound not arriving:**
  - One webhook handles all three: `GET|POST /api/whatsapp/webhook`. Verify Meta's webhook is still subscribed and the verify token matches.
  - Inbound is processed on the **queue** → confirm `queue` is up (see [§8.5](#85-chat-ai-doesnt-reply-text-channels)).
- **Outbound fails / "can't send free-form message":**
  - **24-hour window closed** — outside Meta's 24h customer-service window you can only send **approved templates**. This is by design. Use a template, or wait for the customer to message first.
  - **Token/connection issue** — the channel connection's access token expired or lacks scope. Re-connect the channel (Channels page → reconnect / OAuth). Check `channel_onboarding_logs` on the Channels page; use **Retry**.
- **Calls (WhatsApp Business Calling):** WebRTC bridge is **v1 / experimental**; needs a public host + TURN. Treat call issues as Tier 3.
- **Escalate** for token/permission errors needing Meta app config, or App Review scope issues.

### 8.9 "Unknown database `ai-crm-client-N`"
- **Cause:** the tenant DB was never provisioned for that project.
- **Tier-1 fix:** `docker compose exec app php artisan tenant:provision <project_id>` (or with no id to do all active projects).
- After a tenant **schema change**: `docker compose exec app php artisan tenant:migrate` (all) or `tenant:migrate <id>` (one).
- **Escalate** if provisioning errors (DB privileges, MySQL down).

### 8.10 Human handoff / agent console issues
- **AI won't escalate to a human:** the handoff tool is only offered when the project has at least one **human** agent that is **online** with spare capacity. Check Agents page: agent `type=human`, `presence=online`, under `max_active_chats`.
- **Conversation stuck "queued":** no online human with matching skill/capacity. Bring an agent online or raise capacity.
- **Bot keeps replying after handoff:** per-conversation bot pause flag not set — re-claim/resolve from the chat console; bot is paused while a human owns the chat.
- **Console not updating:** it **polls** (≈6s list / 4s thread), not live websockets — a short delay is expected. A hard refresh clears stale state.

### 8.11 User login / account / permission
- **Can't log in:** verify the email exists; trigger password reset (needs mail configured — `MAIL_*` in `.env`; default mailer is `log`, so resets may not be emailed in some installs). Tier 2 can reset directly via tinker.
- **Create the first/admin user:** see [DEPLOYMENT.md §7](DEPLOYMENT.md). New users are normally created in the admin UI.
- **Per-table AI access / permissions:** managed in the admin UI (access control added in recent release) — verify the project's data-access settings.

---

## 9. Escalation

**When to escalate**
- Past (or about to pass) the SLA resolution target.
- Requires a config/`.env`/DB change in production (Tier 2).
- Code bug, data corruption, or schema fix (Tier 3).
- Third-party outage: LLM provider (Groq/Gemini/Anthropic), Meta, hosting, Let's Encrypt (Tier 2 opens the vendor ticket).

**How to escalate** — hand over a complete package, don't just reassign:
- Ticket ID, tenant/project_id, severity
- Symptom + verbatim errors, timestamps, affected URL
- What you already checked (health checks, logs, articles tried) and the results
- Any change you made (so Tier 2 isn't surprised)

**Escalation contacts** (fill in):

| Path | Who | How | Hours |
|------|-----|-----|-------|
| Tier 2 — Platform/DevOps | ____ | ____ | ____ |
| Tier 3 — Engineering | ____ | ____ | ____ |
| LLM provider | Groq/Gemini/Anthropic console | ____ | 24/7 |
| Meta (WhatsApp/IG/FB) | Meta Business support | ____ | — |
| Hosting / infra | ____ | ____ | ____ |

---

## 10. Communication templates

**Acknowledge (any sev)**
> Hi \<name\>, thanks for reporting this. We've logged it as **\<ticket\>** (Sev \<n\>) and are investigating. Next update by \<time\>.

**Investigating / known issue**
> We've identified the cause (\<plain-language summary\>) and are working on a fix. We'll confirm once resolved. Current workaround: \<if any\>.

**Resolved**
> This is now resolved. Root cause: \<short\>. What we did: \<short\>. Please confirm it's working on your end. We'll keep monitoring.

**Need info**
> To dig in we need: \<the missing intake items\>. Could you share those? It'll let us resolve this faster.

**S1 status update (every 30–60 min until resolved)**
> Update on \<ticket\>: \<what we know / what we're doing / ETA or next update time\>.

---

## 11. Change & maintenance procedures

**Deploy a new version**
```bash
git pull
docker compose build
docker compose up -d                 # recreates only changed services
docker compose exec app php artisan tenant:migrate   # if tenant schema changed
```

**Restart a single service**
```bash
docker compose restart <service>     # e.g. voice-engine, queue, app
```

**Scale text throughput (the #1 lever)**
```bash
docker compose up -d --scale queue=4
```

**Apply an `.env` / config change**
1. Get change approval + note it in the ticket.
2. Edit `.env`, then `docker compose up -d` (recreates, re-caches config).
3. Verify with health checks ([§12](#12-diagnostics-cheat-sheet)).

**Stop / start (data preserved)**
```bash
docker compose down                  # stop; volumes/data kept
docker compose up -d
```

> ⚠️ **Never** run `docker compose down -v` in production — it **deletes volumes**
> (databases, RAG store, models, uploads). Tier 2+ + backup only.

---

## 12. Diagnostics cheat sheet

```bash
# Overall health
docker compose ps                                  # which services are up/healthy

# Public endpoints (through Caddy / TLS)
curl -I https://crm.<domain>                        # app responds?
curl https://voice.<domain>/healthz                 # → {"status":"ok"}
curl https://voice.<domain>/metrics                 # LLM provider + model readiness

# Logs (add --tail=N or -f to follow)
docker compose logs --tail=100 app
docker compose logs --tail=100 queue
docker compose logs --tail=100 voice-engine
docker compose logs --tail=100 caddy

# Tenant management
docker compose exec app php artisan tenant:provision <project_id>
docker compose exec app php artisan tenant:migrate <project_id>

# Run any artisan command
docker compose exec app php artisan <command>

# Resource pressure (RAM is the usual voice culprit)
docker stats --no-stream
```

Quick-reference symptom table (condensed from [DEPLOYMENT.md §15](DEPLOYMENT.md)):

| Symptom | Likely cause / fix | Article |
|---------|--------------------|---------|
| `app` exits: "APP_KEY is empty" | Generate key, set in `.env` | [§8.4](#84-app-container-wont-start--500-errors) |
| Caddy can't get a cert | DNS/ports not ready | [§8.2](#82-caddy-cant-get--renew-a-tls-certificate) |
| voice-engine unhealthy ~10 min on boot | Normal — downloading models | [§8.6](#86-voice-broken-silent-no-reply-or-very-slow) |
| `/stt` or `/tts` → 503 | Models failed to load / low RAM | [§8.6](#86-voice-broken-silent-no-reply-or-very-slow) |
| 502 right after `up` | Upstream still booting | [§8.3](#83-502--503-bad-gateway-from-caddy) |
| Queued work never runs | `queue` down/crashed | [§8.5](#85-chat-ai-doesnt-reply-text-channels) |
| "Unknown database ai-crm-client-N" | Tenant not provisioned | [§8.9](#89-unknown-database-ai-crm-client-n) |
| LLM 429 / rate limits | Add fallback provider / raise tier | [§8.5](#85-chat-ai-doesnt-reply-text-channels) |
| `.env` change ignored | Recreate container (`up -d`) | [§8.4](#84-app-container-wont-start--500-errors) |

---

## 13. Backups & disaster recovery (Tier 2)

Persistent state lives in named volumes: `mysql_data`, `qdrant_storage`,
`app_storage` (uploaded docs + voice samples), `redis_data`, `voice_models`
(re-downloadable — not critical).

**MySQL dump (central + all tenants):**
```bash
docker compose exec mysql sh -c \
  'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --all-databases' > backup-$(date +%F).sql
```
**Restore:**
```bash
cat backup-YYYY-MM-DD.sql | docker compose exec -T mysql sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
```
Automate the nightly dump and ship it off-box. Volume backup commands and the
full DR procedure are in [DEPLOYMENT.md §11](DEPLOYMENT.md). **Verify restores
periodically — an untested backup is not a backup.**

---

## 14. Security & data handling

- Treat all tenant/customer conversation data as confidential — don't export or paste into external tools.
- Secrets live only in `.env`; never commit them or share in tickets/chat. Rotate any secret that leaks.
- Only ports **80/443** should be open; MySQL/Redis/Qdrant stay internal.
- Shared secrets `PYTHON_INTERNAL_SECRET` / `PYTHON_JWT_SECRET` must be **identical** on app and voice-engine.
- **Suspected breach / data exposure = S1.** Don't investigate alone: page Tier 2/3 and the security owner, preserve logs, follow the incident process. Full hardening checklist in [DEPLOYMENT.md §12](DEPLOYMENT.md).

---

## 15. Glossary

| Term | Meaning |
|------|---------|
| **Tenant / Project** | A customer; has its own chat DB `ai-crm-client-<id>` |
| **Bot Agent** | AI persona (or a human agent for handoff) |
| **Skill / Data Source / Flow** | What the AI can do / knows / scripted conversation flows |
| **RAG** | Retrieval from the tenant's knowledge base (via Qdrant) to ground answers |
| **STT / TTS** | Speech-to-text (Whisper) / text-to-speech (XTTS or ElevenLabs) |
| **Handoff** | AI transferring a conversation to a human agent |
| **24h window** | Meta rule: free-form replies allowed only within 24h of the customer's last message; otherwise approved templates only |
| **Compute Mesh** | Live scaling dashboard (desired worker/voice fleet vs load) |
| **Queue worker** | Background process running inbound messages, lead extraction, RAG sync |

---

## 16. Keeping this KB alive

- Resolved something **not** in [§8](#8-issue-catalog-symptom--fix)? Add a short article (Symptom → Cause → Fix → Escalate when).
- A fix changed? Update it here and note the date.
- Recurring tickets with the same root cause → flag for a permanent fix (Tier 3), don't just keep applying the workaround.

> _Owner: ____ · Last reviewed: ____ · Review cadence: quarterly_
