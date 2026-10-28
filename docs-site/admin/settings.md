---
title: Instance settings
description: "Configure a self-hosted Brainitive instance: open or invite-only sign-up, required email verification, your own SMTP server and a test email."
order: 20
---

# Instance settings

**Instance admin → Settings** controls who can create an account and how Brainitive sends email. Click **Save settings** at the bottom after a change.

## Sign-up & accounts

### Anyone can sign up

- **On** (default): anyone who can reach your installation can register.
- **Off**: invite-only. New accounts can only be created by:
  - people with a pending workspace invitation for that email address, or
  - you, with **Users → Add user** (see [Users](./users#add-a-user)).

The rule also applies to sign-up with Google, GitHub and Microsoft. People who already have an account can always sign in.

### Require email verification

- **On** (default): new accounts must confirm their email address before they can use the app.
- **Off**: people can start right away.

Verification needs working email. If mail isn't set up yet, either turn this off for now or verify accounts by hand (**Users → ⋯ → Mark email as verified**).

::: tip
Invited people join their workspace once their email address is verified, whatever this setting says.
:::

## Email delivery (SMTP)

Brainitive sends password resets, invitations, verification emails and notifications. By default it uses the mail settings in the server's `.env` file (`MAIL_MAILER`, `MAIL_HOST`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` …, see [Configuration](/self-hosting/configuration)). The card shows which mailer is in use right now.

To send through your own SMTP server from the console instead:

1. Turn on **Send email through this SMTP server**. This overrides the `.env` mail settings.
2. Fill in:

   | Field | Example |
   |---|---|
   | **Host** | `smtp.example.com` |
   | **Port** | `587` |
   | **Encryption** | **STARTTLS (587)**, **SSL/TLS (465)** or **None** |
   | **Username** | your SMTP user |
   | **Password** | stored encrypted and never shown again |
   | **From address** | `noreply@example.com` |
   | **From name** | the sender name people see, e.g. `Brainitive` |

3. Click **Save settings**.

To change other fields later without retyping the password, leave **Password** empty: the saved one is kept. Tick **Remove the saved password** to delete it.

Turning the switch off brings back the `.env` settings. Your entries stay saved.

## Send a test email

The **Send a test email** card sends a test message with the saved settings. It's addressed to you by default; enter another address if you like and click **Send test**. Errors from the mail server show right away, so this is the quickest way to check host, port and password.

To see what every email looks like, use [Emails](./emails).

Every change on this page is recorded in the [Audit log](./audit-log).
