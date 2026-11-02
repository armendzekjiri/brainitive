# syntax=docker/dockerfile:1.7@sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e
# Brainitive — self-hosting image (FrankenPHP: Caddy + PHP in one process).
# The same image runs the web app, the queue worker, the scheduler and the realtime server for live
# co-editing of documents (see compose.yaml).
#
# Base images are pinned by digest (Codex config review 10): a rebuild of the same source gets the
# same bytes; the tag stays next to it for readability. To take in base-image security updates,
# refresh the digests and keep the tags: `docker buildx imagetools inspect <image:tag>` prints the
# multi-platform index digest (the image is built for linux/amd64 and linux/arm64).

# ── PHP dependencies ──────────────────────────────────────────────────────
FROM composer:2@sha256:4ed71c38c6d788eb4e4e3747b5332f5b40d5b5a6fcadd5062a1a70986a118e0a AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ── Frontend build (needs Ziggy's types from vendor/) ─────────────────────
FROM node:22-alpine@sha256:fe4f6d74e20370acb04ad889077d14d0f64a9c8ea6a42bc60cddf1e844699914 AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
COPY --from=vendor /app/vendor/tightenco/ziggy vendor/tightenco/ziggy
# A checkout on a case-insensitive disk (macOS, Windows) may name the folder "Components"; the
# imports say "@/components/…" and Linux is case-sensitive, so use the tracked lowercase name.
RUN if [ -d resources/js/Components ] && [ ! -d resources/js/components ]; then mv resources/js/Components resources/js/components; fi
RUN npm run build

# ── Realtime server for live co-editing (BRAI-54): production dependencies only ──
FROM node:22-bookworm-slim@sha256:4b150b48b4116e2a22f2c39cc27025fbf4f153e2078fd3b14d159fdb5c7279da AS realtime
WORKDIR /realtime
COPY realtime/package.json realtime/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund

# ── Runtime ───────────────────────────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.4-bookworm@sha256:81ba601edfb650d1df63f54dafdedc021d9d1fb37517850a4e93278ab19cced1

# Node for the realtime service (just the binary, no npm): same Debian (bookworm) as this image,
# from the realtime stage above (the same pinned node:22-bookworm-slim)
COPY --from=realtime /usr/local/bin/node /usr/local/bin/node

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
COPY --from=realtime /realtime/node_modules /app/realtime/node_modules
COPY docker/entrypoint.sh /usr/local/bin/brainitive-entrypoint

RUN chmod +x /usr/local/bin/brainitive-entrypoint \
    && rm -rf node_modules excalidraw-build tests .github docs \
    && php artisan package:discover --ansi \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && mkdir -p /collab \
    && chown -R www-data:www-data storage bootstrap/cache /data /config /collab

USER www-data
EXPOSE 80 443
VOLUME ["/app/storage", "/data", "/config"]

ENTRYPOINT ["brainitive-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
