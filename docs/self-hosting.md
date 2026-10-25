# Self-hosting Brainitive

Brainitive is open source (AGPL-3.0) and runs on any machine with Docker. One image runs the
web app, the queue worker and the scheduler; PostgreSQL and Redis run next to it.
Don't want to run servers? [Brainitive Cloud](https://www.brainitive.app/#pricing) is the same
software, hosted and updated for you.

## Requirements

- Docker 24+ with the Compose plugin
- 2 GB RAM (4 GB for teams above ~50 people), 10 GB disk plus your uploads
- A domain name if you want HTTPS without your own reverse proxy
- An SMTP account for invitations, password resets and notifications (set it in `.env` or
  later in the admin console)

## Quick start

```bash
git clone https://github.com/armendzekjiri/brainitive.git
cd brainitive
cp .env.docker.example .env

# 1. Edit .env: DB_PASSWORD, APP_URL, MAIL_* (MAIL_MAILER=log until you have SMTP)

# 2. Start
docker compose up -d
```

Open `http://<server>:8080`, register your account, then make it the instance admin (this
also marks its email as verified, so you get in before mail works):

```bash
docker compose exec app php artisan make:instance-admin you@example.com
```

Then open **your avatar → Instance admin**. The console has:

- **Overview** — users, workspaces, storage, failed jobs, mail status.
- **Users** — accounts with workspace / project counts, password reset links (emailed, or
  copied to share yourself), verify email, suspend, instance-admin role, delete.
- **Workspaces** — owner, members, projects; transfer ownership, delete.
- **Settings** — open or invite-only sign-up, email verification, SMTP server + test email.
- **Audit log** — every admin action.

Instance admins manage the instance but **can't read workspace content**: they only see
workspaces they've been invited to, like everyone else.

`compose.yaml` runs `ghcr.io/armendzekjiri/brainitive:latest`, the newest release. Image
tags:

| Tag | What |
|---|---|
| `latest` | Newest release |
| `1.15.0`, `1.15` | A specific release / newest patch of a minor version — pin one with `BRAINITIVE_IMAGE` in `.env` |
| `edge` | Latest commit on `main` (may be unreleased) |

Release notes: GitHub → Releases. To build from source instead, set
`BRAINITIVE_IMAGE=brainitive:local` in `.env` and run `docker compose up -d --build`.

## HTTPS

**Option A — Brainitive gets the certificate itself (simplest).** Point DNS to the server,
then in `.env`:

```dotenv
APP_URL=https://brainitive.example.com
SERVER_NAME=brainitive.example.com
HTTP_PORT=80
HTTPS_PORT=443
```

`docker compose up -d` again — Caddy (inside the image) fetches and renews a Let's Encrypt
certificate. Certificates live in the `caddy_data` volume.

**Option B — behind your own reverse proxy** (nginx, Traefik, Cloudflare Tunnel): keep
`SERVER_NAME=:80`, proxy to port `HTTP_PORT`, set `APP_URL=https://…` and
`TRUSTED_PROXIES` to your proxy's IP or network (e.g. `172.16.0.0/12` for a proxy on the Docker
network) so links and cookies use HTTPS. Avoid `*` unless the app port is reachable only through
the proxy — with `*`, any client can fake its IP (and so dodge login rate limits).

## Updating

```bash
git pull                      # or change the image tag
docker compose pull           # skip when you build yourself
docker compose up -d --build
```

Migrations run automatically when the `app` container starts. Check **Help → Changelog**
for what changed.

## Backups

Back up the database and the `storage` volume (uploads, OAuth keys):

```bash
docker compose exec db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > brainitive-$(date +%F).sql
docker run --rm -v brainitive_storage:/storage -v "$PWD":/backup alpine tar czf /backup/storage-$(date +%F).tgz -C /storage .
```

(The volume name is `<folder>_storage` — check with `docker volume ls`.) Restore the database
into a fresh `pgdata` volume with `docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"' < brainitive-….sql`.

## Optional features

| Feature | How |
|---|---|
| Google / GitHub login | Create OAuth apps, set `GOOGLE_*` / `GITHUB_*` in `.env` (callback URLs in `.env.example`) |
| Google Calendar & Tasks | Same Google OAuth app with the Calendar API enabled |
| Web push notifications | `docker compose exec app php artisan webpush:vapid`, copy the keys into `.env` |
| AI writing helpers | Workspace settings → AI (bring your own provider key) |
| MCP (Claude, ChatGPT) | Add `https://<your-domain>/mcp` as a connector; users sign in with OAuth |
| Browser extension | Nothing to set up: in the extension choose **Self-hosted** and enter your domain. It registers itself over OAuth and needs HTTPS (plain http only for `localhost`) |

