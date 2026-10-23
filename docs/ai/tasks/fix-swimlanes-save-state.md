# Fix: swimlane save state, errors and release order

**Branch:** fix/swimlanes-save-state
**Status:** done
**Started:** 2026-10-23
**Board card:** — (MCP not connected; follow-up to BRAI-87)

## Problem / goal
Found by the first trial run of the review agents (see `chore-ecc-agents.md`) on the
swimlanes commit:
1. **High:** after saving swimlanes, "Unsaved changes" stayed on. The settings page compared
   its local config (kept by `preserveState`) with the server's normalized copy, which
   orders rule keys differently, drops empty conditions and `overdue: false`, and returns a
   rule-less lane as `[]` instead of `{}`. There was no success feedback either.
2. A failed swimlane save or lane drop (e.g. into an archived fix version) did nothing visible.
3. A "Custom lanes" choice remembered in localStorage stayed active after the board's
   custom lanes were removed (toolbar said "Custom lanes", no radio checked). `stored in
   MODE_LABELS` also accepted prototype keys.
4. Release choices used `orderBy('release_date')` on a nullable column: undated first on
   SQLite, last on Postgres.

## Changes
- `lib/swimlanes.ts`: `canonicalConfig()` mirrors `Swimlanes::normalize()`'s shape.
- `SwimlaneSettings.tsx`: `dirty` compares canonical forms; `onSuccess` resets from the
  stored config + success toast; `onError` toast.
- `Boards/Show.tsx`: effective `laneMode` falls back when there are no custom lanes; own-key
  check on the stored mode; `onError` toast on lane drops.
- `BoardController` + `ReleaseController::show()` (`otherReleases`, the default "move unfinished
  issues to" target): release pickers sort
  unreleased first, then by date with undated last (`orderByRaw('release_date is null')`).
- Test: `SwimlaneTest::test_fix_version_choices_list_undated_releases_last` (fails without
  the fix on SQLite).
- `HelpModal` CHANGELOG v1.27.1.

## Test plan
Gate, plus the new feature test. There is no JS test runner, so the canonical comparison
was checked by reading against `Swimlanes::normalize()`.
