# Whiteboards: autosave, history, templates, issue cards

**Branch:** feat/whiteboard-essentials
**Status:** done
**Started:** 2026-10-21
**Board card:** —

## Problem / goal
"Miro inside Brainitive" — without building a second canvas. The Excalidraw whiteboards
already exist but feel fragile and empty:
- No autosave; leaving the tab loses work. Two people saving overwrite each other silently.
- No history for whiteboards (versions only came from publishing documents).
- Every board starts blank — no retro / roadmap / story-map starting points.
- Stickies and the board are disconnected: workshop output has to be retyped as issues.

Out of scope: realtime multi-cursor collaboration (needs Reverb/websockets — a dependency
change, not approved).

## Approach
1. **Autosave + conflict guard** — editor autosaves 2.5 s after the last change, sending
   `base_revision`; `saveExcalidraw` answers 409 when the page moved on (banner: Load latest /
   Overwrite with `force`). `beforeunload` warns while dirty. Embeds keep the old flow.
2. **History** — `Page::saveWhiteboard()` records a checkpoint on Save / Save & exit, when
   the newest version is > 30 min old, or when someone else saved last. Restoring a
   whiteboard records a new version. History dialog previews whiteboard versions read-only;
   the editor's *History* link opens it (`?history=1`).
3. **Templates** — Retrospective, Roadmap (Now/Next/Later), Brainstorm, User story map,
   Flowchart, SWOT as Excalidraw element skeletons. Picker opens on new empty whiteboards
   (`?new=1`) and from the *Templates* button; inserted next to existing content.
4. **Issues** — `WhiteboardController`: search project issues and drop them as cards
   (coloured by status category, linked to the item); select stickies → create one issue
   each on a chosen board (first column, backlog) and turn the stickies into cards. Cards
   refresh when the editor opens or via *Refresh cards*.

## Changes
- Model / controllers: `Page::saveWhiteboard`, `PageController::excalidrawEditor|saveExcalidraw`,
  new `WhiteboardController` + routes `pages.whiteboard.issues(.store)`.
- Editor: `resources/views/excalidraw-editor.blade.php`; bundle `excalidraw-build/src/`
  (`main.jsx`, `templates.js`, `issueCards.js`) → `public/js/excalidraw-dist/`.
- Frontend: `components/page/ExcalidrawViewMode.tsx` (extracted from `Pages/Pages/Show.tsx`),
  `components/page/PageHistoryDialog.tsx` (whiteboard preview), `Show.tsx` (`?new=1`, `?history=1`).

## Test plan
`tests/Feature/Pages/WhiteboardTest.php`: revision bump + first checkpoint, no version per
autosave, checkpoint rules (interval / explicit / other user), 409 + force, old clients
without `base_revision`, viewer forbidden, restore records a version, editor config
(`isNew`, boards, history URL), issue search scoping + status category, refresh by ids
ignores other projects, outsider forbidden, stickies → issues (column, backlog, keys,
positions), authz + validation.

Result: 14 tests green; full suite green. Editor checked in Chromium against mocked
endpoints: template picker + insert, autosave with `base_revision`, 409 banner + overwrite,
create issues from selection, add cards, refresh (In Progress → Done redraw).

Found while testing: arrow-fn `++$position` in the create loop captured by value (all new
issues got position 1) — fixed with a plain loop.

## Docs to update
ARCHITECTURE (Pages), CONVENTIONS (Excalidraw), KNOWLEDGE (whiteboard gotchas), tasks index.

## Open questions
- Realtime collaboration (Reverb) — step 4, not approved.
- Cards refresh on open only; a board-side "where is this issue on a whiteboard" link is not built.
