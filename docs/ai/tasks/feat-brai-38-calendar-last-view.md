# Save Last Calendar View

**Branch:** feat/brai-38-calendar-last-view
**Status:** done
**Started:** 2026-05-31
**Board card:** BRAI-38 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-123

## Problem / goal

Calendar users can switch between views, but returning to the calendar should restore the
last view they selected. The saved view must be per user, survive reload/navigation, and
fall back to the current default when no preference exists.

## Approach

Store the selected calendar view as a per-user preference. Load that value in the calendar
controller props and update it from the calendar UI when the view changes.

## Changes

- Migrations: add storage for the user calendar view preference if no suitable preference
  store already exists.
- Models / relationships: expose the new preference field on `User` if stored there.
- Policy (+ register in AppServiceProvider): no policy changes expected.
- Controller / routes: pass the saved calendar view to the Inertia calendar page; add a
  small authenticated route/action to update it.
- Frontend (Pages, components, types/index.d.ts): initialize the calendar view from props,
  persist user-initiated view changes, and type the new prop.
- MCP tool(s) (if exposing to AI): none.

## Test plan

- `php -l` on changed PHP files: passed.
- `./vendor/bin/pint --test` on changed PHP files/routes: passed.
- `npx tsc --noEmit`: passed.
- `npm run build`: passed.
- `php artisan test tests/Feature/CalendarViewPreferenceTest.php`: blocked locally because
  PHP is missing the `pdo_sqlite` driver required by the in-memory SQLite test database.
- Feature tests were added for default view loading, saved view loading, per-user save, and
  invalid view rejection.

## Docs to update (ARCHITECTURE / CONVENTIONS / KNOWLEDGE / skills)

No architecture, convention, knowledge, or skill updates needed.

## Open questions

None. The current calendar UI supports `month`, `week`, and `day`; the preference follows
that existing enum.
