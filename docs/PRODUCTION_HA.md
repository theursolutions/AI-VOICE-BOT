# Production & High Availability runbook

How to run AI Voice CRM like a service that stays up: an HAProxy **ACL load
balancer**, redundant self-healing tiers, a hardened data layer, full
observability, and a clear path from one box to a real multi-node cluster.

Built on top of the base stack ([`DEPLOYMENT.md`](../DEPLOYMENT.md)). The HA
pieces live in [`deploy/production/`](../deploy/production) and are applied as
a compose overlay — nothing in the base setup is thrown away.

---

## 0. The honest version first

> **A single server can never be "highly available."** The host, its disk, its
> NIC, the hypervisor — each is a single point of failure (SPOF). Anyone who
> tells you one VPS "never goes down" is selling something.

What you *can* do is layered, and that's exactly how this is structured:

| Layer | Goal | Where |
|------|------|-------|
| **Single host, hardened** | No *service-level* SPOF; self-healing; zero-downtime deploys; you get paged before it falls over | §1–§6 (this overlay) |
| **Multi-node cluster** | Survive a whole host dying | §8 (Swarm + VIP) |
| **Managed data + multi-AZ** | Survive a data-center incident; durable data | §7, §8 |

Do §1–§6 now (big uptime win, low cost). Move to §8 when the business case
(SLA, revenue at risk) justifies the second/third node and the ops overhead.

---

## 1. Target architecture

```
                       ┌──────────────────────────────────────────────┐
        DNS ─────────► │  Caddy        TLS / ACME / HTTP-3 / compression│   :443
   (A record →         └───────────────┬──────────────────────────────┘
    server or VIP)                     │ HTTP + X-Forwarded-* (private net)
                       ┌───────────────▼──────────────────────────────┐
                       │  HAProxy   ACL routing · health checks ·      │   :80
                       │            rate-limit · WS affinity · stats    │   :8404
                       └───────┬───────────────────────┬───────────────┘
            host hdr = crm.*   │       host hdr = voice.*│  (sticky by client IP)
                       ┌───────▼────────┐      ┌─────────▼─────────────┐
                       │ app × N        │      │ voice-engine × N      │
                       │ php-fpm+nginx  │      │ FastAPI (STT/TTS/LLM) │
                       │ :8080 stateless│      │ :8000                 │
                       └───┬────┬───────┘      └───────────┬───────────┘
              queue × N ───┘    │ scheduler × 1            │
                                ▼                          ▼
                    ┌────────────────────┐      ┌──────────────────────┐
                    │ MySQL · Redis      │      │ Qdrant (vectors)     │
                    └────────────────────┘      └──────────────────────┘

  Observability (scrapes everything):  Prometheus → Alertmanager + Grafana
  Exporters: HAProxy /metrics · node · cAdvisor · blackbox · redis · mysql
```

**Why two proxies?** Caddy is unbeatable at *automatic* TLS. HAProxy is a real
load balancer: per-replica health checking with ejection + slow-start, stick-
table rate limiting, consistent-hash session affinity (a voice call's
WebSocket must stay on one replica), `redispatch` retries, and a runtime API.
Each does the one job it's best at.

---

## 2. The HAProxy ACL load balancer

Config: [`deploy/production/haproxy/haproxy.cfg`](../deploy/production/haproxy/haproxy.cfg).

What it does, and why each matters for uptime:

- **ACL host routing** — `hdr(host)` ACLs split `crm.*` → `be_admin` and
  `voice.*` → `be_voice`. One LB, both services, clean separation.
- **Dynamic backend discovery** — `server-template` + Docker's embedded DNS
  resolver finds every `app` / `voice-engine` replica automatically. Scale
  `--scale app=4` and HAProxy picks the new ones up with no config edit.
- **Per-replica health checks** — `option httpchk` against `/` (app) and
  `/healthz` (voice). Unhealthy replicas are **ejected** (`fall 3`), restored
  on recovery (`rise 2`), and traffic ramps back via `slowstart 30s` so a
  cold replica isn't hammered. A dying replica is invisible to users.
- **`redispatch` + `retries 3`** — if a server dies mid-request, the request
  is retried on another replica instead of erroring.
- **Rate limiting & flood protection** — a per-client-IP stick-table caps
  request rate (429 over 300 req/10s), connection rate, and concurrent
  connections, so one abusive client can't take the app down. Keyed on the
  real client IP from `X-Forwarded-For` (Caddy is the trusted upstream).
