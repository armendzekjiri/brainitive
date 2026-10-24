# Release v1.38.0: Time tracking & client billing (BRAI-99 / BRAI-100 / BRAI-101)

## Goal (the user's words, 2026-10-24)
> "users of our app for their client! … 5 developers worked on 2 different projects, they log their time on
> tickets … the admin at the end of month has what they've done with hours and ticket titles, and they can
> configure different tasks: testing = 45€ per 1h, programming 120€ per 1h … the workspace admin can
> configure prices and VAT or without VAT … then export to Excel or PDF and send to the client"

Decisions taken with the user:
- **Document:** a **time report** by default. An optional per-workspace **invoice mode** adds a consecutive
  number and the invoice wording.
- **Activities:** no templates. The workspace admin creates every activity and picks its **pricing type**:
  - **per hour**: `Programming 120 €/h`, amount = hours × rate;
  - **fixed price**: `SSM setup 450 €`, a flat amount; the hours are shown for information only.
- **When logging time,** the user always picks the activity. A **ticket type → default activity** setting
  preselects it, and the user can still change it.
- **One release,** built as three tickets on top of the shared foundation below.

## Shared foundation (already on `release/v1.38.0`; build on it, don't redefine it)
Migration `2026_10_27_100000_create_time_tracking_foundation`, models in `app/Models/`:

| Table | Columns (all money in **integer cents**, tax in **basis points**: 19 % = 1900) |
|---|---|
| `billing_activities` | workspace_id, name, pricing_type `hourly`/`fixed`, rate_cents, billable (bool), color, position, archived_at, timestamps; unique (workspace_id, name) |
| `clients` | workspace_id, name, email, address (text), vat_id, tax_rate_bp (nullable = workspace default), notes, archived_at, timestamps |
| `projects.client_id` | nullable FK → clients, null on delete |
| `time_entries` | workspace_id, project_id, item_id (nullable, null on delete), user_id (nullable, null on delete), billing_activity_id (nullable, restrict on delete: archive activities, never delete used ones), spent_on (date), minutes (unsigned int), note, billable (bool), source `manual`/`timer`/`legacy`, item_key + item_title (snapshot at logging; reports use the live title while the item exists), billing_report_id (nullable; set only when a report is **finalized**), timestamps |

Rules every ticket follows:
- `items.time_spent` stays, as the running total. `TimeEntry` keeps it in sync: create +minutes, update ± the
  difference, delete −minutes. **Old totals are kept.** Workload (BRAI-75) reads `time_spent` and still works.
- A time entry with `billing_report_id` set is **locked**: it can't be edited, deleted or moved. That's how
  nothing is billed twice.
- **Who sees money:** rates, amounts, clients and reports are for **workspace admins** only
  (`$user->workspaceRole($ws) === 'admin'`; one `BillingPolicy`). Members see and edit only their own hours,
  never prices.
- **Rounding** (a workspace setting, BRAI-101) applies only in reports. Entries store the exact minutes.
- **Fixed-price activities** bill **once per ticket**. The first finalized report that contains a ticket +
  fixed activity charges the flat price, and later reports show those entries as "fixed price, already
  billed" (0 €). BRAI-101 owns the table that records this. Entries without a ticket bill once per report
  per project.

Also in the foundation:
- `billing_activity_defaults` (`BillingActivityDefault`): ticket type → default activity, one per workspace and
  item type. The **activities** agent builds the admin UI for it; the **log-time** agent reads it to preselect.
- `billing_settings` (one row per workspace, `BillingSetting::forWorkspace()`) and `billing_reports`
  (`BillingReport`). `time_entries.billing_report_id` → `billing_reports` (null on delete).
- `Gate::authorize('manageBilling', $workspace)` (`BillingPolicy`). The shared Inertia prop
  `canManageWorkspace` is the same check, for the UI.
- **`routes/billing.php`**, loaded inside `workspaces/{workspace}`, has **one section per agent**. Add your
  routes only in your own section. `billing.index` redirects to the first billing tab that exists.
- Sidebar: **Billing** for admins → `billing.index`; **Timesheet** for everyone, shown once
  `timesheet.index` exists.
- `billing.reports.pdf|xlsx|send` routes plus a stub `Billing\BillingDocumentController` (501) that the
  documents agent implements.
- Libraries: `barryvdh/laravel-dompdf` (PDF) and `openspout/openspout` (xlsx).

### Billing page shell
Every billing page renders inside **`resources/js/Layouts/BillingLayout.tsx`**: a page title, plus tabs
for Reports · Time · Clients · Activities · Settings. A tab shows only when its route exists
(`route().has(...)`).

Route names:
- `billing.reports.index` (reports)
- `billing.time.index` (overview)
- `billing.clients.index` (overview)
- `billing.activities.index` (activities)
- `billing.settings.edit` (documents)

