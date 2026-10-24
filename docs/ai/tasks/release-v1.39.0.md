# Release v1.39.0: Notifications, webhooks, insights, custom sidebar, bulk edit (BRAI-102 … BRAI-109)

## Goal (the user's words, 2026-10-24)
> "i dont like notifications how they look, make something better ui/ux, check everything that need
> notifications, outgoing webhooks, admin insigths! and in projects sidebar is full to make something like
> customable sidebar which and what we want to see like the on top something like hide/show caledar, and
> under projects too hide,show lists, boards, or pages, so user can self decide what want here pinned and
> then in proejct page are all but that page need to be better order"

Billing decisions from the same message (BRAI-107): unbilled hours move with a project to another workspace;
only workspace admins may change an entry's billable flag.

## Tickets, branches, ownership

| # | Card | Branch | Owns (only this ticket edits these) |
|---|---|---|---|
| 1 | BRAI-102 Notifications redesign | `feat/notifications-ui` | `Components/NotificationsDropdown.tsx`, `Components/NotificationMessage.tsx`, `Pages/Notifications/*`, `NotificationController`, notification routes in web.php, Profile → Notifications settings, the `via()` of the **existing** notification classes |
| 2 | BRAI-103 Notification coverage | `feat/notification-coverage` | **new** notification classes, where notifications are dispatched (controllers, `ItemMentions`, observer, commands), `NotificationCatalog::TYPES` additions |
| 3 | BRAI-104 Outgoing webhooks | `feat/outgoing-webhooks` | `DomainEvents` implementation + its dispatch calls, webhook tables/models/jobs, `routes/webhooks.php`, the webhooks settings UI |
| 4 | BRAI-105 Admin insights | `feat/admin-insights` | `routes/insights.php`, `app/Support/Insights/*`, `Insights*` controllers, `Pages/Insights/*`, `Layouts/InsightsLayout.tsx` |
| 5 | BRAI-106 Custom sidebar + project page | `feat/custom-sidebar` | `Layouts/AppLayout.tsx` (sidebar), sidebar payload in `HandleInertiaRequests`, `Pages/Projects/Show.tsx`, `ProjectController@show`, `users.sidebar_preferences` |
| 6 | BRAI-107 Billing follow-ups | `fix/billing-followups` (lead) | `ProjectTransfer`, `TimesheetController` billable rule |
| 7 | BRAI-108 Board list bulk edit | `feat/board-bulk-edit` | the board **list view** components, a bulk-update endpoint (`BoardController` or a new `BulkItemController`) |
| 8 | BRAI-109 Homepage link previews | `feat/social-meta` (lead, merged) | `partials/social-meta.blade.php`, `public/images/og-image.png`, `scripts/make-og-image.mjs` |

Files touched by two tickets (expected, merged by the lead): `ItemObserver`, `SprintLifecycle`, `TicketController`,
`ItemComments`, `LeadIntake` (tickets 2 + 3 add calls on different lines), `types/index.d.ts`, `docs/ai/*`.
Keep additions in those files small and self-contained (one call / one block) so merges stay easy.

## Shared foundation (on `release/v1.39.0`; build on it, don't redefine it)

### Preferences columns
Migration `2026_10_28_100000_add_preference_columns_to_users`: `users.notification_preferences` and
`users.sidebar_preferences`, nullable JSON, cast to `array` and fillable on `User`. Null = defaults.

### Notification catalog (tickets 1 + 2)
`app/Notifications/NotificationCatalog.php`:
- `CATEGORIES` (mentions, assignments, comments, status, reminders, pages, sprints, helpdesk, leads, workspace),
  each with label, description and default channels `{in_app, push, email}`.
- `TYPES`: notification `type` → category. **Every database notification type must be listed** —
  `category()` throws on unknown types.
- `preferences($user, $category)`, `forUser($user)` (for the settings UI), `channels($notifiable, $type)`.
- Stored shape: `users.notification_preferences = {"<category>": {"in_app": bool, "push": bool, "email": bool}}`.

`Concerns/RespectsPreferences::channelsFor($notifiable, $type)` is the `via()` for catalog notifications. It drops
`mail` / push when the class has no `toMail` / `toWebPush`. Non-User notifiables (ticket contacts) get mail only.

**Payload contract for every NEW notification type** (ticket 2 writes it, ticket 1 renders it):
```
type          string   catalog type, e.g. "item_commented"
title         string   one line, already worded: "Max commented on BRAI-12"
body          ?string  optional preview / detail line (plain text, ≤ 200 chars)
url           string   absolute app URL to open (route(...))
actor_name    ?string
actor_avatar  ?string
project_name  ?string  context line ("Website · Main Board" is fine)
subject_key   ?string  e.g. "BRAI-12", shown as a chip
```
Existing types keep their current keys (old rows in the DB must still render). Ticket 1 renders the known
types specially and **any other type generically from the keys above**, so new types work without ticket 1
knowing them. Notification icons per category come from ticket 1's renderer.

