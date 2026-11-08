---
title: App API
description: "The App API (/api/v1) behind the Brainitive desktop, mobile and TV apps and the Developer API: how apps sign in, what a token is, how devices are listed and revoked, and where the API reference lives."
order: 85
---

# App API

The **App API** is the JSON API under `/api/v1` that the official Brainitive apps use: Brainitive Desktop, the mobile app and the TV app. It covers what the web app does for your day-to-day work (boards, issues, docs, My Work, captures, time tracking, the calendar and more), with offline sync and live updates for apps.

Your own scripts and apps can use the same API with a **personal API key**: see [Developer API](./developer-api).

It is separate from the other ways to connect: [AI and MCP](./ai-and-mcp) for AI assistants, the [CLI](./cli) and the [browser extension](./browser-extension) each have their own access.

## How apps sign in

- **Desktop and mobile** open your browser at `/app/authorize`. You sign in as usual (password, Google, Microsoft or GitHub) and confirm the app. The app receives a one-time code and trades it for tokens.
- **TV** has no usable browser. It shows a short code and a QR code; open `/app/device` on your phone or computer, enter the code and click **Approve**. The code works once, for 10 minutes. The page also shows where and when the request started: only approve a code from your own TV, just now. A TV can show your day, your work, your calendar and your notifications and mark work as done — it can't change your account, see your other devices or export data.

No OAuth app or client secret has to be set up for any of this.

## Tokens and devices

- Every sign-in creates a **device** with its own access token. A token is short-lived and is renewed in the background; it only works for the API, never for the web app.
- A device is listed under **Profile → Notifications → Desktop & mobile apps** with its name, platform and last use.
- **Revoke** signs one device out immediately: its token stops working, its live connections (updates and live doc editing) are closed, and the web session its window opened ends. You can also sign out all devices.
- Changing your password, or a suspended account, signs out every device. If an old sign-in token of an app is used again (a sign that it was copied), that device is signed out and you get an email.
- A one-time app link opened in a normal browser, or where someone else is signed in, asks before it signs anyone in.

## Reference

The full reference is public at [docs.brainitive.app/api](/api) (OpenAPI 3.1, also the file [`docs/api/openapi.json`](https://github.com/armendzekjiri/brainitive/blob/main/docs/api/openapi.json) in the repository). Each endpoint says what a personal API key needs for it. On a server, instance admins can also browse it at `/docs/api`.

To use the API from your own scripts, create a personal API key: [Developer API](./developer-api). App sign-in tokens are only issued to the official apps.

## Self-hosting

Nothing to configure, but make sure your proxy forwards `/api/v1`, `/app` and the live websocket. See [Desktop and mobile apps](../self-hosting/desktop-and-mobile-apps).
