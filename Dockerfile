# syntax=docker/dockerfile:1.7
# Brainitive — self-hosting image (FrankenPHP: Caddy + PHP in one process).
# The same image runs the web app, the queue worker and the scheduler (see compose.yaml).

# ── PHP dependencies ──────────────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ── Frontend build (needs Ziggy's types from vendor/) ─────────────────────
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor/tightenco/ziggy vendor/tightenco/ziggy
# A checkout on a case-insensitive disk (macOS, Windows) may name the folder "Components"; the
# imports say "@/components/…" and Linux is case-sensitive, so use the tracked lowercase name.
RUN if [ -d resources/js/Components ] && [ ! -d resources/js/components ]; then mv resources/js/Components resources/js/components; fi
RUN npm run build

# ── Runtime ───────────────────────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions pdo_mysql pdo_pgsql redis gmp bcmath intl zip gd exif pcntl \
    && apt-get update && apt-get install -y --no-install-recommends libcap2-bin \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && rm -rf /var/lib/apt/lists/*

ENV SERVER_NAME=:80 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

WORKDIR /app
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-brainitive.ini"
# Default FrankenPHP config + no PHP execution / sandboxed markup under /storage (uploads)
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY --from=assets /app/public/js/helpdesk-widget.js /app/public/js/helpdesk-widget.js
COPY docker/entrypoint.sh /usr/local/bin/brainitive-entrypoint

RUN chmod +x /usr/local/bin/brainitive-entrypoint \
    && rm -rf node_modules excalidraw-build tests .github docs \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /data /config

USER www-data
EXPOSE 80 443
VOLUME ["/app/storage", "/data", "/config"]

ENTRYPOINT ["brainitive-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
