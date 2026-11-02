---
title: Desktop app
description: "Brainitive Desktop for macOS, Windows and Linux: quick capture with a global shortcut, My Day and the timer in your menu bar, offline captures, and sign-in with your browser — Cloud or self-hosted."
order: 86
---

# Desktop app

::: warning Beta, coming soon
Brainitive Desktop is not released yet. Your server already supports it (Brainitive v1.49.0 and later). This page describes the app as it is currently built; details can still change before version 1.0.
:::

Brainitive Desktop keeps Brainitive one shortcut away, even when the browser is closed. It runs on **macOS, Windows and Linux** and works with **Brainitive Cloud** and with your **own server**.

## What it does

- **Quick capture.** A global shortcut opens a small capture window over any app. Type a thought, press ⌘↩ (Ctrl+↩), and it lands in your capture inbox to sort later.
- **My Day and the timer in your menu bar.** The tray icon shows what is on your plate today. Check things off and start or stop the timer without opening a browser tab.
- **Captures work offline.** Without a connection, captures wait on your computer and are sent when you are back online.
- **Quick switcher.** Search everything, open a result, copy a snippet or start the timer on an issue.

## Sign in

1. Start the app and choose **Brainitive Cloud**, or enter the address of your own server (for example `https://brainitive.example.com`).
2. The app opens your normal browser. Sign in the way you always do (password, Google, Microsoft or GitHub).
3. Confirm **Sign in to Brainitive Desktop**. The browser hands the app a one-time code and you are signed in.

The app never sees your password. It gets a token that belongs to this computer only. See your signed-in apps and sign one out under [Profile](./your-profile), and read [App API](../integrations/app-api) for how tokens work.

## Shortcuts

| Shortcut (macOS) | Windows / Linux | What it does |
|---|---|---|
| ⇧⌘Space | Ctrl+Shift+Space | Quick capture, from any app |
| ⌥⌘K | Ctrl+Alt+K | Quick switcher |
| Tray icon | Tray icon | My Day, the running timer and unread notifications |

Change or turn off the global shortcuts under **Settings** (⌘, or Ctrl+,). These shortcuts may change before version 1.0.

## Self-hosted servers

Your server only needs to be on v1.49.0 or later, with the new database migration and the realtime server restarted. If you forward only selected paths in your proxy, a few more are needed. See [Desktop and mobile apps](../self-hosting/desktop-and-mobile-apps).

## Download

The download page is at `/download` on Brainitive Cloud. Builds appear there when the app is released.
