# Workflow rules for boards + a new board settings page

**Branch:** feat/board-workflow-rules
**Status:** done
**Started:** 2026-11-06
**Board card:** — (MCP tracking not used for this branch)

## Problem / goal
Teams want to say "only Armend may move cards to Done" or "cards reach Done only from Testing".
Today every editor can move any card anywhere. Rules are defined per board by its editors, per target
column ("entering column X"), and are enforced on the server on **every** path that changes an issue's
column or creates an issue in a column. The board settings page (one long stack of cards) becomes a
sectioned settings page like Project settings, with a sentence-style rule editor.

## Rule model (per target column)
A column has at most one rule. Every part is optional; an empty rule = no rule.
- **Who may move cards here** — nobody listed = anyone who may edit the board's cards (today's
  behaviour). Otherwise the actor must be one of the listed **members** OR hold one of the listed
  **roles**: `workspace_owner` (Workspace::owner_id), `workspace_admin` (workspace pivot `admin`; the
  owner has it too), `project_admin`, `project_editor` (project pivot roles). These are the app's
  real roles (CONVENTIONS → Roles); project viewers can't move cards at all (ItemPolicy).
- **Allowed from** — the column can only be entered from the listed columns of the same board.
  Empty = from anywhere.
- **Conditions** — `require_assignee`, `require_subtasks_done` (every descendant in a done column),
  `require_due_date`. Evaluated on the issue as it will be saved (a PATCH that sets the assignee and
  the status at once passes).

**Creation** (issue dialog, quick add, App API, MCP, duplicate, move to another board, recurring
issues…) counts as entering the column **from nowhere**: the who-rule and the conditions apply, and a
column with an allowed-from list can't be created into ("Cards can't be created in Done — they can
only move there from Testing."). Strict on purpose — otherwise "create directly in Done" is a loophole.

**No bypass** — workspace owners/admins are checked like everyone else (they can add themselves to
the rule). The rule editor itself needs `ProjectPolicy::update` (project / workspace admins; see Review fixes).

