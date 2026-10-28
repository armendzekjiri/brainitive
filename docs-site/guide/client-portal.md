---
title: Client portal and document review
description: "Share selected Brainitive docs and plans with clients for approval in a client portal, and ask teammates to review a draft with inline comments."
order: 66
---

# Client portal and document review

Two ways to get sign-off on your work: the **client portal** shares selected docs and plans with people outside your
workspace, who approve them or ask for changes. **Document review** does the same inside your team, on a doc's draft.

## Client portal

Each project can have one client portal, at an address like `…/client/acme-website`. Clients only see what you
explicitly publish there — never your boards, other docs or comments.

### Turn it on

Project admins open the project and click **Client portal** in the quick links at the top. Fill in the **Title**, the
**Address** (after `/client/`) and an optional **Welcome text**, then click **Turn on the client portal**. Use
**Copy link** or **Open portal** to share and check it.

### Invite clients

Under **Clients**, enter the **Client email**, an optional **Name** and optionally **Access until** a date, then click
**Invite**. The client gets an email. They sign in with a link Brainitive emails them — no password, and they never
join your workspace. Each invited client has **Re-invite** and **Revoke**.

People who weren't invited, whose access was revoked or whose access ended see a short page that tells them so.

### Publish deliverables

Under **Deliverables**, choose the **Type**:

- **Doc** – a published doc, in its latest published version.
- **Plan** – a snapshot of a plan as it is now.

Pick the doc or plan, add an optional **Note to the client** and click **Publish to the portal**. Clients get an email.

The client sees the exact version you published. If you publish a newer version of the same doc or plan, it replaces
the previous one for the client; the earlier decision stays in the history, and the new version needs a fresh approval.

### What clients do

In the portal, clients see their **Deliverables** and what's waiting for their review. On a deliverable they can:

- **Approve** it, or **Request changes** with a note ("What should change?") and **Send change request**.
- Write a **Comment** for the team.
- **Download this version**, and look at **All versions** and earlier **Decisions**.

Decisions are only possible on the latest version.

### Follow up as a team

Click a deliverable on the project's **Client portal** page to see its **Decisions**, **Versions** and the
**Conversation**. When you write a message, choose:

- **Reply to client** – visible to the client and emailed to them.
- **Add internal note** – only your team sees it.

**Remove from portal** withdraws a deliverable; clients no longer see it, but its history stays.

Project admins and editors can publish deliverables and reply; only project admins can change the portal settings and
invite clients. You get notified about client approvals, change requests and comments (category
**Reviews & approvals** in your [notification settings](./notifications)).

## Document review

Ask teammates to review a doc's draft before you publish it.

### Request a review

1. Open the doc and click the **⋯** menu → **Request review…**.
2. Pick the reviewers from the people who can open the project and add an optional **Message**.
3. Send it. Reviewers get a notification.

The review is a snapshot of the draft at that moment.

### Review a doc

Reviewers open the review from their notification or the banner on the doc (**Open review**). They can:

- Select text to comment on it, or leave a comment on the whole page. Comments can be answered, resolved and reopened.
- Choose **Approve** or **Request changes** under **Your decision**.

If the author edits the draft after the review was requested, the review is marked outdated: it can no longer be
approved, only changes can be requested. The author then uses **Request new review**.

### Require an approval before publishing

Anyone who manages the doc can tick **⋯ → Review required before publish**. The doc then shows a **Review required**
badge, and publishing is blocked until a review is approved.

## Related

- [Docs](./docs) – drafts, publishing and versions.
- [Feedback portal](./feedback-portal) – public ideas and voting.