- **WebSocket / voice affinity** — `be_voice` uses `balance source` +
  consistent hashing + a stick-table so a caller's WS and follow-up requests
  land on the same voice replica (required — voice state is per-instance).
- **Long tunnel timeout** — `timeout tunnel 1h` keeps live voice streams open.
- **Observability** — native Prometheus exporter at `:8404/metrics` and a
  password-protected live dashboard at `:8404/` (bound to localhost; reach via
  SSH tunnel).

Validate it any time:

```bash
./deploy/production/deploy.sh validate
```

---

## 3. Redundancy & self-healing (the single-host uptime win)

- **Stateless tiers run N replicas.** `app` is stateless (sessions, cache and
  the queue all live in Redis), so `APP_REPLICAS=2+` means a crash, an OOM, or
  a deploy never takes the whole web tier down. `voice-engine` replicates too
  (subject to the CPU ceiling — §6). Set counts in `.env`:
  `APP_REPLICAS`, `QUEUE_REPLICAS`, `VOICE_REPLICAS`.
- **One scheduler, always.** `scheduler` is pinned to a single replica — two
  would double-fire cron tasks.
- **Restart on failure.** Every service is `restart: unless-stopped`; Docker
  resurrects a crashed container immediately.
- **Resource limits.** Each service has CPU/RAM `limits` (and the heavy ones a
  `reservation`). This prevents the classic cascade where voice-engine's XTTS
  balloons, the host OOM-kills random containers, and everything flaps.
- **Log rotation.** `json-file` capped at 10 MB × 5 per container — logs can
  never silently fill the disk and wedge the box.

These five together remove every *service-level* SPOF on the host and make it
self-healing. The host itself is still a SPOF — that's §8.

---

## 4. No downtime — the two cases

"No downtime" means two different things; this stack handles them differently,
and **both work on a 12 GB box**:

### 4a. Unplanned failure (a container crashes / OOMs / hangs) — already zero-downtime

This is the one that matters most day-to-day, and it needs no action:
- `APP_REPLICAS ≥ 2`, so a dead `app` replica still leaves a healthy one.
- HAProxy detects the dead replica in **~4 s** (`inter 2s fall 2`), ejects it,
  and `on-marked-down shutdown-sessions` cuts its in-flight connections.
- Even in that ~4 s window, requests that hit the dying replica are
  **transparently retried on a healthy one** (`option redispatch` + `retries 3`
  + `retry-on all-retryable-errors`) — so users see *no* error, just success.
- Docker restarts the crashed container; HAProxy ramps it back in over 30 s
  (`slowstart`) once healthy.

Net effect: an app crash or OOM is invisible to users on your 12 GB box today.

### 4b. Planned deploy (shipping new code) — rolling, memory-safe

```bash
./deploy/production/deploy.sh deploy
```

The `app` tier rolls with **true zero-downtime and no memory spike**: the
script starts N new-image replicas next to the N old ones (app is ~400 MB each,
so the overlap is cheap even on 12 GB), waits until each new replica actually
serves HTTP, then drains and retires the old ones. HAProxy auto-discovers the
new replicas (Docker DNS) and ejects the old. No blip on the web/API tier.

`voice-engine` is the exception: a warm second replica needs ~3 GB more RAM
than a 12 GB box can spare, so it's recreated in place — a **brief voice blip**
(~30–60 s while the new one reloads its model) during a voice-engine deploy.
Eliminate it by offloading TTS to the cloud (light replicas → run 2) or adding
RAM / a node. Queue/scheduler blips are harmless (jobs resume).

> Migrations run automatically on `app` startup. For a backwards-incompatible
> change use expand/contract (additive migration → code → cleanup migration) so
> old and new replicas coexist safely during the roll.

For **strict** Swarm-style rolling with automatic rollback, the
`deploy.update_config` / `rollback_config` keys are already in the overlay and
activate under Swarm (§8) — with `app: start-first`, `voice: stop-first` (set
for exactly this 12 GB memory constraint).

---

## 5. Observability & alerting — you can't keep up what you can't see

The overlay ships a full stack (all internal except via SSH tunnel):

