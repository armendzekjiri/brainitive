# Releases / fix versions + release notes (BRAI-76)

**Branch:** feat/releases
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-76 (#item-178)

## What the user asked
"fix version? release like jira? to plan releases" — on top of the card's release notes.

## Decisions
- **Release** per project (`releases`): name (unique per project), description, start/release
  date, status `unreleased | released | archived`, released_at. Issues get one **fix version**
  (`items.release_id`, nullable, null on delete). Single fix version keeps reporting simple;
  Jira's multi-version can come later.
- **Progress:** done = `completed_at`; to do = in its board's first column; otherwise in progress.
  Overdue = unreleased and release date passed.
- **Release action:** sets released + date; unfinished issues move to a chosen unreleased
  release or lose their fix version — the user decides, no default move. Unrelease and
  archive are reversible; archived releases can't be picked as fix version.
- **Release notes (card):** deterministic draft from the release's done issues grouped into
  Features (story/epic/feature), Fixes (bug), Improvements (rest) — titles only, no keys,
  links, descriptions or comments. Draft is private and editable (rich text, sanitized with
  `SafeHtml`). **Publish** copies the draft into a public snapshot (`published_notes`) under an
  unguessable token; later edits stay private until "Publish update"; **Unpublish** removes
  public access. Publishing twice is idempotent (one token). Project public changelog
  (`projects.changelog_token`) lists published releases only. No AI in v1.
- **Permissions:** view = project view; manage (create/edit/release/notes/publish, assign
  fix versions from the release page) = project editors/admins (`createBoards` rule).
  Setting the fix version on an issue = editing the issue.
- Previously released: adding an issue that already belongs to a released version is flagged
  in the picker and needs confirming.

## UI
Project sidebar "Releases" → Releases/Index (tabs Unreleased / Released / Archived, progress
bars) → Releases/Show (progress, issues by state, add/remove issues, release dialog, notes
editor + publish). Issue sidebar "Fix version". Public pages: Releases/Public, Releases/Changelog.

## Tests
Feature tests for permissions, progress, release with unfinished issues, fix version
validation, notes draft content, publish/update/unpublish, public endpoints and XSS.

## Also fixed
- Wrong-method requests (e.g. `PUT /login`) returned 500 in production instead of the 405 page.
- `prose` had no effect (no typography plugin) → `rich-html` class for public notes and share pages.
