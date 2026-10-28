---
title: Users
description: "Manage accounts on a self-hosted Brainitive instance: add users, reset passwords, verify email, suspend, grant the admin role and delete accounts."
order: 10
---

# Users

**Instance admin → Users** lists every account on your installation. From here you add people, help them get back in, suspend or delete accounts and hand out the instance admin role.

## The user list

Each row shows the person's name and email, their workspaces, how many projects they can open, their last sign-in and when they joined. Badges mark **You**, **Admin** (instance admin), **Suspended** and **Unverified** (email not confirmed yet).

- **Search** by name or email.
- **Filter** with the menu next to the search: **All users**, **Instance admins**, **Suspended** or **Email not verified**.

Click a row to open the details panel: joined and last sign-in dates, email, how they sign in (password, Google, GitHub or Microsoft), whether they have API / AI tokens, and their workspaces with their role and project count. As everywhere in the console, you see names and counts only, never content.

## Add a user

Useful when sign-up is closed (see [Instance settings](./settings#sign-up-accounts)).

1. Click **Add user** (top right).
2. Enter **Name** and **Email**.
3. Leave **Email them a link to set their password** ticked to send the link by email, or untick it to get a link you can share yourself (handy when email isn't set up yet).
4. Click **Create account**.

The new account's email counts as verified. You never choose a password: the person sets their own through the link. To give them access to content, invite them from a workspace (**Workspace settings → Members**).

## Actions on an account

Open the **⋯** menu at the end of a row:

| Action | What happens |
|---|---|
| **Email a password reset link** | Sends the normal "Reset your password" email. |
| **Copy a password reset link** | Shows a one-time link you hand over yourself. Works without SMTP. The link expires (60 minutes by default). |
| **Mark email as verified** | Only for unverified accounts. Lets them in without the verification email. |
| **Resend verification email** | Only for unverified accounts. |
| **Make instance admin** / **Remove instance admin** | See [Instance admin role](#instance-admin-role). |
| **Suspend** / **Unsuspend** | See [Suspend an account](#suspend-an-account). |
| **Delete user…** | See [Delete an account](#delete-an-account). |

## Suspend an account

**Suspend** blocks an account without deleting anything:

- they're signed out everywhere,
- their API and AI connector tokens are revoked,
- they can't sign in until you click **Unsuspend**.

You can't suspend yourself or the last instance admin.

## Instance admin role

**Make instance admin** lets someone manage all accounts, workspaces and instance settings. They still can't see workspace content.

**Remove instance admin** takes the role away. If you remove your own role, you lose access to the console immediately. The installation always keeps at least one instance admin.

You can do the same on the server with `php artisan make:instance-admin <email>` and `php artisan remove:instance-admin <email>`.

## Delete an account

1. Click **⋯ → Delete user…**.
2. Read what will happen:
   - What they created in other people's workspaces (projects, boards, issues, lists, files) is **kept** and handed to that workspace's owner.
   - Comments they wrote are deleted. Replies by others are kept.
   - Their memberships, watchers, calendar connections and tokens are removed.
3. Type their email address to confirm and click **Delete user**.

Deletion is permanent. It's blocked when:

- it's your own account (people delete their own account in their profile),
- it's the last instance admin,
- they **own a workspace**. Transfer it to someone else or delete it first under [Workspaces](./workspaces).

Every action on this page is recorded in the [Audit log](./audit-log).
