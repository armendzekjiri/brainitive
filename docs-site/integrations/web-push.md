---
title: Web push notifications
sidebarTitle: Web push
description: Get Brainitive notifications on desktop and mobile with web push — register devices, send a test, iPhone setup, and VAPID keys for self-hosting.
order: 60
---

# Web push notifications

Web push sends Brainitive notifications to your desktop or phone, even when the app isn't open. This covers
mentions, assignments, ticket activity and upcoming calendar reminders. It works in modern browsers on Windows,
macOS, Linux and Android, and on iPhone / iPad from the Home Screen app.

## Turn it on

1. Open **your avatar → Profile settings → Notifications**.
2. Click **Register this device** and allow notifications when the browser asks.
3. Under **Your devices**, click **Send test** to check that a notification arrives.

Register each browser and device you use. The list shows every registered device, which one is **This device**,
and devices marked **Expired**. A device expires when its browser drops the subscription, for example after you
clear site data. You can **Remove** a device, or register it again. In the same section, you choose which
notifications reach you in the app, as a push, or by email.

::: tip iPhone and iPad
Push only works in the Home Screen app on iOS / iPadOS 16.4 or later. Tap **Share → Add to Home Screen**, open
Brainitive from the Home Screen, and turn notifications on there.
:::

### The test was delivered, but nothing showed up

"Delivered" means the browser's push service accepted the notification. If you don't see it, the device is hiding
it:

- **Windows:** turn off Focus / Do not disturb, and keep the browser running in the background.
- **macOS:** turn off Focus in Control Center. Check System Settings → Notifications for your browser.
- **iPhone / iPad:** go to Settings → Notifications → Brainitive, allow notifications, and turn Focus off.
- **Android:** turn off Do not disturb. Battery saver can delay notifications.

## Self-hosting: VAPID keys

Web push needs a VAPID key pair. Without one, no browser can subscribe, and the profile page says "Push
notifications aren't set up on the server yet." Your instance also has to be served over **HTTPS**.

1. Generate a key pair:

   ```bash
   # Docker: prints the keys, then copy them into .env
   docker compose exec app php artisan webpush:vapid --show

   # Without Docker: writes the two keys into .env directly
   php artisan webpush:vapid
   ```

2. Set the keys and a contact (the command doesn't write `VAPID_SUBJECT`):

   ```ini
   VAPID_SUBJECT=mailto:admin@example.com
   VAPID_PUBLIC_KEY=BExamplePublicKey...
   VAPID_PRIVATE_KEY=examplePrivateKey...
   ```

3. Restart Brainitive, including the queue worker (with Docker: `docker compose up -d`).

::: warning Keep the keys
Never change the keys on a running instance. Browsers that subscribed with the old keys stop receiving pushes
and have to register again.
:::

The server must be able to reach the browsers' push services (Google, Mozilla, Apple, Microsoft) over the
internet. If **Send test** says "The server could not reach the push service", outgoing connections are being
blocked.
