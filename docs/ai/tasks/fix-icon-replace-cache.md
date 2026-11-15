# Replacing an existing custom icon does nothing (GitHub #154)

**Branch:** fix/icon-replace-cache
**Status:** done
**Started:** 2026-11-15
**Board card:** — (MCP not used for this task)

## Problem / goal
Project and bookmark icon uploads were always stored as `project-icons/{id}/icon.{ext}` /
`bookmark-icons/{id}/icon.{ext}`. Replacing a custom icon with another PNG therefore returned the very same
path and URL: the icon picker's `onChange` got an unchanged value (project settings form not dirty, nothing to
save) and browsers / CDNs kept showing the cached old image (`max-age` 14400). Replacing an icon looked like it
did nothing.

## Approach
- Every icon upload gets a unique name: `icon-<16 random chars>.{ext}` (`Uploads::iconName()` /
  `Uploads::iconFileName($ext)`, extension still from the detected MIME type). New URL → the form is dirty and no
  cache can serve the old picture.
- The superseded file is still deleted. Project: `ProjectController::ownIconPath()` now recognises both the old
  `icon.{ext}` and the new `icon-<random>.{ext}` names (`Uploads::isOwnIconPath()`), so old-style icons are
  cleaned up when replaced. Bookmarks: unchanged — `WorkspaceFiles::forget()` deletes by the recorded row or the
  `bookmark-icons/{id}/` prefix, independent of the file name.
- Stale form guard: with unique names a project settings form opened before a newer upload (another tab) would
  send back the old, already deleted path. An own icon path that differs from the project's current icon is
  ignored (the current icon is kept) instead of pointing the project at a missing file.
- Checked, no change needed: `BackfillFileUsage` (matches by `project-icons/%` / `bookmark-icons/%` prefix),
  project archive export / templates (drop uploaded icons, keep emoji / Lucide names only), item type icons and
  temp icons (already `Str::uuid()` names), snippet icons (temp UUIDs).
- Alternative rejected: a `?v=<timestamp>` cache-buster on `icon_display`. It needs a stored version per model,
  and the stored path would still be identical (form not dirty from server-side props).

## Changes
- `app/Support/Uploads.php` — `iconName()`, `iconFileName()`, `isOwnIconPath()`.
- `app/Http/Controllers/ProjectController.php` — `update` (icon_image) and `uploadIcon` use unique names;
  `ownIconPath()` delegates to `Uploads::isOwnIconPath()`; stale own path keeps the current icon.
- `app/Http/Controllers/BookmarkController.php` — `store` (icon_image + temp move), `update`, `uploadIcon` use
  unique names.
- Frontend: none (the new URL makes the form dirty on its own).

## Test plan
- PHPUnit `tests/Feature/Projects/IconReplaceTest.php`: a second project icon upload (`uploadIcon` and the
  settings form's `icon_image`) changes the stored path / `icon_display` and deletes the old file and its
  `editor_uploads` row; an old-style `icon.png` is deleted when replaced; a stale own path keeps the current icon;
  bookmark icon replace changes path and removes the old file (also old-style).
- e2e: no existing icon upload spec in `e2e/tests` (only `page-icons.spec.ts`, which covers page emoji / data-URL
  icons, a different code path), so none added here.

## Docs to update (ARCHITECTURE / CONVENTIONS / KNOWLEDGE / skills)
- KNOWLEDGE: uploaded files that replace each other need a new name (cache + dirty form).

## Open questions
- Switching a project from an uploaded icon to an emoji still leaves the old file on disk (pre-existing, not part
  of #154).
