---
title: Upgrading
description: Update a self-hosted Brainitive installation safely, with Docker or a manual install. Backup first, then pull, migrate and restart.
order: 80
---

# Upgrading

Brainitive releases often. This page shows how to update a Docker or a manual installation. Read the release notes first: **Help → Changelog** in the app, or the Releases page on GitHub.

::: danger Back up first
Always take a backup of the database and your uploads before an update. See [Backups](./backups).
:::

::: danger Never wipe the database
Never run `php artisan migrate:fresh`, `migrate:reset`, `migrate:refresh` or `db:wipe` on a real installation. They delete every table. An update only needs `php artisan migrate --force`, which adds what's new and keeps your data.
:::

## Docker

1. Back up (see [Backups](./backups)).
2. Pull the newest image of your tag and restart:

   ```bash
   docker compose pull
   docker compose up -d
   ```

3. Check the logs:

   ```bash
   docker compose logs -f app
   ```

That's all. When the `app` container starts, it runs the migrations, queues a search index rebuild if needed and caches the configuration. The `queue`, `scheduler` and `realtime` containers restart with the new image too.

- If you pinned a version with `BRAINITIVE_IMAGE` (for example `:1.45`), change the tag in `.env` to move to a newer minor version.
- If you build from source: `git pull && docker compose up -d --build`.
- From time to time, download the newest `compose.yaml` and compare it with yours. New releases may add services or settings.

## Manual install

Run these in your installation folder (`/opt/brainitive` in this guide).

1. **Back up** the database, `storage/` and `.env`.

2. **Put up the maintenance page** (optional, recommended for large updates):

   ```bash
   sudo -u www-data php artisan down
   ```

3. **Get the new code:**

   ```bash
   git pull
   ```

4. **Install dependencies and build:**

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   npm --prefix realtime ci --omit=dev     # if you run the realtime server
   ```

5. **Update the database and caches:**

   ```bash
   sudo -u www-data php artisan migrate --force
   sudo -u www-data php artisan optimize
   ```

6. **Restart the background processes:**

   ```bash
   sudo -u www-data php artisan queue:restart
   sudo systemctl restart brainitive-realtime     # if you run the realtime server
   sudo systemctl reload php8.4-fpm               # clears PHP's opcode cache
   ```

7. **Bring the site back:**

   ```bash
   sudo -u www-data php artisan up
   ```

The search index rebuilds itself in the background when an update changed it. The scheduler and the queue worker take care of it.

## After the update

- Open **Instance admin → Overview** and check for failed jobs.
- Run `php artisan uploads:audit` once. It lists uploads with executable or HTML/SVG extensions saved by older versions (`--delete` removes them).
- If something went wrong, see [Troubleshooting](./troubleshooting).

## Going back

Migrations change the database, so going back to an older version means restoring the backup you took before the update. Then run the older image tag (Docker) or check out the older code (manual install).
