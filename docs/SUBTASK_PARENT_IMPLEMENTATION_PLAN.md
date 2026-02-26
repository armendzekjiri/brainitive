# Implementation Plan: Subtask–Parent Association on Board Views

## Goal (restatement)

Ensure that on board views, subtask items are always associated with and visibly linked to their parent task: enforce a persistent parent relationship in the data layer, display the parent on board cards/rows, remediate existing orphaned subtasks via migration or triage, and provide full QA, rollout, and observability guidance.

---

## 1. Scope and non-scope

### In scope
- **Data model & API:** Persist and validate `parent_id` on items; require parent when type is Subtask, optional otherwise; allow setting/clearing parent when creating or updating (clear only when not Subtask); same board, prevent circular refs. **Delete parent:** cascade delete subtasks; warn user before delete (“This task has N subtask(s). Deleting it will delete those subtasks too.”).
- **Board UI:** Show parent task on each card/row when `parent_id` is set (parent key + link); parent selector in create/edit = **autocomplete with dropdown** (required for Subtask type).
- **Move item:** When moving a **parent** to another board, move all its subtasks with it. When moving a **subtask only**, require either selecting a parent on the new board or converting to task.
- **Migration/remediation:** One-off identification and handling of existing orphaned subtasks (type = subtask, parent_id null); FK change to `ON DELETE CASCADE`.
- **Validation & errors:** Server- and client-side rules; clear user-facing messages.
- **Security:** Authorization and same-board checks for parent.
- **Performance:** Minimal extra queries (eager-load parent); optional index.
- **Rollout:** Migration steps, backout plan, and basic observability.

### Out of scope
- Nested subtasks (subtask of a subtask); only one level (task → subtasks).
- Changing board layout to “group by parent” or swimlanes; cards remain in columns with a parent indicator.
- Full audit history of parent changes.
- Real-time sync / conflict resolution beyond normal Inertia reload.

---

## 2. High-level approach and detailed design

### 2.1 Summary
- **Backend:** Keep `parent_id` on `items`; add validation (parent exists, same board, not self, no cycles); accept `parent_id` in create/update item endpoints; eager-load `parent` (id, key, title) for board and item-detail; add migration to find and optionally fix orphans.
- **Frontend:** When loading the board, items already include `parent`; card and list row show a compact “Parent: KEY” (and link to parent item) when `item.parent_id` is set; item create/edit (modal or inline) allow choosing “Parent task” via **autocomplete with dropdown** (required when type is Subtask, optional otherwise).

### 2.2 UI/UX changes on boards

**Card view (current card layout):**
- When `item.parent_id` is set, show a small line or chip below the title or above the footer, e.g.:
  - **“Parent: PROJ-42”** (text only) or
  - **“↑ PROJ-42 Task title…”** (link to open parent item in modal or navigate to item).
- Visual: muted text, smaller font (e.g. `text-[10px]` or `text-xs`), optional left border or icon (e.g. indent icon) so subtasks are visually distinct.
- Clicking the parent link opens the parent item (same edit modal or item detail) or scrolls to it if on the same board view.

**List/table view:**
- Add a “Parent” column (or reuse an existing column). Cell content:
  - If `item.parent_id`: show parent key as link (e.g. `PROJ-42`) and optionally parent title on hover.
  - If no parent: “—” or empty.

**Item create/edit (modal or form):**
- Add “Parent task” selector: **autocomplete with dropdown** — search + list of root items on the **same board** (only root-level items, i.e. `parent_id` is null), excluding self when editing. Selecting one sets `parent_id`; “None” clears it. **Required** when item type is “Subtask”; optional for other types.

**No collapse/expand in scope:** Parent and subtasks remain separate cards/rows; no grouping under a single parent row in this phase.

