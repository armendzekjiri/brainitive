# fix/subtask-done-toggle

The parent issue's subtask list had no way to complete a subtask: the circle at the end of a row
was the "unassigned" avatar, and the whole row only opened the subtask.

- `ItemDetail.tsx`: each row starts with a round checkbox (`role=checkbox`, aria-checked) that
  moves the subtask to the board's first done column, or back to the first open column; the rest
  of the row still opens the subtask. Viewers see it disabled.
- e2e: `subtask-done-toggle.spec.ts`.