### Domain events (ticket 3; ticket 4 may read nothing from it)
`app/Support/Events/DomainEvents::dispatch(Workspace $ws, string $event, array $data, ?User $actor)`. Event names
and labels are in `DomainEvents::EVENTS`. It is a **no-op stub** until ticket 3 implements it. Envelope:
`{id, event, occurred_at, workspace: {id, slug, name}, actor: {id, name}|null, data: {...}}`. `data` is plain
arrays: no models, no secrets, no rates or amounts except on `billing_report.*` (admins configured the hook).

### Routes and sidebar
- `routes/webhooks.php` and `routes/insights.php` are required inside the `workspaces/{workspace}` group of
  web.php. Each has a single section for its ticket.
- Sidebar: **Insights** (`ChartColumn` icon) for workspace admins, shown once `insights.index` exists. Ticket 5
  keeps it when it refactors the nav.

## Rules for all tickets
- **Admin-only pages** (webhooks, insights): `Gate::authorize('update', $workspace)` (= `canManageWorkspace`).
  Members get 403, and the UI hides the entry.
- **Accessible names stay stable.** Existing e2e tests click `link 'Billing' | 'Timesheet' | 'Workload' | 'Inbox'`
  and similar. Sidebar entries stay **visible by default**: hiding is opt-in.
- Every ticket ships **PHPUnit + e2e** (`e2e/tests/<feature>.spec.ts`, helpers in `e2e/helpers/`).
- Postgres only. Never `migrate:fresh` or `db:wipe` against `.env`. Use your own `TEST_DB_SUFFIX` and `E2E_PORT`.
- Update `docs/ai/ARCHITECTURE.md` / `KNOWLEDGE.md` for what you add. The lead writes the changelog and homepage.

## Ticket specs

### 1. BRAI-102 Notifications redesign
- **Bell panel** (popover, about 420px wide; full-screen sheet on mobile):
  - header "Notifications" with "Mark all read" and a settings cog that links to Profile → Notifications;
  - filter tabs **All · Unread · Mentions · Assigned** (server-side filter by catalog category);
  - list grouped by **Today / Yesterday / This week / Older**;
  - each row: category icon badge over the actor avatar, the worded line, the subject chip, the project
    context and a relative time, with an unread dot;
  - hover or focus actions: mark read/unread and archive;
  - clicking a row opens it **in the same tab** (Inertia visit for app URLs) and marks it read;
  - "Load more" or infinite scroll; empty states per tab.
- **Inbox page** `/notifications`:
  - the same rows and filters, plus a "Show archived" view, multi-select with bulk mark read and archive, and
    keyboard navigation (j/k/Enter/e);
  - opening the page must **not** mark everything read any more.
- **Archive:** a soft "archived" state. Add a nullable `archived_at` column to `notifications` in a migration;
  archived rows are hidden from the bell and inbox by default. Prune archived rows after 90 days and all
  rows after 180 days (`model:prune` or a scheduled command).
- **Unread count** polling stays at 30s. Also refresh it on window focus. Update the tab title badge
  ("(3) Brainitive").
- **Profile → Notifications:** one row per catalog category with In-app, Push and Email toggles (saves
  `notification_preferences`). The browser-push device toggle and the task digest live in the same section.
- **Wire every existing catalog notification class** to `RespectsPreferences`, keeping today's behaviour as the
  defaults (helpdesk and leads already mail). Add a `toMail` where the email channel should be possible
  (assignments, mentions, ticket and lead classes).
- **Tests:**
  - PHPUnit: list with filter, read/unread, archive, read-all, preferences save and validation, `via()` honours
    preferences, prune.
  - e2e: bell filters and grouping, mark unread, archive, same-tab open, inbox page, preferences toggle
    stops a notification type.

### 2. BRAI-103 Notification coverage
Add these, each a catalog type with the payload contract above and `RespectsPreferences`:
- `item_commented` (comments): to item watchers, the assignee and the reporter. Never to the author, and never
  twice when the user is also @mentioned in that comment.
- `item_due` (reminders): "due tomorrow" and "overdue" for board issues with a due date, assignee only, once
  per issue per kind. Reuse the `sent_item_reminders` dedup pattern and `User::localTimezone()`, scheduled with
  the existing reminders.
- **Assignment from every path:** MCP create/update, meeting-note action items, plan import,
  `ProjectIssues`, bulk moves. Prefer one place (e.g. `ItemObserver` on an `assignee_id` change) over each
  controller, and remove the controller duplicates so nobody is notified twice.
