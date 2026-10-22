#!/bin/sh
# Prepares a container before its command runs. CONTAINER_ROLE: app (default) | queue | scheduler.
# Only the app role migrates the database and builds the caches.
set -e
cd /app

# Generating the first APP_KEY must work before anything is configured
case "$*" in
    *artisan\ key:generate*) exec "$@" ;;
esac

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is missing. Generate one with:"
    echo "  docker compose run --rm --no-deps app php artisan key:generate --show"
    echo "and put it into your .env as APP_KEY=…"
    exit 1
fi

# A fresh storage volume is empty: recreate Laravel's directory layout
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs

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
