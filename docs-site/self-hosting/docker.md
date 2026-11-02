---
title: Docker
description: Install Brainitive with Docker Compose and the published image. Quick start, first admin account, HTTPS, reverse proxy, volumes and updates.
order: 10
---

# Docker

The recommended way to self-host Brainitive: a ready-made image from the GitHub Container Registry and one `compose.yaml`. Nothing to clone, nothing to build.

## Requirements

- Docker 24 or newer with the Compose plugin
- 2 GB RAM (4 GB for teams above about 50 people), 10 GB of disk plus your uploads
- A domain name if you want HTTPS
- An SMTP account for invitations, password resets and notifications (you can add it later)

## Quick start

1. Create a folder and download the two files:

   ```bash
   mkdir brainitive && cd brainitive
   curl -fsSLO https://raw.githubusercontent.com/armendzekjiri/brainitive/main/compose.yaml
   curl -fsSL https://raw.githubusercontent.com/armendzekjiri/brainitive/main/.env.docker.example -o .env
   ```

2. Open `.env` and set at least:
   - `DB_PASSWORD`: a long random password for the database (`openssl rand -hex 24` makes one).
   - `APP_URL`: the address people will open, for example `https://brainitive.example.com`.
   - `MAIL_*`: your SMTP server. Keep `MAIL_MAILER=log` until you have one. See [Email](./email).

3. Start Brainitive. The first start downloads the image:

   ```bash
   docker compose up -d
   ```

4. Open `http://localhost:8080` on the same machine and register your account.

