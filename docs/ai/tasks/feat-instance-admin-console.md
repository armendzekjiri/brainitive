# Instance admin console (and no more data access for instance admins)

**Branch:** feat/instance-admin-console
**Status:** done
**Started:** 2026-10-22
**Board card:** BRAI-49 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-148

## Problem / goal
Instance admins currently bypass **every** policy (`Gate::before()`), see every workspace
in the sidebar, search, calendar, My Work and MCP fetch, and read everyone's pages, boards
and drafts. That's a privacy problem for a multi-tenant / self-hosted instance.

Goal: instance admins **run the instance** but **don't see content**. They get a dedicated
admin console with metadata only (counts, owners, dates) and the tools an operator needs.

## Approach
### 1. Remove the data bypass
- Drop `Gate::before()` and every `isInstanceAdmin()` data branch: `User::hasWorkspaceAccess`
  / `hasProjectAccess`, `HandleInertiaRequests` workspaces, `WorkspaceController`
  (index / role), `Page::scopeVisibleTo`, `SearchController`, `CalendarController`,
  `MyWorkController`, `FetchTool`. Admins keep exactly the access their own memberships give.

### 2. Admin console — `/admin/*` (`auth`, `verified`, `instance.admin` middleware)
- **Overview:** users (total, active 30 d, suspended, unverified), workspaces, projects,
  pages, boards, items; failed / pending queue jobs; storage used; PHP / Laravel / DB / mail /
  queue drivers; app version.
- **Users:** search + paginate; per user: workspaces owned, workspace memberships, projects,
  created, last login, verified, admin, suspended. Detail drawer lists their workspaces
  (name, role, project count). Actions:
  - send password reset email / copy a one-time reset link (works without SMTP) — the admin
    never sees or sets a password
  - mark email verified, resend verification
  - suspend / unsuspend (blocks web login via middleware, revokes Passport tokens)
  - make / remove instance admin
  - delete user — only if they own no workspaces; content they created in other people's
    workspaces is **reassigned to that workspace's owner** first (the `created_by` FKs on
    projects / boards / items / task lists / task list items / helpdesks / snippet
    collections / snippets cascade-delete today)
  - guards: never yourself; never the last instance admin
- **Workspaces:** name, owner, members, projects, created. Actions: transfer ownership to an
  existing member (new owner becomes `admin`), delete (type the name to confirm).
- **Settings:** instance name; allow registration (invited emails can always register,
  social sign-up obeys it too); require email verification; SMTP (host, port, encryption,
  username, password — encrypted, never sent back to the browser; from address / name) +
  "send test email". Saved SMTP overrides `.env` mail config at runtime (also in queue
  workers, re-applied per job).
- **Audit log:** every admin action (actor, action, target label, meta, time).

## Changes
- Migrations: `users.suspended_at`, `users.last_login_at`; `admin_audit_logs`; set
  `require_email_verification = true` on the existing settings row (it was never enforced,
  verification has always been on — keeps behaviour unchanged).
- Models: `AdminAuditLog`; `InstanceSettings` helpers (cached, typed getters, mail config);
  `User` (`isSuspended`, `hasVerifiedEmail` honours the setting).
- Support: `app/Support/Admin/UserDeletion` (guards + reassign + delete),
  `InstanceMailConfig` (apply SMTP settings to config).
- Middleware: `EnsureInstanceAdmin` (`instance.admin`), `EnsureUserIsNotSuspended` (web).
- Listener: record `last_login_at` on `Login`.
- Controllers `app/Http/Controllers/Admin/`: `OverviewController`, `UserController`,
  `WorkspaceController`, `SettingsController`, `AuditLogController`.
- Registration: `RegisteredUserController`, Google / GitHub callbacks, `canRegister` props.
- Frontend: `Pages/Admin/{Overview,Users,Workspaces,Settings,AuditLog}.tsx` +
  `components/admin/AdminShell.tsx`; "Instance admin" entry in the `AppLayout` user menu
  (shared `auth.isInstanceAdmin`); drop the all-workspaces text in `Workspaces/Index.tsx`.

## Test plan
Feature tests: instance admin gets 403 on others' workspace / page / board and doesn't see
them in the sidebar / search; non-admins get 403 on `/admin`; user list shows counts only;
reset link / email; suspend blocks requests + revokes tokens; delete guarded + reassigns;
transfer / delete workspace; settings save (SMTP password encrypted, never in props);
registration closed (invited email still works); verification toggle; last-admin guards;
audit rows written. Plus gate + browser check.

## Docs to update
ARCHITECTURE (auth table, new Admin module), CONVENTIONS (roles: instance admin no longer
bypasses), KNOWLEDGE (user-delete cascades, SMTP override), self-hosting.md (admin console,
SMTP), HelpModal changelog v1.15.0.

## Result
- Gate: `pint --dirty` ✓, `tsc` ✓, `php artisan test` ✓ (23 new admin tests), `npm run build` ✓.
- Browser walkthrough (headless Chrome, throwaway SQLite): admin gets 403 on another user's
  workspace; menu entry; overview; user list + drawer; reset link; delete blocked for an
  owner; suspend → user can't sign in; add user with set-password link; transfer ownership;
  SMTP save (password never in the page) + failing / working test email; audit log; mobile.
- Found while building: user deletion cascaded into other people's workspaces (now
  reassigned); `require_email_verification` would have been off on fresh installs;
  `make:instance-admin` now verifies the email so the console works before mail does;
  social sign-up never accepted pending invitations (fixed with the sign-up gate).

## Follow-ups
- `ProfileController::destroy` (self-service delete) still cascades — route it through
  `UserDeletion` (owned workspaces need a decision: delete or block).
- Instance name / logo in `instance_settings` are still unused.
