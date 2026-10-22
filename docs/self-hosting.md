# Self-hosting Brainitive

Brainitive is open source (AGPL-3.0) and runs on any machine with Docker. One image runs the
web app, the queue worker and the scheduler; MariaDB and Redis run next to it.
Don't want to run servers? [Brainitive Cloud](https://brainitive.com/#pricing) is the same
software, hosted and updated for you.

## Requirements

- Docker 24+ with the Compose plugin
- 2 GB RAM (4 GB for teams above ~50 people), 10 GB disk plus your uploads
- A domain name if you want HTTPS without your own reverse proxy
- An SMTP account for invitations and notifications

## Quick start

```bash
git clone https://github.com/armendzekjiri/brainitive.git
cd brainitive
cp .env.docker.example .env

# 1. App key — paste the output into .env as APP_KEY=…
docker compose run --rm --no-deps app php artisan key:generate --show

# 2. Edit .env: APP_URL, DB_PASSWORD, DB_ROOT_PASSWORD, MAIL_*

# 3. Start
docker compose up -d
```

Open `http://<server>:8080`, register your account, then make it the instance admin:

```bash
docker compose exec app php artisan make:instance-admin you@example.com
```

Until a release image is published, add `--build` to the `up` command to build it from
source (takes a few minutes). To always build yourself, set `BRAINITIVE_IMAGE=brainitive:local`
in `.env` and run `docker compose up -d --build`.

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
`TRUSTED_PROXIES=*` (or your proxy's IP) so links and cookies use HTTPS.

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
docker compose exec db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' > brainitive-$(date +%F).sql
docker run --rm -v brainitive_storage:/storage -v "$PWD":/backup alpine tar czf /backup/storage-$(date +%F).tgz -C /storage .
```

(The volume name is `<folder>_storage` — check with `docker volume ls`.)

## Optional features

| Feature | How |
|---|---|
| Google / GitHub login | Create OAuth apps, set `GOOGLE_*` / `GITHUB_*` in `.env` (callback URLs in `.env.example`) |
| Google Calendar & Tasks | Same Google OAuth app with the Calendar API enabled |
| Web push notifications | `docker compose exec app php artisan webpush:vapid`, copy the keys into `.env` |
| AI writing helpers | Workspace settings → AI (bring your own provider key) |
| MCP (Claude, ChatGPT) | Add `https://<your-domain>/mcp` as a connector; users sign in with OAuth |

After changing `.env`: `docker compose up -d` (the app container rebuilds its config cache
on start).

## Troubleshooting

- **`APP_KEY is missing`** — step 1 of the quick start.
- **`Database not reachable`** — `DB_PASSWORD` in `.env` must match what MariaDB was first
  started with. Changing it later needs `ALTER USER` inside MariaDB, or a fresh `db` volume.
- **Logs** — `docker compose logs -f app queue scheduler`.
- **Mails not arriving** — test with `docker compose exec app php artisan tinker` →
  `Mail::raw('test', fn ($m) => $m->to('you@example.com'));`.

## Licence

Self-hosting is free under the [GNU AGPL-3.0](../LICENSE). If you modify Brainitive and offer
it to others over a network, you must publish your changes under the same licence. For a
commercial licence without that obligation, contact hello@brainitive.com.
