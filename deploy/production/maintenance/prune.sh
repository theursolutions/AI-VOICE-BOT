#!/usr/bin/env bash
# Disk hygiene — keeps a long-running box from slowly filling up.
# Run by cron (see install-cron.sh) or:  ./deploy/production/deploy.sh prune
#
# Prunes, by age:
#   • per-turn voice WAVs in the voice_outputs volume  (KEEPS default_speaker.wav)
#   • stale uploads
#   • old local DB/volume backups
#   • dangling Docker images + build cache
set -euo pipefail

cd "$(dirname "$0")/../../.."     # repo root
[ -f .env ] && { set -a; . ./.env; set +a; }

PROJECT="${COMPOSE_PROJECT_NAME:-ai-crm}"
VOICE_RETAIN="${VOICE_OUTPUT_RETENTION_DAYS:-14}"
BACKUP_RETAIN="${BACKUP_RETENTION_DAYS:-14}"
log() { echo "[prune $(date -u +%FT%TZ)] $*"; }

# 1) Old voice output WAVs + uploads (any one replica shares the named volume).
vc="$(docker ps -qf "name=${PROJECT}-voice-engine" | head -1 || true)"
if [ -n "$vc" ]; then
  # IMPORTANT: never delete the cloned default speaker reference.
  docker exec "$vc" sh -c \
    "find /app/voice_outputs -type f -name '*.wav' ! -name 'default_speaker.wav' -mtime +${VOICE_RETAIN} -delete" || true
  docker exec "$vc" sh -c \
    "find /app/uploads -type f -mtime +${VOICE_RETAIN} -delete" 2>/dev/null || true
  log "voice_outputs/uploads older than ${VOICE_RETAIN}d removed"
else
  log "voice-engine container not found — skipped voice prune"
fi

# 2) Old local backups produced by deploy.sh backup.
if [ -d backups ]; then
  find backups -type f \( -name '*.gz' -o -name '*.sql' \) -mtime +"${BACKUP_RETAIN}" -delete || true
  log "backups older than ${BACKUP_RETAIN}d removed"
fi

# 3) Reclaim Docker disk (safe: only dangling images + build cache).
docker image prune -f   >/dev/null 2>&1 || true
docker builder prune -f >/dev/null 2>&1 || true
log "docker dangling images + build cache reclaimed"

log "done"
