# Fix: priorities and board columns (BRAI-84, BRAI-85/86)

**Branch:** fix/priorities-and-columns
**Status:** done
**Started:** 2026-10-23
**Board cards:** BRAI-84 (#item-186), BRAI-85 (#item-187), duplicate BRAI-86 (#item-188)

## Findings
- **Priority (BRAI-85/86):** production had 0 rows in `item_priorities` — the seeder was never
  run there (deploys only migrate). Picker empty, default priority null. Fix: migration that
  runs the idempotent type + priority seeders. Existing issues keep "no priority" (no silent
  backfill); new issues get Medium.
- **Columns (BRAI-84):**
  - Board settings kept `board.columns` in `useState` without syncing → created/edited/deleted
    columns only showed after a reload. Synced via `useEffect`.
  - Edit dialog was mounted once with `column = null`, so `useForm` kept empty/stale values →
    remount per column (`key`).
  - Board page "New column" only linked to settings → inline create tile (stays open for the
    next name) + "Rename column" in the column menu; WIP dialog resets to the current value.
  - Server: unique `(board_id, slug)` made duplicate or emoji-only names 500 → suffixed slugs
    (`review-2`, `column`); update/destroy didn't check the column belongs to the board → 404.
  - Settings page had two inputs with `id="name"` (board + new column) → label pointed at the
    wrong one.

## Tests
`tests/Feature/Boards/BoardColumnTest.php`; browser check of board + settings + priority picker.
