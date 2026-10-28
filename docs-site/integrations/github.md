---
title: GitHub
description: Sign in with GitHub and link branches, commits, pull requests and deployments to Brainitive issues with the GitHub App — setup for admins and projects.
order: 30
---

# GitHub

Brainitive connects to GitHub in two separate ways. **Sign in with GitHub** is a GitHub OAuth app for logging in.
The **GitHub App** shows branches, commits, pull requests and deployments on the issues they mention. You can set
up either one without the other.

## Sign in with GitHub

On the login page, **Continue with GitHub** signs you in, or creates an account if sign-up is open or you have an
invitation. Brainitive needs your email address from GitHub. If the sign-in fails, add a public email to your
GitHub profile, or allow email access when GitHub asks.

### Self-hosting: create the OAuth app

1. On GitHub, go to **Settings → Developer settings → OAuth Apps → New OAuth App**. For an organisation, use the
   organisation's settings instead.
2. **Homepage URL:** `https://brainitive.example.com`
3. **Authorization callback URL:**

   ```text
   https://brainitive.example.com/login/github/callback
   ```

4. Register the app, then click **Generate a new client secret**.
5. Set:

   ```ini
   GITHUB_CLIENT_ID=your-client-id
   GITHUB_CLIENT_SECRET=your-client-secret
   # Optional (default: APP_URL + /login/github/callback)
   # GITHUB_REDIRECT_URI="${APP_URL}/login/github/callback"
   ```

Brainitive asks for the `read:user` and `user:email` scopes. The button appears once `GITHUB_CLIENT_ID` is set.

## The GitHub App: development info on issues

Once a project is connected, Brainitive reads GitHub activity and links it to issues by their **issue key**. The
key can be in a commit message, a branch name or a pull request title, for example:

```text
fix(BRAI-123): handle empty titles
feat/brai-123-login
```

The issue's **Development** section then lists the branches, commits, pull requests and deployments. You can
also turn on **Merge transitions** for each board: when an issue's primary pull request is merged, the card moves
to the column you chose, and the history shows "GitHub". A card is never moved out of a done column.

The app only **reads**. It requests these repository permissions:

| Permission | Access | Why |
|---|---|---|
| Metadata | Read | Required by GitHub |
| Contents | Read | Commits on push |
| Issues | Read | |
| Pull requests | Read | PR status |
| Deployments | Read | Deployment status |

Events: **Push**, **Create**, **Delete**, **Pull request**, **Deployment status**. GitHub sends installation and
repository changes on its own.

### Instance admins: create the app in one click

Every Brainitive instance needs its **own** GitHub App, because the URLs are different for each instance. On
Brainitive Cloud this is already done for you.

Before you start: `APP_URL` must be the public `https://` address (GitHub calls it), and a queue worker must be
running, because GitHub events and refreshes are processed in the background.

1. Sign in as an instance admin and open **your avatar → Instance admin → GitHub App**.
2. **App name:** it must be unique on all of GitHub, for example "Brainitive Example Corp".
3. **GitHub organization (optional):** leave it empty if your personal GitHub account should own the app, or
   enter the organisation that should own it.
4. **Any GitHub account can install it:** keep this on if users connect repositories from their own accounts or
   organisations. Turn it off if only the owner account should be able to install it.
5. Click the create button. GitHub shows the prefilled app, and you confirm with **Create GitHub App**. You then
   come back to **Admin → GitHub App**, where the app's private key and secrets are stored encrypted.

The same page shows the app's owner, its installations, and how many projects use the app or manual webhooks.
It also has **Open on GitHub** and **Remove from this instance**.

::: tip Optional on GitHub
In the app's settings on GitHub you can upload a logo. Keep **Request user authorization (OAuth) during
installation** turned on, because it proves that an installation belongs to the person connecting it.
:::

### Alternative: create the app by hand (environment variables)

**Admin → GitHub App** lists what the app needs under **What the app gets**. Create a GitHub App on GitHub with
these settings:

| Setting | Value |
|---|---|
| Homepage URL | `https://brainitive.example.com` |
| Callback URL | `https://brainitive.example.com/github/app/setup` |
| Request user authorization (OAuth) during installation | On |
| Setup URL | Leave empty |
| Webhook URL (active) | `https://brainitive.example.com/api/webhooks/github-app` |
| Webhook secret | A long random string |
| Permissions / events | As in the table above |

Then generate a private key and a client secret, and set:

```ini
GITHUB_APP_ID=123456
GITHUB_APP_SLUG=brainitive-example-corp
GITHUB_APP_CLIENT_ID=Iv1.xxxxxxxxxxxxxxxx
GITHUB_APP_CLIENT_SECRET=your-client-secret
# The PEM itself (\n escapes allowed) or a path to the .pem file
GITHUB_APP_PRIVATE_KEY=/path/to/brainitive.private-key.pem
GITHUB_APP_WEBHOOK_SECRET=your-webhook-secret
```

When `GITHUB_APP_ID` is set, these variables replace any app created in the admin console, and the console can't
create or remove one ("Set by environment variables"). All six values are needed. Without the client ID and
secret, projects can't connect, because Brainitive can't verify installations.

### Project admins: connect repositories

1. Open **Project settings → Integrations** and find the **GitHub** card.
2. Click **Connect GitHub**. GitHub opens its install screen, where you pick the account and the repositories.
3. Back in Brainitive, tick the **Repositories this project follows** and click **Save repositories**.
4. Optional: under **Merge transitions**, choose a column for each board (or **Don't move**), then save.

A repository belongs to one project only. If a repository is already taken, it shows **Used by another project**.
To add more repositories later, use **Share more repositories on GitHub**. Only repositories that you can read on
GitHub can be connected.

### Manual webhooks (legacy)

Without a GitHub App on the instance, projects can still use **Use manual webhooks instead**. You add a webhook to
every repository by hand (**Settings → Webhooks → Add webhook** on GitHub) with the **Payload URL** and
**Secret** that the card shows. Set the content type to `application/json` and select these events: Pushes,
Branch or tag creation, Branch or tag deletion, Pull requests, Deployment statuses. The payload URL looks like
`https://brainitive.example.com/api/webhooks/github/<id>`, and one URL and secret work for several repositories.

Projects already on manual webhooks see **Manual webhooks (legacy)** and **Switch to the GitHub App**. Switching
keeps every linked issue and rotates the old secret, so delete the old webhooks in your repositories afterwards.