- `task_assigned` (assignments): a task-list task assigned to someone else.
- `lead_assigned` (assignments).
- `sprint_started` / `sprint_completed` (sprints): to everyone with an issue in the sprint (except the actor),
  with the open/done counts in the body.
- `invitation_accepted` (workspace): to the inviter. `workspace_role_changed` (workspace): to the member.
- `ticket_status_changed` (helpdesk): to ticket watchers except the actor.
- **Duplicate @mentions:** re-saving a description must not notify people who were already mentioned in it
  (diff the previous mention set).
- **Page mentions:** decide and document. Either notify on save (diff like issues) or keep insert-time, but
  dedupe.
- Queue the new classes (`ShouldQueue`); staging runs the sync queue, which is fine.
- **Tests:**
  - PHPUnit per type: recipients, no self-notify, dedupe, preferences respected.
  - e2e: a member comments → the owner (watcher) sees it in the inbox; a sprint start notifies the member;
    MCP/meeting-notes assignment notifies (PHPUnit is enough where there is no UI path).

### 3. BRAI-104 Outgoing webhooks
- **Tables:**
  - `webhooks`: workspace_id, name, url, secret (encrypted, hidden), events (json list of `DomainEvents`
    names, or `["*"]`), active, `failure_count`, `disabled_at`, `last_delivery_at`, created_by, timestamps.
  - `webhook_deliveries`: webhook_id, event, event_id (uuid), payload json, status
    (`pending|success|failed`), attempts, response_code, response_body (truncated to 2 KB), duration_ms,
    error, delivered_at, timestamps. Prunable after 30 days, registered in `routes/console.php`.
- **`DomainEvents::dispatch`:**
  - after the DB commit, create one delivery per active webhook in that workspace subscribed to the event,
    then dispatch a `DeliverWebhook` job;
  - copy `DeliverLeadWebhook`: `OutboundUrl::options()`, `timeout(10)`, headers `X-Brainitive-Event`,
    `X-Brainitive-Delivery`, `X-Brainitive-Signature: sha256=<hmac>`, `X-Brainitive-Timestamp`;
  - retry with backoff on 5xx or network errors; a 4xx is a failure without retry;
  - after 10 consecutive failed deliveries, auto-disable the webhook and notify the workspace admins in-app
    if ticket 2's catalog has a `workspace` type. Otherwise just mark it disabled.
- **Dispatch calls** for every name in `DomainEvents::EVENTS` at the hook points (`ItemObserver`, `ItemComments`,
  `Page::publish`, `SprintLifecycle`, `TicketController`, `LeadIntake`, `TimeEntry`, `ReportFinalizer`).
  Payloads hold ids, keys, titles, URLs, column/status names, and assignee `{id, name}`. No emails of
  contacts or leads beyond what the admin already sees in the app.
- **UI:** Workspace settings → **Webhooks** (a page in the workspace settings area, linked from its tabs; admins only):
  - list, create/edit dialog (URL, events picker grouped by area, "all events", active switch);
  - secret shown once, with "reveal" and "rotate";
  - "Send test" (`ping` event);
  - deliveries table per webhook (status, code, duration, time), expandable request/response, Redeliver.
  - Docs panel: payload envelope, signature verification snippet (Node and PHP).
- **Security:** URL validated with `OutboundUrl`, https required in production, the secret is never shared in
  Inertia props after creation, throttled test and redeliver routes.
- **Tests:**
  - PHPUnit: signing, SSRF block, subscription filter, retry/backoff, auto-disable, member 403, payload shape
    per event, prune.
  - e2e: create a webhook to a local receiver (an e2e-only route, or a Playwright-side HTTP server on
    127.0.0.1 with private IPs allowed in the e2e env only), trigger an issue create, see a successful
    delivery, redeliver.

### 4. BRAI-105 Admin insights
- `insights.index` redirects to Overview. `InsightsLayout` has tabs **Overview · Team · Projects · Helpdesk & leads ·
  Usage**, a date-range picker (this month, last month, last 30/90 days, custom) and a project filter.
- **Overview:**
  - KPI cards: created, completed, completion rate, median cycle time, overdue now, hours logged;
  - weekly created-vs-completed chart;
  - open and overdue issues by project;
  - top issue types.
- **Team:** per member: completed, open assigned, overdue, hours logged, comments, last active (`last_login_at`
  plus their latest activity), and a link to the member profile.
- **Projects:** every project with health status, open, overdue, done in range, hours and last activity
  (reuse `HealthMetrics` cheaply; no N+1).
- **Helpdesk & leads:**
  - tickets created/resolved, median resolution time, open by helpdesk;
  - leads per form and submission funnel (`lead_form_events`).
- **Usage:** members by role, pending invites, projects, storage by project (`attachments` +
  `ticket_attachments`), webhooks count if the table exists.