## Data
- `board_column_rules`: id, `board_id` (FK cascade, indexed: one query loads a board's rules),
  `board_column_id` (FK cascade, **unique**), `allowed_roles` json (array of the 4 role keys,
  normalised on write), `require_assignee` / `require_subtasks_done` / `require_due_date` bool
  default false, timestamps.
- `board_column_rule_user` pivot (rule FK cascade, user FK cascade, unique pair, user indexed).
- `board_column_rule_from_column` pivot (rule FK cascade, `board_column_id` FK cascade, unique pair,
  column indexed).
Pivots instead of JSON id arrays so deletes clean up by FK (no `whereJsonContains`, which differs per
driver): deleting a column drops its rule and removes it from other rules' "allowed from"; deleting a
user drops their entries. Removing someone from the project / workspace doesn't delete rows — a listed
person without edit rights can't move cards anyway (ItemPolicy runs first), and the editor marks them
"no longer a member". If an allowed-from list becomes empty because its columns were deleted, the
restriction is gone (documented; the delete flash says rules were updated).

## Enforcement — one service, two layers
`App\Support\Boards\WorkflowGuard`:
- `violation(Item $item, BoardColumn $to, User $actor, array $completingIds = []): ?WorkflowViolation`
  — the reason (message + machine `reason` + rule summary) or null. `$item` may be unsaved (creation).
  "From" = the item's original column when it already exists on the same board, else nowhere.
  `$completingIds` = issues the same action moves into a done column together (cascade, bulk), counted
  as done for `require_subtasks_done`.
- `ensure(...)` throws `WorkflowRuleViolation` (extends `App\Support\AppApi\ValidationWithExtras`
  → `ValidationException`, key `column_id`, extras `workflow_rule {column_id, column, reason, item_id,
  item_key}`): every surface already renders it — Inertia `back()->withErrors`, JSON 422, App API
  `{message, code: validation_failed, errors, workflow_rule}`, MCP `guard()` → `Response::error(message)`.
- A successful `ensure()` marks that model instance + target column as cleared.
- **Backstop:** `Item::saving` calls `WorkflowGuard::onSaving($item)` whenever an issue is created or
  its `column_id` changes. The actor is the signed-in user (`Auth::user()` — web, App API `auth:app`,
  MCP token user, automation / recurring runners sign their actor in). Skipped when: nobody is signed
  in (webhooks, console without actor), `ImportScope::active()` (Jira / Trello / archive imports —
  admin setup that reproduces existing data), inside `WorkflowGuard::bypass()` (system writes made for
  someone else: booking cards created for the host while a guest might be signed in, templates), or
  the instance was cleared by an explicit `ensure()`. So a write path nobody thought of still can't
  skip a rule; explicit calls exist where a whole action must be refused up front with a clear message.
  Rules are cached per board for the request (scoped singleton).

Paths (each gets a test unless marked):
| Path | How |
|---|---|
| Board drag & drop / status select / My Work circle (web) → `WorkCompletion::moveIssue` / `setDone` | explicit `ensure` for the issue; with "also complete subtasks" every open descendant is checked first (one blocked = nothing moves, message names the subtask); all in one transaction |
| App API `POST /items/{id}/move`, My Work done, offline `work.done` | same `WorkCompletion` |
| Item dialog PATCH (`Issues::update`) — no column field today | backstop (future-proof) |
| Bulk edit (list view bulk bar, right-click menu) → `BulkItemEdit::apply` | explicit check for every issue first; any violation = 422 naming the issues, nothing changes. Selected issues moving to done together count as done for `require_subtasks_done` |
| Create: web `storeItem`, App API create, offline `issue.create` (`Issues::create`) | backstop on `creating` (message for create wording) |
| MCP `create_board_item(s)`, whiteboard / meeting notes / capture conversion (`ProjectIssues::create`, transaction → batch all-or-nothing) | backstop |
| Duplicate (copy lands in the original's column) | backstop (create semantics) |
| Move to another board (`moveItemToBoard`: subtree via query builder) | explicit `ensure` for the issue + each descendant as a creation in the target column, before the transaction |
| MCP `move_board_item` | `WorkCompletion::moveIssue` |
| MCP `update_board_item` (no column field) | backstop |
| Offline `issue.update` with `column_id` | backstop → per-change 422 |
| Automations "Move to column" | explicit check in `AutomationActions::move` → action `error` "Blocked by the board's workflow rules: …" in the run log (automations obey rules, as their actor) |
| Recurring issues | backstop inside a savepoint; a violation pauses the template with `last_error` "Paused: …" |
| GitHub merge transitions (webhook, no user) | not checked — no actor; configured by a board admin (documented) |
| Booking "also create a card" | `bypass` — created for the host on a public page (documented) |
| Jira / Trello / Notion / archive imports, project templates | not checked (`ImportScope` / new project; documented) |
| Sprint complete / move to sprint, release "move unfinished" | don't change columns — nothing to check |

## UI
- **Board payload** (`Boards/Show`, item page): every column gets `workflow` = `{summary: string[],
  allowed_from: number[], requires: {assignee, subtasks_done, due_date}, actor_allowed: bool,
  actor_reason: string|null}` or null. `lib/workflowRules.ts` mirrors the server's checks and messages
  for hints only: while dragging, columns the card can't enter are dimmed with a lock + reason; the
  item dialog's status select disables those options with the reason; column headers show a lock icon
  with the rule summary as tooltip. Every move's `onError` shows the server message as a toast (the
  board isn't optimistic for drags; the item dialog's status select reverts).
- **Board settings page** (`Pages/Boards/Edit.tsx`, same route): Project-settings layout (left section
  nav, horizontal scroller on phones, `?tab=` deep links): **General** (name, description, sprints,
  done retention), **Columns** (drag reorder, inline rename, colour, done flag, WIP limit, add, delete
  with clear empty state), **Swimlanes**, **Workflow rules** (one sentence-style card per column:
  "Cards can enter **Done** only from [Testing] · Only [Armend] [Project admins] can move them here ·
  Require [all subtasks done]"; summary list of all rules at the top), **Danger zone**.
  Split into `components/board/settings/*` so the page stays small.
- Routes: `PUT boards/{board}/columns/{column}/rule` (`boards.columns.rule.update`), `DELETE`
  (`boards.columns.rule.destroy`), `ProjectPolicy::update` (see Review fixes).

## MCP / App API
- `get_board` and `get_project_config`: `workflow_rule` per column (one sentence, '' = none).
  Violations return the guard's message. No write tool for rules (read via get_board is enough).
- App API `GET /boards/{id}`: `columns[].workflow` = `{summary, allowed_from_column_ids, requires,
  can_move_here}` or null; move / create / update 422 carries `workflow_rule`. API.md + `composer openapi`.

## Test plan
`tests/Feature/Boards/WorkflowRulesTest.php` (guard + every path listed with a test), 
`WorkflowRuleSettingsTest.php` (editor auth, validation, scoping, cleanup), MCP + App API cases.
Boards without rules behave as before (existing suites green). e2e `e2e/tests/board-workflow-rules.spec.ts`;
`boards.spec.ts` / `board-bulk-edit.spec.ts` / done-retention updated for the new settings layout.

## Docs to update
ARCHITECTURE (Kanban), KNOWLEDGE (decisions above), API.md, docs-site boards guide, HelpModal v1.52.0.

## Not done / follow-ups
- Rules don't travel with project templates / exports yet (members are personal; roles / from / conditions could).

## Review fixes (security + database review, 2026-11-06)
1. Rule editing needs `ProjectPolicy::update` (project / workspace admins); editors see rules read-only. Rule changes are logged
   on the Board (`ActivityLog`, `workflow_rule_created|updated|deleted`) and listed on the settings tab. Deleting a column with a
   rule or in another rule's "allowed from" needs the same right (web + MCP).
2. Recurring templates: an update makes the editor the creator; store / update check the rules for that person (422).
3. No signed-in user: only the who-part is skipped; GitHub merge transitions record `blocked` + reason on the PR.
4. People who left the project are left out of summaries and messages (editor shows them as former).
5. `allowed_from_column_ids`: max = the board's column count, intersected with the board's other columns.
6. `restrict_who`: an emptied who-rule is locked — nobody may move cards in (decision 2026-11-07: like Jira, no admin
   fallback); admins fix it by editing the rule, settings shows a warning.
7. Open subtasks precomputed per action (`WorkflowGuard::openSubtasks`, one query per level).
8. Moving a subtree into another board's done column counts the subtree as done. Plus: empty rules pruned after column / user
   deletes; automations use `ensure()`.
