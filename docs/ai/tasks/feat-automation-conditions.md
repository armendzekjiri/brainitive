# feat/automation-conditions: BRAI-144 flexible condition builder (release v1.42.0)

Card: BRAI-144 (board item 318). Builds on BRAI-63 (`feat-automation.md`).

User: "automation work but: on the condition we need separated is, is not, contain etc, like: label
then select one: is then chose ... so we are flexible?"

## Model
- A condition is `{field, operator, value}`; a rule has `condition_match` = `all` | `any` (default all).
- `value`: null (empty-type operators), an id / text / number, a list of ids (any-of / all-of / none-of),
  `{days: n}` (within the next n days) or `Y-m-d` (before / after).
- Custom fields of issues: `field = "custom:<item_field id>"`. Archived / deleted field, or an operator
  that no longer fits its type → the condition fails closed, described "(field removed)".
- The field / operator matrix lives in `AutomationCatalog` (`CONDITION_FIELDS`, `OPERATORS`,
  `CUSTOM_FIELD_OPERATORS`), sent with `forFrontend()`; evaluation + `describe()` in
  `AutomationConditions`; validation + legacy normalisation in `AutomationDefinition`.
- Dates ("today", overdue) in the rule actor's time zone (`User::localTimezone()`), like `DueDateTrigger`;
  the dry run uses the current user's.

## Backward compatibility
- Legacy `{type, value}` → `AutomationDefinition::normalizeCondition()` on read (engine, describe,
  builder props): board/helpdesk/item_type/priority/column → `is`, label → labels `has`,
  assignee_empty → assignee `is_empty`, assignee_is → assignee `is`, title_contains → title `contains`.
- Data migration `2026_10_31_180100_convert_automation_conditions` rewrites stored rules once
  (idempotent: already-new conditions are left alone). `down()` is a no-op: the old engine can't read
  the new operators, and the read normalisation keeps working either way.
- Schema migration `2026_10_31_180000_add_condition_match_to_automation_tables`:
  `automation_rules.condition_match` (default `all`), `automation_runs.conditions` (json, the checked
  conditions `{label, passed}` + match, shown in the run history). Old runs have none: nothing shown.

## Steps
1. Catalog matrix, conditions engine, definition validation + normalisation, options (custom fields).
2. Migrations; runner / events / preview store + pass the match and time zone.
3. Builder UI: `components/automations/ConditionRow.tsx` (field → operator → value), match toggle,
   reorder; sentence preview; RunLog shows conditions.
4. PHPUnit `tests/Feature/Automation/AutomationConditionsTest.php`; e2e `automation-conditions.spec.ts`;
   adapt `automations.spec.ts` selectors.

## Doc notes (for ARCHITECTURE / KNOWLEDGE on merge)
- ARCHITECTURE "Workflow automation": conditions are `{field, operator, value}` + rule
  `condition_match`; matrix in `AutomationCatalog::CONDITION_FIELDS` / `OPERATORS` /
  `CUSTOM_FIELD_OPERATORS`; UI `components/automations/ConditionRow.tsx`; runs keep `conditions`.
  Add `AutomationConditionsTest.php` and e2e `automation-conditions.spec.ts` to Tests.
- ARCHITECTURE "Recipes" row: a new condition field = `CONDITION_FIELDS` entry + a case in
  `AutomationConditions::actual()` (+ `AutomationOptions` source if it has ids).
- KNOWLEDGE "Workflow automation": legacy `{type, value}` conditions are normalised on read
  (`AutomationDefinition::normalizeCondition`) even after the data migration — keep it; custom
  field conditions fail closed when the field is archived / gone or its type changed; date operators
  use the actor's time zone; "not" operators (is_not, not_in, has_none, not_contains) are true for
  an empty value.

## Status
In progress.
