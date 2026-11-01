# Item types: turning several off, and keeping them out of every type picker

**Branch:** fix/item-types-toggle (from release/v1.48.0)
**Ticket:** BRAI-430 = GitHub #133 (bug, self-hosted 1.47.2)
**Status:** done (awaiting merge into release/v1.48.0)
**Started:** 2026-10-31

## Problem / goal
Project settings → Item types: after one type is turned off, turning off another "doesn't work",
and a turned-off type is still offered when creating a task. Expected: any number of types can be
turned off (and back on); every place that picks a type for a new / edited issue only offers the
enabled ones; cards that already have a turned-off type keep and show it.

## Root cause
1. `ProjectController::edit` marks switched-off default types with
   `->each(fn ($t) => $t->project_id === null && $t->is_active = …)`. `Collection::each()` stops at
   the first callback that returns `false`, and the arrow fn returns the assigned value — so the
   loop ends at the first disabled type. Every later disabled type is shown as on (Default badge,
   "Disable" button): clicking it again saves the same state, so "the second one won't turn off"
   (or the first one seems to come back). The data in `projects.settings.disabled_item_types` was
   right all along.
2. The global "Create" dialog (`GlobalCreateModal`) lists the shared Inertia prop `itemTypes` =
   **every** type of the instance (no project filter, disabled ones and other projects' custom
   types included).
3. Project-owned types can't be turned off at all (only deleted, which fails while used), and
   several fallbacks pick `ItemType::where('is_default', true)` (global Task) even when the
   project switched it off; `storeItem` / `updateItem` accept any `item_types.id`.

## Approach and changes
- `ItemType`: `forProject` also drops project-owned types with `is_active = false`;
  new `setEnabledFor(Project, bool)` (default type → project settings, own type → `is_active`),
  `isEnabledFor(Project)`, `defaultIdFor(Project)` (enabled default type, else first enabled
  non-subtask type, else null) and `selectableIdsFor(Project, ?keep)`.
- `ProjectController::edit` computes `is_active` with `map` (no early stop); `updateItemType`
  uses `setEnabledFor` for both kinds; new JSON `projects.item-types.index` (enabled types).
- Settings UI: on/off button for project types too.
- `storeItem` / `updateItem` validate `item_type_id` against the enabled types (update also
  keeps the item's current type). Defaults via `defaultIdFor` in BoardController, ProjectIssues,
  RecurringIssueRunner, BookingService, importers / templates where they used the global default.
  Drop the "no types → all types" fallbacks (board show, bulk edit, board views).
- Frontend: GlobalCreateModal loads the selected project's types from the new endpoint (shared
  `itemTypes` prop removed — it leaked every project's custom types); quick add defaults to the
  project default; issue panel type select keeps a card's current (turned-off) type as an extra
  option; type swimlanes give cards of a turned-off type their own lane instead of losing them.
- MCP: `UpdateItemTypeTool` / `CreateItemTypeTool` toggle / re-enable through `setEnabledFor`;
  `update_board_item` may keep the card's current type; `get_project_config` already uses
  `forProject`.

## Test plan
- PHPUnit `tests/Feature/Projects/ItemTypeToggleTest.php`: two types off → settings page shows
  both off; project type off/on; board + create endpoint + global create endpoint offer only
  enabled types; store / update validation; existing card keeps type; default fallback; MCP.
- e2e `e2e/tests/item-types-toggle.spec.ts`: turn off two types, reload, create dialog / quick add
  / issue panel don't offer them, an existing card still shows its type, turn one back on.

## Docs
KNOWLEDGE (item types entry: `forProject` covers own types, `each()` gotcha, `defaultIdFor`),
ARCHITECTURE (projects: item types endpoint).