| Component | Role |
|-----------|------|
| Prometheus | scrapes everything, evaluates alert rules |
| Alertmanager | routes alerts (Slack/email) |
| Grafana | dashboards (`:3000`, behind `/grafana/` or SSH tunnel) |
| node-exporter | host CPU/RAM/disk/network |
| cAdvisor | per-container CPU/RAM/restarts |
| blackbox-exporter | end-to-end HTTP/TLS uptime probes (internal + public) |
| redis / mysqld exporters | data-tier health |
| HAProxy `/metrics` | LB throughput, 4xx/5xx, backend up/down, queue |

Ready-made alerts ([`alerts.yml`](../deploy/production/monitoring/alerts.yml)):
target down, endpoint probe failing, **HAProxy backend has zero healthy
servers**, server ejected, **TLS cert expiring < 14 days**, host CPU/mem/disk
saturation, container restart-loop, Redis/MySQL down.

Wire notifications by pasting your Slack webhook or SMTP into
[`alertmanager.yml`](../deploy/production/monitoring/alertmanager.yml) (it does
not read env vars), then `restart alertmanager`. Then add an **external**
uptime monitor (UptimeRobot / Pingdom / Better Stack) hitting your real domain
— because if the whole host dies, in-host Prometheus dies with it. That
external check is your last line of defense; alert it to phone/SMS.

Set up the dashboards/tunnel:

```bash
ssh -L 8404:127.0.0.1:8404 -L 3000:127.0.0.1:3000 user@server
# HAProxy stats → http://localhost:8404   ·   Grafana → http://localhost:3000
```

**Dashboards auto-load — no manual import.** Grafana provisions an *AI Voice
CRM — Overview* dashboard on boot (service health, host CPU/RAM/disk, HAProxy
throughput/5xx/backend health, per-container usage, Redis/MySQL) from
[`monitoring/grafana/dashboards/`](../deploy/production/monitoring/grafana/dashboards).
This works offline and is version-controlled — drop more `*.json` files in that
folder and they appear automatically. For deeper per-component views you can
still import community dashboards by ID: **1860** (node), **14282** (cAdvisor),
**12693** (HAProxy 2.x), **763** (Redis).

**Public access (optional).** Grafana is reachable via the SSH tunnel above by
default (nothing exposed — most secure). To open it at
`https://<APP_DOMAIN>/grafana/` for browser/on-call access, follow the
3-step opt-in block in [`deploy/production/Caddyfile`](../deploy/production/Caddyfile)
(uncomment the `/grafana/` route, set `GF_SERVER_ROOT_URL` +
`GF_SERVER_SERVE_FROM_SUB_PATH` on the grafana service, and add a basic-auth /
IP-allowlist layer). Convenience vs. attack surface — keep strong auth on.

---

## 6. The voice ceiling (don't fool yourself)

CPU XTTS/Whisper is the hard limit: XTTS ≈ **1 s of compute per character** and
loads ~2 GB of weights per process. A handful of concurrent calls saturate one
box. Implications for HA:

- `VOICE_REPLICAS` adds concurrency but each replica reloads the full model and
  eats CPU+RAM — scale **instances/hosts**, never `uvicorn --workers N`.
- The biggest unlock is to **offload TTS/STT to elastic cloud** (ElevenLabs for
  TTS — already supported per-project; Deepgram/AssemblyAI for STT). That turns
  voice from CPU-bound into an I/O-bound, horizontally-scalable tier.
- Size voice against **concurrent calls**, text against **jobs/sec**. Full
  treatment: [`SCALING.md`](SCALING.md).

---

## 7. Data tier — the part you should usually outsource

The stateless tiers above are easy to make redundant. **Stateful data is
where real HA gets hard**, and getting it subtly wrong loses data. Strong
recommendation in priority order:

1. **Use managed services.** Managed MySQL (RDS/Aurora, Cloud SQL,
   DigitalOcean Managed MySQL), managed Redis (ElastiCache, Upstash), and
   **Qdrant Cloud**. They give you automated failover, backups, PITR and
   patching that a two-person team cannot match by hand. Point `DB_HOST` /
   `REDIS_HOST` / `QDRANT_URL` at them and delete those containers. This is the
   single highest-leverage HA decision you'll make.