### 2.3 Data model
- `items.parent_id` already exists (nullable, FK to `items.id`). **Change:** FK must use `ON DELETE CASCADE` so that when a parent is deleted, its subtasks are deleted too (see Section 3.1).
- **Convention:** Any item can have a parent; items with `item_type.slug === 'subtask'` **must** have a parent (validation enforced). Source of truth for “is a child” is `parent_id`; type “Subtask” implies parent required.

### 2.4 API and backend logic
- **Board show:** Eager-load `parent:id,key,title,board_id` (or minimal fields) for each item so the frontend can render parent without extra requests.
- **Item store:** Accept `parent_id` (required when item type is Subtask, optional otherwise); validate (exists, same board, not self, not descendant); set on create.
- **Item update:** Accept `parent_id` (required when type is Subtask, optional otherwise); same validation; allow clearing with `null` only when type is not Subtask.
- **Item delete:** When user deletes an item that has subtasks (children), **cascade delete** those subtasks. **Before delete:** show confirmation: *“This task has N subtask(s). Deleting it will delete those subtasks too. Continue?”* (Backend: FK `ON DELETE CASCADE` or explicit delete of children in the same transaction.)

### 2.5 Interactions
- Creating an item: if type is Subtask, user must pick a parent (autocomplete); otherwise parent is optional. API validates parent on same board → item created with `parent_id`.
- Editing item: user can set/clear parent (clear only allowed when type is not Subtask). API validates → item updated.
- Board load: columns with items, each item with `parent` loaded → React renders card/row with parent line when present.
- Parent deleted: backend deletes parent and all its subtasks (cascade). User is warned beforehand: “This task has N subtask(s). Deleting it will delete those subtasks too.”
- **Move parent to another board:** When moving an item that has subtasks, move all its subtasks to the same target board (and optionally same column or preserve relative order). One “move” operation = parent + children.
- **Move subtask only to another board:** Require user to either (1) select a parent on the new board, or (2) convert to task (clear `parent_id` and change type from Subtask to Task). Move API must accept `parent_id` (on target board) or `convert_to_task: true`.

---

## 3. Required API and database changes

### 3.1 Schema changes
- **FK change (required):** Replace `ON DELETE SET NULL` with `ON DELETE CASCADE` so that when a parent is deleted, all its subtasks are deleted. Users are warned before delete (see Section 2.4).

**Migration (Laravel):**
```php
// In a new migration: modify items.parent_id foreign key to CASCADE
Schema::table('items', function (Blueprint $table) {
    $table->dropForeign(['parent_id']);
    $table->foreign('parent_id')->references('id')->on('items')->onDelete('cascade');
});
```

- **Optional index** for “items by parent” and “root items per board”:

```sql
CREATE INDEX items_board_id_parent_id ON items(board_id, parent_id);
```

### 3.2 Board show – eager-load parent
**Current (conceptual):**
```php
'items' => function ($q) {
    $q->orderBy('position')
      ->with(['assignee:...', 'itemType', 'priority', 'labels', ...]);
}
```

**Change:** Add to the items `with()`:
```php
'parent:id,key,title,board_id,column_id'
```
So each item may have `parent` with at least `id`, `key`, `title` (and `board_id` for same-board checks if needed).

### 3.3 Item store – accept and validate parent_id
**Endpoint:** `POST /workspaces/{workspace}/projects/{project}/boards/{board}/items` (or equivalent route).

**Request body addition:**
```json
{
  "title": "Implement login",
  "column_id": 5,
  "item_type_id": 2,
  "priority_id": 1,
  "parent_id": 123
}
```

**Validation (server):**
- `parent_id`: required when `item_type_id` is the Subtask type, otherwise `nullable`; `exists:items,id`.
- After validation: ensure parent belongs to the same board; ensure parent is not the item being created. If any fails, return 422 with message e.g. `"Parent must be a task on the same board."` or `"Subtasks must have a parent task."`

**Response:** Unchanged (redirect or JSON success); new item will have `parent_id` set.

