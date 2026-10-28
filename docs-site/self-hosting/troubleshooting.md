---
title: Troubleshooting
description: Fix common problems with self-hosted Brainitive - APP_KEY, storage permissions, session expired behind a proxy, WebSockets, emails, scheduler, search.
order: 100
---

# Troubleshooting

Common problems with a self-hosted Brainitive installation and how to fix them. Start with the logs: they usually name the cause.

```bash
# Docker
docker compose logs -f app queue scheduler realtime

# Manual install
tail -f /opt/brainitive/storage/logs/laravel.log
journalctl -u brainitive-queue -u brainitive-realtime -f
```

Brainitive answers `GET /up` with status 200 when the app is running, which is handy for uptime monitors.

## "Database not reachable"

The Docker containers wait up to two minutes for the database, then stop with `Database not reachable after 2 minutes — check DB_HOST / DB_PASSWORD.`

- `DB_PASSWORD` in `.env` must match the password PostgreSQL was **first** started with. Changing it in `.env` later doesn't change it in the database. Either change it inside PostgreSQL (`ALTER USER brainitive WITH PASSWORD '…';`) or start over with a fresh `pgdata` volume (only on an installation without data).
- Manual install: check `DB_HOST`, `DB_PORT` and `DB_CONNECTION=pgsql`, then test with `php artisan db:show`.

## APP_KEY problems

- **Docker:** if `APP_KEY` is empty, the app container generates one on first start and keeps it in the storage volume as `storage/app.key`. The `queue` and `scheduler` containers wait for it. If they report `APP_KEY is missing and the app container hasn't generated one`, check the app container's logs.
- A key in `.env` always wins over `storage/app.key`.
- **Manual install:** `No application encryption key has been specified` means `APP_KEY` is empty. Run `php artisan key:generate` once, then `php artisan optimize`. Never do this on an installation with data, because a new key makes stored secrets unreadable.
- **Stored secrets can't be decrypted / everyone was signed out:** the key changed. Put the original key back from your backup. If you rotated it on purpose, list the old one in `APP_PREVIOUS_KEYS`.

## Permission errors on `storage`

Errors like `Permission denied` for `storage/logs/laravel.log` or `bootstrap/cache` mean the web server user can't write there. Usually a command was run as `root` and created files `www-data` can't change.

```bash
cd /opt/brainitive
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

Run `php artisan` commands as `sudo -u www-data php artisan …` to avoid this.

## Uploaded images don't show

- Manual install: run `php artisan storage:link` once. It links `public/storage` to `storage/app/public`.
- Check that `APP_URL` matches the address in the browser. Upload links are built from it.

## "Your session expired" or wrong `http://` links behind a proxy

If forms keep failing with **Your session expired. Please try again.**, you get sent back to the login page, or links and redirects use `http://` although the site runs on HTTPS:

1. Set `APP_URL` to the exact address people use, with `https://`.
2. Set `TRUSTED_PROXIES` to your reverse proxy's IP address or network, so Brainitive trusts its `X-Forwarded-Proto` header. The Docker template trusts localhost and private networks by default.
3. Make sure the proxy sends `X-Forwarded-For` and `X-Forwarded-Proto` (see the nginx example in [Docker](./docker#option-b-behind-your-own-reverse-proxy)).
4. Leave `SESSION_DOMAIN` empty unless you know you need it.
5. Apply the change: `docker compose up -d`, or `php artisan optimize` on a manual install.

## Live editing doesn't connect

Editors see **Live editing unavailable — editing alone**, or the bell only updates every 30 seconds.

- **Is `COLLAB_URL` set?** Live co-editing is off while it is empty. In Docker, `compose.yaml` sets it to `/collab`.
- **Is the realtime server running?** Docker: `docker compose ps realtime`. Manual: `systemctl status brainitive-realtime`, and `curl http://127.0.0.1:1234/` should answer `Welcome to Hocuspocus!`.
- **Does the proxy pass WebSockets?** `/collab` needs the `Upgrade` and `Connection` headers and `proxy_http_version 1.1`. A `502` means nginx can't reach the realtime server; check the port.
- **Same secret?** `COLLAB_SECRET` must be identical for the app and the realtime server (or unset on both, with the same `APP_KEY`). With a mismatch, the realtime server closes every connection right after it opens.
- **Can the realtime server reach the app?** It calls `COLLAB_APP_URL` (default `APP_URL`). Large documents need `client_max_body_size` of at least `25m` in nginx.
- **Bell not instant:** set `LIVE_PUBLISH_URL` to the realtime server's own address, for example `http://127.0.0.1:1234`, never the public URL.

More in [Realtime server](./realtime).

## Emails are not sent

1. **Is the queue worker running?** Emails are sent from the queue. Docker: `docker compose ps queue`. Manual: `systemctl status brainitive-queue`.
2. **Is `MAIL_MAILER` still `log`?** Then emails only go to the log.
3. **Test the server:** **Instance admin → Settings → Send a test email** shows the mail server's error right away.
4. **Remember the override:** SMTP saved in the admin console wins over `MAIL_*` in `.env`.
5. **Failed jobs:** **Instance admin → Overview**, or `php artisan queue:failed`.
6. **Sign-up or "forgot password" fails with a mail error:** `MAIL_*` points to a server that doesn't exist. Use `MAIL_MAILER=log` until SMTP works.
7. **Mail lands in spam:** set up SPF, DKIM and DMARC. See [Email](./email#deliverability-spf-dkim-and-dmarc).

## Reminders and digests never arrive

The scheduler isn't running.

- Docker: `docker compose ps scheduler`.
- Manual: check the cron entry with `sudo crontab -u www-data -l`. Run `php artisan schedule:list` to see the tasks and `php artisan schedule:run` once by hand to see errors.

See [Queue worker and scheduler](./queue-and-scheduler).

## Search finds nothing

- The index is built by the queue worker. Check that it runs.
- After an update, the first build takes a few minutes on large installations.
- Rebuild by hand: `php artisan search:reindex`. See [Search](./search).

## Settings in `.env` have no effect

The configuration is cached.

- Docker: run `docker compose up -d` after editing `.env`.
- Manual: `php artisan optimize`, then `php artisan queue:restart` so the worker reloads too.

## Web push notifications never arrive

`VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` are empty. Without them, no browser can subscribe. Generate keys with `php artisan webpush:vapid --show`, add them and `VAPID_SUBJECT` to `.env`, apply the change, and restart the queue worker.

## Uploads fail for large files

Imports and archives may be up to 200 MB. Raise the limits in your proxy (`client_max_body_size 210m;` in nginx) and, on a manual install, in PHP (`upload_max_filesize = 200M`, `post_max_size = 210M`). The Docker image already has these PHP limits.
