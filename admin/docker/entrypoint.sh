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
# NOT `|| true`. That is what hid the duplicate-route-name collision between
# the Breeze and laravel/ui scaffoldings for so long: route:cache threw on
# every boot, the failure was swallowed, and the app quietly served uncached
# routes forever. Laravel 9+ serialises closure routes fine, so a failure here
# is a real routing defect and must stop the boot.
if ! php artisan route:cache; then
  echo "FATAL: route:cache failed — duplicate route names or an unserialisable route." >&2
  echo "       Reproduce locally with: php artisan route:cache" >&2
  exit 1
fi
php artisan view:cache  || true   # views also compile lazily; non-fatal

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

  # SEO: /robots.txt and /sitemap.xml are routes. A static file of either
  # name left over from an older deploy is served by nginx in preference to
  # the route, so drop it here and log what crawlers will actually see.
  # Non-fatal: a wrong sitemap must never stop the app from serving.
  php artisan seo:publish || true

  # Regenerate the Open Graph share card so it carries this deployment's
  # brand name, tagline and domain. Skipped silently if GD is missing.
  php artisan seo:og-image >/dev/null 2>&1 || true
fi

# --- Make runtime state owned by www-data -----------------------------------
# This was `2>/dev/null || true`, which hid both the message and the exit code.
# php-fpm runs as www-data: if it can't write storage/, uploads are written
# nowhere and logs vanish, while the container still reports healthy. That is
# how the voice-upload breakage stayed invisible — the only symptom was a
# zero-byte audio file long after boot.
#
# chown itself is allowed to fail (some volume drivers reject it while the
# mount is already world-writable) — what must hold is that www-data can
# actually WRITE. That is the condition we check, and it is fatal.
if ! chown -R www-data:www-data storage bootstrap/cache; then
  echo "[entrypoint] WARNING: chown of storage/ + bootstrap/cache failed;" >&2
  echo "                      verifying www-data can write anyway ..." >&2
fi

for d in storage storage/app storage/framework storage/logs bootstrap/cache; do
  if ! su -s /bin/sh www-data -c "test -w '$d'"; then
    echo "FATAL: www-data cannot write to $d." >&2
    echo "       php-fpm runs as www-data — booting now means uploads (voice" >&2
    echo "       clips, documents) and logs fail silently at runtime." >&2
    echo "       Check volume ownership on the host:  ls -ln $(pwd)/$d" >&2
    exit 1
  fi
done
echo "[entrypoint] storage + bootstrap/cache writable by www-data."

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
