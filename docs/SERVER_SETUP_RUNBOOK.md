# Server Setup Runbook — bare VPS to live HTTPS

**What this is:** the exact, reproducible procedure used to deploy this stack to
production on 2026-08-08, including every problem hit and why each fix exists.
Follow it top to bottom on a fresh server and you get a working deployment.

**Scope.** This is the *narrative* runbook — how to go from nothing to live, in
order, with verification at each step. See also:

| Document | Covers |
|---|---|
| [`DEPLOYMENT.md`](../DEPLOYMENT.md) | Reference: env-var tables, per-command detail |
| [`PRODUCTION_HA.md`](PRODUCTION_HA.md) | Architecture, HA design, on-call runbook |
| [`SCALING.md`](SCALING.md) | Capacity limits, when to scale what |
| [`deploy/production/README.md`](../deploy/production/README.md) | Overlay quick reference |

> **Read [§9 Bugs this deployment uncovered](#9-bugs-this-deployment-uncovered)
> before changing any pinned version.** Every pin in this project exists because
> something broke in production while reporting itself healthy.

---

## 0. The reference deployment

| | |
|---|---|
| Provider | Contabo VPS |
| Specs | 8 vCPU · 24 GB RAM · 300 GB disk |
| OS | Ubuntu (22.04/24.04) |
| Code location | `/opt/ai-crm`, owned by user `deploy` |
| App URL | `https://crm.<domain>` |
| Voice URL | `https://voice.<domain>` |
| LLM | Ollama `qwen2.5:7b`, fully self-hosted, CPU |
| TTS/STT | Coqui XTTS-v2 / faster-whisper `base`, CPU |

**Architecture:**

```
Internet
   │  TLS (Let's Encrypt, automatic)
   ▼
 Caddy :443 ──► HAProxy :80 ──► app ×2         (php-fpm + nginx :8080)
                  │ ACL routing  └► voice-engine ×1 (FastAPI :8000, sticky)
                  │ health checks
                  │ rate limiting
                  ▼
     MySQL · Redis · Ollama · DuckDB
     + Prometheus · Grafana · Alertmanager · exporters
```

Everything except Caddy's `:80`/`:443` is on a private Docker network or bound
to `127.0.0.1`. Nothing else is publicly reachable.

---

## 1. Prerequisites

Before touching the server:

- [ ] **A fresh VPS** with root SSH access (password from the provider)
- [ ] **An SSH keypair on your laptop.** If you don't have one:
      ```bash
      ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_aicrm -C "you@laptop"
      ```
      Use a dedicated key, not your personal/GitHub key.
- [ ] **A domain** (see [§3](#3-domain-and-dns)) — or plan to use DuckDNS
- [ ] **Git Bash** (Windows). Use it for everything; PowerShell breaks the
      heredocs and `$(...)` substitutions in these commands. MobaXterm/PuTTY
      work for SSH but have their own key handling (see [§11](#11-workstation-gotchas)).

---

## 2. Phase 1 — Harden the host

Run **once**, as root, **before** installing anything else.

```bash
# from your laptop
scp deploy/production/bootstrap-server.sh ~/.ssh/id_ed25519_aicrm.pub root@<VPS_IP>:/root/
ssh root@<VPS_IP>
```

On the server:

```bash
chmod +x bootstrap-server.sh
./bootstrap-server.sh --user deploy --ssh-key "$(cat /root/id_ed25519_aicrm.pub)" --timezone UTC
```

[`bootstrap-server.sh`](../deploy/production/bootstrap-server.sh) is idempotent
and performs 13 steps:

| # | Step | Why |
|---|---|---|
| 1 | Pre-flight checks | Refuses to run without a valid SSH key — cannot lock you out |
| 2 | apt upgrade, timezone, NTP | Clock drift breaks TLS validation and JWT expiry |
| 3 | Create `deploy` sudo user + install key | No shared root account; actions attributable |
| 4 | Harden sshd | Key-only, no root login. Runs `sshd -t` and self-reverts if invalid |
| 5 | UFW: allow 22/80/443 only | Deny-by-default |
| 6 | **Close the Docker→UFW bypass** | See below — the most important step |
| 7 | fail2ban (4 strikes → 24h ban) | Brute-force protection |
| 8 | Security-only auto-upgrades, **no** auto-reboot | Patches without surprise outages |
| 9 | 8 GB swap, `vm.swappiness=10` | Stops an XTTS spike OOM-killing MySQL |
| 10 | sysctl + `nofile 65535` | Connection handling for a busy proxy |
| 11 | Docker Engine + Compose from Docker's repo | Overlay needs Compose **≥ 2.24** (`!override` tags) |
| 12 | `daemon.json`: log rotation + `live-restore` | Unbounded logs filling `/` is the #1 way a healthy Docker host dies |
| 13 | Verification report | Prints open ports, listening sockets, versions |

> ### ⚠️ Step 6 is not optional
> Docker writes its own iptables DNAT rules that are evaluated **before** UFW.
> Without the `DOCKER-USER` allowlist the script installs in
> `/etc/ufw/after.rules`, a single `ports: - "3306:3306"` in any compose file
> would expose MySQL to the entire internet *while `ufw status` still says
> "deny incoming"*. The script re-applies and **verifies** these rules after
> Docker starts, because `dockerd` rebuilds the chain on boot.
>
> Consequence: any new published port is DROPPED unless it's 80/443 or bound to
> `127.0.0.1`. To open another, edit the `DOCKER-USER` block in
> `/etc/ufw/after.rules` — adding a plain `ufw allow` is not enough.

### Verify before closing the root session

**Keep the root session open.** In a *second* terminal:

```bash
ssh -i ~/.ssh/id_ed25519_aicrm deploy@<VPS_IP>
sudo docker ps          # must print an empty table, not an error
```

Then on the server:

```bash
sudo iptables -S DOCKER-USER | head        # allowlist present, 80/443 RETURN rules
sudo ufw status                            # ONLY 22, 80, 443
free -h                                    # swap active
df -h /
```

Only once the new login works should you close the root window — root SSH is now
disabled and that session is your only way back in.

### Convenience: SSH alias (laptop)

```bash
cat >> ~/.ssh/config <<'EOF'
Host aicrm
    HostName <VPS_IP>
    User deploy
    IdentityFile ~/.ssh/id_ed25519_aicrm
    IdentitiesOnly yes
EOF
```

Now `ssh aicrm` works, and so do the SSH tunnels in [§8](#8-phase-7--operations).
`IdentitiesOnly yes` stops SSH offering your other keys first.

---

## 3. Domain and DNS

You need **two** hostnames pointing at the server:

```
crm.<domain>     A  →  <VPS_IP>
voice.<domain>   A  →  <VPS_IP>
```

`A` records only — do not add `AAAA` unless you've enabled IPv6 for Docker.

### Choosing a domain

| Option | Verdict |
|---|---|
| **Bought domain** (Cloudflare Registrar, Porkbun) | ~$10/yr. Correct for anything customer-facing. On Cloudflare set `voice` to **DNS-only (grey cloud)** — the proxy breaks long-lived voice WebSockets |
| **DuckDNS** (`<name>.duckdns.org`) | Free, instant, **works with Let's Encrypt**. Wildcard sub-subdomains resolve automatically, so one registration gives you both hostnames. Good for testing; reads as a hobby URL |
| `sslip.io` / `nip.io` | ❌ **Avoid.** Not on the Public Suffix List, so all users worldwide share one Let's Encrypt rate-limit bucket — issuance usually fails |
| `no-ip.org`, `hopto.org`, `ddns.net` | PSL-listed and fine, but free hostnames expire monthly unless confirmed by email |
| `eu.org` | Free real domain, but approval takes **weeks** |

**Why the PSL matters:** Let's Encrypt limits certificates per *registered
domain*. If a provider is PSL-listed, your subdomain is its own registered
domain with its own quota. If not, you share one quota with every other user.
Check before committing:

```bash
curl -s https://publicsuffix.org/list/public_suffix_list.dat | grep -qxi "duckdns.org" && echo "PSL-listed"
```

### DuckDNS setup

1. https://www.duckdns.org → sign in → go to **/domains** (not `/spec.jsp`)
2. Type a name (e.g. `serveai`) → **add domain**
3. Enter the VPS IP in **current ip** → **update ip**
4. Ignore the token — only needed for dynamic IPs; a VPS IP is static

### Verify DNS before requesting certificates

```bash
for n in crm.<domain> voice.<domain>; do nslookup "$n" | tail -3; done
```

Both must return the VPS IP. **Do not start Caddy before this resolves** —
failed ACME validations are rate-limited to 5 per hostname per hour.

Also set **reverse DNS** in your provider's panel to `crm.<domain>`. Mail
providers reject hosts without it.

---

## 4. Phase 2 — Get the code onto the server

> **Check first:** is your deployment infrastructure actually committed? On this
> project the entire `deploy/` directory and `docker-compose.yml` were untracked,
> so a `git clone` would have produced a repo with no deployment files at all.
> ```bash
> git status --short          # on your laptop
> ```

### Secret scan before pushing

```bash
git add -A
git diff --cached --name-only | grep -E "(^|/)\.env$|\.pem$|\.key$|duckdb" && echo "STOP" || echo "CLEAN"
```

`.gitignore` must cover `.env`, `*.duckdb`, `voice-engine/duckdb_data/`,
`backups/`. See [§9](#9-bugs-this-deployment-uncovered) — item 2 explains why the
DuckDB exclusion is critical.

Also grep for live API keys in `*.example` files — an example file in this repo
had a real Gemini key committed in the initial commit:

```bash
grep -rE "AIza[0-9A-Za-z_-]{30}|gsk_[A-Za-z0-9]{40}|sk-ant-" --include="*.example" .
```

A key that has ever been committed must be **rotated** — editing the file does
not remove it from git history.

### Read-only deploy key

On the **server**:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_github -N "" -C "aicrm-vps"
cat ~/.ssh/id_github.pub
```

Add that at **GitHub repo → Settings → Deploy keys → Add deploy key**, and leave
**"Allow write access" UNCHECKED**. The server only ever needs to pull; if it's
compromised, nobody can push to your repo. (This was verified working — an
accidental `git push` from the server was correctly rejected.)

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
    IdentityFile ~/.ssh/id_github
    IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

ssh -T git@github.com          # "successfully authenticated" = success
```

### Clone

```bash
sudo mkdir -p /opt/ai-crm && sudo chown deploy:deploy /opt/ai-crm
git clone git@github.com:<org>/<repo>.git /opt/ai-crm
cd /opt/ai-crm && ls deploy/production/ && git log --oneline -1
```

**Do this as `deploy`, not root.** Cloning as root leaves files root-owned inside
a `deploy`-owned directory, which triggers git's "dubious ownership" error and
leaves the deploy key in `/root/.ssh` where `deploy` can't reach it. If it
happens:

```bash
sudo chown -R deploy:deploy /opt/ai-crm
sudo cp /root/.ssh/id_github* /home/deploy/.ssh/ && sudo chown deploy:deploy /home/deploy/.ssh/id_github*
```

---

## 5. Phase 3 — Configure `.env`

```bash
cd /opt/ai-crm
{ cat .env.example; echo; cat deploy/production/.env.prod.example; } > .env

gen()  { openssl rand -hex 24; }
setv() { sed -i "s|^$1=.*|$1=$2|" .env; }

setv APP_KEY                 "base64:$(openssl rand -base64 32)"
setv DB_PASSWORD             "$(gen)"
setv DB_ROOT_PASSWORD        "$(gen)"
setv REDIS_PASSWORD          "$(gen)"
setv PYTHON_INTERNAL_SECRET  "$(gen)"
setv PYTHON_JWT_SECRET       "$(gen)"
setv HAPROXY_STATS_PASSWORD  "$(gen)"
setv GRAFANA_ADMIN_PASSWORD  "$(gen)"
setv MYSQL_EXPORTER_PASSWORD "$(gen)"

chmod 600 .env
grep -nE "change-me|CHANGE_ME" .env || echo "NO PLACEHOLDER SECRETS - GOOD"
git check-ignore -v .env          # MUST be ignored
```

Hex rather than base64 for passwords: no `/`, `+`, `=` or `$` to be mangled by
shell, YAML or MySQL client parsing.

Then set the domains:

```bash
setv APP_DOMAIN   crm.<domain>
setv VOICE_DOMAIN voice.<domain>
setv ACME_EMAIL   you@example.com
```

### Values that need care

| Variable | Note |
|---|---|
| `DB_USERNAME` | **Must stay `aicrm`.** [`01-grants.sql`](../deploy/mysql/init/01-grants.sql) hardcodes it to grant rights over per-tenant DBs, and only runs once on a fresh MySQL volume |
| `VOICE_REPLICAS` | **Must stay `1`.** DuckDB is single-writer (file locks); a second replica cannot open the same `project_<id>.duckdb` read-write |
| `LLM_PROVIDER` | `groq`, `ollama` or `anthropic` only. **Gemini is not an LLM provider here** despite `GEMINI_API_KEY` existing — that was for the retired embedding layer |
| `WITH_OLLAMA` | Must be `1` if `LLM_PROVIDER=ollama`. `deploy.sh` hard-fails otherwise, because without the overlay there is no `ollama` service to talk to |
| `MAIL_*` | Contabo blocks outbound port 25 → use an SMTP relay (Resend/Brevo/SES) or password resets fail silently |

### Local-LLM mode

```bash
setv LLM_PROVIDER          ollama
setv LLM_FALLBACK_PROVIDER ""
setv WITH_OLLAMA           1
setv OLLAMA_MODEL          qwen2.5:7b
```

---

## 6. Phase 4 — Build the images

Wrap in `nohup` so a dropped SSH connection doesn't kill a 40-minute build
(or install `tmux` and use that):

```bash
cd /opt/ai-crm
nohup docker compose build --progress=plain > /tmp/build.log 2>&1 &
echo "PID $!"
```

Poll:

```bash
pgrep -f "docker compose build" >/dev/null && echo "STILL BUILDING" || echo "FINISHED"
```

Verify:

```bash
grep -cE "^#[0-9]+ ERROR|error:|failed to solve" /tmp/build.log   # want 0
docker images | grep aicrm                                        # 2 images
```

Expect ~20–40 min. `aicrm/admin` ≈ 616 MB, `aicrm/voice-engine` ≈ 2.97 GB.
Long silent pauses during the torch download are normal.

**A shell shorthand you'll use constantly** — three overlay flags, every time:

```bash
cat >> ~/.bashrc <<'EOF'
dc() {
  ( cd /opt/ai-crm && docker compose \
      -f docker-compose.yml \
      -f deploy/production/docker-compose.prod.yml \
      -f deploy/production/ollama.override.yml \
      --env-file .env "$@" )
}
EOF
source ~/.bashrc
```

`watch` won't see it (`watch` spawns `sh`, which doesn't inherit bash functions).
Use `watch -n 5 "bash -lc 'dc ps'"`.

---

## 7. Phase 5 — First boot (without TLS)

Start everything **except Caddy** so no ACME request is made before DNS and
config are confirmed:

```bash
dc up -d --scale caddy=0
watch -n 5 "bash -lc 'dc ps'"
```

`voice-engine` stays `starting` for ~3 min on first boot while it downloads
~2 GB of XTTS weights into the `voice_models` volume.

### Pull the local model

```bash
dc exec -T ollama ollama pull qwen2.5:7b      # ~4.7 GB → ollama_models volume
```

### Create the MySQL monitoring user

```bash
./deploy/production/deploy.sh exporter-user
```

Until this runs, `mysqld-exporter` logs auth failures — harmless but noisy.

### Seed the XTTS speaker reference

**XTTS is a voice-cloning model — it cannot synthesise without a reference
clip.** No WAV ships in the repo (`.dockerignore` excludes `*.wav`), so without
this every voice turn fails with `FileNotFoundError: speaker_wav not found`.

Record 10–30 s of clean, single-speaker audio. Clean matters far more than long.
For Urdu output, record in Urdu — XTTS transfers timbre across languages but a
native-language reference gives better prosody.

```bash
# laptop
scp /path/to/voice.wav aicrm:/tmp/speaker.wav
```

```bash
# server
./deploy/production/deploy.sh seed-speaker /tmp/speaker.wav
```

It normalises to 24 kHz mono s16, installs it at
`/app/voice_outputs/default_speaker.wav` (the path `prune.sh` spares from daily
cleanup), and recreates the container so XTTS re-derives its conditioning
latents — which are cached per file path, so a restart is required.

### Verify each layer by exercising it

Container status is **not** evidence. Four bugs in this deployment reported
healthy while broken.

```bash
cd /opt/ai-crm
SECRET=$(grep -E '^PYTHON_INTERNAL_SECRET=' .env | cut -d= -f2-)

# voice-engine startup — want: stt=True tts=True store=duckdb, NO warm-up failure
dc logs --tail=30 voice-engine | grep -iE "warm-up|started —"

# LLM end-to-end
dc exec -T voice-engine sh -c "curl -sS -X POST http://localhost:8000/llm/respond \
  -H 'Content-Type: application/json' -H 'X-Internal-Secret: $SECRET' \
  -d '{\"messages\":[{\"role\":\"user\",\"content\":\"Reply in one short sentence.\"}]}' \
  -w '\nHTTP %{http_code} in %{time_total}s\n' --max-time 600"

# TTS — produces a real WAV
dc exec -T voice-engine sh -c 'curl -sS -X POST http://localhost:8000/tts \
  -F "text=Hello, testing the voice engine." -F "language=en" \
  -o /tmp/out.wav -w "HTTP %{http_code} in %{time_total}s\n" --max-time 900
  ffprobe -v error -show_entries format=duration -of default=nw=1 /tmp/out.wav'
```

Then **listen to it** — the only check that proves the seeded speaker took effect:

```bash
docker cp $(dc ps -q voice-engine):/tmp/out.wav /tmp/out.wav   # server
scp aicrm:/tmp/out.wav ~/Downloads/                            # laptop
```

Note: `time` is a bash builtin and the containers run `dash` — use curl's
`%{time_total}` instead.

---

## 8. Phase 6 — TLS cutover

With DNS confirmed and `.env` domains set:

```bash
cd /opt/ai-crm
dc up -d          # no --scale caddy=0 → starts Caddy
dc logs -f caddy  # wait for "certificate obtained successfully" (both hosts)
```

`dc up -d` also recreates `app`, `queue`, `scheduler` and `voice-engine`. This is
**required**: `APP_URL`, `PYTHON_WS_URL` and `VOICE_OUTPUT_URL_PREFIX` are built
from the domain variables, and Laravel bakes `APP_URL` into its config cache at
container start.

### Verify from OUTSIDE the server

Testing from the server bypasses the layers most likely to be broken.

```bash
curl -sS -w "\n-> %{http_code}\n" https://voice.<domain>/healthz          # {"status":"ok"} 200
curl -sS -o /dev/null -D - https://crm.<domain>/ | grep -iE "^(HTTP/|X-Frame|Strict-Transport|X-Content|Referrer|Permissions|Via)"
curl -sS -o /dev/null -w "%{http_code} -> %{redirect_url}\n" http://crm.<domain>/   # 308
echo | openssl s_client -connect crm.<domain>:443 -servername crm.<domain> 2>/dev/null | openssl x509 -noout -subject -issuer -dates
```

Expected: app 200 with HSTS + `nosniff` + `Referrer-Policy` +
`Permissions-Policy` + exactly **one** `X-Frame-Options: DENY`; voice
`{"status":"ok"}`; HTTP→HTTPS 308; a Let's Encrypt certificate ~90 days valid.

> **Test the voice hostname specifically.** If HAProxy's ACLs don't match, both
> hostnames fall through to `default_backend be_admin` and the app still works
> — so only the voice hostname reveals the fault. See [§9](#9-bugs-this-deployment-uncovered) item 19.

---

## 9. Phase 7 — Application setup and operations

### First admin user

```bash
dc exec -T app php artisan tinker --execute="
\$u = App\Models\User::create([
  'name'              => 'Admin',
  'email'             => 'admin@example.com',
  'password'          => bcrypt('<STRONG-PASSWORD>'),
  'email_verified_at' => now(),
  'is_super_admin'    => true,
]);
echo 'created user id '.\$u->id.PHP_EOL;
"
```

`email_verified_at` is required — `EnsureEmailVerified` middleware will otherwise
block login, and with no mail configured there's no way to receive the email.
The email is effectively a username; account recovery means running an artisan
command on the server.

### Provision a tenant

```bash
dc exec -T app php artisan tenant:provision <project_id>
```

### Disk hygiene — do not skip

```bash
./deploy/production/deploy.sh cron
```

Installs the daily prune of per-turn voice WAVs and old backups. Without it the
disk fills over time and the host dies.

### Backups

```bash
./deploy/production/deploy.sh backup
```

Dumps MySQL and snapshots `voice_duckdb`, `app_storage`, `app_uploads`,
`voice_uploads`, verifying each archive with `gzip -t`.

> **`voice_duckdb` holds ALL tenant data** — snapshots, knowledge base, crawled
> pages. **Copy `backups/` off the server** to object storage. A backup that
> only exists on the machine it protects is not a backup, and VPS providers do
> not back up your instance for you.

For a clean DuckDB snapshot, run backups when no ingest job is active — the
files are copied live (transactional, `.wal` captured alongside, but a quiet
moment is safer).

### Monitoring — via SSH tunnel, never public

```bash
ssh -L 3000:127.0.0.1:3000 -L 8404:127.0.0.1:8404 aicrm
```

- Grafana → http://localhost:3000 (`admin` / `GRAFANA_ADMIN_PASSWORD`)
- HAProxy stats → http://localhost:8404 (`HAPROXY_STATS_USER` / `..._PASSWORD`)

Set a Slack webhook in
[`alertmanager.yml`](../deploy/production/monitoring/alertmanager.yml) so you
hear about problems before customers do. Alertmanager does **not** read env vars
— paste the webhook into the file.

---

## 10. Day-2 operations

```bash
./deploy/production/deploy.sh deploy          # rolling, zero-downtime app redeploy
./deploy/production/deploy.sh ps
./deploy/production/deploy.sh logs [service]
./deploy/production/deploy.sh scale queue=6
./deploy/production/deploy.sh validate        # lint compose + haproxy config
./deploy/production/deploy.sh backup
./deploy/production/deploy.sh prune           # free disk now
./deploy/production/deploy.sh cron
./deploy/production/deploy.sh seed-speaker FILE.wav
./deploy/production/deploy.sh ollama-pull
./deploy/production/deploy.sh exporter-user
```

**Shipping code:**

```bash
# laptop
git commit … && git push origin master
# server
cd /opt/ai-crm && git pull
```

Then, depending on what changed:

| Changed | Action |
|---|---|
| `haproxy.cfg`, `Caddyfile`, monitoring configs | `dc up -d --no-deps --force-recreate <service>` (mounted files, no rebuild) |
| `.env` | `dc up -d` (recreates services whose env changed) |
| PHP source, `php.ini`, `nginx.conf` | `docker compose build app` then `./deploy.sh deploy` |
| `voice-engine/` source | `docker compose build voice-engine` then `dc up -d --no-deps voice-engine` |
| `requirements.lock.txt` | Full voice-engine rebuild (~25–40 min) |

`deploy.sh deploy` rolls the app tier with no downtime: starts new replicas
alongside the old, polls each until it genuinely serves, then retires the old
while HAProxy drains them. `voice-engine` is `stop-first` (a brief blip) because
a second replica needs ~3 GB more RAM.

---

## 11. Measured performance on this hardware

Real measurements from the reference deployment, not estimates:

| Metric | Measured | Meaning |
|---|---|---|
| LLM (`qwen2.5:7b`, CPU, warm) | **0.61 tok/s** | 34 tokens in 55.9 s. A ~60-token reply ≈ 100 s |
| Ollama cold load | **~16 s** | Before any token. Mitigated by `OLLAMA_KEEP_ALIVE=-1` |
| XTTS-v2 (CPU) | **~7.1× slower than real time** | 3.71 s of audio took 26.5 s (~0.83 s/char) |
| Concurrency ceiling | **~20 turns** | `pm.max_children=20`; each turn holds a worker for its full duration |
| Throughput | **~12 replies/min** | Requests queue: `OLLAMA_NUM_PARALLEL=1` |

**Implications, stated plainly:**

- **Live voice conversation is not viable on CPU.** Streaming does not rescue it
  — for smooth playback, generation must be *faster* than real time (RTF < 1.0).
  At 7× the stream starves continuously. CPU XTTS is fine for *asynchronously*
  generated audio (voice notes, pre-rendered replies) where a 30 s render is
  invisible.
- **Self-hosted CPU text chat is ~20× slower than "responsive"** (>10 tok/s).
  Workable for demos, internal use, or async/queued messaging. Not for a busy
  public widget.
- **The timeout chain must match your latency** (see item 20 below).

**If you need speed:** switching `LLM_PROVIDER` to a cloud provider is two
`.env` lines and a restart — Groq's free tier runs comparable open models at
300–800 tok/s. A GPU host fixes both LLM and TTS (XTTS ≈ 0.3× RTF) while staying
self-hosted; the voice-engine Dockerfile documents the CUDA switch.

---

## 12. Bugs this deployment uncovered

**Every pin and comment in this project traces to one of these.** The common
thread: *containers reported healthy while the feature was structurally broken.*

### Data loss and correctness

1. **DuckDB not persisted.** `DUCKDB_DIR` defaulted inside the container with no
   volume — every redeploy destroyed all tenant data. → `voice_duckdb` volume.
2. **Dev data baked into the image.** `voice-engine/duckdb_data/` was in neither
   `.gitignore` nor `.dockerignore`. Docker seeds an empty named volume *from
   image contents*, so a fresh production deploy would come up pre-loaded with a
   developer's test projects. → excluded from both.
3. **Backups covered the wrong volume** — snapshotted the retired Qdrant volume,
   never DuckDB. → backup list corrected + `gzip -t` verification.
4. **Concurrent migration race.** `migrate --force || true` ran in *every* web
   replica; with `APP_REPLICAS=2` both raced the migrations table and `|| true`
   hid failures. → `migrate --force --isolated`, fatal on failure.

### Security

5. **Docker bypassed UFW** — published ports were reachable despite
   deny-by-default. → `DOCKER-USER` allowlist, verified after `dockerd` restart.
6. **`TrustProxies::$proxies = null`** — behind Caddy→HAProxy, Laravel generated
   `http://` URLs and saw the proxy IP as every client's, collapsing rate
   limiting into one bucket. → RFC-1918 ranges.
7. **Session cookies not `Secure`.** → `SESSION_SECURE_COOKIE=true`, `SameSite=lax`.
8. **No HSTS or security headers** at the TLS edge. → added in the Caddyfile.
9. **Blanket `X-Frame-Options: SAMEORIGIN`** in both nginx and HAProxy. This
   would have broken **every embedded customer widget**, and produced duplicate
   conflicting headers. → removed from both; Caddy sets `DENY` scoped to exclude
   `/widget*`.
10. **A live Gemini API key committed** in `admin/.env.example` since the initial
    commit. → placeholder; **the key must be rotated**, editing history's copy is
    not possible retroactively.
11. **`mysqld-exporter` shipped a placeholder password** plus a manual setup step
    everyone forgets. → env-driven + `deploy.sh exporter-user`.

### Reliability

12. **Queue/scheduler had no healthchecks** — a hung worker stayed "up" forever
    while silently consuming nothing. → `pgrep` healthchecks (needed `procps` in
    the image).
13. **Redis unbounded inside a 768 MB cap** — a kernel OOM-kill would drop queued
    jobs and every live session. → `maxmemory 512mb` + `noeviction` (deliberate:
    jobs must fail loudly, never vanish).
14. **10 s queue drain** killed jobs mid-flight on deploy. → `stop_grace_period: 60s`.
15. **All monitoring images on `:latest`** — a surprise Prometheus 3.x or Grafana
    major bump on redeploy is a self-inflicted outage. → 8 images pinned.

### Dependency drift — the recurring theme

16. **`openai` and `anthropic` absent from `requirements.txt`** while the code
    imported them. **No LLM provider could work in a clean container build** —
    it worked on the developer's laptop because that venv had them installed ad
    hoc. → added.
17. **`transformers` resolved to 5.x**, which removed `isin_mps_friendly` that
    coqui-tts imports. XTTS failed to load. → `transformers>=4.57,<5`
    (4.57.6 verified to contain the symbol).
18. **`torch` resolved to 2.13.** From torch 2.9 coqui-tts requires `torchcodec`
    for audio I/O. XTTS died at load. → pinned `torch==2.8.0` / `torchaudio==2.8.0`
    from the CPU wheel index.

    **→ All three led to `requirements.lock.txt`** (155 exact pins). The image
    installs the lock; `requirements.txt` is intent only. Regeneration
    instructions are in the voice-engine Dockerfile.

### Configuration

19. **HAProxy does not expand unquoted `${VAR}`.** Environment variables are only
    interpreted **inside double quotes** (HAProxy manual §2.3). Unquoted, the
    ACLs matched the literal text `${APP_DOMAIN}`, so neither matched and
    everything fell through to `default_backend be_admin` — the voice hostname
    was served by Laravel (404) while the app hostname worked *by accident*,
    because `be_admin` is also the default. The same bug made the stats page
    return 401 with the correct password. → quoted.
20. **Timeout chain shorter than reply latency.** HAProxy `timeout server 60s`
    cut every self-hosted-LLM reply mid-generation; the answer completed and the
    user still saw "Upstream API error". → HAProxy 300 s (client *and* server —
    the client connection looks idle while HAProxy waits on the backend),
    PHP `max_execution_time` 300 s, `PythonClient` 280 s (deliberately below
    PHP's, so Guzzle times out first and Laravel can return a real error).
21. **HAProxy couldn't start:** `maxconn 60000` needs ~120 k file descriptors,
    but `daemon.json`'s `nofile 65535` capped it. → per-service `ulimits` of 200 k.
22. **XTTS had no reference clip.** `config.py` defaulted to
    `/app/temp_speaker.wav` (excluded by `.dockerignore`, tracked nowhere) while
    `prune.sh` protected a *differently named file in a different directory*. →
    `DEFAULT_SPEAKER_WAV` unified on the persistent volume + `deploy.sh seed-speaker`.
23. **Exec bits lost.** Windows git commits shell scripts as mode `644`, so
    `deploy.sh` and `prune.sh` were not executable — the latter runs from cron
    and would have failed silently until the disk filled. → `git update-index
    --chmod=+x` + `.gitattributes` enforcing LF (a CRLF script fails on Linux
    with `bash\r: No such file or directory`).
24. **Ollama unloaded the model after ~5 min idle**, so every conversation after
    a quiet period paid ~16 s before its first token. → `OLLAMA_KEEP_ALIVE=-1`.

---

## 13. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Voice hostname returns a Laravel 404 | HAProxy ACLs unquoted, or stale domain env | Quote `"${VAR}"`; `dc up -d --no-deps --force-recreate haproxy` |
| HAProxy `Restarting (1)`, `Cannot raise FD limit` | `maxconn` needs more FDs than the daemon default | Per-service `ulimits: nofile 200000` |
| HAProxy stats 401 with the right password | `stats auth` unquoted | Quote it |
| `Warm-up of 'tts_service' failed` | transformers or torch too new | Check pins; rebuild from the lock file |
| `ImportError: torchcodec is required` | torch ≥ 2.9 | Pin `torch==2.8.0` |
| `ModuleNotFoundError: openai` | SDK missing | Present in the lock file |
| `FileNotFoundError: speaker_wav not found` | No reference clip | `deploy.sh seed-speaker FILE.wav` |
| Chat fails ~60 s in, "Upstream API error" | Timeout chain shorter than reply time | Raise HAProxy / PHP / PythonClient timeouts |
| Certificate issuance fails | DNS wrong, or PSL-shared rate limit | Verify DNS resolves; avoid `sslip.io`/`nip.io` |
| `git: dubious ownership` | Cloned as root into a `deploy`-owned dir | `chown -R deploy:deploy /opt/ai-crm` |
| `permission denied: ./deploy.sh` | Exec bit lost | `bash deploy/production/deploy.sh …`, then fix in git |
| `sh: 1: dc: not found` under `watch` | `watch` uses `sh`, no bash functions | `watch -n 5 "bash -lc 'dc ps'"` |
| `time: not found` in a container | `time` is a bash builtin; containers use dash | Use curl's `%{time_total}` |
| Every reply slow, CPU ~83% | Normal during CPU inference | Check `dc logs haproxy \| grep "is DOWN"` for health flapping; if flapping, cut Ollama's `cpus` from 6 to 4 |

---

## 14. Workstation gotchas (Windows)

- **Use Git Bash.** PowerShell cannot parse `$(cat <<'EOF' …)` heredocs, has no
  `&&` in 5.1, and needs `$HOME\.ssh\…` paths. For long commit messages that
  must work in either shell: `git commit -F messagefile.txt`.
- **Server vs laptop.** `git commit`/`push` and `/c/laragon/...` paths are
  **laptop**. `dc`, `/opt/ai-crm`, `docker` are **server**. The server can only
  `git pull` — the read-only deploy key rejects pushes by design.
- **`chmod` doesn't stick** on NTFS through Git Bash. Harmless; Git Bash's SSH
  doesn't enforce the strict key-permission check Linux does.
- **PuTTY** needs a converted `.ppk` (PuTTYgen → Conversions → Import key).
  **MobaXterm** reads OpenSSH keys directly (Advanced SSH settings → Use private
  key) but has its own `HOME`, so it never sees `C:\Users\<you>\.ssh\config` —
  `ssh aicrm` won't work in its local terminal unless you copy the config there.
  Its drive paths are `/drives/c/...`, not `/c/...`.
- **Pasting multi-line blocks:** if one line opens a new shell (`su -`, `ssh`,
  `docker exec -it`), everything after it is fed to the *new* shell's stdin and
  usually discarded. Paste those in two parts.

---

## 15. Known limitations

- **A single VPS is a single point of failure.** The HA overlay removes every
  *service-level* SPOF and makes the box self-healing and observable, but it
  cannot survive the host dying. True HA needs ≥2 nodes —
  [`keepalived.conf`](../deploy/production/keepalived/keepalived.conf) is
  pre-written for a floating IP.
- **`VOICE_REPLICAS` cannot exceed 1** until ingest is split out of the
  voice-engine (DuckDB single-writer).
- **Voice latency** — see [§11](#11-measured-performance-on-this-hardware).
- **Self-managed MySQL/Redis.** For genuinely durable data, prefer managed
  services; the configs under `deploy/production/{mysql,redis}/` are reference
  material for self-managed HA.
- **A DuckDNS hostname is fine for testing** but reads as a hobby URL and would
  point customers at `duckdns.org` via the embedded widget. Buy a real domain
  before customer-facing use — it's two `.env` lines and a Caddy restart.

---

## 16. Quick reference

```bash
# Paths
/opt/ai-crm                     # code, owned by deploy
/opt/ai-crm/.env                # ALL secrets, chmod 600, gitignored
/opt/ai-crm/backups/            # local backups — copy these OFF the box

# Volumes that matter
voice_duckdb                    # ALL TENANT DATA — back this up
app_storage, app_uploads        # RAG docs, agent voices, customer uploads
voice_uploads                   # ingest staging
mysql_data, redis_data
voice_models, ollama_models     # re-downloadable
voice_outputs                   # per-turn WAVs, pruned daily (keeps default_speaker.wav)

# Health
dc ps --format "table {{.Name}}\t{{.Status}}"
dc logs --tail=50 <service>
curl -sS https://voice.<domain>/healthz
```

**Rule learned the hard way: verify by exercising the feature, not by reading
container status.** Get a real 200 from the public voice hostname. Generate a
real WAV and listen to it. Send a real chat turn. Every serious bug in this
deployment was invisible to healthchecks.
