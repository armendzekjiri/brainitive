# Billing reports: builder, finalize / lock, status (BRAI-101, part 2 of 2)

**Branch:** feat/billing-reports (from release/v1.38.0)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-101
**Contract:** [release-v1.38.0-billing.md](./release-v1.38.0-billing.md) (snapshot v1, money rules,
fixed price once per ticket, locking)

## Problem / goal
At the end of the month a workspace admin turns the logged hours of a client's projects into a
time report (or, in invoice mode, an invoice): pick client + period, check the lines, leave out
entries, finalize. Finalizing freezes the numbers and locks the entries so nothing is billed twice.
PDF / Excel / sending are the documents part (other agent); this part links to their routes.

## Approach
- **`App\Support\Billing\ReportBuilder`** (implements `App\Contracts\Billing\BuildsReportData`):
  `build(BillingReport): array` returns the snapshot v1 array; `compute()` returns it plus the
  fixed charges to record, the excluded entries and the warnings (used by the pages and finalize).
  Fixed number of queries (settings, client, projects, entries + user / activity / item, fixed
  charges).
  - Candidates: draft = **unbilled** entries of the client's projects (`options.project_ids`, null =
    all of the client's projects) in the period; finalized = the entries locked to the report.
    `excluded_entry_ids` are split off (shown as "Left out", not locked on finalize).
  - Per entry `billed = ceil(minutes / rounding) × rounding` (0 = exact). Non-billable entries
    are only in `entries` (billed_minutes 0), never in lines / totals.
  - Lines per project group: `ticket` = ticket + activity; `activity` = activity; `person` =
    person + activity. **Fixed-price** entries always form one line per ticket + activity (the price
    is per ticket), whatever the grouping. `person` = the line's people (sorted, ", ") when
    `show_people` or grouping by person, else null. Notes only with `show_notes`.
  - Hourly amount = round_half_up(billed_minutes × rate / 60) per line, integer math.
  - Fixed: the flat price once per ticket + activity unless `billing_fixed_charges` already has
    that pair from another report → `fixed_already_billed: true`, amount 0. No ticket = once per
    report per project.
  - No activity = "No activity", hourly, rate 0 (warning in the UI, "leave them out" button).
  - Totals: net mode `tax = round(net × bp / 10000)`; gross mode `net = round(gross × 10000 /
    (10000 + bp))`, `tax = gross − net`. Tax rate = client override ?? workspace default.
  - Deterministic order: groups by project name / id; lines by ticket key (natural), activity,
    person; entries by date, person, id.
- **`billing_fixed_charges`** (migration `2026_10_27_130000`): workspace, project, item (nullable),
  activity, report (cascade), amount_cents; **unique (item_id, billing_activity_id)** — Postgres
  treats NULLs as distinct, so entries without a ticket can bill once per report.
- **Finalize** (`App\Support\Billing\ReportFinalizer`, one transaction): lock the report row; an
  already finalized / sent / paid report returns as is (double click = same result, one number);
  lock the settings row; lock the candidate entries `FOR UPDATE` = unbilled ones in scope **plus the
  `entry_ids` the admin reviewed** (sent by the page), ordered by id. Refuse (422, nothing changes)
  when one of those is billed by another report or the set differs from what was reviewed (time
  logged / edited meanwhile). Then build, store snapshot + totals, set `billing_report_id`, record
  the fixed charges (unique violation from a concurrent finalize → 422), take the invoice number in
  invoice mode (`prefix + zero-padded 3 digits`, unique per workspace, `next_number` + 1).
  Plain reports keep `number = null`.
- **Status:** draft → finalized → sent (documents agent) → paid; void from finalized / sent / paid
  (paid needs `confirm`): unlocks the entries, deletes the report's fixed charges, keeps the
  snapshot and the number (never reused).

## Changes
- Migration: `2026_10_27_130000_create_billing_fixed_charges_table`.
- Model: `BillingFixedCharge`; `BillingReport::fixedCharges()`, status helpers.
- Contract: `app/Contracts/Billing/BuildsReportData.php` (same file as the documents agent's).
- Support: `app/Support/Billing/{ReportBuilder,ReportComputation,ReportFinalizer,InvoiceNumber}.php`.
- Controller: `Billing\BillingReportController` (index, store, show, update, destroy, finalize,
  markPaid, void), `Gate::authorize('manageBilling')`, reports looked up inside the workspace (404).
- Routes (section "agent: reports" in `routes/billing.php`): `billing.reports.index|store|show|
  update|destroy|finalize|paid|void`, prefixed throttles on writes.
- Frontend: `Pages/Billing/Reports/{Index,Show}.tsx`, `components/billing/reports/*`
  (document table, options panel, dialogs), types in `types/index.d.ts`.
- E2E seed: "v1.38.0 billing fixtures" block in `E2eSeeder` (Programming 120 €/h, Testing 45 €/h
  via `firstOrCreate` by name, client Muster GmbH on `website`, entries last month).

## Test plan
- PHPUnit `tests/Feature/Billing/BillingReportBuilderTest.php` (fixture: 2 developers, 2 projects
  of one client, Programming 120 €/h, Testing 45 €/h, SSM 450 € fixed): exact totals net 19 %,
  gross, 0 %, client override; rounding 15; fixed once per ticket across two reports; excluded and
  non-billable entries; snapshot keys; query count.
- `tests/Feature/Billing/BillingReportFlowTest.php`: create / update / delete draft, finalize locks,
  next report doesn't see them, refused when an entry was locked by another report, idempotent
  double finalize, void unlocks + frees fixed charges, invoice numbers consecutive + unique, paid /
  void transitions, members 403, other workspace 404.
- E2E `e2e/tests/billing-reports.spec.ts`: create → lines + total → leave out → finalize → locked →
  new report empty → void → billable again; member gets 403.

## Docs to update
ARCHITECTURE (billing reports module), KNOWLEDGE (finalize locking / fixed charges / numbering).

## Decisions made while building
- `entries[].person` / `.note` follow `show_people` / `show_notes` (the Excel detail sheet goes to the
  client); the admin page gets its own rows with person + note always.
- `issuer.payment_terms_days` falls back to 14 when the setting is empty (as in the contract example).
- `total_minutes` on the report = billed (rounded) minutes; group `net_cents` = Σ line amounts in the
  workspace's price mode (gross amounts when prices include VAT).
- A draft's period can be changed in the options panel; a report covers at most one year.
- Finalize takes the reviewed `entry_ids` from the page (see KNOWLEDGE) instead of re-reading the
  unbilled entries, so what the admin saw is exactly what gets locked.

## Open questions
- Invoice number format (`prefix + 3 digits`) lives in `InvoiceNumber::format()`; the settings page
  (documents agent) should preview it with the same helper.
- The documents agent's `BuildsReportData` file must match `app/Contracts/Billing/BuildsReportData.php`
  (bound to `ReportBuilder` in `AppServiceProvider`).