### 3.4 Item update – accept parent_id
**Endpoint:** `PATCH/PUT .../items/{item}` (or equivalent).

**Request body addition:**
```json
{
  "title": "Implement login",
  "parent_id": 456
}
```
or `"parent_id": null` to clear.

**Validation (server):**
- `parent_id`: required when item’s type is Subtask, otherwise `nullable`; `exists:items,id`.
- Same-board check: parent must be on the same board as the item.
- Not self: `parent_id !== item.id`.
- No cycle: parent must not be the item itself nor a descendant of the item (walk parent chain of the candidate parent; if you reach the item being updated, reject).
- Clearing parent (`null`) is only allowed when item type is not Subtask.

**Response:** Unchanged.

### 3.5 Delete confirmation (children_count)
When the item has subtasks, the frontend must show: “This task has N subtask(s). Deleting it will delete those subtasks too.” Include `children_count` in the board payload for each item (e.g. `withCount('children')` on items) so the delete confirmation can show N without an extra request. Alternatively return `children_count` in the item detail/GET response.

### 3.6 Move item to another board (new or extended endpoint)
When “move item to another board” is supported:

**Move parent (item with subtasks):**
- Request: e.g. `PATCH .../items/{item}/move` with `target_board_id`, `target_column_id`.
- Backend: move the item and **all its children** (subtasks) to the target board. Children’s `board_id` and `column_id` updated (e.g. same column as parent, or preserve column mapping if target board has matching columns).
- No extra payload; subtasks always move with parent.

**Move subtask only:**
- Request: same endpoint with `target_board_id`, `target_column_id`, and **either**:
  - `parent_id`: id of a root item on the **target** board (subtask gets this parent on the new board), **or**
  - `convert_to_task`: `true` (clear `parent_id`, set item type to Task so it becomes a root task on the new board).
- Validation: reject if neither `parent_id` nor `convert_to_task` provided; if `parent_id` provided, must be on target board and root.

**Example request (move subtask, assign new parent):**
```json
{
  "target_board_id": 2,
  "target_column_id": 4,
  "parent_id": 99
}
```

**Example request (move subtask, convert to task):**
```json
{
  "target_board_id": 2,
  "target_column_id": 4,
  "convert_to_task": true
}
```

### 3.8 Example response shape (board payload)
Board is loaded via Inertia; the `columns` array contains items. Each item can include:

```json
{
  "id": 102,
  "key": "PROJ-12",
  "title": "Subtask: Add tests",
  "parent_id": 100,
  "parent": {
    "id": 100,
    "key": "PROJ-10",
    "title": "Implement login"
  },
  "column_id": 3,
  "item_type": { "id": 2, "slug": "subtask", "name": "Subtask" },
  ...
}
```

So the frontend can render: “Parent: PROJ-10” and link to item 100.

---

## 4. Validation and error handling

### 4.1 Server-side
- **parent_id exists:** `exists:items,id` in validation.
- **Subtask must have parent:** When `item_type_id` is the type with slug `subtask`, `parent_id` is **required** (store and update). If null, return 422: `"Subtasks must have a parent task."`
- **Same board:** After validation, load parent; require `parent->board_id === $board->id` (for store) or `parent->board_id === $item->board_id` (for update). Return 422: `"Parent task must be on the same board."`
- **Not self (update only):** `parent_id != $item->id`. Return 422: `"An item cannot be its own parent."`
- **No cycle (update only):** Traverse `parent->parent_id` upward; if you ever reach `$item->id`, return 422: `"Parent would create a circular reference."`
- **Clear parent:** Allowed only when item type is not Subtask. If type is Subtask and `parent_id` is null, reject with `"Subtasks must have a parent task."`

### 4.2 Client-side
- **Parent selector:** Autocomplete with dropdown — search + list of root items on the same board (exclude self when editing). When type is “Subtask”, parent is **required** (disable submit until a parent is selected or show validation error). When type is not Subtask, “None” is allowed.
- On submit, if parent is selected, send `parent_id`; if “None” and type allows it, send `parent_id: null`. Show inline or toast error if server returns 422.

