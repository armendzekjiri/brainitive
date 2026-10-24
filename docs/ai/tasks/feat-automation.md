# feat/automation: BRAI-63 Workflow automation (release v1.40.0, ticket 1)

Card: BRAI-63 (Brainitive / Main Board, item 165). Contract: `release-v1.40.0.md` → "1. BRAI-63".

## Data model
- `automation_rules`: project, name, `enabled` (false on create), `version` (+1 when the trigger,
  conditions or actions change), `actor_id` (the person who saved it last; the rule runs as them),
  `trigger`, `trigger_config` (json), `conditions` (json list), `actions` (json list), last run.
- `automation_runs`: rule, `rule_version`, `event_id` (unique with rule + version = idempotency),
  subject (item / ticket + "KEY title" snapshot), status queued → running → success / skipped / error,
  message, per-action `results`, attempts, timestamps. Pruned after 30 days.

## Flow
`DomainEvents::emit(..., subject: $model, context: [...])` → `Automation\AutomationEvents::seen()`
(one `exists` query for an enabled rule of that project + trigger, nothing when a run is in progress)
→ `DB::afterCommit` → buffered until the HTTP request's controller finished (`DeferAutomations`
middleware), else started at once → per matching rule: conditions pre-check, `createOrFirst` run,
`RunAutomation` job (queued; inline on the sync queue) → `AutomationRunner` (actor + permissions
re-checked, conditions re-checked, actions one transaction each with their result).

Scheduled: `app:run-due-date-automations` (every 5 min) raises `item.due` once per item + due date.

## Steps
1. Migration + models + `Project::automationRules()`.
2. `Support/Automation`: catalog, definition validation, events/deferral, conditions, actions,
   runner (+ dry run), due-date scan. Job, middleware, command, notification `automation_notice`.
3. Hook points: `DomainEvents::emit` subject/context; ItemEvents, ItemComments, TicketController.
4. `AutomationController` + routes + Inertia pages (`Pages/Automations/Index|Edit`), links from
   project Tools + settings.
5. PHPUnit (`tests/Feature/Automation/*`), e2e `automations.spec.ts`, docs.

## Status
Done (2026-10-24): feature, PHPUnit (`tests/Feature/Automation/*`), e2e `automations.spec.ts`, docs
(ARCHITECTURE "Workflow automation", KNOWLEDGE "Workflow automation"). Merged `release/v1.40.0`.
