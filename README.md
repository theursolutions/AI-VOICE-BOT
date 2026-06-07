# AI Voice Bot

A multi-tenant Voice CRM Agent. Customers ask questions through a web
widget (or, eventually, a phone call); the system transcribes, reasons,
extracts lead data, and replies with a cloned voice.

## Repository layout

```
.
├── voice-engine/   Python FastAPI workers — STT (faster-whisper),
│                   LLM (Gemini), TTS (Coqui XTTS), lead extraction.
│                   Stateless — receives JWT, calls back into admin.
│
├── admin/          Laravel 10 backend + admin UI.
│                   Owns sessions, messages, leads, voices (per-tenant
│                   DB) and projects/clients/users (central app DB).
│                   Mints JWTs for the data plane and persists turns.
│
├── widget/         Embeddable chat widget. Plain PHP + JS, drops onto
│                   any customer site. Talks to admin over HTTP and
│                   (soon) directly to voice-engine over WebSocket.
│
├── docs/
│   └── API_CONTRACT.md   Authoritative JSON contract between the three.
│
└── scripts/
    └── smoke-test.ps1    End-to-end HTTP smoke test.
```

## Architecture (one sentence)

Browser ↔ admin (control plane: sessions, persistence, multi-tenant
routing) and Browser ↔ voice-engine (data plane: streamed audio + LLM
tokens, authenticated by short-lived JWT minted by admin).

Read [docs/API_CONTRACT.md](docs/API_CONTRACT.md) for the wire format.

## Multi-tenancy

Three DB roles, three Laravel connections:

| Connection | Database name           | Holds                                          | Provisioned by   |
|------------|-------------------------|------------------------------------------------|------------------|
| `mysql`    | `ai-crm-config`         | users, projects, clients, payment_plans, jobs  | you, once        |
| `tenant`   | `ai-crm-client-{id}`    | sessions, messages, leads, voices, summaries   | `tenant:provision` |
| `client`   | customer-supplied       | their own CRM tables (read-only AI SQL target) | the customer     |

- The `tenant` chat DB lives on **our** host (env: `TENANT_DB_*`), one per project.
- The `client` connection points at the customer's BYO CRM, credentials stored in `projects.db_*`. Only used by AI-generated SQL queries in [BotChatController](admin/app/Http/Controllers/BotChatController.php).
- [`TenantManager::useFor($project)`](admin/app/Services/Tenant/TenantManager.php) swaps both `tenant` and `client` connections at request time.
- voice-engine never touches any DB. It's a pure compute layer.

## Local setup

Each subproject has its own README and `.env.example`. Quickstart:

```powershell
# admin
cd admin
composer install
cp .env.example .env
php artisan key:generate
# 1. master tables in `ai-crm-config`
php artisan migrate
# 2. for each onboarded project, create its `ai-crm-client-{id}` DB
#    and run the 5 chat-data migrations into it
php artisan tenant:provision <project_id>
php artisan serve --port=8001

# voice-engine
cd voice-engine
python -m venv .venv ; .venv\Scripts\Activate.ps1
pip install -r requirements.txt
cp .env.example .env
uvicorn app.api.http:app --port 8000

# widget — drop into any PHP-served folder, edit app/.env
```

End-to-end smoke test:

```powershell
.\scripts\smoke-test.ps1 -ApiKey "<projects.project_api_key>"
```

## Default voice provider

Coqui XTTS-v2 (local, free). ElevenLabs is opt-in per project via the
`voices` table.

## Data sources (RAG / CRM grounding)

Each project can attach one or more `data_sources`. The
`DataSourceRouter` queries them all before the LLM responds and
injects results into the prompt as Reference data.

| Type        | Tier | Status |
|-------------|------|--------|
| `website`   | 1    | crawled via Trafilatura, embedded with Gemini, indexed in Qdrant |
| `document`  | 1    | PDF/CSV/TXT/DOCX uploaded, parsed, embedded, indexed |
| `crm_oauth` | 2    | scaffolded; per-provider connectors next |
| `database`  | 3a   | wraps the legacy SQL-gen path; live queries |
| `agent`     | 3b   | scaffolded; customer-hosted query runner |

### Local Qdrant

Tier 1 needs a vector DB. Run Qdrant via Docker:

```powershell
docker run -d --name qdrant -p 6333:6333 -v qdrant_storage:/qdrant/storage qdrant/qdrant
```

Set in `voice-engine/.env`:

```
QDRANT_URL=http://127.0.0.1:6333
QDRANT_COLLECTION=crm_chunks
EMBEDDING_MODEL=models/text-embedding-004
```

### Onboarding a project with a website

```powershell
curl -X POST http://127.0.0.1:8001/api/v1/data-sources `
     -H "X-CLIENT-API-KEY: <project_api_key>" `
     -H "Content-Type: application/json" `
     -d '{ "type":"website", "name":"Marketing site", "config":{"url":"https://example.com","max_depth":2} }'
```

Returns the `DataSource` row with `status=pending`. The
`SyncDataSource` job (queue) calls Python `/rag/ingest`, which crawls,
chunks, embeds, and writes to Qdrant. Poll `/api/v1/data-sources/{id}/status`
for progress.

### Uploading documents

```powershell
curl -X POST http://127.0.0.1:8001/api/v1/data-sources/upload-documents `
     -H "X-CLIENT-API-KEY: <project_api_key>" `
     -F "name=FAQs" `
     -F "files[]=@./faq.pdf" `
     -F "files[]=@./pricing.pdf"
```

## Status

| Capability                       | State |
|----------------------------------|-------|
| Modular service architecture     | done |
| Conversation manager + memory    | done |
| Lead extraction (structured JSON)| done |
| Multi-tenant per-project DBs     | done |
| Real-time streaming (WS server)  | done |
| Real-time streaming (browser)    | scaffolded, not yet driven |
| Twilio / Plivo telephony         | not yet |
