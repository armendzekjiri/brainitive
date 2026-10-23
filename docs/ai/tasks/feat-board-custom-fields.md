# Board custom fields: typed values, filtering and sorting (BRAI-77)

**Branch:** feat/board-custom-fields
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-77 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-179

## Problem / goal
Teams need their own fields on issues (customer, budget, go-live date, "needs QA", environment…).
Project admins define typed fields; editors fill them in on the issue; everyone who can see the
issue can read them; the board list view shows chosen fields as columns and the board can be
filtered and sorted by them.

## Approach
Fields are **project-level** (like labels), optionally limited to some boards. Values are stored
**typed** in one row per item + field (one nullable column per type), so sorting and range
filters compare real numbers/dates and `0` / `false` never collapse into "empty".

- **Types (v1):** `text`, `number`, `date`, `checkbox`, `select` (single). No formulas.
- **Stable ids:** the numeric `item_fields.id` / `item_field_options.id`; renames never touch values.
- **Empty vs zero/false:** "no value" = no row. A number `0` and a checkbox `false` are rows.
  An empty text is "no value" (Laravel turns `''` into `null` anyway).
- **Option lifecycle:** values point at `option_id`, so a rename is free. Removing an option
  archives it (`archived_at`): existing values keep showing it (marked archived), pickers stop
  offering it. Options are never hard-deleted.
- **Field lifecycle:** archive / restore. An archived field disappears from boards, issues and
  filters, its values stay. Hard delete only for a field that has no values (typos).
- **Type changes:** allowed only while the field has no values. With values the server refuses
  (422) — the explicit strategy is "create a new field and archive the old one"; no silent
  conversion in v1.
- **History:** `ActivityLog::log($item, 'field_changed', ['value' => [from, to]], ['field' => name])`
  with display values (option label, `Y-m-d`, Yes/No), so history survives renames.
- **Filter / sort:** boards load every card (no pagination today), so filtering and sorting stay
  client-side like the existing filters. One URL param per field (`f{id}=…`), sort in the list
  view (`sort=f12` / `sort=-f12`); **empty values always sort last** in both directions, ties by
  key. Pure functions in `resources/js/lib/customFields.ts`.
- **List columns:** a column picker in the list view; choice per board in localStorage
  (`board-{id}-list-fields`). Shared/team views are BRAI-78's job.

## Changes
- **Migrations:** `item_fields` (project_id, name, type, board_ids json null = all boards,
  position, archived_at), `item_field_options` (item_field_id, label, color, position,
  archived_at), `item_field_values` (item_id, item_field_id, value_text, value_number
  decimal(20,6), value_date, value_bool, option_id; unique(item_id, item_field_id); indexes
  per typed column with item_field_id first).
- **Models:** `ItemField`, `ItemFieldOption`, `ItemFieldValue`; `Project::itemFields()`,
  `Item::fieldValues()`.
- **Support:** `App\Support\Boards\ItemFieldValues` — validation per type, apply (upsert /
  delete), history entries, display values.
- **Policy:** no new policy — manage = `ProjectPolicy::update` (project admin / workspace admin),
  values = `ItemPolicy::update`, read = `ItemPolicy::view`.
- **Controllers / routes:**
  - `ItemFieldController` under `projects/{project:slug}`: `projects.fields.store`,
    `projects.fields.update` (name, boards, type if no values, options sync, archived),
    `projects.fields.reorder`, `projects.fields.destroy` (only without values).
  - `BoardController@updateItemFields` → `PUT boards/{board}/items/{item}/fields`
    (`boards.items.fields.update`): `values: {fieldId: value|null}`; every id must be an active
    field of the item's project that applies to the board, else 422.
  - Payload: `withCardData()` eager-loads slim `fieldValues`; `Boards/Show` + `Items/Show` get
    `customFields` (active fields for the board, with options incl. archived ones flagged);
    `Projects/Edit` gets `itemFields` (all, incl. archived) + `projectBoards`.
  - `duplicateItem` copies field values.
- **Frontend:**
  - Types in `types/index.d.ts`: `ItemField`, `ItemFieldOption`, `ItemFieldValue`, `Item.field_values`.
  - `lib/customFields.ts`: value read/format, filter parse/serialize/match, comparator.
  - `components/projects/ItemFieldsCard.tsx` — new "Fields" tab in project settings.
  - `components/item/ItemFieldsSection.tsx` — typed editors in the issue sidebar.
  - `Pages/Boards/Show.tsx`: custom-field filters in the Filter popover, list-view columns +
    picker + sortable headers.
  - History: `field_changed` case in `HistoryEntryBody`.
- **MCP:** out of scope for v1 (read/write tools can reuse `ItemFieldValues` later).

## Limits
50 active fields per project, 100 options per field, names/labels 100 chars, text values 1000
chars, numbers ±10^14 with up to 6 decimals.

## Test plan (tests/Feature/Boards/ItemFieldsTest.php)
- Each type: valid value stored in the right column; invalid rejected (422); `0` / `false`
  stored and returned distinct from cleared; clearing deletes the row.
- Option rename keeps the value; removing an option archives it, value still readable; archived
  option can't be newly chosen.
- Type change blocked when values exist, allowed when none.
- Field from another project / archived field / field not on this board → 422; nothing written.
- Viewer can't write values (403), can read them on the board; outsider gets 403.
- Non-admin editor can't manage fields.
- History entry with display values; duplicate copies values.
- Board show query count stays flat as items with values grow.

## Docs to update
ARCHITECTURE (Boards module), CONVENTIONS (custom fields rules), KNOWLEDGE (empty vs zero,
type-change strategy, client-side filtering), HelpModal changelog (minor bump).

## Open questions
- Server-side filtering/sorting (for MCP or paginated lists) isn't needed yet; add a query scope
  when the first paginated consumer appears.
