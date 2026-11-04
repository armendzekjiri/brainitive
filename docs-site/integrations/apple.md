---
title: Apple
description: Set up Sign in with Apple in Brainitive — Services ID, Sign in with Apple key, domains and return URL, and the APPLE_* environment variables.
order: 25
---

# Apple

People can sign in or sign up with their Apple ID. The **Sign in with Apple** button is on the login page, so it
also shows when the desktop and mobile apps open the sign-in page in the browser (`/app/authorize`).

## What you get

| Feature | What it does | Where |
|---|---|---|
| **Sign in with Apple** | Sign in or sign up with an Apple ID | Login page, homepage, the apps' sign-in page |

How accounts are matched:

- Signing in with Apple the first time links the Apple ID to the Brainitive account with the **same email
  address**. Afterwards the Apple ID signs in even if the email changes.
- With **Hide My Email**, Apple gives Brainitive a private relay address (`…@privaterelay.appleid.com`).
  Brainitive treats it like any verified email: a new account is created with that address, and Apple forwards
  the mail to the real inbox. Such an account isn't linked to an existing account that uses the real address.
  Sign in with your password first if you want that.
- Apple shares your name only the first time you sign in with Apple. If you didn't share it, change it later in
  **Profile**.

## Self-hosting: set up Sign in with Apple

You need a paid [Apple Developer Program](https://developer.apple.com/programs/) membership, and Brainitive must
run on a public `https://` domain. Apple doesn't accept `localhost` or IP addresses as return URLs.

Brainitive hides the Apple button until `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID` and
`APPLE_PRIVATE_KEY` are all set.

All the steps below are in [Certificates, Identifiers & Profiles](https://developer.apple.com/account/resources).

### 1. App ID (primary)

Sign in with Apple belongs to an App ID. If you have an iOS app, use its App ID. If not, go to **Identifiers → +
→ App IDs → App**, enter a description and a bundle ID such as `com.example.brainitive`, and tick **Sign in with
Apple** under **Capabilities**.

### 2. Services ID (the web client)

Go to **Identifiers → + → Services IDs**:

- **Description:** for example "Brainitive". People see it on Apple's consent screen.
- **Identifier:** for example `com.example.brainitive.web`. This is your `APPLE_CLIENT_ID`. It must differ from
  the App ID.

Open the new Services ID, tick **Sign in with Apple** and click **Configure**:

- **Primary App ID:** the App ID from step 1.
- **Domains and Subdomains:** your Brainitive host without `https://`, for example `brainitive.example.com`.
- **Return URLs:** your `APP_URL` followed by `/login/apple/callback`:

```text
https://brainitive.example.com/login/apple/callback
```

Save, then **Continue → Save** the Services ID.

### 3. Sign in with Apple key

Go to **Keys → +**, enter a name, tick **Sign in with Apple**, click **Configure** and pick the primary App ID
from step 1. Register the key and **download the `.p8` file**. Apple lets you download it only once.

- The **Key ID** shown next to the key is your `APPLE_KEY_ID`.
- Your **Team ID** is in the top right of the developer website, or under **Membership details**. This is your
  `APPLE_TEAM_ID`.

Brainitive signs a short-lived client secret with this key whenever someone signs in, so there is no secret
that expires every six months.

### 4. Environment variables

```ini
APPLE_CLIENT_ID=com.example.brainitive.web
APPLE_TEAM_ID=ABCDE12345
APPLE_KEY_ID=XYZ9876543
# the path to the .p8 file (readable by the web server)...
APPLE_PRIVATE_KEY=/etc/brainitive/AuthKey_XYZ9876543.p8
# ...or the key itself on one line, with \n for the line breaks
# APPLE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIGT...\n-----END PRIVATE KEY-----"
# Optional — the default is APP_URL + /login/apple/callback
# APPLE_REDIRECT_URI="${APP_URL}/login/apple/callback"
```

Restart Brainitive afterwards (with Docker: `docker compose up -d`). See also
[Configuration](/self-hosting/configuration).

### 5. Email to private relay addresses

Apple only forwards mail to `@privaterelay.appleid.com` addresses from senders you register. Under
**Services → Sign in with Apple for Email Communication → Configure**, add the domain of your `MAIL_FROM_ADDRESS`
(or the address itself). The domain needs SPF, and Apple recommends DKIM. Without this, people who hid their email
don't get notifications or password reset emails. See [Email](/self-hosting/email).

## Troubleshooting

| Message | What to do |
|---|---|
| Apple shows `invalid_client` | `APPLE_CLIENT_ID` must be the **Services ID**, not the App ID. Check that the Services ID has Sign in with Apple turned on. |
| Apple shows `invalid_request` / "Invalid web redirect url" | Add the exact return URL (step 2) and the domain. Check that `APP_URL` is your public `https://` address. |
| "Apple didn't accept the sign-in…" | Check `APPLE_TEAM_ID` and `APPLE_KEY_ID`, and that the key belongs to the same team and App ID. |
| "Sign in with Apple isn't set up correctly on this server…" | The `.p8` key can't be read or isn't a valid key. Check `APPLE_PRIVATE_KEY`. |
| "We couldn't confirm the email address of this Apple ID…" | Apple didn't send a verified email. Try again, and share or hide your email when Apple asks. |
| "Sign-up is by invitation only…" | The instance is invite-only. Ask a workspace admin to invite that address. With Hide My Email, invite the relay address. |
