# Saved board views (BRAI-78)

**Branch:** feat/saved-board-views
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-78 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-180

## Problem / goal
People rebuild the same board filters again and again ("my open bugs", "sprint by priority").
Save the current board view configuration under a name — personal or shared with the team —
reopen it in one click, see when it was changed, and pick a personal default per board.

## Approach
The board already keeps its whole view in the URL (`q`, `assignee`, `status`, `labels`,
`priority`, `type`, `quick`, custom-field `f<id>`, list `sort`, `view`). A saved view stores that
parameter map — no second filter language:

```
config = { version: 1, params: { q?, assignee?, status?, labels?, priority?, type?, quick?, sort?, view?, f<id>? } }
```

- `App\Support\Boards\BoardViewConfig`: allowlisted keys + value formats (normalize on save),
  and `sanitize()` against the current board on open — drops deleted columns / labels /
  priorities / types / fields / members and reports what was dropped.
- Opening a view: `GET …/boards/{board}/views/{view}/open` → authorizes, sanitizes, redirects to
  the board URL with the clean params + `bv=<id>` and a flash warning when something was dropped.
- `assignee=me` is stored literally, so it resolves for whoever opens the view.
- Default: `board_view_defaults` (user, board → view). Opening the board without view params
  redirects to the default (`bv=0` = ordinary board). Deleting the view removes the default.
- Permissions (`BoardViewPolicy`): personal views only for their owner (who must still see the
  board); shared views for everyone who can see the board; creating / changing / deleting a
  shared view needs board `update`. Sharing never grants board access — items stay filtered by
  the normal board query.
- UI: "Views" menu in the board toolbar: personal + team views, save, save as new, rename,
  duplicate, delete, share toggle (managers), set / clear default; "unsaved changes" when the
  current params differ from the active view.

## Changes
- Migration: `board_views`, `board_view_defaults`.
- Models `BoardView`; policy `BoardViewPolicy` (registered); `BoardViewController`; routes under
  `projects/{project}` (scoped bindings).
- `BoardController::show`: `savedViews`, `activeViewId`, `defaultViewId`, `canShareViews`,
  default redirect.
- Frontend: `components/board/SavedViewsMenu.tsx`, `Pages/Boards/Show.tsx` (current params,
  list sort lifted for unsaved detection).

## Out of scope (v1)
Cross-project dashboards, scheduled reports, list-view column visibility (doesn't exist yet).

## Test plan
URL round-trip (save → open reproduces params), personal privacy, shared permissions, `me`
per viewer, deleted fields / members / columns → warning + reset, defaults (set, apply, delete),
config validation (unknown keys, bad formats), duplicate.
