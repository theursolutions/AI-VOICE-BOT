#!/usr/bin/env bash
# Installs (idempotently) the daily disk-hygiene cron job on the host.
#   ./deploy/production/maintenance/install-cron.sh
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../../.." && pwd)"
SCRIPT="$REPO/deploy/production/maintenance/prune.sh"
LOG="/var/log/aicrm-prune.log"
chmod +x "$SCRIPT"

# 03:30 daily; remove any previous entry for this script first (idempotent).
LINE="30 3 * * * $SCRIPT >> $LOG 2>&1"
( crontab -l 2>/dev/null | grep -vF "$SCRIPT" ; echo "$LINE" ) | crontab -

echo "Installed cron:"
echo "  $LINE"
echo "Verify with:  crontab -l"
echo "Run once now: $SCRIPT"
