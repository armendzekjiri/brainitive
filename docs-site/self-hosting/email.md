---
title: Email
description: Set up email for self-hosted Brainitive - SMTP settings, sender address, which emails are sent, testing with admin previews, SPF and DKIM.
order: 50
---

# Email

Brainitive sends invitations, password resets, notifications and helpdesk replies by email. This page shows how to connect an SMTP server and check that mail arrives.

## Two places to set SMTP

You can set the mail server in either place:

1. **In `.env`** with the `MAIL_*` variables (below).
2. **In Instance admin → Settings → Email delivery (SMTP)**. Turn on **Send email through this SMTP server** and fill in host, port, encryption, username, password, from address and from name. The password is stored encrypted with `APP_KEY`.

SMTP saved in the admin console **overrides** `.env`. Turning it off there goes back to the `.env` values.

## SMTP in `.env`

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=brainitive@example.com
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=brainitive@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

- **Port 587** uses STARTTLS. Leave `MAIL_SCHEME` empty.
- **Port 465** uses SSL/TLS from the start. Add `MAIL_SCHEME=smtps`.
- `MAIL_FROM_ADDRESS` should be an address your SMTP server is allowed to send from.

All mail variables are listed in [Configuration](./configuration#mail).

::: tip Before you have SMTP
Keep `MAIL_MAILER=log`. Emails are then written to the log instead of being sent, so sign-up and password resets don't fail. In Docker, read them with `docker compose logs app queue`. Make yourself admin with `php artisan make:instance-admin`, which also verifies your email.
:::

## Emails go through the queue

Most emails are sent by the queue worker in the background. If the worker isn't running, nothing is sent. See [Queue worker and scheduler](./queue-and-scheduler).

## Which emails Brainitive sends

**Instance admin → Emails** lists every email with sample data, in light and dark mode, as HTML or text, at desktop or phone width, and can send a test to your own address. The emails come in three groups:

- **Member notifications**: assigned issues and tasks, comments and mentions, issue reminders and the daily task digest, sprints started and completed, page updates and review requests, helpdesk tickets and SLA warnings, new leads and bookings, export ready, automation notices, disabled webhooks and failed GitHub syncs. Members choose which ones they get in their notification settings.
- **Customers, clients and visitors**: helpdesk ticket confirmations, replies and resolutions, booking confirmations, changes and reminders, lead form confirmations, portal sign-in links, client portal invitations and deliverables, feedback status changes, billing reports.
- **Account and admin**: verify email, reset password, workspace invitation, "Download my data" ready, and the admin test email.

The scheduler triggers some of them (reminders, the daily digest, booking reminders, SLA warnings), so it must run too.

## Test your setup

1. Open **Instance admin → Settings** and use **Send a test email**. It uses the saved settings, and errors from the mail server are shown right away.
2. Open **Instance admin → Emails**, pick an email and send it to yourself to see how it looks in your mail client.
3. **Instance admin → Overview** shows the mail status and failed background jobs.

More about the console in the [Instance admin guide](/admin/).

## Deliverability: SPF, DKIM and DMARC

To keep Brainitive's mail out of spam folders, the domain of `MAIL_FROM_ADDRESS` needs DNS records that allow your mail provider to send for it:

- **SPF**: a TXT record listing the servers allowed to send for your domain. Your mail provider tells you what to add.
- **DKIM**: a key your provider uses to sign each message. Add the DNS record your provider gives you.
- **DMARC**: a TXT record at `_dmarc.your-domain` that tells receivers what to do with mail that fails SPF or DKIM. Start with `p=none` and tighten it later.

Use a sender address on a domain you control, not a free mailbox address.

## Helpdesk email

Helpdesks can receive email too, either through a forwarding address (an inbound mail provider such as Postmark or Mailgun, set with `INBOUND_MAIL_*`) or by connecting a mailbox over IMAP in the helpdesk's settings. A connected mailbox is checked every minute by the scheduler. The variables are listed in [Configuration](./configuration#email-to-helpdesk).
