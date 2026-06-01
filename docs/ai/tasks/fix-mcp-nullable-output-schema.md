# Fix MCP tools returning null for string output fields (strict-client compat)

**Branch:** fix/mcp-nullable-output-schema
**Status:** done
**Started:** 2026-06-01

## Problem / goal
Strict MCP clients (OpenClaw) validate every tool response against the tool's `outputSchema()`
and **reject the whole response** when a field declared `$schema->string()` comes back `null`.
Lenient clients (ChatGPT, Claude) silently tolerate null, which is why it "worked" there.

Observed failures:
- `list-projects-tool` → `data/projects/N/description must be string` (project description null).
- `list_calendar_events` → `description`/`location` must be string (Google event fields null).

Goal: make the MCP surface work with strict clients **without changing** behavior for the
already-working ChatGPT/Claude integrations.

## Approach
Coerce every nullable **string** output to `''` at the data-mapping site (the user's stated
preference — "send back empty … if something is missing"). Empty string satisfies a `string`
schema for *every* client, strict or lenient, with zero behavior change for existing ones.

For genuinely nullable **integers** with no sensible empty value, mark the schema
`->nullable()` instead of coercing (e.g. `parent_id` = null means "root page";
`connection_id` defensive). `JsonSchema` supports `->nullable()` → serializes to
`type: ["string"|"integer", "null"]`.

## Changes (string→'' unless noted)
- Shared formatters (cover all 7 calendar/task tools + every write tool's `location`):
  - `app/Support/Mcp/CalendarFormatter.php` — `event()` & `task()` string fields → `''`;
    `connection_id` schema → `->nullable()`.
  - `app/Support/Mcp/ResourceLocator.php` — `build()` `workspace`/`project` → `''` (+ docblock).
- Tools: `ListProjectsTool` (key, description), `GetProjectTool` (key), `ListBoardsTool`
  (description), `ListBookmarksTool` (description), `GetBoardTool` (key, due_date),
  `GetTaskListTool` (due_date, recurrence), `GetBoardItemTool` (10 fields),
  `UpdateBoardItemTool` (item_key), `CreateBoardItemTool` (item_key, assignee, due_date),
  `GetPageTool` (markdown — whiteboard `null`→`''`), `SearchTool` (url, subtitle),
  `FetchTool` (title, text, url), `ListWorkspacesTool` (role), `UpdateTaskTool` (next_due_date).
  - `ListPagesTool` — `parent_id` schema → `->nullable()` (null = root page).
- Test: `tests/Unit/CalendarFormatterTest.php` — pure unit test (no DB; runs without sqlite)
  asserting formatter string fields are never null.

## Test plan
- `./vendor/bin/pint` clean on changed files; `npx tsc --noEmit` clean (no TS).
- `php artisan test tests/Unit/CalendarFormatterTest.php` → green.
- Manual (real pgsql dev DB via tinker): project description / ResourceLocator / formatter
  outputs contain no null string fields. Verified.

## Docs updated
- `docs/ai/skills/add-mcp-tool.md` — new convention: never return null for string/integer
  output fields; coerce to `''` or mark schema `->nullable()`.

## Follow-up (not in this branch)
- User also wants a **dedicated Brainitive-calendar MCP tool** that returns all dated board
  items + task-list items across workspaces/projects in a date range (mirror `CalendarController`).
  Tracked separately — confirm scope before building.