2. **If you must self-manage**, reference configs are provided:
   - **Redis**: primary + [`redis-replica.conf`](../deploy/production/redis/redis-replica.conf)
     + 3× [`sentinel.conf`](../deploy/production/redis/sentinel.conf) for
     automatic failover. App-transparent trick: add a `listen` block in HAProxy
     that routes `:6379` only to the node answering `role:master`, and point
     `REDIS_HOST` at HAProxy — failover then needs no app change.
   - **MySQL**: [`primary.cnf`](../deploy/production/mysql/primary.cnf) +
     [`replica.cnf`](../deploy/production/mysql/replica.cnf) give GTID
     replication and a warm read-only standby. **Automatic** failover needs
     Group Replication / InnoDB Cluster + a router (MySQL Router / ProxySQL) or
     an orchestrator — material ops work; this is exactly why (1) is preferred.
   - **Qdrant**: enable its distributed mode across nodes, or rely on scheduled
     snapshots + restore.

### Backups (do this regardless of everything else)

```bash
./deploy/production/deploy.sh backup     # mysqldump + qdrant + app_storage
```

- Schedule it (cron / the `scheduler` service) **daily**, and **copy it
  off-server** to object storage — a backup on the same dying disk is not a
  backup.
- **Test restores quarterly.** An untested backup is a hope, not a backup.
- Keep `MYSQL`/`REDIS` dumps + the `mysql_data`, `qdrant_storage`,
  `app_storage`, `voice_models`, `caddy_data` volumes in your DR plan.

### Disk hygiene (so a long-running box never fills up)

Per-turn voice WAVs, old uploads, stale backups and dangling images accumulate
and can eventually fill the disk — which *will* take the box down. Install the
daily prune cron once:

```bash
./deploy/production/deploy.sh cron      # installs a 03:30 daily prune (host crontab)
./deploy/production/deploy.sh prune     # or run it on demand
```

It deletes voice WAVs + uploads and local backups older than
`VOICE_OUTPUT_RETENTION_DAYS` / `BACKUP_RETENTION_DAYS` (default 14, set in
`.env`) and reclaims dangling Docker images/build cache. It **never** deletes
`default_speaker.wav`. Log: `/var/log/aicrm-prune.log`. Container log growth is
already bounded by the `json-file` 10 MB × 5 cap on every service (§3), and the
`HostDiskWillFill` alert (§5) warns you 24 h ahead regardless.

---

## 8. True HA: multi-node

To survive a host dying you need ≥2 nodes and a way to move traffic off a dead
one. Two well-trodden paths:

### A. Docker Swarm (smallest leap from here)

The overlay's `deploy:` keys (`replicas`, `update_config`, `rollback_config`,
`restart_policy`, `placement`) are **already Swarm-ready**. Migration:

```bash
docker swarm init                      # on node 1
docker swarm join --token <worker> …   # on nodes 2..n
# build + push images to a registry (multi-node can't share local images)
docker stack deploy -c docker-compose.yml -c deploy/production/docker-compose.prod.yml ai-crm
```

Swarm then gives you, for free: real one-at-a-time **rolling updates with
automatic rollback**, restart policies, **secrets/configs** (replace the
plaintext `.env` with `docker secret`), placement constraints (pin MySQL to a
node with its volume), and overlay networking across hosts. Notes:
- Replace HAProxy `server-template app:8080` with `tasks.app:8080` (Swarm task
  DNS) so it sees per-replica IPs instead of the service VIP.
- Stateful services (MySQL/Redis/Qdrant) need pinned placement + node-local
  volumes, or — better — managed services (§7).

### B. Edge HA with a floating IP (keepalived)

Run Caddy+HAProxy on **two** edge nodes sharing one Virtual IP via VRRP
([`keepalived.conf`](../deploy/production/keepalived/keepalived.conf)). DNS
points at the VIP; if the master edge dies, the VIP fails over to the backup in
~1–3 s. Combine with a health script that demotes a node whose Caddy stopped
answering. (Or skip all this and put a **cloud load balancer** / Cloudflare in
front of two origins — often the simplest real edge HA.)

### Reference topology for genuine HA

- 3 small nodes (Swarm managers/workers) across 2–3 availability zones
- Cloud LB or keepalived VIP at the edge
- **Managed** MySQL (multi-AZ) + managed Redis + Qdrant Cloud
- Object storage for `app_storage` (S3-compatible) instead of a host volume
- Off-region backups + an external uptime monitor with phone alerts

---

