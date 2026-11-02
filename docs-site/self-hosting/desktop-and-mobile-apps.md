---
title: Desktop and mobile apps
description: What a self-hosted Brainitive server needs for the Brainitive desktop and mobile apps - the App API under /api/v1, sign-in through /app, minimum app versions, reverse proxy paths and the OpenAPI file.
order: 75
---

# Desktop and mobile apps

The Brainitive desktop app (and later the mobile app) connects to **your** Brainitive server, the same way the browser does. People enter your address (for example `https://brainitive.example.com`) on the app's first start, then sign in in their normal browser. Nothing goes through Brainitive Cloud.

::: info Not released yet
The desktop app is in development. The server side ships with Brainitive v1.49.0, so your server is ready when the app comes out.
:::

## What the apps use on your server

| Path | What it is |
|---|---|
| `/api/v1/*` | The **App API**: a JSON API only for the first-party desktop and mobile apps. It has its own tokens and rate limits and is separate from the API that AI apps, the CLI and the browser extension use (`/mcp`, `/api/cli`, `/api/ext`) |
| `/app/authorize` | Sign-in page the app opens in the system browser. People sign in the way they always do (password, Google, Microsoft or GitHub) and confirm "Sign in to Brainitive Desktop" |
| `/app/session/*` | One-time link that signs the app's window into the web app after the native sign-in |
| `/app/device` | Where people confirm the code a **TV** shows (device-code sign-in). They open it on a phone or computer where they are signed in, or scan the TV's QR code, and click **Approve** |
| `/collab` | Live updates, as for the browser (see [Realtime server](./realtime)) |

Sign-in hands the app a one-time code through the `brainitive://` link. No OAuth app, client secret or redirect URL has to be set up on your server for this.

The **TV app** has no usable browser, so it signs in with a code instead (OAuth device authorization, RFC 8628): the TV shows a code like `BCDF-GHJK` and a QR code, the person confirms it at `https://<your server>/app/device`, and the TV is signed in. The code works once, for 10 minutes. Nothing to set up for this either: `/app/device` uses the address in `APP_URL`, so make sure it is the public address people reach your server at.

## Reverse proxy

Nothing extra is needed when your proxy forwards everything to Brainitive (the nginx examples in [Docker](./docker) and [Manual install](./manual-install) do). If you only forward selected paths, also forward:

- `/api/v1/` with the `Authorization` header unchanged, and
- `/app/` (sign-in, the one-time session links and the TV code page `/app/device`).

The App API sends no CORS headers on purpose: only the native apps call it, never a website.

### Paths to allow

If a proxy, firewall or login wall sits in front of Brainitive, these paths must reach it:

| Path | Why |
|---|---|
| `/api/v1/` | The App API, with the `Authorization` header unchanged. Do not put another login in front of it |
| `/app/authorize` | Sign-in page the app opens in the browser |
| `/app/device` | Where people approve a TV code |
| `/app/session/` | One-time link that signs the app window into the web app |
| `/collab` (websocket) | Live updates for apps use the same websocket as the browser. Forward the `Upgrade` and `Connection` headers, as in [Realtime server](./realtime) |

## Settings

| Variable | Default | What it does |
|---|---|---|
| `APP_API_MIN_DESKTOP_VERSION` | `0.1.0` | Oldest desktop app version your server works with. Older apps ask the user to update |
| `APP_API_MIN_MOBILE_VERSION` | `0.1.0` | The same for the mobile app |

Raise them only when an upgrade note asks you to. All variables: [Configuration](./configuration#desktop-and-mobile-apps).

## Upgrading to v1.49.0

1. Run the database migration: `php artisan migrate`. It adds one table, `sync_tombstones`, which remembers deleted or reassigned items for 30 days so offline apps can drop them. Nothing existing changes.
2. Restart the realtime service (`sudo systemctl restart brainitive-realtime`, or the `realtime` container with Docker). App sign-out then also closes that device's live connection. See [Realtime server](./realtime).
3. Make sure the scheduler runs (`php artisan schedule:run` every minute). It prunes old tombstones daily. See [Queue and scheduler](./queue-and-scheduler).

## Signed-in devices

Every app sign-in is a device (desktop, mobile or TV). People see theirs under **Profile → Notifications → Desktop & mobile apps** and can sign one out there. Suspending a user or changing their password signs out all their apps.

## API reference

The App API is described in an OpenAPI 3.1 file in the repository: [`docs/api/openapi.json`](https://github.com/armendzekjiri/brainitive/blob/main/docs/api/openapi.json). Its version is the API's own version (`1.0.0`), not the Brainitive release. Instance admins can also browse it on their own server at `/docs/api`.
