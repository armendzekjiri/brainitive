---
title: Booking pages
description: "Let clients book a time with you: Brainitive booking pages with meeting types, availability, buffers and Google Meet or Teams links."
order: 54
---

# Booking pages

Share a link and let people outside Brainitive pick a free time with you — no account and no back-and-forth emails.
Free times are your working hours minus what is already in your calendars.

## How it works

- A **booking page** has its own public link, for example `…/book/anna-consulting`. You can have as many pages as you
  need ("Clients", "Emergency", "Hiring").
- Each page has one or more **meeting types**, each with its own name, length, hours and questions — "Quick question,
  15 min" and "Project kickoff, 60 min", for example.
- Visitors pick a meeting type, a day and a free time, enter their name and email, and are booked. They see times in
  their own time zone.
- Only free times ever leave Brainitive — never what is in your calendar.

## Create a booking page

1. Click **Booking pages** in the sidebar.
2. Click **New booking page**.
3. Pick a **Template**. Templates fill in meeting types, hours, rules and questions — everything stays editable:
   Blank, Sales / intro call, Consulting, Support / help session, Job interview, Emergency, Office hours,
   Coaching / tutoring.
4. Optionally give it a **Title**, and choose the **Workspace**. The workspace is shown as who runs the page, and
   cards for bookings go to its boards.
5. Click **Create**.

The page opens with three tabs: **Meeting types**, **Settings** and **Bookings**. **Copy link** copies the public link;
**Open** shows the page as visitors see it.

## Meeting types

On the **Meeting types** tab you see every type with its length, location and how many days a week it is bookable.

- **Add meeting type** – name it and pick a length, or start from a template.
- **Edit** – change everything (below).
- **Bookable** – untick to hide a type without deleting it.
- Arrows change the order; other buttons copy a direct link to the type, duplicate it or delete it.

### Edit a meeting type

| Setting | What it does |
|---|---|
| **Name**, **Link ending**, **Length** | What visitors see and the end of the type's own link. Length is any number of minutes. |
| **Description (optional)** | A short text on the page. |
| **Add bookings to calendar** | The calendar a booking goes into. The guest is invited from this calendar. Choose **Don't add to a calendar** to skip it. |
| **Where** | No location, **Google Meet**, **Microsoft Teams**, **Phone call**, **In person** or **Online (your link)**. Meet and Teams need a matching Google or Outlook calendar above; a fresh call link is created for every booking. |
| **Weekly hours** | For each day, one or more time ranges (**Add hours** for a second range, e.g. after a lunch break). Days without hours are **Not bookable**. |
| **Date overrides** | Holidays, days off or extra hours on one date. Pick a date and click **Add day off**, or **Set hours instead** to give that day different hours. |
| **Rules** | **Start times every (min)**, **Free before (min)** and **Free after (min)** (buffers), **Minimum notice (min)**, **Bookable up to (days ahead)** and **Max bookings a day**. |
| **Questions** | Extra questions: Short text, Long text, Phone or Choice, each optionally **Required**. Name and email are always asked. |

Under **Next free times**, click **Check (saved settings)** to see the next free slots for the saved version of the type.

## Settings: what blocks your time

On the **Settings** tab, **What blocks this page's time** decides which busy times are taken out of your hours:

- **All my calendars (also ones I connect later)**, or only the calendars you tick.
- **Issues assigned to me with a due time**.
- **My tasks with a time**.
- **All-day events block the whole day**.

Bookings on **any** of your booking pages always block, so you are never booked twice. Use the per-page choice to, for
example, leave a personal calendar out of an emergency page.

::: warning
If a calendar can't be read, the page keeps offering times without it and shows a warning: bookings may overlap that
calendar's events. You also get a notification. Reconnect the calendar under **Calendar → Add calendar → Manage connected**.
:::

### Other settings

- **Title**, **Address** (the part after `…/book/`), **Intro (optional)**.
- **Time zone of your hours** – the zone your weekly hours are in.
- **Page is on (people can book)** – turn the page off without deleting it.
- **Where bookings show up** – bookings always appear on the **Bookings** tab, in your Brainitive calendar and in
  the calendar the meeting type adds them to. Under **Also create a card** you can pick a board and **Column** to get
  a card for every booking.
- **Consent** – a consent checkbox that is **Off**, **Optional** or **Required**, with your own text.
  `{organization}` is replaced with your workspace's name.
- **Embed on your website** – copy the iframe code to show the page on your own site.
- **Delete page** – upcoming bookings are cancelled and the guests are told.

Click **Save settings** when you're done.

## When someone books

- The meeting lands in the calendar you picked, with the guest invited (and a Meet or Teams link if you chose one).
- It shows in your Brainitive calendar and on the page's **Bookings** tab, and as a card if you set that up.
- You get a notification (category **Bookings** in your [notification settings](./notifications)).
- The guest gets a confirmation email with a calendar file and a link to reschedule or cancel, and a reminder about
  a day before.

## Manage bookings

The **Bookings** tab lists **Upcoming**, **Past** and **Cancelled** bookings with the guest's answers. Upcoming bookings
have **Join** (when there is a call) and **Cancel**. When you cancel, you can add a message to the guest; they are told
by email.

## What your guests can do

Guests open the link in their confirmation email (**Reschedule or cancel**). As long as the booking hasn't started they
can:

- **Reschedule** – pick a new free time and confirm.
- **Cancel booking** – optionally with a reason.

You're notified either way.

## Related

- [Calendar](./calendar-and-tasks) – connect the calendars that block your time.
- [Meetings](./meetings) – schedule a call yourself from an issue or doc.
