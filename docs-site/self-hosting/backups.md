---
title: Backups
description: Back up and restore self-hosted Brainitive - PostgreSQL dump, uploads in storage, and the APP_KEY. Plus project export and import.
order: 90
---

# Backups

A complete Brainitive backup has three parts: the **database**, the **storage folder** with uploads, and the **app key**. This page shows how to back up and restore each one.

## What to back up

| What | Where | Why |
|---|---|---|
| **Database** | PostgreSQL | All your boards, documents, users and settings |
| **Storage** | Docker: the `storage` volume. Manual: `storage/` (mainly `storage/app/`) | Uploaded files and images, the OAuth keys of the MCP server (`storage/oauth-*.key`). In Docker also the generated `APP_KEY` (`storage/app.key`) |
| **`.env`** | Your installation folder | Configuration, and `APP_KEY` if it is set there |

::: danger Never lose APP_KEY
Brainitive encrypts stored secrets with `APP_KEY`: the SMTP password from the admin console, helpdesk mailbox passwords, calendar and GitHub connections, webhook secrets. Without the original key they can't be read anymore, and everyone is signed out. Keep a copy of the key somewhere safe, separate from the server.
:::

The `redis`, `caddy_config` and `collab` Docker volumes don't need a backup.

## Docker

**Database:**

```bash
docker compose exec db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > brainitive-$(date +%F).sql
```

**Storage volume** (uploads, OAuth keys, generated app key):

```bash
docker run --rm -v brainitive_storage:/storage -v "$PWD":/backup alpine \
  tar czf /backup/storage-$(date +%F).tgz -C /storage .
```

The volume name is your folder name plus `_storage`. Check with `docker volume ls`.

**`.env`:** copy it next to the other files.

## Manual install

```bash
# Database
pg_dump -h 127.0.0.1 -U brainitive brainitive > brainitive-$(date +%F).sql

# Storage and .env
tar czf storage-$(date +%F).tgz -C /opt/brainitive storage/app storage/oauth-private.key storage/oauth-public.key .env
```

## Automate it

Run the commands from a nightly cron job and copy the files to another machine or object storage. A backup on the same disk doesn't help when the disk fails. Test a restore now and then.

## Restore

### Docker

1. Stop Brainitive, but keep the database running:

   ```bash
   docker compose stop app queue scheduler realtime
   ```

2. Restore the storage volume:

   ```bash
   docker run --rm -v brainitive_storage:/storage -v "$PWD":/backup alpine \
     sh -c 'rm -rf /storage/* && tar xzf /backup/storage-2026-01-31.tgz -C /storage'
   ```

3. Restore the database into an **empty** database. On a new server, start with a fresh `pgdata` volume (`docker compose up -d db`), then:

   ```bash
   docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"' < brainitive-2026-01-31.sql
   ```

4. Put your `.env` back (same `DB_PASSWORD`, same `APP_KEY` if you set one) and start everything:

   ```bash
   docker compose up -d
   ```

### Manual install

1. Stop the queue worker and the realtime server, and put the site into maintenance mode (`php artisan down`).
2. Create an empty database owned by your database user (see [Manual install](./manual-install#_2-create-the-database)) and load the dump:

   ```bash
   psql -h 127.0.0.1 -U brainitive brainitive < brainitive-2026-01-31.sql
   ```

3. Unpack the storage archive into the installation folder and restore `.env` with the original `APP_KEY`.
4. Fix ownership: `sudo chown -R www-data:www-data storage bootstrap/cache`.
5. Run `php artisan migrate --force` (if the code is newer than the backup), `php artisan optimize`, `php artisan up`, and start the worker and realtime server again.

After a restore, you can rebuild the search index with `php artisan search:reindex`. See [Search](./search).

## Project export and import

Besides full backups, Brainitive can export **one project** to a ZIP file and restore it as a **new** project, on the same installation or another one. It's useful for moving a project between installations, not as a replacement for backups.

- **Export:** open the project → **Settings** → **General** → **Export project**. You get a notification when the ZIP is ready. The download link works for 24 hours.
- **Restore:** account menu → **Import data** → **Brainitive project export**, upload the ZIP, check the preview and choose **Restore as a new project**.

The ZIP holds boards, issues (with subtasks, labels, custom fields, comments, attachments and links), docs, whiteboards, task lists, plans, releases, sprints and the helpdesk structure. It does **not** hold members and roles, helpdesk tickets and contacts, leads, time entries, history, integrations, webhooks, automations, share links or tokens.

Every user can also download a copy of their own account data with **Download my data** (**Profile → Your data**).
