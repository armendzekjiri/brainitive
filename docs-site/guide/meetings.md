---
title: Meetings
description: "Schedule a Google Meet or Microsoft Teams meeting from a Brainitive issue, doc or the calendar, invite people and keep the meeting notes one click away."
order: 52
---

# Meetings

Schedule a video call right from the work it is about. Brainitive creates the meeting in your own Google or Outlook
calendar, with a Google Meet or Microsoft Teams link, and keeps it linked to the issue or doc.

## Before you start

Meetings are created in **your own calendar**, so you need one connected that can add video calls:

- **Google Calendar** → Google Meet
- **Outlook / Microsoft 365** → Microsoft Teams

Connect it under **Calendar → Add calendar** (see [Calendar](./calendar-and-tasks#connect-your-own-calendars)).
Read-only calendar links and CalDAV calendars can't create video calls.

::: tip
Teams meetings need a work or school Microsoft 365 account with Teams. If Brainitive says
*"Teams isn't available for this account"*, your Microsoft account can't create Teams meetings.
:::

## From an issue

1. Open the issue.
2. Click **Meeting** next to **Link issue** (or **Schedule** in the **Meetings** section of the issue's sidebar).
3. Fill in the **Schedule meeting** dialog:
   - **Title** – starts with the issue key and title.
   - **Date**, **Start** and **Duration** (15, 30, 45, 60 or 90 minutes, or **Custom…** between 5 minutes and 12 hours).
   - **Time zone**.
   - **Calendar** – which of your calendars the event goes into.
   - **Video call** – Google Meet, Microsoft Teams or **No video call**.
   - **Attendees** – pick project members, and add other guests by email.
   - **Description** (optional).
4. Click **Schedule**.

The invitations go out from your calendar, like any meeting you create there. The meeting then shows in the issue's
**Meetings** section with a **Join** button, and the issue history notes that a meeting was scheduled.

You need edit rights on the issue to schedule a meeting.

## From a doc

Open the doc, click the **⋯** menu and choose **Schedule meeting**. The dialog is the same as for issues. Upcoming
meetings appear in a small strip under the doc's title, with **Join**.

## From the calendar

1. Click an empty spot in the calendar to open **Quick create**.
2. Choose **Calendar** → **Event** and pick a Google or Outlook calendar.
3. Set a date and a **time** — a video call needs a start time.
4. Click **Add Google Meet** or **Add Teams meeting**, and add **Guests** by email.
5. Click **Create**.

Events with a video call show a camera icon in the calendar; open the event and click **Join**.

## Meeting notes

Every meeting on an issue or doc has **Create meeting notes** (also in the meeting's **⋯** menu, next to **Copy link**
and **Open in** your calendar). It creates a doc called "Notes — …" for the meeting,
linked both ways, so the notes are one click away from the issue. Once it exists, the button opens it.

::: tip
From a notes doc you can use **⋯ → Extract action items** to turn the action items into issues. You review every
suggestion before anything is created.
:::

## Remove or cancel a meeting

Open the meeting's **⋯** menu on the issue or doc and choose **Remove…**. You get two options:

- **Only unlink** – the meeting disappears from the issue or doc; the event stays in the calendar.
- **Cancel event** – deletes the event in your calendar, and the attendees get a cancellation. Only the organiser
  can do this, and only while their calendar is still connected.

## Related

- [Booking pages](./booking-pages) – let others pick a time, with a Meet or Teams link added automatically.
- [Google](/integrations/google) and [Microsoft](/integrations/microsoft) – set up the providers on a self-hosted instance.
