# Billing → Activities + ticket type → default activity (BRAI-99, part 1 of 2)

**Branch:** feat/billing-activities (from `release/v1.38.0`)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-99 (shared with the log-time part; the release row in `tasks/README.md` covers it)
**Contract:** [release-v1.38.0-billing.md](./release-v1.38.0-billing.md) — the foundation tables, models,
gate and `BillingLayout` are used as they are.

## Problem / goal
Workspace admins define what their team bills — "Programming 120 €/h", "SSM setup 450 € flat" — and
which activity is preselected when someone logs time on an issue of a given type. No templates, no
seeded activities.

## Approach
- One admin page, **Billing → Activities** (`Billing/Activities/Index`, inside `BillingLayout`):
  a card with the activity list and a card with the ticket type → default activity table.
- `Billing\BillingActivityController`, every action `Gate::authorize('manageBilling', $workspace)` and
  the activity must belong to the workspace (else 404).
- **Price** is entered in the currency's major unit (`price`, ≤ 2 decimals, ≥ 0) and stored as
  `rate_cents`. Pricing type `hourly` | `fixed` (`BillingActivity::PRICING_TYPES`). The currency comes
  from `BillingSetting::forWorkspace()->currency`.
- **Names** are unique per workspace, case-insensitive, archived ones included (the DB unique key
  covers archived rows too), so the user gets a validation message, never a unique violation.
- **Archive / restore**: archived activities are hidden from pickers but stay on old entries. Archiving
  also removes the ticket-type defaults that pointed at it, so a default always names an active
  activity (the log-time part can trust it).
- **Delete** only when no time entry uses it; otherwise a validation message says "archive instead"
  (the DB `restrict`, SQLSTATE 23503, is caught as a second line of defence → same message, never a 500).
  A concurrent duplicate name (23505) also becomes the validation message.
- **Reorder**: up / down buttons post the whole active order (`position`), like project fields.
- **Defaults**: one row per item type usable in the workspace = active global types
  (`project_id` null) + the types of the workspace's projects (labelled with the project name).
  Changing the select saves at once (`PUT …/defaults`, `billing_activity_id` null = no default).
  Validation scopes both ids to the workspace.

## Changes
- Controller: `app/Http/Controllers/Billing/BillingActivityController.php`.
- Routes (`routes/billing.php`, section "agent: activities"):
  `billing.activities.index|store|update|archive|restore|reorder|destroy|defaults.update`; writes
  throttled with prefixed names.
- Frontend: `resources/js/Pages/Billing/Activities/Index.tsx` (money formatting kept local to the page, so
  parallel branches don't collide on a shared `lib/` file); types `BillingActivity`,
  `BillingActivityItemType` in `types/index.d.ts`.
- No migrations, no model changes.

## Test plan
- PHPUnit `tests/Feature/Billing/BillingActivitiesTest.php`: render + props, create / update / archive /
  restore / reorder / delete, validation (unique name per workspace incl. case, price ≥ 0 and 2
  decimals, pricing type), delete of a used activity → archive message, defaults (set, change, clear,
  foreign item type / foreign or archived activity rejected, archiving clears), member + outsider 403
  on every route, no rate in member-visible responses.
- E2E `e2e/tests/billing-activities.spec.ts`: owner (own workspace) creates "Programming" 120/h and
  "SSM setup" 450 fixed, sets Bug → Programming, archives one; the seeded member gets 403 and has no
  Billing link.

## Docs to update
- ARCHITECTURE: the activities page/controller under the billing module.

## Open questions
- None blocking. The foundation test `test_billing_is_for_workspace_admins_only` asserted `billing.index`
  → 404 while no tab existed; with this tab it redirects, so the assertion is relaxed to "not forbidden".
