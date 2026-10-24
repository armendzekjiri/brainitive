# Notifications redesign: inbox UI/UX + per-category preferences (BRAI-102)

**Branch:** feat/notifications-ui (into release/v1.39.0)
**Status:** in-progress
**Started:** 2026-10-24
**Board card:** BRAI-102 · contract: [`release-v1.39.0.md`](./release-v1.39.0.md) ticket 1

## Problem / goal
> "i dont like notifications how they look, make something better ui/ux"

The bell was a plain list that opened links in a new tab, the page marked everything read on open,
nothing could be archived or filtered, and every notification went to every channel.

## Approach
- **One renderer** (`components/NotificationMessage.tsx`): `describeNotification(data)` turns any payload
  into `{headline, text, body, subjectKey, context, url}`. Known legacy types keep their wording
  ("Erin assigned you to WEB-5"); every other type renders from the payload contract keys (`title`,
  `body`, `url`, `actor_*`, `project_name`, `subject_key`), so ticket 2's new types work unseen.
- **Category per row is computed server-side** from `NotificationCatalog::TYPES` (unknown → `null`, never
  throws) and decides the icon badge (`components/notifications/categories.ts`).
- **Shared list UI** in `components/notifications/`: `NotificationRow` (stretched link = same-tab Inertia visit,
  hover/focus actions read/unread + archive), `NotificationGroups` (Today / Yesterday / This week /
  Older), `useNotificationFeed` (filter, cursor "Load more", optimistic actions), `useUnreadCount`
  (30 s poll + focus + `(3) …` tab title).
- **Bell:** popover ~420 px, a full-screen sheet below 768 px. Tabs All · Unread · Mentions · Assigned.
- **Inbox page:** same rows + Archived view, multi-select with bulk read / unread / archive, keyboard
  j / k / Enter / e / x. Opening it no longer marks everything read.
- **Archive** = `notifications.archived_at`; archiving also marks read (so the shared unread count
  needs no change), "mark unread" on an archived row moves it back to the inbox.
- **Prune:** `App\Models\InboxNotification` (extends `DatabaseNotification`, `MassPrunable`): archived
  > 90 days, any > 180 days. Own `model:prune` line in `routes/console.php`.
- **Preferences:** `PATCH notifications.preferences` (own route, so ticket 5 can extend
  `profile.preferences` without conflicts) saves `users.notification_preferences`. Profile →
  Notifications shows a category × {In-app, Push, Email} grid + device push + task digest.
- **Existing classes** use `RespectsPreferences::channelsFor()`; `toMail` added where missing.

## Changes
- Migration: `notifications.archived_at` (nullable timestamp) + index `(notifiable_type, notifiable_id, archived_at)`.
- Model: `App\Models\InboxNotification` (prunable).
- Controller / routes: `NotificationController` — `page`, `index` (filter / archived / cursor),
  `unreadCount`, `markRead`, `markUnread`, `archive`, `unarchive`, `bulk`, `markAllRead`,
  `updatePreferences`. Routes in the notifications block of `web.php`.
- `ProfileController::edit` passes `notificationCategories` (one line).
- Notification classes: `via()` → `channelsFor()`; `toMail` for mention, item assigned, status, page, reminder, digest.
- Frontend: bell, page, renderer, `components/notifications/*`, Profile `NotificationPreferencesForm`.

## Test plan
- PHPUnit `tests/Feature/Notifications/*`: list + filters + category, unknown type fallback, read / unread,
  archive / unarchive / bulk, read-all, page doesn't mark read, preferences save + validation, `via()`
  honours preferences, prune.
- e2e `e2e/tests/notifications.spec.ts`: bell filters + grouping, mark unread, archive, same-tab open,
  inbox page (keyboard, bulk), preference toggle stops a notification type.

## Docs to update
ARCHITECTURE (Notifications), KNOWLEDGE (renderer gotcha, archive invariant), skills/add-notification.md.

## Open questions
- `ticket_assigned` is in the `assignments` category, whose default email is off; it used to always mail.
  Kept the catalog as is (the lead owns it) — flagged in the report.
