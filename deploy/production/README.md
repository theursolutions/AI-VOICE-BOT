# Production HA overlay

Turns the single-node stack into a **redundant, self-healing, observable**
deployment fronted by an **HAProxy ACL load balancer**.

> Full architecture + runbook: [`docs/PRODUCTION_HA.md`](../../docs/PRODUCTION_HA.md)

```
Internet
   │  TLS (Let's Encrypt, auto)
   ▼
 Caddy  ──►  HAProxy (ACL LB)  ──►  app ×N        (php-fpm+nginx :8080)
                │  health checks   ├─►  voice-engine ×N  (FastAPI :8000, sticky)
                │  rate limiting
                │  WS affinity
                ▼
        MySQL · Redis · DuckDB        +   Prometheus · Alertmanager · Grafana
```

## What it adds over the base compose
- **HAProxy** — host-based ACL routing, per-replica health checks + ejection,
  per-client-IP rate limiting + connection caps, consistent-hash affinity for
  voice WebSockets, retries/redispatch, stats dashboard + Prometheus metrics.
- **Redundancy** — `app` and `voice-engine` run as multiple replicas; HAProxy
  drains/ejects unhealthy ones automatically.
- **Resilience** — CPU/RAM limits + log rotation on every container so no
  single service can starve or fill the host; restart-on-failure everywhere.
- **Observability** — Prometheus + Alertmanager + Grafana + node/cAdvisor/
  blackbox/redis/mysql exporters, with ready alert rules.

## Phase 1 — harden the host first (fresh VPS only, run once)

Do this **before** cloning the repo or starting a container. It creates a
non-root sudo user, locks SSH to keys, enables UFW + fail2ban, closes the
Docker→UFW bypass, adds swap, tunes the kernel, and installs Docker Engine +
Compose v2 with log rotation and `live-restore`.

```bash
# from your laptop
scp deploy/production/bootstrap-server.sh root@<vps-ip>:/root/
ssh root@<vps-ip>
chmod +x bootstrap-server.sh
./bootstrap-server.sh --user deploy --ssh-key "$(cat ~/.ssh/id_ed25519.pub)" --timezone Europe/Berlin
```

It refuses to run without an SSH public key, and will not disable password login
until it has verified the key is installed — so it cannot lock you out. **Keep
the root session open** until you have confirmed `ssh deploy@<ip>` works.

## Quick start (single host)

```bash
# 1. base stack must already be configured (.env with APP_KEY, secrets, domains)
# 2. append the extra overlay vars:
cat deploy/production/.env.prod.example >> .env
#    then edit .env: HAPROXY_STATS_PASSWORD, GRAFANA_ADMIN_PASSWORD,
#                    MYSQL_EXPORTER_PASSWORD, replica counts

# 3. validate, then deploy
./deploy/production/deploy.sh validate
./deploy/production/deploy.sh up

# 4. create the MySQL monitoring user (once MySQL is healthy)
./deploy/production/deploy.sh exporter-user

# 5. status / dashboards (via SSH tunnel)
./deploy/production/deploy.sh ps
#   ssh -L 8404:127.0.0.1:8404 -L 3000:127.0.0.1:3000 user@server
#   HAProxy stats → http://localhost:8404   Grafana → http://localhost:3000
```

> **`VOICE_REPLICAS` must stay 1.** DuckDB is single-writer; a second
> voice-engine replica cannot open the same project file read-write.

Requires **Docker Compose v2.24+** (the overlay uses `!override`). If your
version is older, instead of the overlay's Caddy override just edit
`deploy/Caddyfile` to `reverse_proxy haproxy:80` for both domains.

## Day-2

```bash
./deploy/production/deploy.sh deploy          # rolling, zero-downtime-ish redeploy
./deploy/production/deploy.sh scale queue=6   # more throughput
./deploy/production/deploy.sh backup          # mysqldump + volume snapshots
./deploy/production/deploy.sh logs haproxy
```

## Honest scope
A **single host is still a single point of failure.** This overlay removes
every *service-level* SPOF and makes the box self-healing and observable. For
**true HA** (survive a host dying) you need ≥2 nodes — see the multi-node /
Swarm / keepalived section of [`docs/PRODUCTION_HA.md`](../../docs/PRODUCTION_HA.md).
For genuinely durable data, prefer **managed MySQL/Redis** (the data-tier
configs here — `redis/`, `mysql/` — are reference for self-managed HA).