### 4.3 User-facing messages
| Scenario | Message |
|----------|---------|
| Parent not on same board | "Parent task must be on the same board." |
| Self as parent | "An item cannot be its own parent." |
| Circular parent | "Parent would create a circular reference." |
| Subtask type without parent | "Subtasks must have a parent task." |

---

## 5. Security considerations

- **Authorization:** Reuse existing item create/update policies (`authorize('createItems', $board)` and `authorize('update', $item)`). No new roles.
- **Parent visibility:** Only allow choosing a parent that the user can see (same board/project). Loading parents from the same board already enforces this.
- **Broken links:** DB FK prevents `parent_id` pointing to a non-existent id. `ON DELETE CASCADE` ensures when a parent is deleted, its subtasks are deleted in the same operation; no orphaned subtasks. User is warned before delete (see Section 2.4).
- **Migration:** Run remediation as a one-off (e.g. artisan command or migration) with the same app user/DB credentials; no public endpoint. Optionally restrict to env `APP_ENV=production` and a feature flag or backup-before-run.

---

## 6. Performance considerations

- **Board load:** One extra relation per item (`parent`) with a small set of columns. Single additional join or N+1 avoided by eager-loading. Impact should be low.
- **Index:** Adding `(board_id, parent_id)` helps “root items for this board” (`where board_id = ? and parent_id is null`) and “children of parent” (`where parent_id = ?`). Recommended if the board has many items.
- **List view:** Same payload as card view; no extra API call. Parent column is trivial to render.
- **Parent selector in modal:** Autocomplete with dropdown — use a dedicated endpoint for search, e.g. `GET .../boards/{board}/items?root_only=1&q=...` returning id, key, title (paginated or limited, e.g. 20 results per query). Frontend debounces search and shows results in a dropdown.

---

## 7. Logging, monitoring, and observability

- **Log:** When an item is created or updated with `parent_id`, log at info level (e.g. `item_id`, `parent_id`, `board_id`) for debugging.
- **Metric (optional):** Count of items with `parent_id` set (subtasks) vs total items per board; or count of items with `item_type=subtask` and `parent_id` null (orphans) after remediation.
- **Alert (optional):** If “orphan” count grows above a threshold after rollout, alert (e.g. nightly job that counts `parent_id is null and item_type_id = subtask_type_id`).
- **Dashboard (optional):** Single panel: “Subtask orphans” per project or board.

---

## 8. Rollout and migration notes

### 8.1 Identifying orphaned subtasks
- **Definition 1 (type-based):** Items where `item_type_id` = (id of type with slug `subtask`) and `parent_id` is null.
- **Definition 2 (all roots):** No “orphan” in the strict sense; any item with `parent_id` null is a valid root. Orphans are then “subtasks that lost their parent” (type = subtask, parent_id null).

**SQL (definition 1):**
```sql
SELECT i.id, i.key, i.board_id, i.column_id, i.title
FROM items i
JOIN item_types it ON it.id = i.item_type_id AND it.slug = 'subtask'
WHERE i.parent_id IS NULL
  AND i.deleted_at IS NULL;
```

### 8.2 Recommended default fix for orphans
- **Option A (recommended):** Leave as-is. Orphaned subtasks appear as root tasks. UI shows “Parent: —”. Add a small “Subtask without parent” filter or report later if needed; no data change.
- **Option B:** Set `parent_id` to the first root item in the same column: `UPDATE items SET parent_id = (SELECT id FROM items i2 WHERE i2.column_id = items.column_id AND i2.parent_id IS NULL AND i2.deleted_at IS NULL ORDER BY position LIMIT 1) WHERE ...` (exact syntax DB-dependent). Risk: arbitrary attachment.
- **Option C:** Mark for triage: add a nullable `needs_triage_at` timestamp or a tag; surface in admin list; let humans reassign or convert to task.