### Report data, `BillingReport` snapshot v1
The **reports** agent builds this array (`App\Support\Billing\ReportBuilder::build(BillingReport): array`).
For a draft it is computed live; on finalize it is stored in `billing_reports.snapshot`. The **documents**
agent renders exactly this array to PDF and Excel and never recomputes amounts. Until both are merged,
the documents agent tests with a fixture of this shape.
```jsonc
{
  "version": 1,
  "kind": "report",                 // or "invoice" (invoice mode)
  "number": "2026-014",             // null on drafts / plain reports without numbering
  "title": "Time report October 2026",
  "issued_on": "2026-11-01",        // date of finalizing (today for drafts)
  "period": { "from": "2026-10-01", "to": "2026-10-31" },
  "currency": "EUR",
  "tax_rate_bp": 1900,
  "prices_include_tax": false,
  "rounding_minutes": 15,
  "issuer": { "company_name": "", "address": "", "vat_id": "", "email": "", "phone": "",
              "bank_name": "", "iban": "", "bic": "", "footer": "", "logo_path": null, "payment_terms_days": 14 },
  "client": { "name": "Muster GmbH", "email": "", "address": "", "vat_id": "" },
  "options": { "group_by": "ticket", "show_people": true, "show_notes": false },   // ticket | activity | person
  "groups": [{
    "project": { "id": 1, "name": "Website", "key": "WEB" },
    "lines": [{
      "item_key": "WEB-12", "item_title": "Checkout bug",          // null/"No ticket" for entries without one
      "activity": { "name": "Programming", "pricing_type": "hourly" },
      "person": "Erin Owner",                                        // null unless group_by person / show_people
      "minutes": 385, "billed_minutes": 390,                          // billed = after rounding
      "rate_cents": 12000,
      "amount_cents": 78000,
      "fixed_already_billed": false,                                  // fixed price billed by an earlier report → 0
      "notes": ["Fixed rounding in cart"],                            // [] unless show_notes
      "entry_ids": [11, 12]
    }],
    "minutes": 385, "billed_minutes": 390, "net_cents": 78000
  }],
  "totals": { "minutes": 385, "billed_minutes": 390, "net_cents": 78000, "tax_cents": 14820, "gross_cents": 92820 },
  "entries": [{ "id": 11, "date": "2026-10-03", "person": "Erin Owner", "project_key": "WEB", "item_key": "WEB-12",
                "item_title": "Checkout bug", "activity": "Programming", "minutes": 200, "billed_minutes": 210,
                "billable": true, "note": "Fixed rounding in cart" }]   // detail sheet in Excel
}
```
Money rules:
- An hourly line is `round(billed_minutes / 60 × rate_cents)`, half up, per line.
- **Net prices:** `net = Σ lines`, `tax = round(net × tax_rate_bp / 10000)`, `gross = net + tax`.
- **Gross prices:** `gross = Σ lines`, `net = round(gross × 10000 / (10000 + tax_rate_bp))`, `tax = gross − net`.
- Non-billable entries stay in `entries` but add no amount.

## Tickets
- **BRAI-99 Time log** (`feat/time-log`):
  - activities admin UI (create, edit, archive, reorder, pricing type);
  - ticket type → default activity;
  - "Log time" in the issue dialog with an activity picker;
  - `Item::logWork()` and the timer stop write `TimeEntry`;
  - backfill the old `work_logged` activity entries as `source = legacy` entries without an activity, *without*
    changing `time_spent`;
  - **My timesheet:** a week view to add, edit and delete your own unlocked entries;
  - e2e.
- **BRAI-100 Team time overview** (`feat/time-overview`), admin only:
  - workspace Time page: filter by period (default last month), project, client, person, activity,
    billable;
  - group by project / ticket / person / activity / day; totals and billable split;
  - **Excel export** (openspout) of the filtered entries;
  - clients admin UI (CRUD) and assigning a client in project settings;
  - e2e with 2 users on 2 projects.
- **BRAI-101 Billing reports** (`feat/billing-reports`), admin only:
  - billing settings: currency, default VAT, prices net/gross, company details and logo, bank details, footer,
    rounding (0/5/15/30 min), invoice mode, number prefix and next number;
  - report builder: client + period → preview grouped by ticket / activity / person, show or hide names and
    notes, leave out entries;
  - subtotal, VAT, total;
  - **finalize:** freeze the lines as a snapshot, lock the entries in one transaction, refuse any entry that is
    already locked, take the next number in invoice mode;
  - **PDF** (dompdf) and **Excel**;
  - **send to the client by email** with the PDF attached (queued mail; reply-to the admin);
  - status draft → finalized → sent → paid / void (void unlocks the entries);
  - e2e.

Each ticket: plan in its own `docs/ai/tasks/<branch>.md`, PHPUnit + e2e, dark mode and mobile, and the
gate in its worktree.
