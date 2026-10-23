# Fix: saved swimlanes don't show on the board

**Branch:** fix/swimlanes-default-wins
**Status:** done
**Started:** 2026-10-23
**Board card:** — (MCP not connected; follow-up to BRAI-87, reported by the owner)

## Problem
"I set custom lanes, hit save, and see no difference." The board's lane mode came from a
per-browser choice in localStorage (`board-{id}-lane-mode`) and fell back to the board's
saved default only when nothing was stored. Anyone who had ever touched the toolbar's Lanes
menu (even "No lanes") never saw lanes an admin set up later.

## Change
`Boards/Show.tsx`: the personal choice is stored as `{ mode, setup }`, where `setup` is
`JSON.stringify(canonicalConfig(board.settings.swimlanes))`. It applies only while the board's
setup is unchanged; after a new save everyone starts from the new default again. Old
plain-string values are ignored once. "Custom" with no lanes falls back to the default, then
to no lanes. Shipped in v1.27.1 together with `fix-swimlanes-save-state.md`.

## Test plan
Gate. No JS test runner; the cases (old stored value, choice after a re-save, custom without
lanes) were walked through against the code.
