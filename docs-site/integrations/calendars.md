---
title: Calendars
description: Connect Google Calendar, Outlook, iCloud / CalDAV and ICS links to Brainitive, and subscribe to your Brainitive dates in any calendar app.
order: 40
---

# Calendars

The Brainitive calendar shows your issues, tasks and plans next to your external calendars. Calendars work in both
directions: you can **bring calendars in** (Google, Outlook, iCloud / CalDAV, ICS links) and **send your Brainitive
dates out** to Apple Calendar, Google Calendar or Outlook with a private feed.

## Bring calendars into Brainitive

Open **Sidebar → Calendar → Add calendar** and pick a provider:

| Provider | How you connect | Read / write | Extras |
|---|---|---|---|
| **Google Calendar** | Sign in with Google | Read + write | Google Tasks, Google Meet |
| **Outlook / Microsoft 365** | Sign in with Microsoft | Read + write | Microsoft Teams (work / school) |
| **Apple iCloud / CalDAV** | Server URL, username, app password | Read + write | |
| **Calendar link (ICS)** | Paste a link | Read only | |

After connecting, pick which calendars to show. **Add calendar → Manage connected** lists every connected
calendar, with a **Read-only** badge where it applies and any sync error, and lets you disconnect it.
Disconnecting only removes the events from Brainitive, never from the provider.

On writable calendars you can create events, open them to edit, drag them to another time, and delete them.
Events from read-only calendars can't be dragged.

### Google Calendar and Google Tasks

See [Google](./google). One connection brings in both your calendars and your task lists. Task changes are saved
straight to Google Tasks.

### Outlook / Microsoft 365

See [Microsoft](./microsoft). On a self-hosted instance, this option only shows up after an admin has set up the
Entra app.

### Apple iCloud / CalDAV

Use this for iCloud and any CalDAV server, such as Fastmail, Nextcloud, Zoho, Synology or Radicale.

1. **Add calendar → Apple iCloud / CalDAV**.
2. **Server URL:** leave it empty for iCloud (`https://caldav.icloud.com`). Other examples are
   `https://caldav.fastmail.com` and `https://<your cloud>/remote.php/dav` for Nextcloud.
3. **Username or Apple ID:** for example `name@icloud.com`.
4. **App password:** for iCloud, create an **app-specific password** at
   [appleid.apple.com](https://appleid.apple.com) → **Sign-In and Security**. Other servers need an app password,
   or your normal password if the server has no app passwords.
5. Pick the calendars to show.

The password is stored encrypted and only sent over `https` to the server you entered. If the password stops
working, the calendar shows "Check your username and app password."

### Calendar link (ICS)

This adds a read-only calendar from any iCal / ICS / `webcal://` link. For example, in Google Calendar go to
**Settings → your calendar → Secret address in iCal format**. Brainitive refreshes the link about every
15 minutes. To change a link, remove it and add the new one.

### Self-hosting notes for CalDAV and ICS

Brainitive's server fetches these URLs itself, so a few rules apply:

- The server must be on the **public internet**. Private and local addresses are refused.
- CalDAV needs `https`. Allowed ports are 80, 443 and the ports in `CALENDAR_ALLOWED_PORTS`.

```ini
# Extra ports a CalDAV server / ICS link may use (default: 8443, Synology 5001, Radicale 5232)
CALENDAR_ALLOWED_PORTS=8443,5001,5232
# Largest ICS file / CalDAV response read, in bytes (default 5 MB)
CALENDAR_MAX_RESPONSE_BYTES=5000000
# Minutes an ICS link is cached before it is fetched again (default 15)
CALENDAR_ICS_REFRESH_MINUTES=15
```

## Subscribe to Brainitive in other apps (calendar feed)

The calendar feed is a private link that puts your Brainitive dates into Apple Calendar, Google Calendar or Outlook
on your phone and desktop.

1. Open **Sidebar → Calendar** and click **Subscribe**.
2. Click **Create calendar link**.
3. Choose **What's included**: Issues assigned to me, Issues I created, List tasks, My Day, Focus sessions.
   You can also include completed work and descriptions, and limit the feed to some **Projects**.
4. Use **Open in Apple Calendar**, **Add to Google Calendar** or **Add to Outlook**, or **Copy** the link into any
   app that can subscribe to a calendar by URL.

The feed URL looks like `https://brainitive.example.com/calendar/feed/<token>.ics`. It only shows what you can
open in Brainitive, and covers the last 60 days and the next year.

::: warning Keep the link private
Anyone with the link can see these titles. Use **Reset link** if it leaks, or **Turn off** to stop the feed.
:::

Calendar apps refresh subscriptions on their own schedule. Apple Calendar and Outlook take 15 minutes to a few
hours, and Google Calendar can take several hours. Changes appear after the next refresh.

## Booking pages

Booking pages use your connected calendars to work out when you're free. They can also add each booking to one of
your calendars, with a Google Meet or Teams link. See [Meetings](./meetings#booking-pages).
