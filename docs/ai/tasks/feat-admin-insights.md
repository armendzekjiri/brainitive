# Admin insights (BRAI-105)

**Branch:** feat/admin-insights (from release/v1.39.0)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-105 · contract: [release-v1.39.0.md](./release-v1.39.0.md) → "4. BRAI-105"

## Problem / goal
"admin insigths!" — workspace admins need one place with the numbers of their workspace: throughput,
team, projects, helpdesk/leads and usage, for a chosen date range and optionally one project.

## Approach
- Read-only pages under `workspaces/{workspace}/insights/*`, `Gate::authorize('update', $workspace)` on every
  route (admins; members 403). Admins see every project of the workspace (`hasProjectAccess` gives admins all
  projects), so the scope is every live project, or the one picked in the project filter.
- `App\Support\Insights\InsightsFilters` parses period (`this_month`, `last_month`, `last_30`, `last_90`,
  `custom` ≤ 366 days) + project in the viewer's `localTimezone()`; `InsightsQueries` holds the shared SQL
  pieces (issue scope = live issues on live boards of scope projects, done = `completed_at` or done column, like
  `HealthMetrics`); one class per tab (`OverviewInsights`, `TeamInsights`, `ProjectInsights`,
  `HelpdeskInsights`, `UsageInsights`) returns a plain array with a **fixed number of queries** (aggregates
  grouped in SQL, never per row). `InsightsCsv` turns each tab into CSV.
- Median cycle / resolution time: `percentile_cont` on Postgres, PHP median elsewhere (one query either way).
- Charts: no chart library in package.json → SVG / CSS bars with the existing `.viz-root` tokens
  (created = reference grey, completed = series-1, like the sprint velocity chart).
- No caching: every tab is a handful of aggregate queries.

## Changes
- Routes: `routes/insights.php` (`insights.index` redirect, `insights.overview|team|projects|helpdesk|usage`,
  `insights.export` CSV, throttled).
- Backend: `app/Http/Controllers/InsightsController.php`, `app/Support/Insights/*`.
- Frontend: `Layouts/InsightsLayout.tsx` (tabs, period, project filter, Export CSV), `Pages/Insights/*`,
  `components/insights/*`, types in `types/index.d.ts`.

## Test plan
- PHPUnit `tests/Feature/Insights/InsightsTest.php`: numbers on seeded data per tab, member 403 / outsider 403,
  redirect, project filter, range parsing, query count constant on small vs large data, CSV per tab.
- e2e `e2e/tests/insights.spec.ts`: admin opens Insights from the sidebar, switches tabs + range + project,
  downloads CSV; the member has no link and gets 403.

## Docs to update
ARCHITECTURE (module map), KNOWLEDGE (metric definitions / decisions).
