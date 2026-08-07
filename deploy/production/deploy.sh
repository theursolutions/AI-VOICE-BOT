#!/usr/bin/env bash
# Helper for the production HA stack. Run from anywhere in the repo.
#
#   ./deploy/production/deploy.sh up          build + (re)deploy with the overlay
#   ./deploy/production/deploy.sh deploy       memory-safe ROLLING redeploy (app: zero-downtime)
#   ./deploy/production/deploy.sh ps           status of all services
#   ./deploy/production/deploy.sh logs [svc]   follow logs
#   ./deploy/production/deploy.sh scale app=4 queue=6
#   ./deploy/production/deploy.sh validate     lint compose + haproxy config
#   ./deploy/production/deploy.sh backup       dump MySQL + snapshot key volumes
#   ./deploy/production/deploy.sh seed-speaker FILE.wav  install the XTTS reference voice
#   ./deploy/production/deploy.sh ollama-pull  download the local LLM weights
#   ./deploy/production/deploy.sh exporter-user create the MySQL monitoring user
#   ./deploy/production/deploy.sh prune        free disk now (voice WAVs, backups, images)
#   ./deploy/production/deploy.sh cron         install the daily prune cron job
set -euo pipefail

cd "$(dirname "$0")/../.."   # repo root
BASE=docker-compose.yml
OVERLAY=deploy/production/docker-compose.prod.yml
DC=(docker compose -f "$BASE" -f "$OVERLAY")

# Optional local-LLM overlay. Set WITH_OLLAMA=1 in .env to run Ollama as part of
# the stack — required if LLM_PROVIDER=ollama, since without this overlay there
# is no ollama service to talk to and every generation fails with a connection
# error. Also used for the Groq-primary + Ollama-fallback setup.
if grep -qE '^WITH_OLLAMA=1[[:space:]]*$' .env 2>/dev/null; then
  DC+=(-f deploy/production/ollama.override.yml)
fi

DC+=(--env-file .env)

# Guard against the most common misconfiguration: a local-LLM provider selected
# without the overlay that actually provides it.
if grep -qE '^LLM_PROVIDER=ollama' .env 2>/dev/null \
   && ! grep -qE '^WITH_OLLAMA=1[[:space:]]*$' .env 2>/dev/null; then
  echo "FATAL: LLM_PROVIDER=ollama but WITH_OLLAMA=1 is not set in .env." >&2
  echo "       The ollama service would not be deployed and every LLM call" >&2
  echo "       would fail. Add 'WITH_OLLAMA=1' to .env and re-run." >&2
  exit 1
fi

# Roll the app tier with NO downtime and WITHOUT a big memory spike:
# start N new-image replicas next to the N old ones (app is ~400MB each, so the
# overlap is cheap even on 12GB), wait until each new one actually serves, then
# retire the old. HAProxy auto-discovers the new replicas and drains the old.
rolling_app() {
  local svc=app cur old id newset tries
  cur=$("${DC[@]}" ps -q "$svc" 2>/dev/null | wc -l | tr -d ' ')
  if [ "${cur:-0}" -lt 1 ]; then "${DC[@]}" up -d --no-deps "$svc"; return; fi

  echo ">> rolling '$svc': $cur old replica(s) → new image (memory-safe overlap)"
  old=$("${DC[@]}" ps -q "$svc")
  "${DC[@]}" up -d --no-deps --no-recreate --scale "$svc=$((cur*2))" "$svc"

  sleep 3
  newset=""
  for id in $("${DC[@]}" ps -q "$svc"); do
    grep -q "$id" <<<"$old" || newset="$newset $id"
  done

  for id in $newset; do
    printf "   waiting for %.12s " "$id"
    tries=0
    until docker exec "$id" sh -c 'curl -fsS http://localhost:8080/ -o /dev/null' >/dev/null 2>&1; do
      tries=$((tries+1))
      [ "$tries" -gt 90 ] && { echo "TIMEOUT — leaving OLD replicas in place, aborting roll"; return 1; }
      printf "."; sleep 2
    done
    echo " healthy"
  done

  echo ">> retiring old '$svc' replica(s) (30s graceful drain)…"
  for id in $old; do docker stop -t 30 "$id" >/dev/null 2>&1 && docker rm "$id" >/dev/null 2>&1 || true; done
  "${DC[@]}" up -d --no-deps --no-recreate --scale "$svc=$cur" "$svc" >/dev/null
  echo ">> '$svc' rolled with no downtime."
}

