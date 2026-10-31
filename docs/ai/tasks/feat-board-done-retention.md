# Done column retention: keep done cards on the board for N days (BRAI-346)

**Branch:** feat/board-done-retention (from `release/v1.48.0`)
**Status:** done
**Started:** 2026-10-30
**Board card:** BRAI-346 (bug: "max days in done! do how jira do it!")

## Problem / goal
The Done column of a Kanban board keeps every finished card forever and grows huge. Jira Kanban
boards have the "Completed issues" sub-filter ("hide completed issues older than…", default
2 weeks): the last column only shows recently finished issues; older ones stay in the project
(search, list view, links) but leave the board.

## Approach
- **Setting:** `boards.settings.done_retention_days` — `0` (Off, show all) or `7 / 14 / 30 / 60 / 90`
  (`Board::DONE_RETENTION_OPTIONS`). Stored in the existing `settings` JSON next to `swimlanes` /
  `github`, so no schema change for it.
  - **Existing boards: Off.** A board without the key reads as 0, so nobody's board suddenly loses
    cards after the deploy; teams opt in.
  - **New boards: 14 days, like Jira.** `Board::creating` adds the key when it is missing (UI,
    project create, MCP, built-in templates). Templates and project archives carry the value of the
    board they came from (archives from before this change → Off, the old behaviour). **Jira / Trello
    imports start Off**: an imported board is full of history the user didn't choose to hide (decided
    while building — the import-jira e2e journey expects its resolved card in Done).
  - **Kanban only (like Jira).** Scrum boards show the running sprint, which already bounds Done;
    `Board::doneRetentionDays()` is 0 for scrum, the settings card says so.
  - Edited in Board settings ("Done column" card), `PUT boards/{board}/done-retention`
    (`boards.done-retention.update`, `BoardPolicy::update`), and via MCP `update_board`.
- **Reliable `completed_at`:** the column already exists (set by `Item::moveToColumn`, bulk edit,
  GitHub merges, imports) but several paths skipped it (create in a done column, MCP create /
  update, offline creates…). `Item::saving` now keeps it in step with the column on every Eloquent
  write: entering a done column sets it (unless the caller set a date, e.g. Jira's resolved date),
  leaving a done column clears it, done → done keeps the original date (reordering inside Done no
  longer "re-completes" a card). A column switched to done stamps its cards with now; switched off
  clears them (query builder + `BoardLive::itemIds`). Migration backfills done-column cards
  without a date from `updated_at` (fallback `created_at`).
- **Where the rule applies — client side, like every other board filter** (boards load every card;
  CONVENTIONS "Filters/sorting are client-side"). `lib/doneRetention.ts`: a card in a done column
  is "older" when `completed_at < now − N days`; no date = never hidden.
  - **Kanban + swimlanes** hide older done cards; the done column shows a footer
    "12 older done cards hidden · Show". "Show" is per board and per browser tab
    (`sessionStorage`), "Hide" puts them away again.
  - **Counts / WIP:** the column count and the WIP limit count what the board shows (hidden cards
    have left the board, as in Jira); the footer names the hidden number. Filters apply first, so
    the hidden number is "older done cards that match the filters".
  - **List, Timeline, Calendar views are unaffected** — the old cards stay there (and in search,
    links, My work, reports).
  - **Saved views:** not a view param. The setting is the board's; "Show" is a session toggle.
  - **Live (BRAI-152):** nothing new to publish for cards — the client re-evaluates with the card's
    `completed_at` from `boards.cards`; a settings change publishes `board.updated` (model event) so
    open boards reload the setting.
- **MCP `get_board`:** applies the same rule to done columns by default, returns
  `done_retention_days` and per column `hidden_done_count`; `include_old_done: true` lists all.

## Changes
- Migration: `2026_10_30_120000_backfill_items_completed_at.php` (data only, query builder, cross-DB).
- Models: `Board` (constants, `doneRetentionSetting()`, `doneRetentionDays()`, `doneCutoff()`,
  creating default), `Item` (saving hook, `moveToColumn` keeps the date done → done),
  `BoardColumn` (done flag toggle).
- Controller / routes: `BoardController::updateDoneRetention`, route `boards.done-retention.update`.
- Frontend: `lib/doneRetention.ts`, `components/board/DoneRetentionSettings.tsx` (Board settings),
  `Pages/Boards/Show.tsx` (kanban + swimlanes filtering, footer, counts), `types/index.d.ts`.
- MCP: `GetBoardTool` (filter + counts + `include_old_done`), `UpdateBoardTool` (`done_retention_days`).
- Templates (`TemplateCapture`, `TemplateDocument`, `TemplateInstantiator`) and archives
  (`ProjectArchiveWriter`, `ProjectArchiveRestorer`) carry the setting.
- Importers: `JiraImporter`, `TrelloImporter` create their board with retention Off.

## Test plan
- PHPUnit `tests/Feature/Boards/DoneRetentionTest.php`: defaults (new 14 / existing Off), setting
  validation + policy (editor ok, viewer / outsider forbidden), scrum reads 0, completed_at set on
  create in Done / move in / cleared on move out / kept done → done / explicit date kept, column
  toggle, backfill migration, MCP get_board filtering + include_old_done, update_board, template +
  archive round trip.
- e2e `e2e/tests/board-done-retention.spec.ts`: a two-issue Jira CSV (one resolved 10 days ago) is
  imported into its own board (the importer keeps the resolved date — no seeder or test-only hook
  needed); set the board to 7 days, the old card disappears from Done, the list view still lists it,
  a freshly completed card stays, "Show" brings it back (and survives a reload in the tab), "Hide",
  then 14 days shows it again.

## Docs to update
ARCHITECTURE (Kanban section), KNOWLEDGE (completed_at semantics + retention decisions).

## Open questions
- Server-side trimming of the payload for very large Done columns (would need the list view to
  load separately) — follow-up if boards with thousands of done cards get slow.
