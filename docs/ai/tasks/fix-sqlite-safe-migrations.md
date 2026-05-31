# Fix SQLite Migration Blockers

**Branch:** fix/sqlite-safe-migrations
**Status:** done
**Started:** 2026-05-31
**Board card:** BRAI-46 — https://www.brainitive.app/workspaces/personal/projects/brainitive/boards/main-board#item-131

## Problem / goal
Some migrations still contain raw database-specific SQL that breaks SQLite local setup or
SQLite-backed tests. Fix the migrations so these are no longer documented as current
known blockers.

## Approach
Add explicit driver branches for the affected migrations. SQLite should either run a
portable schema operation or no-op when the column type/constraint is not enforced in a
way that needs changing.

## Changes
- Migrations:
  - `2026_02_03_145210_add_member_role_to_workspace_user_table`: added SQLite/MySQL/pgsql branches.
  - `2026_02_23_153119_extend_bookmarks_icon_for_url`: made SQLite a no-op because SQLite does not enforce varchar length.
  - `2026_05_31_161000_fix_calendar_connections_unique_index`: removed SQLite-unsafe constraint alteration from the SQLite path.
- Models / relationships: none.
- Policy (+ register in AppServiceProvider): none.
- Controller / routes: none.
- Frontend (Pages, components, types/index.d.ts): none.
- MCP tool(s) (if exposing to AI): none.

## Test plan
- `php -l` on changed migration files: passed.
- `./vendor/bin/pint --test` on changed migration files: passed after formatting.
- `git diff --check`: passed.
- `php artisan test`: blocked in this local environment because PHP is missing the `pdo_sqlite`
  extension, so Laravel cannot open the `:memory:` test database.

## Docs to update (ARCHITECTURE / CONVENTIONS / KNOWLEDGE / skills)
- Removed stale `KNOWLEDGE.md` and `AGENTS.md` entries that described the fixed migrations
  as current blockers.
- Keep the convention that future migrations must stay cross-database safe.

## Open questions
None.