- Charts: use the chart library already in package.json if there is one, otherwise simple CSS/SVG bars. No new
  heavy dependency.
- **CSV export** per tab.
- Queries are bounded, with a fixed query count per tab (assert it in PHPUnit like `HealthMetrics`). Cache 5
  minutes per workspace+range if needed.
- **Tests:**
  - PHPUnit: numbers on seeded data, member 403, query count, CSV.
  - e2e: the admin opens Insights from the sidebar, switches tabs and range, a member has no link and gets 403.

### 5. BRAI-106 Custom sidebar + better project page
- **Nav as data:** refactor the top-level nav into a config list. **Home and Inbox** are always visible. Every
  other entry (My Work, Captures, Timesheet, Billing, Insights, Calendar, Bookmarks, Snippets) can be hidden.
- **"Customize sidebar"** (in the account menu and a small pencil at the sidebar's bottom) opens a panel:
  - top-level entries with checkboxes;
  - for the active project, per-group toggles (Boards, Lists, Plans, Helpdesk, Lead forms, Pages, Whiteboards,
    Folders);
  - "Reset to default".
  - Hidden entries remain reachable from the command palette.
- **Pins:** pin or unpin any board, list, plan, helpdesk, lead form or page/whiteboard/folder, from the sidebar
  item's context menu or hover button and from the project page.
  - Each project's sidebar shows a **Pinned** group first.
  - If a project has pins, or the user chose "Pinned only" for it, the other groups collapse to a "Show all
    in project →" link to the project page.
- **Storage:** `users.sidebar_preferences`:
  `{hidden_nav: string[], projects: {"<project_id>": {groups: {boards: bool, …}, pins: [{type, id}], pinned_only: bool}}}`.
  - Saved through `PATCH profile.preferences` (extend its validation) or a dedicated `sidebar.preferences` route.
  - Validate types and ids. Ignore pins the user can no longer see.
  - Shared to the frontend through `auth.user` (already shared) or a dedicated prop.
  - Defaults: everything visible, nothing pinned, so today's sidebar is unchanged.
- **Project page, better order:**
  - header: name, key, description, members (avatars), quick links to Health · Workload · Releases;
  - then **Pinned** (the user's pins in this project);
  - then **Recently updated** (5 items across types);
  - then sections in a fixed order with counts:
    - **Work**: Boards, Lists, Plans
    - **Docs**: Pages and folders tree, Whiteboards
    - **Customer-facing**: Helpdesk, Lead forms
  - Empty sections collapse to one compact "+ Add …" row instead of big dashed cards.
  - A pin toggle on every card. Existing create dialogs stay.
  - Keep each card's heading text (h3) the same: `helpdesk.spec.ts` matches it.
- **Tests:**
  - PHPUnit: preferences validation, pins of inaccessible items ignored, payload.
  - e2e: hide Calendar and it disappears and survives a reload; hide the Lists group; pin a page and it shows
    under Pinned; the project page order and sections; reset.

### 6. BRAI-107 Billing follow-ups (lead)
- `ProjectTransfer::move` moves **unbilled** time entries (`billing_report_id` null) to the target workspace.
  The activity is mapped by name in the target, otherwise null. Billed entries keep the old workspace.
  The preview shows how many hours move.
- `TimesheetController` store/update: `billable` is honoured only for workspace admins. Members get the
  activity default, and on update their entry keeps its flag unless the activity changes.
- PHPUnit + e2e.

### 7. BRAI-108 Board list view: multi-select + bulk edit (added 2026-10-24)
> "on boards when we stich to list to be able to multi edit some fileds you decide which"

- In the board's **list view**: a checkbox per row, shift-click to select a range, a header checkbox to select all
  (visible/filtered) rows, and Esc to clear.
- A sticky **bulk bar** ("3 selected"):
  - **Status** (column);
  - **Assignee** (including Unassigned);
  - **Priority**;
  - **Type**;
  - **Sprint** (including Backlog, when the board is scrum);
  - **Due date** (set or clear);
  - **Labels** (add or remove);
  - **Delete**, with a confirmation.
- One request per bulk action: `PATCH …/items/bulk` with `{item_ids[], changes{}}`, max 200 ids.
  - Every id must belong to this board; authorize per item.
  - Run it in one transaction and go through the model, so `ItemObserver`, activity log, `completed_at` and
    notifications behave as for single edits. Ticket 2 centralises assignment notifications in the observer.
  - Notify each assignee once, not once per field.
  - Reuse the existing bulk-move code if there is one.
- **Tests:**
  - PHPUnit: per-field changes, foreign ids rejected, viewer 403, the 200 limit, activity logged.
  - e2e: switch to list, select 3, set assignee and priority, move to Done, add a label, delete 2.
