# Deployment Guide — AI Voice CRM

This guide deploys the whole platform onto a single Linux server with Docker
Compose. Everything (app, AI compute, databases, TLS) comes up with one
command and persists across restarts.

> **TL;DR**
> ```bash
> git clone <repo> && cd AI-CRM-AGENT
> cp .env.example .env          # then edit it (secrets + domains)
> docker compose run --rm app php artisan key:generate --show   # paste into APP_KEY
> docker compose build          # ~15–25 min the first time (ML deps)
> docker compose up -d
> docker compose logs -f voice-engine   # wait for models to download on first boot
> ```

---

## 1. What gets deployed

| Service        | Image                  | Role | Public? |
|----------------|------------------------|------|---------|
| `caddy`        | `caddy:2-alpine`       | TLS termination + reverse proxy (auto Let's Encrypt) | **80 / 443** |
| `app`          | `aicrm/admin` (built)  | Laravel admin UI + HTTP API (nginx + PHP-FPM)        | via Caddy |
| `queue`        | `aicrm/admin` (built)  | Queue workers — inbound messages, lead extraction, RAG sync | no |
| `scheduler`    | `aicrm/admin` (built)  | Laravel scheduler (`schedule:work`)                  | no |
| `voice-engine` | `aicrm/voice-engine` (built) | FastAPI: STT (Whisper) → LLM → TTS (XTTS), RAG ingest | via Caddy |
| `qdrant`       | `qdrant/qdrant`        | Vector store for RAG                                 | no |
| `mysql`        | `mysql:8.0`            | Central app DB **+** per-tenant chat DBs             | no |
| `redis`        | `redis:7-alpine`       | Cache + sessions + queue backend                     | no |

```
                      ┌─────────── Caddy (80/443, auto-TLS) ───────────┐
   crm.example.com  ──┤ → app (nginx+php-fpm) ─┐                        │
 voice.example.com ──┤ → voice-engine (FastAPI)│                        │
                      └─────────────────────────┼────────────────────────┘
                                                 │  (private docker network: aicrm)
        queue ─┐   scheduler ─┐   app ───────────┼──► mysql   (central + tenant DBs)
               └─────────────►└─────────────────►├──► redis   (cache/session/queue)
                                                 └──► voice-engine ──► qdrant (RAG)
                                  voice-engine ──────► app (turn-completed webhook)
```

Only Caddy publishes ports. Everything else talks over the private `aicrm`
network by service name, so MySQL/Redis/Qdrant are never exposed to the
internet.

---

## 2. Server prerequisites

**Machine** (CPU-only voice — the default build):

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| vCPU     | 4       | 8+ (voice is CPU-bound — XTTS ≈ 1s/char/core) |
| RAM      | 8 GB    | 16 GB (XTTS+Whisper load ~2–3 GB resident) |
| Disk     | 40 GB   | 80 GB+ SSD (images ~6 GB, model cache ~3 GB, DB grows) |
| OS       | Ubuntu 22.04 / Debian 12 / any Docker-capable Linux | |

> Voice calls are the hard ceiling — a handful of concurrent calls saturate
> one CPU box. See [docs/SCALING.md](docs/SCALING.md). Text/chat scales fine by
> adding queue workers. For heavy voice, move to a GPU host (§10) or cloud
> TTS/STT.

**Software** — install Docker Engine + the Compose plugin:

```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER   # log out/in so `docker` works without sudo
docker compose version          # confirm the plugin is present
```

**Network**
- Open inbound TCP **80** and **443** (firewall / security group).
- Two DNS records pointing at the server's public IP — see §5.
- Outbound HTTPS so the LLM provider (Groq/Gemini/Anthropic), Let's Encrypt,
  and the model downloads (Hugging Face / Coqui) are reachable.

---

## 3. Files this deployment uses

```
.
├── docker-compose.yml          # the whole stack
├── .env.example                # copy to .env and fill in
├── DEPLOYMENT.md               # this file
├── deploy/
│   ├── Caddyfile               # reverse proxy + auto-TLS
│   └── mysql/init/01-grants.sql # tenant-DB grant (first MySQL init only)
├── admin/
│   ├── Dockerfile              # 3-stage: composer → vite/react → php-fpm+nginx
│   └── docker/                 # nginx, php-fpm, php.ini, supervisor, entrypoint
└── voice-engine/
    └── Dockerfile              # CPU-only: builder venv → slim runtime
```

---

## 4. Configure `.env`

```bash
cp .env.example .env
```

Edit `.env` and set, at minimum:

| Key | What |
|-----|------|
| `APP_DOMAIN`, `VOICE_DOMAIN` | Your two hostnames (e.g. `crm.acme.com`, `voice.acme.com`) |
| `ACME_EMAIL` | Email for Let's Encrypt expiry notices |
| `APP_KEY` | Generate it (next step) |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD` | Strong, unique secrets |
| `PYTHON_INTERNAL_SECRET`, `PYTHON_JWT_SECRET` | Strong secrets — **must be identical** for app & voice-engine (they already are, since both read the same `.env`) |
| `GROQ_API_KEY` (or `GEMINI_API_KEY`/`ANTHROPIC_API_KEY`) | Your LLM provider key |

Generate the Laravel app key and paste the output into `APP_KEY=`:

```bash
docker compose run --rm app php artisan key:generate --show
# → base64:xxxxxxxx...   copy the whole line into .env
```

> Generate strong secrets quickly: `openssl rand -base64 32`

---

## 5. DNS

Create two **A** records (or AAAA for IPv6) → your server IP:

```
crm.example.com     A    203.0.113.10
voice.example.com   A    203.0.113.10
```

Caddy obtains and renews TLS certificates automatically once these resolve and
ports 80/443 are reachable. (No DNS yet? Set both to `localhost` in `.env` for
a local trial — Caddy will use an internal self-signed cert.)

---

## 6. Build & launch

```bash
docker compose build          # first build is slow: ML wheels + torch + vite
docker compose up -d
docker compose ps             # all services should become healthy
```

What happens automatically on first start:
- **mysql** creates the central DB `ai-crm-config` and the app user.
- **app** waits for MySQL, runs `php artisan migrate --force` (central schema),
  caches config/routes/views, links storage, then serves via nginx+PHP-FPM.
- **voice-engine** downloads the XTTS-v2 (~1.8 GB) and Whisper models into the
  `voice_models` volume. **This takes several minutes** — watch it:

```bash
docker compose logs -f voice-engine
# healthy once it stops downloading and /healthz responds
```

The voice-engine healthcheck has a 10-minute start grace period for exactly
this reason. Subsequent restarts are fast (models are cached on the volume).

---

## 7. Create the first admin user

The seeder ships empty, so create your first login one of two ways:

```bash
# Option A — Laravel tinker
docker compose exec app php artisan tinker
>>> \App\Models\User::create([
...   'name' => 'Admin',
...   'email' => 'you@example.com',
...   'password' => bcrypt('a-strong-password'),
... ]);
```

Or **Option B** — open `https://crm.example.com/register` if registration is
enabled, then disable public registration afterwards.

---

## 8. Provision a tenant (per project)

Each onboarded project gets its own chat DB (`ai-crm-client-<project_id>`).
After creating a project in the admin UI, provision its tenant DB:

```bash
# one project
docker compose exec app php artisan tenant:provision <project_id>

# or all active projects at once
docker compose exec app php artisan tenant:provision
```

After a future schema change to tenant tables, roll the migrations out:

```bash
docker compose exec app php artisan tenant:migrate          # all projects
docker compose exec app php artisan tenant:migrate <id>     # one
```

---

## 9. Verify

```bash
# App responds (through Caddy / TLS)
curl -I https://crm.example.com

# Voice engine is healthy
curl https://voice.example.com/healthz        # → {"status":"ok"}
curl https://voice.example.com/metrics         # llm provider, model readiness

# Containers + health
docker compose ps
```

Then log into `https://crm.example.com`, create a project + bot agent, attach a
data source, and run a test chat.

---

## 10. Day-2 operations

**Logs**
```bash
docker compose logs -f app          # web
docker compose logs -f queue        # workers
docker compose logs -f voice-engine # AI compute
```

**Scale queue workers** (the #1 text-throughput lever):
```bash
docker compose up -d --scale queue=4
```

**Deploy a new version**
```bash
git pull
docker compose build
docker compose up -d                # recreates only changed services
# tenant schema changed? then:
docker compose exec app php artisan tenant:migrate
```

**Restart / stop**
```bash
docker compose restart voice-engine
docker compose down                 # stop (volumes/data preserved)
docker compose down -v              # ⚠️ also deletes volumes (DB, models, RAG)
```

**Run artisan / one-off commands**
```bash
docker compose exec app php artisan <command>
```

---

## 11. Backups & restore

Persistent state lives in named volumes: `mysql_data`, `qdrant_storage`,
`app_storage` (uploaded RAG docs + agent voice samples), `redis_data`,
`voice_models` (re-downloadable — not critical to back up).

**MySQL** (central + all tenant DBs):
```bash
docker compose exec mysql sh -c \
  'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --all-databases' > backup-$(date +%F).sql
```

**Restore:**
```bash
cat backup-YYYY-MM-DD.sql | docker compose exec -T mysql sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
```

**Uploaded files / Qdrant** — back up the volumes:
```bash
docker run --rm -v ai-crm_app_storage:/data -v "$PWD":/backup alpine \
  tar czf /backup/app_storage-$(date +%F).tgz -C /data .
docker run --rm -v ai-crm_qdrant_storage:/data -v "$PWD":/backup alpine \
  tar czf /backup/qdrant-$(date +%F).tgz -C /data .
```
(Volume names are prefixed with the compose project name `ai-crm`. Confirm with
`docker volume ls`.)

Automate the MySQL dump nightly via cron and ship the files off-box.

---

## 12. Security hardening checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production` (defaults in `.env.example`).
- [ ] Strong, unique values for every `*_PASSWORD` and `*_SECRET`.
- [ ] Only ports **80/443** open at the firewall; DB/Redis/Qdrant stay internal.
- [x] **Tenant DB privileges:** already least-privilege — the app user (not
      root) provisions tenant DBs, via the grant in
      `deploy/mysql/init/01-grants.sql`
      (`GRANT ALL ON \`ai-crm-client-%\`.* TO 'aicrm'@'%'`). If you rename
      `DB_USERNAME`, update that SQL file and recreate the `mysql_data` volume
      (the init script only runs on a fresh volume).
- [ ] Restrict `CORS_ALLOW_ORIGINS` on the voice-engine to your widget domains
      (defaults to `*`). Add it to the `voice-engine` environment as a JSON
      array, e.g. `CORS_ALLOW_ORIGINS=["https://crm.example.com"]`.
- [ ] Keep base images patched: `docker compose pull && docker compose up -d`
      for the off-the-shelf images; rebuild app/voice-engine on updates.
- [ ] Take and test backups (§11).

---

## 13. Optional extras

**GPU voice (much faster):** this build is CPU-only. For a GPU host, change the
`voice-engine/Dockerfile` base images to an `nvidia/cuda` Python runtime, drop
the `--index-url .../cpu` line so CUDA torch installs, install
`nvidia-container-toolkit` on the host, add a `deploy.resources.reservations.
devices` GPU reservation to the `voice-engine` service, and set
`COQUI_USE_GPU=true` / `WHISPER_DEVICE=cuda` in `.env`.

**Cloud TTS/STT instead of self-hosting:** ElevenLabs is supported per-project
(set `ELEVENLABS_API_KEY` and enable it on the project's voice). This removes
the CPU bottleneck entirely — the biggest scaling unlock for voice.

**Urdu / fine-tuned XTTS:** mount a checkpoint dir into the `voice-engine`
service and set `XTTS_CHECKPOINT_DIR` (see `voice-engine/.env.example`).

---

## 14. The other components (not in this stack)

- **`widget/`** — the embeddable chat widget (static HTML/JS + one PHP file).
  Drop `webchat-app.php` / the HTML onto any web host, or serve it from the
  `app` container's `public/widget`. Point it at `https://crm.example.com` and
  `wss://voice.example.com/ws/turn`.
- **`query-agent/`** — the Tier-3 "agent" data source runner. It is meant to run
  **on the customer's own infrastructure** (next to their CRM/database), not on
  this server. It already has its own `Dockerfile`; deploy it separately with
  the `LARAVEL_BASE_URL` + agent token from the project. See
  `query-agent/README.md`.

---

## 15. Troubleshooting

| Symptom | Likely cause / fix |
|---------|--------------------|
| `app` exits with "APP_KEY is empty" | Run the key:generate step in §4 and put it in `.env`. |
| Caddy can't get a certificate | DNS not pointing at the server yet, or ports 80/443 blocked. `docker compose logs caddy`. |
| `voice-engine` unhealthy for ~10 min on first boot | Normal — it's downloading models. Watch `docker compose logs -f voice-engine`. |
| `/stt` or `/tts` return 503 | Voice models failed to load (out of RAM, or download failed). Check voice-engine logs; ensure ≥8 GB RAM. |
| 502 from Caddy right after `up` | Upstream still booting (MySQL migrate / model load). Wait for `docker compose ps` to show healthy. |
| Queued work (WhatsApp replies, lead extraction) never runs | The `queue` service isn't up, or crashed. `docker compose ps` / `logs queue`. |
| "Unknown database `ai-crm-client-N`" | Tenant not provisioned — run `tenant:provision <id>` (§8). |
| LLM 429 / rate limits | Set `LLM_FALLBACK_PROVIDER` + a second key, or raise your provider tier (see docs/SCALING.md §4). |
| Changed `.env` but app didn't pick it up | `docker compose up -d` to recreate; config is re-cached on each container start. |

---

## 16. Environment variable reference

All configuration lives in the single root `.env`. Container networking
(`DB_HOST=mysql`, `REDIS_HOST=redis`, `PYTHON_BASE_URL=http://voice-engine:8000`,
`LARAVEL_BASE_URL=http://app:8080`, etc.) is wired in `docker-compose.yml` and
overrides anything in `.env`, so you only manage secrets, domains and provider
keys there. See [`.env.example`](.env.example) for the annotated list.
