# syntax=docker/dockerfile:1

###############################################################################
# Stage 1 — PHP dependencies (Composer)
###############################################################################
FROM composer:2 AS vendor
WORKDIR /app
# Copy the whole app first so the optimized autoloader can see app/ and
# packages/meta-channels/src (both are PSR-4 roots in composer.json).
COPY . .
# --ignore-platform-reqs: the builder image may lack some PHP extensions
# (intl/gd/…) that the runtime stage installs; deps are only *run* there.
RUN composer install \
        --no-dev --prefer-dist --no-interaction --no-progress \
        --optimize-autoloader --no-scripts --ignore-platform-reqs

###############################################################################
# Stage 2 — Front-end assets (Vite + React Flow Builder + Tailwind/SASS)
###############################################################################
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

###############################################################################
# Stage 3 — Runtime: PHP-FPM + nginx + supervisor
###############################################################################
FROM php:8.2-fpm-bookworm AS app

# PHP extensions (via mlocati's installer) + nginx + supervisor + curl.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN apt-get update \
 # procps supplies pgrep, used by the queue/scheduler healthchecks in compose.
 && apt-get install -y --no-install-recommends nginx supervisor curl procps \
 && install-php-extensions pdo_mysql mbstring bcmath gd zip intl exif pcntl opcache redis \
 && apt-get clean && rm -rf /var/lib/apt/lists/* \
 && rm -f /etc/nginx/sites-enabled/default

WORKDIR /var/www/html

# App source + built artefacts from the earlier stages.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Container configuration.
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/www-pool.conf    /usr/local/etc/php-fpm.d/zz-overrides.conf
COPY docker/nginx.conf       /etc/nginx/conf.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
 && chmod +x /usr/local/bin/entrypoint.sh \
 # Pre-build the package manifest (self-heals at runtime if this is skipped).
 && php artisan package:discover --ansi || true \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
