# Bookmarks: Collaborators and Description move into the header (GitHub #152)

**Branch:** feat/bookmark-header-details
**Status:** done
**Started:** 2026-11-15
**Board card:** — (worked from the GitHub issue; the parent session tracks the card)

## Problem / goal
`Pages/Bookmarks/Show.tsx` rendered "Collaborators" and "Description" as cards in the left column under
"Add to bookmark", so the left column grew long and both read like content. They are details of the
collection: move them into the page header so the left column holds only "Add to bookmark" and the content
cards (Links, Files, To-dos, Notes) use the width.

## Approach
- **Description** = a muted subtitle under the title (2 lines, full text in the editor / on "Show more").
  People who may edit (`canEdit`) click it to edit inline (Textarea, Save / Cancel, ⌘/Ctrl+Enter saves,
  Escape cancels); empty → "Add a description" placeholder. Read-only: the text, nothing when empty. Same
  request as before (`PUT bookmarks.update` `{name, description}`).
- **Title edit** stays, as a pencil button ("Edit title") next to the title instead of the text link under it
  (the subtitle now lives there).
- **Collaborators** = an avatar stack next to the Private / Shared control (`components/bookmarks/
  BookmarkCollaborators.tsx`): owner first, then collaborators, up to 3 avatars + "+n". It opens a popover with
  everyone and their role (Owner / Editor / Viewer); people who may edit change roles, remove (same
  confirmation as before) and invite there — same routes (`bookmarks.collaborators.invite|update|remove`,
  `BookmarkPolicy::manageCollaborators` = update). When the owner is the only member there is no stack: editors
  get an "Invite" button (opens the same popover), read-only viewers see nothing.
- Avatars: `show()` loads `avatar` for creator and collaborators so `avatar_display` (appended) has a picture.
- Left column = "Add to bookmark" only; viewers (no left column) no longer get the separate "Description"
  section above the grid — it's in the header for them too.
- Phone width: the header row wraps (title block `flex-1 basis-40`, actions `ml-auto`), so the actions go to
  a second line instead of squeezing the title; no horizontal scroll. Dark mode: only theme tokens.
- Public view (`ShowPublic.tsx`) already shows the description under the title and no collaborators; it only
  gets `whitespace-pre-wrap` so multi-line descriptions look the same.

## Changes
- Controller: `BookmarkController::show` (avatar column for creator + collaborators).
- Frontend: `Pages/Bookmarks/Show.tsx` (header, left column), new `components/bookmarks/BookmarkCollaborators.tsx`,
  `Pages/Bookmarks/ShowPublic.tsx` (pre-wrap), `types/index.d.ts` (`Bookmark.collaborators[].avatar_display`).

## Test plan
- PHPUnit `tests/Feature/Bookmarks/BookmarkHeaderDetailsTest.php`: show sends the description, creator +
  collaborators with roles and `avatar_display`; an editor collaborator can invite / change / remove, a viewer
  collaborator can't (nor change the description); the description saves and clears.
- e2e `e2e/tests/bookmark-header.spec.ts`: add a description from the header (placeholder → saved subtitle),
  invite a collaborator from the header popover (stack shows, role listed, role change, remove with
  confirmation), the left column has only "Add to bookmark"; phone width (390 px) has no horizontal scroll and
  the header still shows title, subtitle and controls.

## Docs to update
- ARCHITECTURE → Bookmarks (header details component), docs-site `guide/bookmarks-and-snippets.md`.

## Follow-ups
- `canEdit` (`isEditableBy`) doesn't include workspace admins although `BookmarkPolicy::update` does — they can
  open and change a private bookmark but see it read-only. Pre-existing; not changed here.
