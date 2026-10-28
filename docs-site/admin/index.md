---
title: Instance admin
description: "Run a Brainitive installation: who instance admins are, how to make one, and what the Admin console offers for users, workspaces, settings and email."
order: 0
---

# Instance admin

An **instance admin** runs a whole Brainitive installation: accounts, sign-up rules, email delivery and the instance-wide GitHub App. This section is for people who [self-host Brainitive](/self-hosting/).

::: tip Using Brainitive Cloud?
On Brainitive Cloud the Brainitive team runs the instance for you. What you manage is your **workspace**, see [Workspaces and projects](/guide/workspaces-and-projects). The [legal settings for your public pages](./legal#your-workspace-s-public-pages) apply to Cloud workspaces too.
:::

## Instance admin vs. workspace owner and admin

Brainitive has two separate kinds of "admin":

| | Instance admin | Workspace owner / admin |
|---|---|---|
| Scope | The whole installation | One workspace |
| Manages | All accounts, sign-up, email (SMTP), GitHub App, audit log | Members, roles, projects, workspace settings |
| Reads workspace content | **No**, only names, emails, counts and dates | Yes, in their own workspace |
| Where | **Avatar menu → Instance admin** | **Avatar menu → Workspace settings** |

Instance admins can't read workspace content. They see only the workspaces they've been invited to, like everyone else. The Admin console shows metadata only: names, email addresses, counts and dates, never pages, boards or issues.

## Make someone an instance admin

The first account on a new installation is **not** an admin automatically. You make the first admin from the server's command line:

1. Open your installation in the browser and register an account.
2. On the server, run:

   ```bash
   # Docker
   docker compose exec app php artisan make:instance-admin you@example.com

   # Without Docker, in the app folder
   php artisan make:instance-admin you@example.com
   ```

3. Reload Brainitive. **Your avatar → Instance admin** is now in the menu.

The command also marks the account's email address as verified, so you get in even before email delivery works.

After that you can promote more people from the console: **Instance admin → Users → ⋯ → Make instance admin**. See [Users](./users#instance-admin-role).

Two more commands help on the server:

| Command | What it does |
|---|---|
| `php artisan list:instance-admins` | Lists every instance admin |
| `php artisan remove:instance-admin you@example.com` | Takes the role away from that account |

::: warning
An installation always needs at least one instance admin. In the console you can't remove or suspend the last one, or delete their account.
:::

## The Admin console

Open it from **your avatar → Instance admin**. It has these tabs:

| Tab | What you do there |
|---|---|
| **Overview** | Instance health at a glance (see below) |
| **Users** | Add, verify, suspend and delete accounts, send password reset links, grant the admin role. [Users](./users) |
| **Workspaces** | See owner, members and projects; transfer ownership or delete a workspace. [Workspaces](./workspaces) |
| **Settings** | Open or invite-only sign-up, email verification, your SMTP server and a test email. [Instance settings](./settings) |
| **GitHub App** | Create the GitHub App that projects use to connect repositories. [GitHub integration](/integrations/github) |
| **Emails** | Preview every email Brainitive sends and send a test to yourself. [Emails](./emails) |
| **Audit log** | Every action taken in the console. [Audit log](./audit-log) |

### Overview

The Overview page shows:

- **Counts:** users (and how many are new in the last 30 days), active users in the last 30 days, workspaces, projects, pages, boards and issues, helpdesks and tickets, and instance admins.
- **Accounts:** suspended accounts, accounts whose email isn't verified, whether sign-up is open to everyone or invitation only, and whether email verification is required.
- **System:** PHP and Laravel versions, database type and size, the space used by uploaded files, which mailer sends email, the queue, and the number of failed background jobs.

If email is only written to the log (the default `MAIL_MAILER=log` of a fresh install), a yellow banner warns that password resets, invitations and notifications never reach anyone, with a link to **Set up an SMTP server**.

## Also on this site

- [Self-hosting](/self-hosting/) to install and update Brainitive
- [Configuration](/self-hosting/configuration) for the `.env` settings
- [Legal pages](./legal) for the imprint, privacy policy and terms of your installation
