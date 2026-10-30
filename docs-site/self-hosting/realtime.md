---
title: Realtime server
description: Set up Brainitive's realtime server for live co-editing of docs, issue descriptions and whiteboards, and for live updates. systemd and nginx.
order: 40
---

# Realtime server

The realtime server is a small Node.js process that makes Brainitive live: several people editing one document at once, and instant updates without reloading. It is optional. Without it, Brainitive works fully, just without the live features.

## What it does

**Live co-editing.** Several people can edit the same document draft, issue description or whiteboard at the same time. Everyone sees the others' changes appear, with named, coloured cursors. Changes made offline merge when the browser reconnects. The draft and publish workflow doesn't change: people edit the draft together, and **Publish** makes it live as before.

**Live channels.** The notification bell rings the moment something arrives, you see who else is looking at an issue and who is typing, and boards and plans update as others change them.

How it works:

- The browser connects to `/collab` on your Brainitive address over a WebSocket. Live channels use the same address with `?live=1`, so one proxy rule covers both.
- Every connection carries a short-lived token from Brainitive. The realtime server asks Brainitive about each user's access when they connect and again every minute.
- Brainitive stays the source of truth. The realtime server loads and saves document state through Brainitive's internal API (`/api/internal/collab/…`), protected by a key derived from the shared secret.
- For live channels, Brainitive sends short "something changed" messages (ids only, never content) to the realtime server's own address. Browsers then fetch the content through the normal, permission-checked pages.

## What happens without it

- Documents, descriptions and whiteboards are edited one person at a time, as before.
- If `COLLAB_URL` is set but the server is down, editors see **Live editing unavailable — editing alone** and keep working.
- The bell checks for new notifications every 30 seconds instead of instantly.

## Docker

Nothing to do. The `realtime` service in `compose.yaml` runs the server, Caddy in the `app` container forwards `/collab` to it, and the app publishes to it at `http://realtime:1234` on the internal network. The shared secret is derived from `APP_KEY` and handed over through the `collab` volume.

To turn it off: set `COLLAB_URL=` (empty) in `.env`, remove the `realtime` service from `compose.yaml` (or `docker compose stop realtime`) and run `docker compose up -d`.

To keep live co-editing but turn off live channels: `LIVE_ENABLED=false`.

If you put your own reverse proxy in front, forward WebSockets for `/collab`. See [Docker → HTTPS](./docker#option-b-behind-your-own-reverse-proxy).

## Manual install

The steps below assume Brainitive is in `/opt/brainitive` and the realtime server listens on `127.0.0.1:1234`. Any free port works; use the same one everywhere.

### 1. Install the dependencies

Node.js 20 or newer is required (22 recommended).

```bash
cd /opt/brainitive
npm --prefix realtime ci --omit=dev
```

### 2. Create a shared secret

The app and the realtime server need the same secret. Generate one:

```bash
openssl rand -hex 32
```

Give the realtime server its own small env file, **not** the app's `.env`. It faces the internet and needs nothing else.

```bash
sudo mkdir -p /etc/brainitive
sudo install -m 600 -o www-data /dev/null /etc/brainitive/realtime.env
sudo nano /etc/brainitive/realtime.env
```

```ini
COLLAB_SECRET=paste-the-secret-here
```

### 3. Create the systemd service

`/etc/systemd/system/brainitive-realtime.service`:

```ini
[Unit]
Description=Brainitive realtime server (live co-editing)
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/brainitive
EnvironmentFile=/etc/brainitive/realtime.env
Environment=NODE_ENV=production
Environment=COLLAB_HOST=127.0.0.1
Environment=COLLAB_PORT=1234
# where the server reaches Brainitive's internal API
Environment=COLLAB_APP_URL=https://brainitive.example.com
ExecStart=/usr/bin/node realtime/src/index.js
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadOnlyPaths=/opt/brainitive

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now brainitive-realtime
journalctl -u brainitive-realtime -f
```

On start it logs `[realtime] Brainitive live editing + live channels on 127.0.0.1:1234 → …`.

### 4. Proxy `/collab` in nginx

Inside your HTTPS `server { … }` block:

```nginx
location = /collab {
    proxy_pass http://127.0.0.1:1234;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    # long-lived connections: the server pings every 30 s
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_buffering off;
}
```

The realtime server saves large documents through `/api/internal/collab/…`. nginx's default body limit of 1 MB is too small: make sure `client_max_body_size` is at least `25m` (the [manual install](./manual-install#_8-nginx) uses `210m` for the whole site).

::: warning Only proxy `/collab`
Never forward other paths of the realtime server to the internet. Its publish endpoint refuses requests that carry proxy headers, and the app reaches it directly on `127.0.0.1`.
:::

Then `sudo nginx -t && sudo systemctl reload nginx`.

### 5. Tell the app

In the app's `.env`:

```ini
COLLAB_URL=wss://brainitive.example.com/collab
COLLAB_SECRET=paste-the-same-secret-here
LIVE_PUBLISH_URL=http://127.0.0.1:1234
```

Then `php artisan optimize` and `php artisan queue:restart`.

`COLLAB_URL` may also be just the path `/collab`: the browser then connects to the host it is on.

## Environment variables

### Read by the realtime server

| Variable | Default | What it does |
|---|---|---|
| `COLLAB_HOST` | `127.0.0.1` | Address to listen on. Keep `127.0.0.1` behind nginx |
| `COLLAB_PORT` | `1234` | Port to listen on |
| `COLLAB_SECRET` | derived from `APP_KEY` | Shared secret, same value as in the app. If it's not set, the server derives it from `APP_KEY` the same way the app does |
| `COLLAB_SECRET_FILE` | – | Read the secret from this file instead (Docker uses this) |
| `COLLAB_APP_URL` | `APP_URL` | Where the server reaches Brainitive's internal API |
| `COLLAB_APP_HOST` | – | Host name to send when `COLLAB_APP_URL` is a loopback address, e.g. `COLLAB_APP_URL=https://127.0.0.1` + `COLLAB_APP_HOST=brainitive.example.com`. Use it when a firewall stops the server from reaching its own public address ("Live editing unavailable" with *the live editing server can't reach Brainitive*). nginx must listen on 127.0.0.1:443 (`listen 443 ssl;` does) |
| `LIVE_PUBLISH_FROM` | loopback only | `private` also accepts publishes from private networks (Docker) |
| `COLLAB_ACCESS_INTERVAL_MS` | `60000` | How often access is re-checked, in milliseconds |

### Read by the app

`COLLAB_URL`, `COLLAB_SECRET`, `COLLAB_TOKEN_TTL`, `COLLAB_MAX_STATE_BYTES`, `LIVE_ENABLED`, `LIVE_URL`, `LIVE_PUBLISH_URL` and `LIVE_TOKEN_TTL`. See [Configuration](./configuration#realtime-live-co-editing).

::: tip LIVE_PUBLISH_URL is internal
`LIVE_PUBLISH_URL` is the realtime server's own address (`http://127.0.0.1:1234`), never the public `wss://` URL. Without it, presence and typing still work, but the bell falls back to checking every 30 seconds.
:::

## Health check

The server answers plain HTTP on its port:

```bash
curl -s http://127.0.0.1:1234/
# Welcome to Hocuspocus!
```

In the browser, open a document: when live editing works, other editors' names appear next to their cursors. A `502` on `/collab` means nginx can't reach the server.

## After an update

When an update changes the `realtime/` folder:

```bash
npm --prefix realtime ci --omit=dev
sudo systemctl restart brainitive-realtime
```

Browsers reconnect by themselves.
