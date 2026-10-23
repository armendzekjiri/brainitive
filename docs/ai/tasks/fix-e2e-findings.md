# Small fixes from the e2e suite (BRAI-97)

**Branch:** fix/e2e-findings (into release/v1.36.0)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-97 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-206

## Problem
- A `#item-N` link to a card created after the board loaded opened the full item page instead of
  the dialog. The hashchange listener only knew the cards of the first render.
- Blank projects started their issue numbers at KEY-2. `next_item_number` holds the last number
  used, but defaulted to 1.

## Changes
- `Boards/Show.tsx`: the listener reads the current columns through a ref.
- Migration `2026_10_25_120000_issue_numbers_start_at_one`: the default becomes 0, and projects
  that never had an issue reset to 0. Projects with issues keep their counter.
- `WorkTimerTest` used a hand-picked `BRAI-1` that now collides with the first generated key.

## Tests
- PHPUnit: `IssueNumberingTest`.
- e2e:
  - "a #item link to a card created after the page loaded opens it in the dialog" (boards)
  - "the first issue of a new project is KEY-1" (workspaces-projects)
