# syntax=docker/dockerfile:1
#
# NOTE ON CONTEXT
# ---------------
# This Dockerfile is built with the REPO ROOT as its context, not admin/:
#
#     build: { context: ., dockerfile: admin/Dockerfile }
#
# The image serves two applications — the Laravel admin (which is the nginx
# docroot) and the standalone webchat widget at widget/, which loader.js loads
# in an iframe. A context of ./admin cannot reach a sibling directory, so with
# the old context the widget was silently never deployed and every embed 404'd.
#
# Every COPY below is therefore prefixed with admin/ or widget/. The ignore
# rules that apply are /.dockerignore, NOT admin/.dockerignore.

###############################################################################
# Stage 1 — PHP dependencies for the admin (Composer)
###############################################################################
FROM composer:2 AS vendor
WORKDIR /app
# Copy the whole app first so the optimized autoloader can see app/ and
# packages/meta-channels/src (both are PSR-4 roots in composer.json).
COPY admin/ .
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
COPY admin/package.json admin/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY admin/ .
RUN npm run build

###############################################################################
# Stage 4 — Runtime: PHP-FPM + nginx + supervisor
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
COPY admin/ .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# The webchat widget, deliberately OUTSIDE the docroot (/var/www/html/public).
# nginx maps /widget/ here by alias. Keeping it out of the docroot is the whole
# point: widget/app/config/ holds bootstrap and credentials, and under public/
# they would be fetchable over HTTP.
#
# No composer stage for it: its dependencies are vendored INTO the repo at
# widget/app/vendor (235 tracked files, autoload.php included), which is what
# bootstrap.php loads via ../vendor/autoload.php. Its composer.json lives at
# widget/app/, not widget/, so a stage rooted here would have failed anyway.
COPY widget/ /var/www/widget/

# loader.js is part of the Laravel app but must resolve under the same /widget/
# alias as the app it loads, so the iframe URL is a plain sibling lookup in
# every environment. Copied rather than moved so local development — where the
# two live in different directories — keeps working unchanged.
COPY admin/public/widget/loader.js /var/www/widget/loader.js

# Container configuration.
COPY admin/docker/php.ini          /usr/local/etc/php/conf.d/zz-app.ini
COPY admin/docker/www-pool.conf    /usr/local/etc/php-fpm.d/zz-overrides.conf
COPY admin/docker/nginx.conf       /etc/nginx/conf.d/default.conf
COPY admin/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY admin/docker/entrypoint.sh    /usr/local/bin/entrypoint.sh

RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
 && chmod +x /usr/local/bin/entrypoint.sh \
 # Pre-build the package manifest (self-heals at runtime if this is skipped).
 && php artisan package:discover --ansi || true \
 && chown -R www-data:www-data storage bootstrap/cache /var/www/widget

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