Recommendation: **Option A** for first release; optionally surface “Subtask (no parent)” in UI so users can reassign parent in edit.

### 8.3 Safe migration steps
1. **Backup** items table (and item_types if changing type logic).
2. **Run** orphan query (read-only); record counts per board.
3. **Migration:** Change `items.parent_id` FK from `ON DELETE SET NULL` to `ON DELETE CASCADE` (see Section 3.1). After this, deleting a parent will delete its subtasks (ensure delete confirmation is in place before or with the next deploy).
4. **Deploy** code that eager-loads `parent` and `withCount('children')`, displays parent on board, validation (Subtask requires parent), parent_id in store/update, delete confirmation (“This task has N subtask(s)…”), and move logic.
5. **Enable** “Parent task” (autocomplete + dropdown) in create/edit UI; monitor errors and support.
6. Optionally run Option B/C for existing orphans in a separate, reviewed script (or leave as Option A).

### 8.4 Backout
- **Code:** Revert deploy; remove parent from eager-load, withCount, and UI; remove `parent_id` from store/update validation and assignment; remove delete confirmation and move logic. Data (parent_id) remains in DB and is harmless.
- **Data:** If Option B/C was run, backout would require a separate script to set `parent_id` back to null for affected rows (restore from backup if necessary). **FK CASCADE:** Rolling back the FK to `SET NULL` is possible (new migration), but any subtasks already cascade-deleted cannot be recovered without restoring from backup.

### 8.5 Feature flag (optional)
- Flag “board_subtask_parent_ui”: when off, do not show parent on cards/rows and do not show “Parent task” in create/edit. Backend can still accept and store `parent_id` so that when the flag is on, data is already there.

---

## 9. Edge cases and handling

| Edge case | Handling |
|-----------|----------|
| **Parent deleted** | FK `ON DELETE CASCADE`: parent and all its subtasks are deleted. **Before delete:** UI shows confirmation: “This task has N subtask(s). Deleting it will delete those subtasks too. Continue?” Backend may also return subtask count for the modal (e.g. `GET item` or delete endpoint returns `children_count`). |
| **Parent moved to another column** | Allowed. Child can stay in its column; parent display still shows parent key/link. Optionally: when moving parent (same board), offer to move subtasks to the same column. |
| **Parent moved to another board** | **In scope:** When moving the parent, move all its subtasks to the target board (same operation). Update `board_id` (and optionally `column_id`) for parent and all children. |
| **Subtask moved to another board** | **In scope:** User must either (1) select a parent on the new board (`parent_id` in move payload), or (2) convert to task (`convert_to_task: true`). Reject move if neither provided. |
| **Circular reference** | Update validation: walk parent chain from candidate parent; if current item is reached, return 422. |
| **Concurrent edits** | Last-write-wins for `parent_id`. Optional: optimistic locking (version column) later. |
| **Subtask type but no parent** | Validation rejects: “Subtasks must have a parent task.” (Required for create and update when type is Subtask.) |

---

## 10. Acceptance criteria

### Functional
- **AC1:** When an item has `parent_id` set, the board card and list row show the parent (e.g. “Parent: PROJ-10” or link with parent key).
- **AC2:** User can set a parent when creating an item via **autocomplete with dropdown**; parent is **required** when type is Subtask, optional otherwise (same board, root items only).
- **AC3:** User can set or clear parent when editing an item (clear only when type is not Subtask); validation prevents same-board violation, self-reference, and cycles.
- **AC4:** Orphaned subtasks (type = subtask, parent_id null) are identified by the migration/query; remediated per chosen strategy; FK uses `ON DELETE CASCADE`.
- **AC5:** When user deletes a task that has subtasks, the UI shows: “This task has N subtask(s). Deleting it will delete those subtasks too. Continue?” On confirm, parent and all its subtasks are deleted (cascade).
- **AC6 (move):** When moving a parent to another board, all its subtasks move with it. When moving a subtask alone to another board, user must either select a parent on the new board or convert to task.

