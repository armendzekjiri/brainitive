# Security hardening, batch 2: access control

**Branch:** fix/security-batch-2
**Status:** done
**Started:** 2026-10-23

## Changes
- Page and bookmark route binders resolve `{workspace}` / `{project}` from the slugs (explicit
  binders run before implicit binding) — a page only binds inside its own project.
- Policies: workspace editors get edit rights only in projects they can open (boards' items,
  task lists, plans, releases via createBoards, helpdesk tickets).
- Mentions: only people who share a workspace with the sender; the link must point into the app
  and is stored as a path. Notification links render / open only app URLs (UI + service worker).
- Assignees and watchers must be project members (`ProjectMember` rule, web + CLI); viewers may
  only (un)watch themselves.
- Calendar reschedule needs edit rights on the item / list.
- Member profiles only show work from projects the viewer can open.
- Workspace `settings` hidden from serialization; private bookmark collections not listed to
  others (`Bookmark::scopeVisibleTo`); AI generate throttled.

## Tests
`tests/Feature/Security/AccessControlTest.php`; PlanIssueLinkTest adjusted to the new rule.