::: warning The quick start is local only
Out of the box the app listens on `127.0.0.1` (`BIND_ADDRESS` in `.env`), so only the machine it runs on can open it, over plain HTTP. Before other people use it, set up [HTTPS](#https): either Brainitive gets its own certificate (option A) or your own reverse proxy terminates HTTPS (option B). Don't open the plain-HTTP port to your network or the internet: passwords and session cookies would travel unencrypted. (Since v1.49.0. Before, `compose.yaml` published the port on every interface.)
:::

::: tip APP_KEY is created for you
Leave `APP_KEY` empty. The app container generates one on the first start and keeps it in the `storage` volume as `storage/app.key`. Back up that volume. See [Backups](./backups).
:::

## Make yourself the instance admin

Run this with the email address you registered:

```bash
docker compose exec app php artisan make:instance-admin you@example.com
```

This also marks your email as verified, so you can sign in before mail works. Then open **avatar menu → Instance admin**. There you can:

- see users, workspaces, storage, failed jobs and mail status (**Overview**),
- manage accounts (**Users**) and workspaces (**Workspaces**),
- choose open or invite-only sign-up and set up SMTP (**Settings**),
- preview every email (**Emails**) and read the **Audit log**.

Instance admins manage the installation but can't read workspace content. More in the [Instance admin guide](/admin/).

Other admin commands: `php artisan list:instance-admins` and `php artisan remove:instance-admin you@example.com`.

## What runs

`compose.yaml` starts six containers. Four of them use the same Brainitive image in different roles:

| Service | Role |
|---|---|
| `app` | Web server (FrankenPHP with Caddy). On every start it runs the database migrations, queues the search index build if needed, and caches the configuration |
| `queue` | Queue worker (`php artisan queue:work --tries=3 --max-time=3600`) |
| `scheduler` | Scheduler (`php artisan schedule:work`) |
| `realtime` | Realtime server for live co-editing and live updates (Node.js) |
| `db` | PostgreSQL 16 |
| `redis` | Redis 7, for cache, sessions and the queue |

The `realtime` container gets neither your `.env` nor the storage volume. The `app` container hands it a shared secret through the small `collab` volume. See [Realtime server](./realtime).

## Volumes

| Volume | Contains | Back up? |
|---|---|---|
| `storage` | Uploads, the generated `APP_KEY` (`app.key`), OAuth keys for the MCP server, logs | **Yes** |
| `pgdata` | The PostgreSQL database | **Yes** (with `pg_dump`, see [Backups](./backups)) |
| `redis` | Cache, sessions and queued jobs | No |
| `caddy_data` | TLS certificates when Caddy gets them itself | Optional |
| `caddy_config` | Caddy's runtime configuration | No |
| `collab` | The shared secret for the realtime server, written on every start | No |

Docker prefixes volume names with the folder name, for example `brainitive_storage`. Check with `docker volume ls`.

## Image tags

`compose.yaml` runs `ghcr.io/armendzekjiri/brainitive:latest` unless you set `BRAINITIVE_IMAGE` in `.env`. Images are built for `linux/amd64` and `linux/arm64`.

| Tag | What you get |
|---|---|
| `latest` | The newest release |
| `1.45.0` | Exactly this release |
| `1.45` | The newest patch release of 1.45 |
| `sha-<commit>` | The image of exactly that commit (immutable) |
| `edge` | The latest commit on `main`, which may not be released yet |

Images are only published after the tests passed for exactly that code.

To pin a version, add this to `.env`:

```ini
BRAINITIVE_IMAGE=ghcr.io/armendzekjiri/brainitive:1.45.0
```

`latest` and `1.45` move with new releases. For a production server, pin an exact version, or pin the image digest so a re-pull can never bring other bytes:

```bash
docker buildx imagetools inspect ghcr.io/armendzekjiri/brainitive:1.45.0   # prints "Digest: sha256:…"
# BRAINITIVE_IMAGE=ghcr.io/armendzekjiri/brainitive:1.45.0@sha256:…
```

::: details Build the image from source instead
Clone the repository, set `BRAINITIVE_IMAGE=brainitive:local` in `.env` and run `docker compose up -d --build`.
:::

## HTTPS

### Option A: Brainitive gets the certificate itself

The simplest way. Caddy inside the image fetches and renews a Let's Encrypt certificate.

1. Point your domain's DNS to the server. Ports 80 and 443 must be free and reachable.
2. In `.env`:

   ```ini
   APP_URL=https://brainitive.example.com
   SERVER_NAME=brainitive.example.com
   BIND_ADDRESS=0.0.0.0
   HTTP_PORT=80
   HTTPS_PORT=443
   TRUSTED_PROXIES=
   ```

3. Run `docker compose up -d` again.

Caddy redirects HTTP to HTTPS by itself. Certificates are stored in the `caddy_data` volume. `TRUSTED_PROXIES` stays empty: nothing sits in front of Brainitive, so no forwarded headers are trusted.

### Option B: behind your own reverse proxy

Use this if nginx, Caddy, Traefik or another proxy on the same server already handles HTTPS.

1. Keep `SERVER_NAME=:80` (plain HTTP inside) and `BIND_ADDRESS=127.0.0.1`, and let the proxy forward to `127.0.0.1:8080` (`HTTP_PORT`). Only the proxy can reach the app then; check from another machine that `http://your-server:8080` does **not** answer.
2. Set `APP_URL=https://brainitive.example.com`. The session cookie is then sent over HTTPS only (`SESSION_SECURE_COOKIE` follows `APP_URL`; set it to `true` to force it).
3. Set `TRUSTED_PROXIES` to the address the proxy's requests come from **as the container sees it**, so Brainitive sees HTTPS and the real client IP. A proxy on the host arrives from the Docker network's gateway:

   ```bash
   docker network inspect "$(basename "$PWD")_default" --format '{{(index .IPAM.Config 0).Gateway}}'
   # e.g. 172.18.0.1  →  TRUSTED_PROXIES=172.18.0.1
   ```

   The proxy must overwrite `X-Forwarded-For` / `X-Forwarded-Proto` (the nginx example below does). A proxy in another container on the same Docker network: use that container's address or the network's subnet.
4. Forward WebSockets for `/collab`, so live co-editing works.

Until v1.48, `.env.docker.example` trusted all private networks (`127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16`) while the port was published on every interface: any client on such a network could fake its IP address. If your `.env` still has that line, replace it with your proxy's address and keep `BIND_ADDRESS=127.0.0.1`.

An nginx example (certificate lines left out):

```nginx
server {
    listen 443 ssl;
    server_name brainitive.example.com;

    # imports (project archives, Notion ZIPs) can be up to 200 MB
    client_max_body_size 210m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # live co-editing and live updates (WebSocket)
    location = /collab {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }
}
```

::: warning Don't trust every proxy
`TRUSTED_PROXIES=*` (or a whole private range) trusts forwarded headers from every client in it. Then they can fake their IP address and get around login rate limits. Use `*` only if the app port can be reached solely through your proxy.
:::

### Security headers

On HTTPS, production responses carry `Strict-Transport-Security: max-age=31536000; includeSubDomains` (no `preload`): browsers that saw it use only HTTPS for this host for a year. Turn it off with `HSTS_ENABLED=false`, for example while you still test HTTPS. Behind a reverse proxy this needs `TRUSTED_PROXIES`, otherwise Brainitive doesn't know the request was HTTPS. Pages also carry a `Content-Security-Policy-Report-Only` header for scripts. It blocks nothing; see [Configuration](./configuration#security-headers).

## Change settings

Edit `.env`, then run:

```bash
docker compose up -d
```

The containers are recreated, and the app container rebuilds its configuration cache on start. All variables are listed in [Configuration](./configuration).

## Update

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically when the `app` container starts. Take a backup first. Details in [Upgrading](./upgrading).

## Useful commands

```bash
docker compose ps                              # status of all containers
docker compose logs -f app queue scheduler     # follow the logs
docker compose exec app php artisan about      # versions and configuration overview
docker compose exec app php artisan uploads:audit   # check old uploads after an update
```

## Optional features

| Feature | How |
|---|---|
| Sign in with Google, Microsoft or GitHub | See [Integrations](/integrations/) |
| Web push notifications | `docker compose exec app php artisan webpush:vapid --show`, copy the two keys into `.env` as `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`, and set `VAPID_SUBJECT` (for example `mailto:admin@example.com`) |
| AI on your own network (Ollama, LM Studio) | `AI_ALLOW_PRIVATE_ENDPOINTS=true` |
| MCP (Claude, ChatGPT) | Add `https://brainitive.example.com/mcp` as a connector. Users sign in with OAuth |
| Legal pages (imprint, privacy) | `LEGAL_*` variables, see [Configuration](./configuration#legal-pages) |
