---
title: Your profile and account
description: "Manage your Brainitive profile, sign-in methods, password, connected apps and notifications, download your data, or delete your account."
order: 95
---

# Your profile and account

Everything about your own account is on one page. Open the account menu (your avatar) and choose **Profile settings**.

## Profile information

Change your **Profile picture**, **Name**, **Email**, **Phone (optional)** and **Bio (optional)**, then click **Save**.
Your name and picture are what teammates see on issues, comments and mentions.

If you change your email address, you confirm the new address again with the link Brainitive sends you.

## Signing in

Depending on how your Brainitive is set up, the login page offers:

- **Email and password**, with **Remember me** and **Forgot your password?**
- **Continue with Google**
- **Continue with GitHub**
- **Continue with Microsoft** (Outlook.com and Microsoft 365)

You can use a provider and your email address side by side: signing in with Google, GitHub or Microsoft using the same
verified email address opens the same account.

::: tip Only ever signed in with Google, GitHub or Microsoft?
Your account then has no password you know. If you need one — for example to change your password or delete your
account — use **Forgot your password?** on the login page to set one.
:::

After several failed login attempts in a row, the account is locked for a while to protect it from password guessing.

## Update your password

Under **Update password**, enter your **Current Password**, a **New Password** and **Confirm Password**, then click
**Save**. Use a long, random password.

Changing your password also disconnects every connected app (AI connectors, the browser extension and the CLI).

## Notifications and offline

- **Notifications** – choose per category whether you hear about things in the app, by push or by email, manage your
  push devices and the morning task summary. See [Notifications](./notifications).
- **Offline** – work without internet on this device. See [Offline mode](./offline).

## Connected apps

**Connected apps** lists the AI connectors (MCP), the browser extension and the command-line tool that can access your
account, and what each may do ("Read and write your content", "Save pages, links and notes", "Full access (CLI)").
Click **Disconnect** to remove one; it loses access right away. See [AI and MCP](./ai-and-mcp).

## Your data

**Your data** → **Download my data** prepares a ZIP with everything Brainitive holds about your account. See
[Import and export](./import-and-export#download-my-data).

## Your time zone

Brainitive takes your time zone from your browser, so reminders, the morning summary and due times follow your local
time. When you travel, it updates automatically.

## Delete your account

Under **Delete account**, click **Delete Account** and confirm with your password. This can't be undone.

What happens:

- **Your account and personal data are deleted**: profile, settings, memberships, watches, notifications, connected
  calendars and apps.
- **Work you created in workspaces other people use stays there.** Issues, docs, boards, task lists and other content
  are handed over to the owner of each workspace, so your teammates don't lose anything. Other people's replies to your
  comments are kept; your own comments are removed.
- **Workspaces only you use are deleted** together with your account.

::: warning You own a workspace others use?
Then Brainitive won't delete your account yet. First hand the workspace over or delete it: an instance administrator
can transfer its ownership to someone else (see [Instance admin](/admin/)), or you delete it under
**Workspace settings → Danger zone**.
:::

Before you delete your account, consider [downloading your data](./import-and-export#download-my-data).

## Related

- [Workspaces and projects](./workspaces-and-projects)
- [Notifications](./notifications)