### Non-functional
- **AC7:** Board load does not introduce N+1 queries (parent eager-loaded).
- **AC8:** Invalid parent_id (wrong board, self, cycle, or Subtask without parent) returns 422 with a clear message.

---

## 11. Test plan

### Unit (backend)
- **Item model:** Parent/children relations; cycle check helper (if extracted).
- **Validation:** Store item with parent_id same board / different board / self / cycle; expect success or 422 with correct message.
- **Orphan query:** Unit test the SQL or scope that returns “subtask type and parent_id null”.

### Integration
- **Board show:** Response includes items with `parent` when parent_id is set; parent has id, key, title.
- **Item store:** With parent_id (required when type Subtask); item created; board show returns that item with parent populated. Store Subtask without parent_id returns 422.
- **Item update parent_id:** Set and clear (clear only when type is not Subtask); verify DB and next board load.
- **Item delete (parent with subtasks):** Parent and all children deleted (cascade); delete endpoint or pre-check returns children count for confirmation modal.
- **Move parent to another board:** Parent and all children have board_id (and column_id) updated to target board.
- **Move subtask to another board:** With parent_id on target board, subtask moves and has new parent; with convert_to_task, subtask moves with parent_id null and type Task. Move without parent_id and without convert_to_task returns 422.

### Data migration verification
- Run orphan query against staging copy; assert count and sample ids. If Option B/C is run, run verification script: no item has parent_id pointing to another board; no cycles.

### E2E / manual QA
- Create task; create subtask with parent = that task (autocomplete); assert card shows “Parent: KEY”; open parent link and assert correct item.
- Create Subtask without selecting parent; assert validation error “Subtasks must have a parent task.”
- Edit subtask; clear parent (only if type is not Subtask); when type is Subtask, clearing parent should be disabled or show validation error.
- Edit item; set parent to self or to a child; assert 422 and message.
- Delete parent item: assert confirmation “This task has N subtask(s). Deleting it will delete those subtasks too.”; confirm; assert parent and all subtasks are gone.
- Move parent to another board; assert all subtasks appear on the target board with parent unchanged.
- Move subtask to another board: with new parent selected, assert subtask on target board with that parent; with “convert to task”, assert subtask on target board as root task.
- List view: Parent column shows key or “—”.

---

## 12. Effort and complexity estimate

| Area | Complexity | Estimate |
|------|------------|----------|
| **Backend** | Medium | 2–3 d |
| Eager-load parent, withCount(children), store/update validation (same board, self, cycle), Subtask requires parent; FK CASCADE migration; delete cascade; move parent/subtask logic | | |
| **Frontend** | Medium | 2–3 d |
| Parent on card and list row; Parent selector (autocomplete + dropdown) in create/edit; link to parent; delete confirmation with children count; move flow (parent vs subtask) | | |
| **Migration / remediation** | Low | 0.5–1 d |
| Orphan query, optional script for Option B/C, backup/run on staging | | |
| **QA** | Medium | 1.5–2 d |
| Unit, integration, manual E2E, migration verification | | |
| **Total** | Medium | ~6–9 story points (about 1–2 sprints) |

---

## Product decisions (locked in)

1. **Subtask type vs any item as child:** Any item can have a parent; only items with type “Subtask” **must** have a parent.
2. **Delete parent behavior:** Delete subtasks with the parent (cascade); show users a warning: “This task has N subtask(s). Deleting it will delete those subtasks too.”
3. **Parent selector UX:** Autocomplete with dropdown.
4. **Move item to another board:** If moving the **parent**, move all its subtasks with it. If moving only a **subtask**, require either selecting a parent on the new board or converting to task.
