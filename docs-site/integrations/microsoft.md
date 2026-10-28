---
title: Microsoft
description: Set up Sign in with Microsoft, Outlook calendar and Teams meetings in Brainitive — Entra app, redirect URIs, permissions and env vars.
order: 20
---

# Microsoft

Brainitive supports personal Microsoft accounts (Outlook.com, Hotmail, Live) and work or school accounts
(Microsoft 365 / Entra ID). You can sign in with them, connect Outlook calendars and add Microsoft Teams links to
meetings. On a self-hosted instance, **one** Microsoft Entra app registration covers all three.

## What you get

| Feature | What it does | Where |
|---|---|---|
| **Continue with Microsoft** | Sign in or sign up with a Microsoft account. | Login page |
| **Outlook / Microsoft 365 calendar** | Shows the calendars you pick in Brainitive. You can create, edit, move and delete events, and they are saved in Outlook. | **Sidebar → Calendar → Add calendar → Outlook / Microsoft 365** |
| **Microsoft Teams meetings** | Schedule a meeting with a Teams link from an issue, a doc, the calendar or a booking page. | See [Meetings](./meetings) |

::: info Teams needs a work or school account
Brainitive only offers Teams when the connected calendar allows Teams meetings, which in practice means a work or
school account. Personal Outlook.com accounts usually show "Teams isn't available for this account." Their
calendar still works as usual.
:::

## Connect your Outlook calendar

1. Open **Sidebar → Calendar** and click **Add calendar → Outlook / Microsoft 365**.
2. Choose your Microsoft account and accept the permissions.
3. Back in Brainitive, pick the calendars you want to show.

If Microsoft stops accepting the connection (for example after a password change or a revoked consent), the
calendar shows "Outlook access expired. Reconnect Outlook." Click **Add calendar → Outlook / Microsoft 365** again
to fix it.

## Self-hosting: register the Entra app

Brainitive hides the Microsoft button and the Outlook calendar option until both `MICROSOFT_CLIENT_ID` and
`MICROSOFT_CLIENT_SECRET` are set.

### 1. New registration

In the [Microsoft Entra admin center](https://entra.microsoft.com), go to **Identity → Applications → App
registrations → New registration**.

- **Name:** for example "Brainitive".
- **Supported account types:** choose **Accounts in any organizational directory (Any Microsoft Entra ID tenant -
  Multitenant) and personal Microsoft accounts**. This matches the default `MICROSOFT_TENANT=common`.
  If you only want people from your own organisation, choose **Accounts in this organizational directory only**
  and set `MICROSOFT_TENANT` to your tenant ID (see below).
- **Redirect URI:** platform **Web** (not SPA), with the first URI from the next step.

### 2. Redirect URIs (platform: Web)

Under **Authentication**, add both, using your own `APP_URL`:

```text
https://brainitive.example.com/login/microsoft/callback
https://brainitive.example.com/calendar/connections/outlook/callback
```

Entra only accepts `http://` for `localhost`. Leave **Front-channel logout URL** empty, because Brainitive
doesn't use it. Leave the implicit grant and hybrid flow boxes unticked.

### 3. Client secret

Go to **Certificates & secrets → New client secret**. Copy the **Value** (not the Secret ID) into
`MICROSOFT_CLIENT_SECRET`. Secrets expire, so set a reminder to replace yours before the date shown.

### 4. API permissions

Under **API permissions → Add a permission → Microsoft Graph → Delegated permissions**, add:

| Permission | Used for |
|---|---|
| `openid`, `profile`, `email` | Sign in |
| `User.Read` | Sign in and calendar (the basic "sign in and read profile" permission) |
| `offline_access` | Keeping the calendar connected (refresh token) |
| `Calendars.ReadWrite` | Reading and writing calendar events, including Teams meetings |

These don't need admin consent, because each user consents for themselves. Some organisations require admin
consent through their own policy. Teams meetings are created as calendar events, so you don't need any
`OnlineMeetings.*` permission.

### 5. Optional claims (needed for work and school sign-up)

Under **Token configuration → Add optional claim → Token type: ID**, tick **`email`**, **`xms_edov`** and
**`acct`**. If Entra asks to turn on the Microsoft Graph email permission, accept.

Brainitive only trusts the email address of a work or school account when Microsoft confirms that the
organisation owns its domain (`xms_edov`). Without these claims, work and school accounts can't create an account
or link to an existing one by email. They can only sign in once their account is already linked. Personal
Microsoft accounts work either way.

### 6. Environment variables

Copy the **Application (client) ID** from **Overview**, then set:

```ini
MICROSOFT_CLIENT_ID=00000000-0000-0000-0000-000000000000
MICROSOFT_CLIENT_SECRET=your-client-secret-value
# common = personal + any work/school account (default); or your tenant ID for one organisation only
MICROSOFT_TENANT=common
# Optional — the defaults are APP_URL + the paths above
# MICROSOFT_LOGIN_REDIRECT_URI="${APP_URL}/login/microsoft/callback"
# MICROSOFT_REDIRECT_URI="${APP_URL}/calendar/connections/outlook/callback"
```

If `MICROSOFT_TENANT` is a tenant ID (a GUID), Brainitive also refuses sign-ins from any other tenant.

Restart Brainitive afterwards (with Docker: `docker compose up -d`). See also [Configuration](/self-hosting/configuration).

### 7. Optional branding

Under **Branding & properties** you can add a logo, homepage and terms / privacy links. Work accounts see an
"unverified" label on the consent screen until you complete **publisher verification**, which needs a Microsoft
Partner Network ID.

## Troubleshooting

| Message | What to do |
|---|---|
| `AADSTS50011` redirect URI mismatch | Add the exact callback URI under **Authentication** (platform **Web**). Check that `APP_URL` is your public `https://` address. |
| "We couldn't confirm the email address of this Microsoft account…" | Add the `email` and `xms_edov` optional claims (step 5), or sign in with email and password first. |
| "Teams isn't available for this account." | The calendar (usually a personal account) doesn't allow Teams meetings. Use a work or school account. |
| "Sign-up is by invitation only…" | The instance is invite-only. Ask a workspace admin to invite that address. |
