#!/bin/sh
# Prepares a container before its command runs. CONTAINER_ROLE: app (default) | queue | scheduler |
# realtime. Only the app role migrates the database and builds the caches.
set -e
cd /app

# Generating an APP_KEY by hand must work before anything is configured
case "$*" in
    *artisan\ key:generate*) exec "$@" ;;
esac

# A fresh storage volume is empty: recreate Laravel's directory layout
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs

# No APP_KEY in .env: the app role generates one once and keeps it in the storage volume,
# which queue and scheduler share (back that volume up: it also holds uploads).
if [ -z "$APP_KEY" ]; then
    if [ "${CONTAINER_ROLE:-app}" = "app" ] && [ ! -s storage/app.key ]; then
        php artisan key:generate --show > storage/app.key.tmp
        mv storage/app.key.tmp storage/app.key
        echo "Generated an APP_KEY (kept in the storage volume as storage/app.key)."
    fi
    attempts=0
    until [ -s storage/app.key ]; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 60 ]; then
            echo "APP_KEY is missing and the app container hasn't generated one: check its logs."
            exit 1
        fi
        sleep 2
    done
    APP_KEY=$(cat storage/app.key)
    export APP_KEY
fi

# The realtime server (live co-editing) only needs APP_KEY (its secret is derived from it) — it talks
# to the app over HTTP, never to the database.
if [ "${CONTAINER_ROLE:-app}" = "realtime" ]; then
    exec "$@"
fi

echo "Waiting for the database…"
attempts=0
until php artisan db:show >/dev/null 2>&1; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 60 ]; then
        echo "Database not reachable after 2 minutes — check DB_HOST / DB_PASSWORD."
        exit 1
    fi
    sleep 2
done

if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    php artisan migrate --force
    # Item types, priorities and instance settings (idempotent)
    php artisan db:seed --force --class=Database\\Seeders\\InstanceSettingsSeeder
    php artisan db:seed --force --class=Database\\Seeders\\ItemTypeSeeder
    php artisan db:seed --force --class=Database\\Seeders\\ItemPrioritySeeder
    # OAuth keys for the MCP server (kept in the storage volume)
    if [ ! -f storage/oauth-private.key ]; then
        php artisan passport:keys --force
    fi
    php artisan storage:link >/dev/null 2>&1 || true
    php artisan optimize
fi

exec "$@"
