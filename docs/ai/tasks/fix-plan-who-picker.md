# fix/plan-who-picker

Plans: the Who cell only had the browser's `<datalist>` (often not shown, not in every webview)
and its names came from workspace members only, so people invited to just this project were
missing.

- `PlanController::show`: `members` = workspace members + this project's own members (guests).
- `PlanChart`: `WhoEditor` — the Who cell's editor is a combobox with a portaled list of names
  (members + names already used in the plan), filtered while typing; ↑/↓, Enter/Tab, click, Esc.
- e2e: `plan-who-picker.spec.ts`.