After changing `.env`: `docker compose up -d` (the app container rebuilds its config cache
on start).

## Security notes

- **Uploads are served from `/storage` and must never run as code.** The Docker image ships
  `docker/Caddyfile`, which answers 404 for `*.php` under `/storage` and serves every upload
  that isn't passive media / a document in a sandbox. Upload names are generated by the server
  from v1.16.1 on. If you run Brainitive **without** Docker, do the same in your web server:

  ```nginx
  # nginx — before your `location ~ \.php$` block
  location ^~ /storage/ {
      location ~* \.(php\d?|phtml|phar|pht|inc)$ { return 404; }
      # passive media / documents: served normally
      location ~* \.(pdf|png|jpe?g|gif|webp|avif|bmp|ico|heic|heif|tiff?|mp3|wav|ogg|oga|m4a|aac|flac|mp4|m4v|mov|webm|avi|mkv|zip|gz|tgz|7z|rar|bz2|tar|bin|docx?|xlsx?|pptx?|odt|ods|odp|csv|tsv|txt|md|json|rtf)$ {
          add_header X-Content-Type-Options nosniff always;
          try_files $uri =404;
      }
      # everything else (HTML, SVG, XML … from older versions): sandboxed, never runs script
      add_header X-Content-Type-Options nosniff always;
      add_header Content-Security-Policy "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox" always;
      try_files $uri =404;
  }
  ```

  ```apache
  # Apache — storage/app/public/.htaccess
  php_flag engine off
  <FilesMatch "\.(php\d?|phtml|phar|pht|inc)$">
      Require all denied
  </FilesMatch>
  ```
- **Check old uploads** after updating: `php artisan uploads:audit` lists files with
  executable or HTML/SVG extensions saved by older versions (`--delete` removes them), and
  separately files whose type isn't on the upload allowlist (review only, never deleted).
  A `.php` file there means someone tried to run code: review your logs.
- **AI endpoints on your own network** (Ollama, LM Studio…) need
  `AI_ALLOW_PRIVATE_ENDPOINTS=true`. By default, server-side requests (AI, link previews)
  only go to public addresses.

## Troubleshooting

- **App key** — generated on the first start and kept in the `storage` volume
  (`storage/app.key`), so back that volume up. To manage it yourself, set `APP_KEY=` in `.env`
  (`docker compose run --rm --no-deps app php artisan key:generate --show`); a key in `.env` wins.
  Never change it on a running instance: sessions, encrypted settings and tokens depend on it.
- **`Database not reachable`** — `DB_PASSWORD` in `.env` must match what PostgreSQL was first
  started with. Changing it later needs `ALTER USER` inside PostgreSQL, or a fresh `pgdata` volume.
- **Upgrading from the old compose file with MariaDB** — those installs never got past the
  first migrations, so there is no data to keep. PostgreSQL uses a new `pgdata` volume, so
  `git pull && docker compose up -d` just works; the old one can go: `docker volume rm <folder>_db`.
- **Sign-up or "forgot password" fails with a mail error** — `MAIL_*` points to a server that
  doesn't exist. Keep `MAIL_MAILER=log` until SMTP works (mails then appear in
  `docker compose logs app`).
- **Logs** — `docker compose logs -f app queue scheduler`.
- **Mails not arriving** — Instance admin → Settings → *Send a test email* shows the mail
  server's error. SMTP saved there overrides `MAIL_*` in `.env`.

## Licence

Self-hosting is free under the [GNU AGPL-3.0](../LICENSE). If you modify Brainitive and offer
it to others over a network, you must publish your changes under the same licence. For a
commercial licence without that obligation, contact hello@brainitive.app.

## Connecting AI apps (MCP)

Apps register themselves when you connect them. Only redirect hosts of known clients (Claude,
ChatGPT, Perplexity, Cursor, VS Code, the browser extension) and `localhost` are accepted. To allow
another client, add its callback host: `MCP_REDIRECT_HOSTS=app.example.com,*.example.org`.

