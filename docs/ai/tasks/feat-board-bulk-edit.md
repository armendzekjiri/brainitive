# BRAI-108: Board list view, multi-select + bulk edit

Branch `feat/board-bulk-edit` (release v1.39.0, ticket 7). Spec: `release-v1.39.0.md` § 7.

> "on boards when we stich to list to be able to multi edit some fileds you decide which"

## Plan
- **Backend**
  - `BoardBulkItemController`: `PATCH boards/{board}/items/bulk` (`boards.items.bulk.update`) and
    `DELETE boards/{board}/items/bulk` (`boards.items.bulk.destroy`). Registered before the
    `items/{item}` routes.
  - Validation: `item_ids` 1..200, distinct, on this board (`Rule::exists` + `board_id`).
    `changes`: `column_id` (this board), `assigned_to` (`ProjectMember`, null = unassigned),
    `priority_id` / `item_type_id` (`forProject`), `sprint_id` (scrum only, planned/active,
    null = backlog), `due_date` (date or null), `add_label_ids` / `remove_label_ids` (project
    labels). At least one change.
  - Authorize `update` (or `delete`) per item. 403 if any item is not allowed.
  - `App\Support\Boards\BulkItemEdit`: one transaction, one `save()` per item (observer = one
    history entry per item), `completed_at` like `Item::moveToColumn`, `moveToSprint` for sprints
    (subtasks follow their parent), `labels_changed` history per item, delete with subtree
    like `destroyItem`.
  - Notifications as for single edits: `ItemAssignedNotification` to a new assignee (not the
    actor), `ItemStatusChangedNotification` to watchers. Both in one isolated method each,
    so ticket 2 can move them into `ItemObserver` at merge.
- **Frontend** (`Pages/Boards/Show.tsx` ListView + `components/board/BulkEditBar.tsx`)
  - Checkbox column (row checkbox `Select WEB-12`, header `Select all`), shift-click range,
    Esc clears, selection pruned to the visible rows after filters / reloads.
  - Sticky bulk bar "3 selected": Status, Assignee, Priority, Type, Sprint (scrum), Due date,
    Labels (add / remove), Delete (AlertDialog). Hidden for viewers (`canEditItems`).
  - `router.patch(..., { preserveScroll, preserveState })`, toast with the server's count.
- **Tests**
  - PHPUnit `tests/Feature/Boards/BulkItemEditTest.php`.
  - e2e `e2e/tests/board-bulk-edit.spec.ts`.
- **Docs**: ARCHITECTURE (Kanban section), KNOWLEDGE (bulk edit decisions).
