# Clients + team time overview + Excel export (BRAI-100)

**Branch:** feat/time-overview (from `release/v1.38.0`)
**Status:** in-progress
**Started:** 2026-10-24
**Board card:** BRAI-100
**Contract:** [release-v1.38.0-billing.md](./release-v1.38.0-billing.md) (foundation: `Client`, `projects.client_id`,
`TimeEntry`, `BillingActivity`, `BillingSetting`, `manageBilling`, `routes/billing.php`, `BillingLayout`)

## Problem / goal
At month end the workspace admin needs to see who worked how long on which ticket, for which client,
and hand it over as Excel. Clients must exist and projects must be assigned to them first.

## Approach
Everything is for workspace admins (`Gate::authorize('manageBilling', $workspace)`), rendered in `BillingLayout`.

**Clients** (`Billing\ClientController`): create / edit / archive / restore (no delete: reports keep pointing
at them). VAT is entered as a percent and stored as `tax_rate_bp` (empty = workspace default). The list shows
each client's projects; "Assign projects" syncs the client's projects (projects of this workspace only;
moving a project from another client is allowed and shown). Project settings get one "Client" select, only
for workspace admins: `ProjectController::update` handles `client_id` only when the request carries it and
the user can `manageBilling`, validated against this workspace's clients (`client_id` stays non-fillable).

**Time overview** (`Billing\TimeOverviewController` + `App\Support\Billing\TimeOverview`):
- Filters from the query string (`period=last_month|this_month|custom`, `from`, `to`, `projects[]`, `client`,
  `person`, `activity`, `billable=all|billable|non_billable`, `group=ticket|person|activity|day`, `detail`).
  Periods are computed in the viewer's `localTimezone()`; default = last month.
- **One joined query** (time_entries + projects + clients + items (live title, not trashed) + users +
  billing_activities + billing_reports) streamed with `cursor()` and reduced in PHP into groups: a fixed
  number of queries whatever the entry count (filter options are 4 more small queries).
- Group by project → ticket (default: project groups with ticket rows), person, activity or day. Each group
  has minutes, billable minutes, entry count, and the indicative amount.
- **Estimate** (labelled "estimate before rounding / VAT"): billable entries only; hourly = minutes / 60 ×
  rate; fixed = the flat price once per (project, ticket or "no ticket", activity), credited to the group of
  the earliest entry so group amounts add up to the total. Currency from `BillingSetting::forWorkspace()`.
  Earlier reports that already charged a fixed price are not known here (BRAI-101 owns that).
- Optional per-leaf entry detail (person, date, activity, note, minutes, locked when billed), capped at
  2 000 entries per page with a "truncated" flag.
- **Excel** (`billing.time.export`): openspout XLSX written to `php://output` inside a `StreamedResponse`,
  rows streamed from the same `cursor()` query; header row + one row per entry. Free text always goes in as
  a `StringCell` (a note starting with `=` must never become a formula). Filename `time-YYYY-MM.xlsx` for a
  whole month, else `time-YYYY-MM-DD_YYYY-MM-DD.xlsx`.

**E2E entries:** the journey logs time through the real `boards.items.work-logs.store` endpoint. On this
branch `Item::logWork()` writes a `TimeEntry` (no activity, today, manual) instead of incrementing
`time_spent` directly — a deliberate bridge: BRAI-99 (log-time) rewrites the same method, so the merge
conflicts there on purpose and log-time's version wins.

## Changes
- Routes (`routes/billing.php`, BRAI-100 section): `billing.clients.index|store|update|archive|restore`,
  `billing.clients.projects.update`, `billing.time.index`, `billing.time.export`. Writes throttled with
  prefixed names.
- Controllers: `Billing\ClientController`, `Billing\TimeOverviewController`; `ProjectController::edit/update`
  (client select for admins).
- Support: `App\Support\Billing\TimeOverview` (filters, query, grouping, estimate),
  `App\Support\Billing\TimeExport` (xlsx stream).
- `Item::logWork()` → `TimeEntry` (bridge, see above).
- Frontend: `Pages/Billing/Clients/Index.tsx`, `Pages/Billing/Time/Index.tsx`, `components/billing/*`;
  `Pages/Projects/Edit.tsx` client select; types in `types/index.d.ts`.
- Foundation test: `billing.index` now redirects (a tab exists) instead of 404.

## Test plan
- PHPUnit `tests/Feature/Billing/ClientsTest.php`, `TimeOverviewTest.php`: clients CRUD + validation,
  archived hidden by default, project assignment (client page + project settings, cross-workspace refused,
  non-admin ignored), filters, grouping totals, fixed price once per ticket, 403 for members/outsiders,
  xlsx row count read back with openspout, formula-safe notes, query count bounded with 300 entries.
- E2E `e2e/tests/time-overview.spec.ts`: two new projects, owner + member log work, admin filters by
  project, groups by person, downloads Excel; member gets 403; client created, both projects assigned,
  filter by client.

## Docs to update
ARCHITECTURE (billing module rows), KNOWLEDGE (streamed xlsx + formula injection, overview query shape).
