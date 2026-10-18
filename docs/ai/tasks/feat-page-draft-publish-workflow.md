# Pages: Confluence-style draft / publish workflow

**Branch:** feat/page-draft-publish-workflow (stacked on feat/doc-editor-outline-style)
**Status:** done
**Started:** 2026-10-18
**Board card:** —

## Problem / goal
Edit/publish doesn't behave like Confluence:
1. Autosave on a **published** page writes straight into `content` — readers see half-written
   text; "Update" only re-saves + notifies; nothing can be discarded.
2. "Draft" is only a badge — everyone in the project sees draft pages.
3. No version history — every autosave overwrites.
4. Title/icon edits on a published page go live immediately.
5. No authorization on page routes (`auth` + `verified` only) — any user with the UUID can
   view / edit / publish / delete. Viewers can edit.
6. Concurrent editors silently overwrite each other.
7. `duplicate()` authorizes against a non-existent policy (403 for non-instance-admins);
   `toggleWatch` accepts any `user_id`.

## Decisions (from the user)
- Unpublished pages are visible to their **author + admins** only (folders are always visible).
- **Enforce project roles** via a new `PagePolicy`.
- History: **list + view + restore** (no visual diff yet).
- Concurrency: **presence + conflict guard**, not real-time co-editing.
- Presence uses a cache heartbeat instead of Reverb (not installed; adding it is a
  dependency change).

## Approach
**Data**
- `pages`: `draft_content`, `draft_name`, `draft_icon`, `draft_updated_at`,
  `draft_updated_by`, `edit_revision` (bumped on every autosave → conflict guard),
  `current_version`.
- `page_versions`: one immutable snapshot (`name`, `icon`, `content`, `message`,
  `created_by`) per publish. Migration backfills v1 for already-published pages.

**Lifecycle**
- Never-published page (`status=draft`): autosave writes `content` directly (it *is* the
  draft). **Publish** → `published`, creates v1.
- Published page: autosave writes `draft_*` only; readers keep seeing `content`.
  **Update** → draft becomes `content`, new version (+ optional message, optional watcher
  notification). **Discard** → clears the draft. **Close** keeps the draft.
- **Unpublish** folds a pending draft into `content` (page becomes the author's draft again).
- **Restore version** → content/name/icon from the snapshot, clears the draft; on a
  published page it creates a new version "Restored from vN".
- MCP `update-page` on a published page publishes directly and records a version.

**Concurrency**
- Autosave is a JSON `PUT pages/{page}/draft` with `base_revision`; mismatch → `409` with
  who/when, the client shows a banner (reload or overwrite with `force`).
- Presence channel `pages.{id}` (Reverb) shows who is viewing / editing.

**Permissions** (`PagePolicy`, same role logic as `BoardPolicy`)
- `view`: project access; unpublished pages only author or admin (folders exempt).
- `create` / `update`: workspace admin or project admin/editor (+ `view`).
- `delete`: admins, or the author if they can edit.
- `Page::visibleTo($user)` scope filters drafts in every listing (tree, project, children,
  dashboard, search, MCP).

## Changes
- Migrations: draft columns + `page_versions` (+ backfill).
- Models: `Page` (casts, relations, `visibleTo`, workflow methods), `PageVersion`;
  factories for both.
- Policy: `PagePolicy` (+ register in `AppServiceProvider`).
- Controller / routes: `PageController` (authorize all actions, draft / publish-changes /
  discard / versions / restore), `PageVersionController`; `routes/channels.php`.
- Frontend: `Pages/Pages/Show.tsx` (workflow UI, history panel, conflict banner,
  presence), `types/index.d.ts`.
- MCP: page tools use `PagePolicy` + `visibleTo`; update creates a version.

## Test plan
Feature tests (`tests/Feature/Pages/`): draft isolation on published pages, publish/v1,
update + version + notify toggle, discard, unpublish folding, conflict 409 / force,
versions list/show/restore, policy matrix (viewer / editor / admin / outsider), draft
visibility in tree/project listing, duplicate + watch fixes, MCP update versioning.

Result: 32 new tests in `tests/Feature/Pages/` (workflow, access, MCP), full suite 64
green; `pint --dirty`, `tsc`, `npm run build` green. UI states checked in the Vite harness
(reader, draft banner, editing draft, conflict, history, new page). Ran on PHP 8.3 with
`composer install --ignore-platform-req=php` (container had no 8.4).

## Docs to update
ARCHITECTURE (Pages module), CONVENTIONS (page workflow), KNOWLEDGE (draft columns gotcha).

## Open questions
- Real-time co-editing (Yjs) and visual diff are follow-ups.