## 9. Security hardening checklist

- [ ] Strong unique secrets: `DB_*`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`,
      `PYTHON_JWT_SECRET`, `PYTHON_INTERNAL_SECRET`, `HAPROXY_STATS_PASSWORD`,
      `GRAFANA_ADMIN_PASSWORD`. Generate with `openssl rand -hex 32`.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] Only Caddy publishes ports (80/443). HAProxy stats, Grafana, Prometheus
      bind to `127.0.0.1` — reach them via SSH tunnel, never expose them raw.
- [ ] Host firewall (`ufw`) allows only 22/80/443. Lock SSH to keys + a
      non-default port / allowlist if possible.
- [ ] Data services (MySQL/Redis/Qdrant) are **not** published to the host.
- [ ] `CORS_ALLOW_ORIGINS` on voice-engine restricted to your domains.
- [ ] Move secrets to **Docker secrets** under Swarm (§8) — out of `.env`.
- [ ] `unattended-upgrades` on the host; rebuild images monthly for base-image
      CVEs.
- [ ] Backups encrypted + off-server; restore tested.

---

## 10. On-call runbook (symptom → action)

| Symptom (alert) | First moves |
|---|---|
| `EndpointDown` / external monitor red | `deploy.sh ps`; is the host reachable at all? If host is dead → VIP/DNS failover (§8) or restore from backup |
| `HAProxyNoHealthyBackends` (be_admin) | `deploy.sh logs app` — all replicas failing health. Check DB/Redis reachability; recent bad deploy → roll back image tag |
| `HAProxyNoHealthyBackends` (be_voice) | voice OOM/CPU? check cAdvisor; lower `VOICE_REPLICAS` or offload TTS (§6) |
| `RedisDown` | app degrades fast (sessions/queue). `logs redis`; restart; if data volume corrupt, restore. Long-term → managed Redis |
| `MySQLDown` | `logs mysql`; disk full? (`HostDiskWillFill`). Restore from latest dump if needed |
| `HostLowMemory` / OOM kills | usually voice-engine XTTS — add swap, raise box, cut `VOICE_REPLICAS`, or offload TTS |
| `HostDiskWillFill` | `docker system prune -af`; check log rotation; grow disk |
| `TLSCertExpiringSoon` | Caddy auto-renews; if firing, `logs caddy` for ACME errors (rate limits / DNS) |
| 429s spiking at HAProxy | real abuse vs. legit burst — tune the stick-table thresholds in `haproxy.cfg` |
| Queued WhatsApp/IG replies not sending | `deploy.sh ps` queue replicas up? `logs queue`; check LLM provider 429s, set `LLM_FALLBACK_PROVIDER` |

Keep the last-known-good image tags pinned so a rollback is one redeploy away.

---

## 11. File map

```
deploy/production/
├── docker-compose.prod.yml      # the HA overlay (replicas, limits, logs, monitoring)
├── .env.prod.example            # extra vars to append to .env
├── deploy.sh                    # up / deploy(rolling) / scale / validate / backup / prune / cron
├── maintenance/
│   ├── prune.sh                 # disk hygiene: old voice WAVs, backups, dangling images
│   └── install-cron.sh          # installs the daily prune cron
├── Caddyfile                    # TLS edge → haproxy:80
├── haproxy/haproxy.cfg          # the ACL load balancer
├── monitoring/
│   ├── prometheus.yml           # scrape config
│   ├── alerts.yml               # alert rules
│   ├── alertmanager.yml         # routing (paste your Slack/email)
│   ├── blackbox.yml             # uptime probe modules
│   └── grafana/
│       ├── provisioning/datasources/datasource.yml   # auto Prometheus source
│       ├── provisioning/dashboards/dashboards.yml     # dashboard auto-loader
│       └── dashboards/ai-crm-overview.json            # ready-made dashboard
├── redis/{redis-replica,sentinel}.conf   # self-managed Redis HA (opt-in)
├── mysql/{primary,replica}.cnf           # self-managed MySQL replication (opt-in)
├── mysql/exporter.my.cnf                 # mysqld-exporter creds
└── keepalived/keepalived.conf            # floating-IP edge HA across 2 nodes
```

See also: [`DEPLOYMENT.md`](../DEPLOYMENT.md) (base setup) ·
[`SCALING.md`](SCALING.md) (capacity model).
