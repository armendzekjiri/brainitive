---
title: Google
description: Set up Sign in with Google, Google Calendar, Google Tasks and Google Meet in Brainitive — OAuth client, scopes, redirect URIs and env vars.
order: 10
---

# Google

Brainitive works with your Google account in four ways: you can sign in with it, show and edit your Google
calendars, manage Google Tasks, and add Google Meet links to meetings. On Brainitive Cloud everything is ready
to use. On a self-hosted instance you need one Google OAuth client first.

## What you get

| Feature | What it does | Where |
|---|---|---|
| **Continue with Google** | Sign in or sign up with your Google account. Brainitive only accepts an address that Google has verified. | Login page |
| **Google Calendar** | Shows the calendars you pick next to your Brainitive dates. You can create, edit, move and delete events, and the changes are saved in Google. | **Sidebar → Calendar → Add calendar → Google Calendar** |
| **Google Tasks** | Your Google task lists show up in the calendar. You can create, edit and complete tasks, and they are saved straight to Google Tasks. | Same connection as the calendar |
| **Google Meet** | Schedule a meeting with a Meet link from an issue, a doc, the calendar or a booking page. | See [Meetings](./meetings) |

## Connect your Google Calendar and Tasks

1. Open **Sidebar → Calendar** and click **Add calendar → Google Calendar**.
2. Pick the Google account you want to connect. It doesn't have to be the one you signed in with.
3. Allow access on Google's consent screen.
4. Back in Brainitive, choose which **calendars** and **task lists** to show, then save.

Each calendar and task list becomes a separate connection. To remove one, open **Add calendar → Manage
connected** and click the trash icon. You can connect several Google accounts.

::: tip Read-only alternative
If you only want to *see* a Google calendar, you can add its secret iCal address as a **Calendar link (ICS)**
instead, with no OAuth needed. See [Calendars](./calendars#calendar-link-ics).
:::

## Self-hosting: create the Google OAuth client

All Google features use **one** OAuth client. Sign-in works with just the client; Calendar and Tasks also need
two APIs turned on.

### 1. Create a project and enable the APIs

1. Open the [Google Cloud console](https://console.cloud.google.com/) and create a project (for example
   "Brainitive").
2. Under **APIs & Services → Library**, enable:
   - **Google Calendar API** (calendars, events and Meet links)
   - **Google Tasks API** (task lists)

   If you only want **Continue with Google**, you can skip this step.

### 2. Configure the OAuth consent screen

In **Google Auth Platform** (called **APIs & Services → OAuth consent screen** in older consoles):

1. **Audience / user type:** choose **External** so any Google account can use it. A Google Workspace
   organisation that only wants its own members can choose **Internal**.
2. **Branding:** set the app name, a support email and your domain (for example `brainitive.example.com`) as an
   authorized domain.
3. **Data access / scopes:** add the scopes Brainitive requests:

| Used for | Scopes |
|---|---|
| Sign in with Google | `openid`, `email`, `profile` |
| Google Calendar + Tasks connection | `https://www.googleapis.com/auth/calendar`, `https://www.googleapis.com/auth/calendar.events`, `https://www.googleapis.com/auth/tasks`, plus `openid`, `email`, `profile` |

The calendar connection also asks for offline access, which gives Brainitive a refresh token, so the connection
keeps working after the first hour.

### 3. Create the OAuth client ID

1. Go to **Clients** (or **APIs & Services → Credentials**) → **Create client** → **OAuth client ID**.
2. Application type: **Web application**.
3. Under **Authorized redirect URIs**, add both of these, using your own `APP_URL`:

```text
https://brainitive.example.com/login/google/callback
https://brainitive.example.com/calendar/connections/google/callback
```

4. Save, then copy the **Client ID** and **Client secret**.

You don't need any **Authorized JavaScript origins**.

### 4. Set the environment variables

```ini
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
# Optional: only changes the sign-in callback (default: APP_URL + /login/google/callback)
# GOOGLE_REDIRECT_URI="${APP_URL}/login/google/callback"
```

Restart Brainitive so it picks up the new values (with Docker: `docker compose up -d`). The **Continue with
Google** button appears as soon as `GOOGLE_CLIENT_ID` is set.

::: warning APP_URL must match
The calendar callback is always built from `APP_URL`, so `GOOGLE_REDIRECT_URI` doesn't change it. If `APP_URL`
isn't exactly the public address people use (`https://`, the same host), Google answers with
`redirect_uri_mismatch`. See [Configuration](/self-hosting/configuration).
:::

### 5. Publishing and verification

- **Sign-in only** (`openid`, `email`, `profile`) is non-sensitive. You can publish the app without Google's
  security review.
- **Calendar and Tasks** use *sensitive* scopes. An **External** app that is still in **Testing** only works for
  the test users you list, and Google expires their refresh tokens after 7 days, so people have to reconnect
  every week. To lift these limits, **publish** the app and complete Google's **verification**: a privacy
  policy, a homepage on your verified domain, and a short explanation of why you need each scope.
- An **Internal** app (Google Workspace, members of your organisation only) needs no verification.

## Troubleshooting

| Message | What to do |
|---|---|
| `redirect_uri_mismatch` (Google error page) | Add the exact callback URI from step 3. Check that `APP_URL` uses `https` and the public host. |
| "Google didn't return a refresh token. Try again." | Remove Brainitive under [Google Account → Security → Third-party connections](https://myaccount.google.com/connections) and connect again. |
| "Your Google email address is not verified…" | Verify the address with Google, or sign up with email and password. |
| "Sign-up is by invitation only…" | The instance has invite-only sign-up. Ask a workspace admin to invite that address. |
| Error 403 `access_denied` for some users | The app is in **Testing** and the user isn't a test user. Add them or publish the app. |
