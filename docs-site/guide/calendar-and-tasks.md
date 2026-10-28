---
title: Calendar
description: "See due dates, list tasks, bookings and your Google, Outlook or iCloud calendars in one Brainitive calendar, and subscribe to it in other apps."
order: 50
---

# Calendar

The Brainitive calendar puts your issue due dates, list tasks and bookings next to the meetings in your own
calendars — Google, Outlook, iCloud or any calendar link — and lets you subscribe to your Brainitive dates in other apps.

## Open the calendar

Click **Calendar** in the sidebar. It shows everything you have access to:

| Source | What shows up |
|---|---|
| **Board** | Board issues with a start or due date |
| **Lists** | Tasks from task lists that have a date |
| **Calendars** | Events (and Google Tasks) from the calendars you connected |
| **Bookings** | Bookings people made on your [booking pages](./booking-pages) |

Use the arrows and **Today** to move around, and switch between **Month**, **Week** and **Day** at the top right.
Brainitive remembers the view you used last.

### Filter what you see

Click **Filter** to narrow the calendar down:

- **Assigned to me only** – hide work that belongs to others.
- **Show completed** – show or hide finished issues and tasks.
- **Source** – pick Board, Lists, Calendars and/or Bookings.
- **Connected calendars** – turn single calendars on or off.
- **Projects** – show only some projects.

**Clear all** resets the filters.

### Move things around

Drag an issue, task or event to another day or time to reschedule it. In the week and day views you can also
drag the top or bottom edge of an item to change when it starts or ends.

Some items can't be moved: events from read-only calendars, and single occurrences of a repeating event.

### Create something from the calendar

Click an empty spot (a day, or a time in the week and day views) to open **Quick create**. Choose what to make:

- **Board** – a new issue on a board in one of your projects.
- **List** – a new task in a task list.
- **Calendar** – an **Event** in one of your connected calendars, or a **Task** in Google Tasks.

Give it a title, check the date and time, and click **Create**. For events in a Google or Outlook calendar you can
also add a video call and guests — see [Meetings](./meetings#from-the-calendar).

## Connect your own calendars

Click **Add calendar** at the top of the calendar and pick a provider:

| Provider | How you connect | Can you edit events? |
|---|---|---|
| **Google Calendar** | Sign in with Google, then choose calendars and task lists | Yes |
| **Outlook / Microsoft 365** | Sign in with Microsoft, then choose calendars | Yes |
| **Apple iCloud / CalDAV** | Enter the server URL, your username or Apple ID and an app password | Yes |
| **Calendar link (ICS)** | Paste any calendar's ICS link | No, read-only |

After you sign in, Brainitive asks **Choose what to add**. Tick the calendars (and, for Google, the task lists) you want
to see and confirm.

::: tip
For iCloud, create an *app-specific password* in your Apple account settings and use that, not your normal
password. CalDAV also works with Fastmail, Nextcloud and other providers.
:::

Your connected calendars are personal: only you see them. If your Brainitive is self-hosted and a provider is missing
from the list, your administrator still has to set it up — see [Integrations](/integrations/).

### Edit events and Google Tasks

Click an event to open it. You can change the title, location, description, start and end, and click **Save**.
**Join** opens the video call when the event has one, and **Copy link** copies the call link. Changes are saved in the
original calendar, so they show up on your phone and everywhere else too.

Google Tasks open in **Edit Google task**: change the title, notes, due date and time. Changes are saved straight to
Google Tasks.

### Manage or disconnect calendars

Open **Add calendar → Manage connected** to see every calendar you connected, which provider and account it belongs
to, and whether it is read-only. Click the bin icon to disconnect one; its events disappear from Brainitive (nothing is
deleted in the calendar itself).

If a calendar can't be loaded (for example, after you changed your password at the provider), Brainitive shows a
warning instead of hiding it. Disconnect it and connect it again to fix it.

## Subscribe to your Brainitive dates in other apps

You can also go the other way and see your Brainitive work in Apple Calendar, Google Calendar or Outlook.

1. In the calendar, click **Subscribe**.
2. Choose **What's included**:
   - **Issues assigned to me** and **Issues I created** (start and due dates)
   - **List tasks** (repeating tasks repeat)
   - **My Day** (what you planned, on the day you planned it)
   - **Focus sessions** (time logged with the focus timer)
   - **Include completed work** and **Include descriptions**
3. Optionally tick **Projects** to limit the feed to some projects.
4. Click **Create calendar link**, then **Copy** the link into your calendar app, or use **Open in Apple Calendar**.

The link is private: anyone who has it can see the included items. If it leaks, **reset** it — calendars that use the old
link stop updating and you subscribe again with the new one. You can also turn the feed off completely.

::: tip
Calendar apps refresh subscribed calendars on their own schedule, often only every few hours. Changes in Brainitive
can take a while to show up there.
:::

## Task lists and reminders

[Task lists](./task-lists) are to-do lists inside a project, with due dates, repeats and reminders. Tasks with a date
show up in the calendar under the **Lists** source, and you can drag them to another day like any other item.

Reminders arrive in your notification bell and as push notifications, and every morning at 8:00 you can get a summary of
the list tasks that are due today or overdue. Turn the summary off under
**Profile settings → Notifications → Morning task summary**.

## Related

- [Meetings](./meetings) – schedule Google Meet or Teams calls.
- [Booking pages](./booking-pages) – let people book free time in your calendar.
- [Task lists](./task-lists) – to-do lists with dates, repeats and reminders.
- [My Work, My Day and quick capture](./my-work) – everything assigned to you, and My Day.
- [Integrations](/integrations/) – set up Google and Microsoft on a self-hosted instance.
