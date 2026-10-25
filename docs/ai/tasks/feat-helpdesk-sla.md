# Helpdesk SLA (BRAI-125)

**Branch:** feat/helpdesk-sla (into `release/v1.40.0`)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-125 · contract: [`release-v1.40.0.md`](./release-v1.40.0.md) → "5. BRAI-125"

## Problem / goal
Helpdesks have no service levels. Agents can't see which tickets need an answer first, nobody is warned
before a target is missed, and Insights can't say how often targets are met.

## Approach
- **Policy per helpdesk** in its own table (`helpdesk_sla_policies`, one row per helpdesk): on/off,
  targets per priority (`{"<priority id>"|"none": {first_response: {value, unit}|null, resolution: …}}`),
  business hours (`null` = 24/7, else weekdays + from/to, overnight allowed) in a time zone, pause columns,
  warning threshold (% of the target, default 80).
- **Ticket columns:** `first_responded_at`, `sla_first_response_due_at`, `sla_resolution_due_at`, the two
  warning times, `sla_paused_at` + `sla_paused_minutes`, `sla_breached_at` + one flag per target.
- **Maths** in `Support/Helpdesks/Sla/BusinessCalendar` (pure: add business minutes, business minutes
  between two instants; local wall-clock windows per date → DST-safe). `TicketSla::apply()` computes every
  SLA column from the ticket + policy + now (deterministic). Pause = resolution clock only; paused business
  minutes are added to the resolution target.
- **When recomputed:** the `Ticket` model's `saving` hook when created or when priority, column,
  `completed_at` or `first_responded_at` change; saving the policy recomputes the helpdesk's open tickets;
  the checker recomputes the tickets it looks at. `first_responded_at` is set by a `TicketReply` `created`
  hook (first public reply with a `user_id`), so every agent path counts (app reply, resolve message, ticket 3's
  emailed replies) without touching the controllers.
- **Checker** `app:check-helpdesk-sla` (every minute, idempotent): pending targets past their warning /
  due time → `sla_warning` / `sla_breached` (category `helpdesk`) to the assignee, else the project admins;
  once per (ticket, kind, target, user) via `sent_item_reminders`.
- **UI:** SLA tab (own JSON endpoints `helpdesks.sla.show/update`), `SlaBadge` on cards, list (new column)
  and the ticket panel; filter + sort by SLA on the board and the list. Badges compute "breached" on the
  client too, so dev (no scheduler) shows it.
- **Insights → Helpdesk:** breaches in the range and "met %" of the tickets with an SLA resolved in the
  range, inside the existing per-helpdesk query (query count stays 7).
- **e2e:** `helpdesks.sla.e2e-check` (registered only with `APP_ENV=e2e`) backdates one ticket and runs the
  checker.

## Test plan
- PHPUnit `HelpdeskSlaTest` (calendar maths: overnight, weekends, DST; pause/resume; recompute on
  priority/policy; first response; notifications once; permissions; e2e route absent), InsightsTest (SLA
  numbers), CatalogNotificationContractTest (new classes).
- e2e `helpdesk-sla.spec.ts`: policy → badge; checker → "Breached" + bell notification + Insights.

## Docs to update
ARCHITECTURE (helpdesk SLA), KNOWLEDGE (SLA maths + recompute decisions), add-notification skill table.
