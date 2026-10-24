# Billing settings + documents: PDF, Excel, email to the client (BRAI-101, part 1 of 2)

**Branch:** feat/billing-documents (from release/v1.38.0)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-101 (shared with the reports agent; the lead moves it)

## Problem / goal
Workspace admins configure how their client documents look and are numbered, and turn a billing
report into a PDF or an Excel file, or mail the PDF to the client. Contract and snapshot shape:
[release-v1.38.0-billing.md](./release-v1.38.0-billing.md). The report builder / finalize flow is
the reports agent's (part 2), built in parallel.

## Approach
- **Settings** (`billing.settings.edit|update`): one form for currency, default VAT (percent with two
  decimals → `default_tax_rate_bp`), net/gross prices, rounding (0/5/15/30), invoice mode + prefix +
  next number, payment terms, company, bank, footer. Admins only (`manageBilling`). IBAN / BIC are
  normalised (spaces removed, upper case) and validated loosely; the IBAN is never logged.
- **Logo** (`billing.settings.logo.store|destroy`, `billing.settings.logo` to show it): png / jpg /
  webp, ≤ 1 MB, ≤ 4000 px per side (checked before decoding). It is **re-encoded with GD to PNG**
  (strips metadata and anything appended to the image, max 1200×600) and stored on the **private**
  `local` disk as `billing-logos/<workspace id>/<uuid>.png`. It is never served publicly; the
  settings page loads it through an admin-only route, and the PDF embeds it as a data URI. An old
  logo is deleted on replace / remove unless a finalized report's snapshot still points to it.
- **Renderer** `App\Support\Billing\BillingDocument`: takes the snapshot v1 array and renders, never
  recomputes money (it only formats cents, minutes and basis points).
  - PDF: dompdf + Blade view `billing/document.blade.php`, A4, DejaVu Sans (€ and accents), remote
    resources, PHP and JS off, only the `data://` protocol allowed, page numbers on the canvas.
  - xlsx: openspout, sheet "Summary" (header block, groups and lines, totals) and sheet "Entries".
    **Every text cell is a `StringCell`**: openspout turns a string starting with `=` into a formula.
- **Draft data:** `App\Contracts\Billing\BuildsReportData` (`build(BillingReport): array`).
  `BillingServiceProvider` binds it to an adapter that calls `App\Support\Billing\ReportBuilder` only
  when that class exists (the reports agent's). Without a binding a draft returns 409 "Draft preview
  not available". Finalized / sent / paid / void reports use `snapshot`.
- **Send** (`billing.reports.send`): only finalized / sent / paid; `to` (default the client's email),
  up to 5 cc, subject and message (no line breaks in subject / addresses). A queued
  `BillingReportMail` with the PDF attached (rendered in the worker from the frozen snapshot),
  reply-to the sending admin, from the app's mail-from. Sets `sent_at`, `sent_to` and moves
  finalized → sent.
- **Components** for the reports page (mounted by the reports agent / the lead):
  `components/billing/ReportDocumentButtons.tsx` (PDF, Excel, "Send to client") and
  `components/billing/SendReportDialog.tsx`.

## Changes
- Migrations: none (foundation has `billing_settings` / `billing_reports`).
- Contracts / support: `app/Contracts/Billing/BuildsReportData.php`,
  `app/Support/Billing/{BillingDocument,BillingLogo,LiveReportData,ReportData}.php`.
- Provider: `app/Providers/BillingServiceProvider.php` (+ `bootstrap/providers.php`).
- Controllers: `Billing\BillingSettingsController` (edit, update, logo show / store / destroy),
  `Billing\BillingDocumentController` (pdf, xlsx, send) — routes in the documents section of
  `routes/billing.php`.
- Mail: `app/Mail/BillingReportMail.php` + `resources/views/mail/billing-report.blade.php`.
- Views: `resources/views/billing/document.blade.php`.
- Frontend: `Pages/Billing/Settings.tsx`, `components/billing/SendReportDialog.tsx`,
  `components/billing/ReportDocumentButtons.tsx`.

## Test plan
- PHPUnit `tests/Feature/Billing/BillingSettingsTest.php`, `BillingDocumentTest.php`, fixture
  `tests/Fixtures/billing-snapshot-v1.php`: validation, logo type / size / private disk, 403 for
  members, PDF text (client, totals, escaped HTML), xlsx sheets read back, send queues the mail with
  the attachment and updates the status, refused for draft / void, 404 across workspaces, draft
  409 without a builder and rendering with a bound builder.
- e2e `e2e/tests/billing-settings.spec.ts`: owner fills the settings + uploads a png logo, values
  persist after reload; the member gets 403.

## Docs to update
ARCHITECTURE (billing documents row), KNOWLEDGE (logo storage, StringCell, draft binding).

## Review outcomes (php / frontend / security reviewers)
- Send: per-workspace daily cap (named limiter `billing-send`), From "<admin> via Brainitive",
  `email:rfc,strict`, case-insensitive cc dedupe; PDF / xlsx downloads throttled 30/min.
- Excel "Entries" honours `show_people` / `show_notes`; draft / void documents are stamped and ask
  for no payment; array input to the settings is a 422, not a 500.
- Send dialog form remounts on every open (no reset effect); billing types in `types/index.d.ts`.

## Open questions
- Number format in invoice mode (prefix + next number) is the reports agent's: the settings page
  only stores prefix and next number.
