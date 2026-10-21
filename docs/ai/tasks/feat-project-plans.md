# Plans: Tom's Planner–style Gantt planner per project

**Branch:** feat/project-plans
**Status:** done
**Started:** 2026-10-21
**Board card:** —

## Problem / goal
Workshops and kick-offs need a quick, high-level plan — phases, bars, milestones, who — not
issues. The board Timeline only shows existing items (too detailed, needs issues first).
Goal: a new project module **Plans**, next to Boards / Lists / Pages, several per project,
with the feature set of Tom's Planner and our look.

## Tom's Planner features → Brainitive
| Tom's Planner | Plans (this branch) |
|---|---|
| Spreadsheet left + Gantt right, inline editing | Grid (Name, Who, Status, Start, End, Duration, Progress, Notes) + timeline, sticky header/column, spreadsheet keys |
| Hierarchy: groups / sub-rows, collapse | Outline levels, indent/outdent (Tab / Shift+Tab), collapse, group summary bar + rolled-up dates/progress |
| Draw bars by click-drag, move, resize | Draw / move / resize with day snapping; several bars per row |
| Milestones | Diamond bars (toolbar, context menu, bar editor) |
| Dependencies | Finish-to-start arrows; drag from a bar's connector to another bar |
| Colours, bar labels | 10-colour palette, label next to the bar, progress fill |
| Zoom day / week / month / quarter, today line, weekends | Same; weekend shading at day/week zoom; jump to today |
| Who / Status / Notes columns, hide columns | Same; Who autocompletes project members; column toggles |
| Undo / redo, copy / paste, drag rows | Undo/redo (100 steps), copy/paste rows (also TSV to/from Excel), drag rows, Alt+↑/↓, duplicate |
| Templates | Blank, Project plan, Product launch, Event, Marketing campaign, Software release, Workshop |
| Import from Excel | CSV import (comma / semicolon / tab) + paste from Excel |
| Export Excel / PDF / print | CSV export (Excel-friendly), print / PDF view |
| Publish to web (read-only link) | Share link (`/share/plans/{token}`), regenerate / disable |
| Collaboration, history | Project permissions; autosave with revision guard (409 banner); version history + restore |
| Search / filter | Filter rows by text, who, status |

**Phase 2 (not in this branch):** link rows ↔ board issues (convert to issues, live progress),
MS Project XML / xlsx import-export and PNG export (need new dependencies), realtime
co-editing (Reverb), baselines, critical path, resource (who) workload view, holiday calendars.

## Approach
- **Storage = one JSON document per plan** (`plans.data`: rows + dependencies,
  `plans.settings`: zoom, columns, grid width), like whiteboards. Edits are many tiny ops
  (drags, indents, pastes); a document with undo/redo and one guarded save is simpler and
  safer than dozens of row/bar endpoints. Server validates the shape and limits sizes.
- Rows are a flat outline (`level`), so indent/outdent/move are array ops; a row is a group
  when the next row is deeper.
- Autosave 1.5 s after the last change with `base_revision` → 409 on conflict; history
  checkpoints like whiteboards (explicit save, 30 min, other user).
- `PlanPolicy` = TaskList rules (editors edit, admins delete, members view).
- Templates are built server-side (`App\Support\Plans\PlanTemplates`) relative to the
  current week so a new plan starts "now".

## Changes
- Migrations: `plans`, `plan_versions`. Models `Plan`, `PlanVersion` + factories.
- `PlanPolicy`, `PlanController` (store, show, update, save, duplicate, destroy, share,
  versions), `PublicPlanController`, routes under `projects/{project}/plans`.
- Sidebar (`HandleInertiaRequests`, `AppLayout`), create modal tile, `Projects/Show` section.
- Frontend `components/plan/*`, `Pages/Plans/Show.tsx`, `Pages/Plans/Public.tsx`.

## Test plan
Feature tests `tests/Feature/Plans/`: create (template, slug, authz), show, save (revision,
409, force, validation, checkpoints), update meta, duplicate, delete authz, share link
(enable, public view, disable → 404), versions + restore, sidebar payload.

Result: 16 feature tests in `tests/Feature/Plans/PlanTest.php`, full suite green. Checked in
Chromium (harness with mocked endpoints): week / month / day zoom, dark mode, phone width,
draw / move / resize bars, link bars, cell editing + Enter fast entry, Tab indent + undo,
Alt+↓, Ctrl+D, copy/paste rows (internal keeps bars, TSV from Excel), CSV import, filter,
409 banner + overwrite, history preview, share dialog, public view, print view.

Found while testing: arrow hit areas covered the bar's link handle (bars now rise above them
on hover); the sticky grid was translucent; Enter fast entry left an empty row; print used
the full scroll range.

## Docs to update
ARCHITECTURE (new Plans module), CONVENTIONS (plan document), KNOWLEDGE, tasks index.

## Open questions
- Phase 2 order: issue linking first (most value), then exports needing dependencies.
