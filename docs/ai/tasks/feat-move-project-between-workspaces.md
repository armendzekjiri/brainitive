# Move a project to another workspace

**Branch:** feat/move-project-between-workspaces
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-58 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-160

## Decisions (from the user)
- Only an admin/owner of **both** workspaces can move a project.
- Project members who are already in the target workspace keep their project role; others are removed and are invited by hand (workspace, then project).
- Everything in the project moves: boards/issues, docs, whiteboards, plans, task lists, helpdesks/tickets, labels, item types.

## Approach
`App\Support\Projects\ProjectTransfer`: `preview()` (keep / remove lists, key clash, new slug, external links) and `move()` (transaction: detach non-members, delete links to issues outside the project, update `workspace_id` + free slug). The project key must be free in the target, because it's part of every issue and ticket key; the UI says to change it first. Routes `projects.move.preview` (JSON) and `projects.move` (confirm by typing the project name). UI in Project settings → Danger zone.

## Test plan
`tests/Feature/Projects/MoveProjectTest.php` (content follows, members kept/removed, external vs internal links, admin of both, key/slug clash, confirm name), also run on PostgreSQL. Browser: preview, then move, then redirect to the new workspace; the helpdesk opens there.

## Known limits
Old URLs (notifications, links pasted in docs) contain the old workspace slug and stop working.
