---
title: Meetings
description: Schedule Google Meet and Microsoft Teams meetings from Brainitive issues, docs, the calendar and booking pages — what you need to connect first.
order: 50
---

# Meetings (Google Meet & Microsoft Teams)

You can schedule an online meeting from an issue, a doc, the calendar or a booking page. Brainitive creates the
event in **your own** calendar with a video link, and the calendar invite notifies the guests.

## What you need

A connected, writable calendar from one of these providers:

| Calendar | Video call |
|---|---|
| Google Calendar ([set up](./google)) | **Google Meet**, if your Google Workspace admin hasn't turned Meet off |
| Outlook / Microsoft 365 ([set up](./microsoft)) | **Microsoft Teams**, for work or school accounts only. Personal accounts show "Teams isn't available for this account." |

Connect the calendar under **Sidebar → Calendar → Add calendar**. If you have no suitable calendar, the dialog
tells you so and links to that page. iCloud / CalDAV calendars and ICS links can't add video calls.

## From an issue or a doc

- **Issue:** open the issue and click **Schedule meeting** in the sidebar.
- **Doc:** open the **⋯** menu and choose **Schedule meeting**.

In the dialog, set the title, date, start, duration, time zone and calendar. Then choose the video call
(**Google Meet**, **Teams** or none), the attendees (project members, or any email address) and a description,
which links back to the issue or doc.

Upcoming meetings appear on the issue (**Meetings**) or under the doc's title, with **Join**. From the meeting's
menu you can copy the link, open it in Google Calendar or Outlook, and **create meeting notes**. Notes are a doc
with Agenda, Notes and Action items, linked to the issue or doc. Afterwards, **Extract action items** turns the
notes into issues.

To remove a meeting, the organiser can **cancel the event** (it is deleted from the calendar and guests get the
cancellation) or **only unlink** it. Other people can only unlink.

## From the calendar

When you create an event in **Sidebar → Calendar**, pick a Google or Outlook calendar and turn on **Add Google
Meet** or **Add Teams meeting**, then add guests. A video call needs a start time. Events with a call show a video
icon, and the event dialog has **Join** and **Copy link**.

## Booking pages

On a booking page, set each meeting type's **Where** to **Google Meet** or **Microsoft Teams**, and choose a
calendar under **Add bookings to calendar**. When someone books, Brainitive creates the event in that calendar
with a video link and invites the guest. The calendar must be your own and must allow that kind of call.
Otherwise the event is created without a video link.

## Self-hosting

There is nothing extra to set up beyond the Google and Microsoft apps:

- **Google Meet** uses the Google Calendar API (no extra scope).
- **Teams** uses the `Calendars.ReadWrite` permission (no `OnlineMeetings.*` permission).
