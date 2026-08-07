#!/usr/bin/env bash
# Entrypoint for the admin (Laravel) image. One image, three roles selected
# via $CONTAINER_ROLE: web (php-fpm + nginx), queue (worker), scheduler.
set -euo pipefail

cd /var/www/html
ROLE="${CONTAINER_ROLE:-web}"

# --- APP_KEY is mandatory (sessions/cookies/encryption depend on it) --------
if [ -z "${APP_KEY:-}" ]; then
  echo "FATAL: APP_KEY is empty. Generate one and put it in .env:" >&2
  echo "   docker compose run --rm app php artisan key:generate --show" >&2
  exit 1
fi

# --- Wait for MySQL ---------------------------------------------------------
echo "[entrypoint] waiting for database ${DB_HOST:-mysql}:${DB_PORT:-3306} ..."
for i in $(seq 1 60); do
  if php -r '
      $h=getenv("DB_HOST")?:"mysql"; $p=getenv("DB_PORT")?:"3306";
      $u=getenv("DB_USERNAME")?:"root"; $w=getenv("DB_PASSWORD")?:"";
      $d=getenv("DB_DATABASE")?:"";
      try { new PDO("mysql:host=$h;port=$p;dbname=$d",$u,$w,[PDO::ATTR_TIMEOUT=>2]); exit(0); }
      catch (Throwable $e) { exit(1); }
  ' 2>/dev/null; then
    echo "[entrypoint] database is up."
    break
  fi
  [ "$i" -eq 60 ] && { echo "[entrypoint] database unreachable, aborting." >&2; exit 1; }
  sleep 2
done

# --- Rebuild the package manifest (don't trust any baked/stale cache) -------
php artisan package:discover --ansi || true

# --- Optimize: cache config/routes/views (env is present now) ---------------
# Run as root, then hand ownership to www-data below so every role (and the
# php-fpm workers) can read the caches and write to storage.
php artisan config:cache
php artisan route:cache || true   # tolerate closure routes
php artisan view:cache  || true

if [ "$ROLE" = "web" ]; then
  # storage/app is an empty named volume on first boot — make the symlink
  # target exist before linking.
  mkdir -p storage/app/public
  # Central-DB schema. Tenant DBs are provisioned separately
  # (php artisan tenant:provision <project_id>).
  #
  # --isolated: with APP_REPLICAS>1 every web container boots at once and would
  # otherwise race the migrations table. This takes a cache (Redis) lock so
  # exactly one replica migrates and the rest skip straight through.
  #
  # A failed migration is FATAL and must not be swallowed: booting on a
  # half-applied schema turns one clear error into a day of mystery 500s. The
  # container exits, `restart: unless-stopped` retries, and the old replicas
  # keep serving because HAProxy never marks a non-responding replica as up.
  if ! php artisan migrate --force --isolated; then
    echo "FATAL: database migration failed — refusing to serve on an unknown schema." >&2
    echo "       Inspect with: docker compose logs app | tail -50" >&2
    exit 1
  fi
  php artisan storage:link --force 2>/dev/null || true
fi

# --- Make runtime state owned by www-data -----------------------------------
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# --- Launch -----------------------------------------------------------------
case "$ROLE" in
  web)
    # supervisor runs as root; php-fpm + nginx spawn www-data workers.
    echo "[entrypoint] starting php-fpm + nginx (web role)"
    exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
    ;;
  queue)
    echo "[entrypoint] starting queue worker (www-data)"
    exec su -p -s /bin/bash www-data -c \
      "php artisan queue:work --sleep=1 --tries=3 --backoff=5 --max-time=3600 --max-jobs=500"
    ;;
  scheduler)
    echo "[entrypoint] starting scheduler (www-data)"
    exec su -p -s /bin/bash www-data -c "php artisan schedule:work"
    ;;
  *)
    echo "FATAL: unknown CONTAINER_ROLE '$ROLE' (expected web|queue|scheduler)" >&2
    exit 1
    ;;
esac
