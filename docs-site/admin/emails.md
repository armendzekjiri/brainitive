---
title: Emails
description: "Brainitive's branded email layout, previews of every email in Admin → Emails, test sends, and how to set the sender name with MAIL_FROM_NAME."
order: 30
---

# Emails

Every email Brainitive sends uses one branded layout. Instance admins can preview each one in **Instance admin → Emails** and send a test to themselves.

## The branded layout

All emails share the same look:

- the Brainitive logo on a clean card, with a **dark mode** version for mail apps that use dark mode,
- a layout that works on phones,
- a footer that says why the person got the email. Members also get a **Manage notifications** link to their notification settings.

Emails to your helpdesk's customers are signed with your helpdesk's name (or the sender name of its connected mailbox), not "Brainitive".

If your installation has its own name in `APP_NAME` (for example `APP_NAME="Acme Work"`), the footer shows it next to Brainitive: "Brainitive — Acme Work". Default names such as "Laravel" are never shown.

Links in emails always point to your `APP_URL`, never to whatever address a request came from. Set `APP_URL` correctly, e.g. `https://brainitive.example.com`.

## Preview every email

Open **Instance admin → Emails**. The list on the left groups every email Brainitive can send:

| Group | Examples |
|---|---|
| **Member notifications** | Issue commented, issue moved, review comment, booking cancelled, webhook disabled … |
| **Customers, clients & visitors** | Ticket received, ticket reply, booking confirmed, lead confirmation, portal sign-in link, billing report / invoice … |
| **Account & admin** | Verify email, reset password, workspace invitation, "Your data is ready", admin test email |

Click an email to see it with sample data (made-up names like "Northwind Studio"; nothing is saved). Above the preview you can switch:

- **Format:** **HTML** or **Text** (the plain-text version mail apps fall back to),
- **Color scheme:** **Light** or **Dark**,
- **Width:** **Desktop** or **Phone**.

### Send a test to yourself

Click **Send test to me** to receive the selected email at your own address. The subject starts with `[Test]`. You can send up to five tests a minute. Each test send is recorded in the [Audit log](./audit-log).

Test sends use your current mail settings, so they also show whether delivery works. To test the SMTP connection itself, use **Settings → Send a test email** ([Instance settings](./settings#send-a-test-email)).

## Sender name and address

The "From" line of every email comes from one of two places:

1. **Instance admin → Settings → Email delivery (SMTP)**, when **Send email through this SMTP server** is on: the **From address** and **From name** fields.
2. Otherwise the server's `.env`:

   ```dotenv
   MAIL_FROM_ADDRESS=noreply@brainitive.example.com
   MAIL_FROM_NAME="Brainitive"
   ```

::: tip Set a real sender name
The example `.env` files set `MAIL_FROM_NAME="${APP_NAME}"`. The Docker example uses `APP_NAME=Brainitive`; the one for installs from source uses the framework default `Laravel`, so your emails would arrive "from Laravel". Set `MAIL_FROM_NAME` (or **From name** in the console) to the name people should see, for example `Brainitive` or your company name. After changing `.env`, restart the containers (`docker compose up -d`).
:::

Helpdesk replies sent through a connected mailbox use that mailbox's own sender name.
