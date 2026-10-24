# Notification coverage (BRAI-103)

**Branch:** feat/notification-coverage (from release/v1.39.0)
**Status:** in-progress
**Started:** 2026-10-24
**Board card:** BRAI-103 · contract: [`release-v1.39.0.md`](release-v1.39.0.md) ticket 2

## Problem / goal
"check everything that need notifications": comments only notified @mentions, re-saving a description
re-notified everyone mentioned in it, assignments made through MCP / meeting notes / plan import / whiteboard /
bulk edit told nobody, board issues had no due / overdue reminders, and sprints, task lists, leads, invitations,
role changes and ticket status changes were silent.

## Approach
- **One base class** `App\Notifications\CatalogNotification` (abstract, `ShouldQueue`, `afterCommit`) for every new
  type: `via()` = `RespectsPreferences::channelsFor()`, `toDatabase()` = the payload contract
  (`type, title, body, url, actor_name, actor_avatar, project_name, subject_key`), `toWebPush()` and a generic
  `toMail()` built from the same payload. Subclasses only build the payload.
- **Assignments in one place:** `ItemObserver::created/updated` → `ItemAssignments::notify()` on an
  `assigned_to` change. Actor = the authenticated user; no actor (seeders, console) = silent; self-assignment
  = silent. The controller duplicates in `BoardController::storeItem/updateItem` go. Same for task-list tasks:
  `TaskListItemObserver` → `task_assigned`.
- **Mentions:** `ItemMentions::notify($content, $item, $actor, $previous)` only notifies ids that are new
  compared with the previous description, and returns the mentioned ids (comments skip them for
  `item_commented`). Pages: notify on **save** (`Page::saveDraft` diffs the previous draft/content) instead of at
  insert time; the editor's `notify-mention` POST is dropped (the endpoint stays for ticket 1 to remove).
- **Due dates:** `app:send-item-reminders` also sends `item_due` ("due tomorrow" from 08:00 local the day
  before, "overdue" once the due moment passed, within 2 days), keyed in `sent_item_reminders` as
  `item_due:{id}:{kind}:{due_date}`.
- **Sprints:** `SprintLifecycle` calls `SprintNotifications::started/completed` (recipients = assignees of the
  sprint's issues, captured before completion moves open issues out).
- Workspace: `invitation_accepted` (inviter, from both accept paths), `workspace_role_changed` (member).
- Helpdesk: `ticket_status_changed` (watchers except the actor) on a column change. Leads: `lead_assigned`.

## Changes
- `app/Notifications/CatalogNotification.php` + one class per new type; `NotificationCatalog::TYPES` additions.
- `app/Support/Notifications/*` (dispatch helpers), `app/Observers/TaskListItemObserver.php`.
- Dispatch points: `ItemObserver`, `ItemComments`, `ItemMentions`, `BoardController`, `SprintLifecycle`,
  `TaskListItem`, `LeadController`, `InvitationController`, `WorkspaceInvitation`, `WorkspaceController`,
  `TicketController`, `Page::saveDraft`, `SendItemReminders`, `MentionsPlugin.tsx` (drop insert-time POST).

## Test plan
- PHPUnit per type (`tests/Feature/Notifications/*`): recipients, no self-notify, dedupe, preferences respected.
- e2e `e2e/tests/notifications-coverage.spec.ts`: a member comments → the owner (watcher) gets
  `item_commented`; a sprint start notifies the member.

## Docs to update
- `skills/add-notification.md`: catalog, base class, payload contract.
- `KNOWLEDGE.md`: dedupe rules, where assignment notifications fire.
- `ARCHITECTURE.md`: notification map.