cmd="${1:-ps}"; shift || true

case "$cmd" in
  up)
    "${DC[@]}" build
    "${DC[@]}" up -d --remove-orphans
    "${DC[@]}" ps
    ;;

  deploy)
    "${DC[@]}" build
    rolling_app                                   # app tier: zero-downtime
    echo ">> updating remaining services…"
    # Reconciles voice/queue/scheduler/edge to the new image. The already-rolled
    # app containers match the new image, so Compose leaves them untouched.
    # NOTE: voice-engine at VOICE_REPLICAS=1 has a brief blip here while the new
    # one reloads its model — that needs ~3GB more RAM to avoid, i.e. a 2nd
    # replica (not feasible on 12GB) or offloaded cloud TTS. See PRODUCTION_HA.md.
    "${DC[@]}" up -d --remove-orphans
    "${DC[@]}" ps
    echo ">> watch the roll live: ssh -L 8404:127.0.0.1:8404 user@server → http://localhost:8404"
    ;;

  ps)      "${DC[@]}" ps ;;
  logs)    "${DC[@]}" logs -f --tail=100 "$@" ;;
  scale)   "${DC[@]}" up -d --scale "$@" ;;

  validate)
    echo ">> compose config:"; "${DC[@]}" config -q && echo "   OK"
    echo ">> haproxy config:"
    docker run --rm -e APP_DOMAIN=x -e VOICE_DOMAIN=y \
      -e HAPROXY_STATS_USER=u -e HAPROXY_STATS_PASSWORD=p \
      -v "$PWD/deploy/production/haproxy/haproxy.cfg:/c.cfg:ro" \
      haproxy:2.9-alpine haproxy -c -f /c.cfg
    ;;

  backup)
    ts=$(date +%F-%H%M); mkdir -p backups
    echo ">> mysqldump → backups/db-$ts.sql.gz"
    # pipefail (set at the top) makes a failed mysqldump fail the whole pipeline
    # instead of leaving a silently-truncated .gz that looks like a good backup.
    "${DC[@]}" exec -T mysql sh -c \
      'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --all-databases --single-transaction --routines --triggers' \
      | gzip > "backups/db-$ts.sql.gz"
    gzip -t "backups/db-$ts.sql.gz" || { echo "!! DB dump is corrupt — backup FAILED" >&2; exit 1; }

    # Volumes that hold data you cannot regenerate:
    #   voice_duckdb — per-project snapshots, knowledge base, crawled pages.
    #                  THIS IS THE TENANT DATA. Losing it loses the product.
    #   app_storage  — RAG source documents, per-agent voice samples
    #   app_uploads  — customer-uploaded files served by the app
    #   voice_uploads— ingest staging for documents
    # Deliberately NOT backed up: voice_models (re-downloadable, ~2GB),
    # voice_outputs (per-turn WAVs, pruned daily), redis_data (cache/queue only).
    for v in ai-crm_voice_duckdb ai-crm_app_storage ai-crm_app_uploads ai-crm_voice_uploads; do
      if ! docker volume inspect "$v" >/dev/null 2>&1; then
        echo ">> volume $v does not exist yet — skipping"
        continue
      fi
      echo ">> volume $v → backups/$v-$ts.tar.gz"
      docker run --rm -v "$v:/data:ro" -v "$PWD/backups:/out" alpine \
        tar czf "/out/$v-$ts.tar.gz" -C /data .
      gzip -t "backups/$v-$ts.tar.gz" || { echo "!! $v archive is corrupt — backup FAILED" >&2; exit 1; }
    done

    echo
    echo ">> wrote:"; ls -lh backups/*-"$ts".* | sed 's/^/     /'
    echo ">> NOTE: the DuckDB files are copied live. They are transactional (a"
    echo "   .wal is captured alongside), but for a guaranteed-clean snapshot"
    echo "   take the backup while no ingest job is running."
    echo ">> COPY backups/ OFF THIS SERVER now (object storage). A backup that"
    echo "   only exists on the box it protects is not a backup."
    ;;

  seed-speaker)
    # Installs the reference voice clip XTTS clones from. Without it, every
    # voice turn on an agent that has no custom sample dies with
    # "FileNotFoundError: speaker_wav not found".
    #
    # Source it from a 10-30s recording of ONE speaker, no music or background
    # noise. Any format ffmpeg reads is fine — it is normalised here.
    src="${1:-}"
    if [ -z "$src" ] || [ ! -f "$src" ]; then
      echo "usage: $0 seed-speaker /path/to/voice.wav" >&2
      echo "  10-30s of clean single-speaker audio. Longer is not better;" >&2
      echo "  clean matters far more than long." >&2
      exit 1
    fi
    cid=$("${DC[@]}" ps -q voice-engine | head -1)
    [ -n "$cid" ] || { echo "!! voice-engine is not running" >&2; exit 1; }

    echo ">> normalising '$src' to 24kHz mono s16…"
    docker cp "$src" "$cid:/tmp/seed_in"
    "${DC[@]}" exec -T voice-engine sh -c '
      set -e
      ffmpeg -y -i /tmp/seed_in -ac 1 -ar 24000 -sample_fmt s16 \
             /app/voice_outputs/default_speaker.wav >/dev/null 2>&1
      rm -f /tmp/seed_in
      ls -lh /app/voice_outputs/default_speaker.wav'

    # The XTTS conditioning latents are cached per speaker path, so an existing
    # process would keep using the OLD clip. Restart to pick up the new one.
    echo ">> restarting voice-engine to clear the conditioning cache…"
    "${DC[@]}" up -d --no-deps --force-recreate voice-engine
    echo ">> done."
    ;;

  ollama-pull)
    # Downloads the local LLM weights into the ollama_models volume (one time).
    # Kept out of the image so it survives rebuilds and isn't re-downloaded.
    m=$(grep -E '^OLLAMA_MODEL=' .env | head -1 | cut -d= -f2-)
    m=${m:-qwen2.5:7b}
    echo ">> pulling '$m' (several GB — this takes a while)…"
    "${DC[@]}" exec -T ollama ollama pull "$m"
    echo ">> installed models:"
    "${DC[@]}" exec -T ollama ollama list
    ;;

  exporter-user)
    # Creates the least-privilege MySQL user that mysqld-exporter logs in as.
    # Idempotent: re-run it any time you rotate MYSQL_EXPORTER_PASSWORD.
    # shellcheck disable=SC1091
    pw=$(grep -E '^MYSQL_EXPORTER_PASSWORD=' .env | head -1 | cut -d= -f2-)
    [ -n "$pw" ] || { echo "!! set MYSQL_EXPORTER_PASSWORD in .env first" >&2; exit 1; }
    case "$pw" in
      *change-me*|*CHANGE_ME*) echo "!! MYSQL_EXPORTER_PASSWORD is still the placeholder" >&2; exit 1 ;;
    esac
    echo ">> creating/updating MySQL user 'exporter'…"
    "${DC[@]}" exec -T mysql sh -c "exec mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\"" <<SQL
CREATE USER IF NOT EXISTS 'exporter'@'%' IDENTIFIED BY '$pw' WITH MAX_USER_CONNECTIONS 3;
ALTER USER 'exporter'@'%' IDENTIFIED BY '$pw';
GRANT PROCESS, REPLICATION CLIENT ON *.* TO 'exporter'@'%';
GRANT SELECT ON performance_schema.* TO 'exporter'@'%';
FLUSH PRIVILEGES;
SQL
    echo ">> done. Restarting the exporter to pick it up…"
    "${DC[@]}" up -d --force-recreate --no-deps mysqld-exporter
    ;;

  prune)   exec bash deploy/production/maintenance/prune.sh ;;
  cron)    exec bash deploy/production/maintenance/install-cron.sh ;;

  *) echo "unknown command: $cmd"; sed -n '4,13p' "$0"; exit 1 ;;
esac
