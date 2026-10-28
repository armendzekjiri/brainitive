---
title: Audit log
description: "The Brainitive admin audit log records every action taken in the instance admin console: who did what, to which account or workspace, and when."
order: 50
---

# Audit log

**Instance admin → Audit log** records every action taken in the Admin console, newest first, 50 per page.

Each entry shows who did it, the action, its target (an account or workspace), extra details, the IP address and the time.

## What is recorded

| Area | Actions |
|---|---|
| Accounts | Created account, emailed or created a password reset link, marked email as verified, resent verification email, suspended, unsuspended, made or removed instance admin, deleted account |
| Workspaces | Transferred workspace, deleted workspace |
| Settings | Updated settings, sent test email |
| Emails | Sent an email preview to themselves |
| GitHub App | Created or removed the GitHub App |

Nothing in the log contains workspace content: entries name accounts and workspaces and keep counts, not pages, boards or issues.
