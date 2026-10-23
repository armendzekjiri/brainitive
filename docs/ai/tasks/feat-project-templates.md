# Project templates (BRAI-72)

**Branch:** feat/project-templates
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-72 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-174

## Problem / goal
Start a complete project — boards with their columns, sample issues, docs, checklists and a
plan — from a reusable structure instead of building it by hand every time. Built-in
templates ship with the app; workspace admins can save one of their projects as a template.

## Approach
A template is a **versioned JSON document** (`format: 1`) that never contains database ids:
every object has a local `ref` (`b1`, `c3`, `i12`, …) and references use those refs. Creating
a project walks the document once, creating fresh rows and filling a ref → new id map, so
internal links always point at the new objects. Dates are **calendar-day offsets** from the
template's anchor; the user picks the new project's start date (day 0).

- **Built-ins** (code, `BuiltinTemplates`): Client website delivery, Product launch, Scrum project.
- **Workspace templates** (`project_templates` + immutable `project_template_versions`):
  saving again adds a new version; a project never follows later template edits (it's a copy).
- **Capture** (`TemplateCapture`): labels, project item types, disabled default types, custom
  fields + options, boards (type, columns, WIP limits, swimlanes with ids → refs), and by
  choice sample issues (hierarchy, labels, type / priority by slug, points, estimate, dates as
  offsets, field values, links between included issues), published docs/folders (live content
  only), task lists + tasks, plans (bars as offsets, issue links as refs).
- **Never captured:** comments, attachments, history, watchers, members, assignees / reporters
  (plan "Who" is cleared too), sprints, releases, helpdesks, GitHub connection, share / changelog
  tokens, drafts and unpublished docs, My Day / timers, reminders already sent. Mentions are
  turned into plain text; whiteboard issue cards lose their issue link; inline diagram embeds
  (stored per page) are dropped.
- **Create** (`TemplateInstantiator`): one DB transaction → no half-created project. Idempotent
  via a client `request_id` in `project_template_runs` (unique): a retry returns the project
  that was already created; a failed run can be retried.
- **Preview / exclusion:** the save dialog shows what will be captured (with toggles for sample
  issues, docs, lists, plans); the create form shows what the template contains and lets the
  user leave out the same sections.
- **Compatibility:** `TemplateDocument::normalize()` validates and upgrades documents by
  `format`; unknown future formats are refused; missing optional keys are tolerated.

## Changes
- **Migrations:** `project_templates` (uuid, workspace_id, name, description, created_by),
  `project_template_versions` (template, version, format, document longText, summary json,
  source_project_id, created_by), `project_template_runs` (request_id unique, user, workspace,
  template, status, project_id, error).
- **Models:** `ProjectTemplate`, `ProjectTemplateVersion`, `ProjectTemplateRun`.
- **Support:** `app/Support/Templates/` — `TemplateDocument`, `TemplateCapture`,
  `TemplateInstantiator`, `BuiltinTemplates`.
- **Policy:** `ProjectTemplatePolicy` (use = workspace members who may create projects; save /
  delete = workspace admins) — registered in `AppServiceProvider`.
- **Controller / routes:** `ProjectController@store` gains optional `template`, `start_date`,
  `include[]`, `request_id`; `ProjectTemplateController` — `capturePreview` (project → summary
  JSON), `store` (save project as template / new version), `destroy`.
- **Frontend:** `Projects/Create.tsx` template picker + preview + start date + include toggles;
  `components/projects/SaveAsTemplateCard.tsx` in project settings (General tab); types.

## Limits
500 issues, 200 docs, 50 task lists (500 tasks each), 20 plans per template; document ≤ 8 MB.

## Test plan (tests/Feature/Projects/ProjectTemplatesTest.php)
- Each built-in creates a usable project (boards, columns, issues, docs readable by members,
  lists, plans) with keys `KEY-1…` and no original ids.
- Capture → instantiate round trip: refs remapped (parents, links, labels, types, field values
  incl. options, plan itemIds, field board_ids, swimlane rules); excluded data absent.
- Dates shift by offsets across month / year boundaries.
- Two uses → independent projects; later template version doesn't touch old projects.
- Same request_id twice → one project; failure mid-way → nothing created, run marked failed.
- Permissions: viewers / non-members can't use or save; editors can use but not save; templates
  of another workspace are 404.
- Key / name collisions → validation errors; slug collision (incl. trashed) → `-2`.
- Old / unknown format handling.

## Docs to update
ARCHITECTURE (Projects module), CONVENTIONS (template rules), KNOWLEDGE (what's excluded and
why, next_item_number gotcha), HelpModal changelog (minor bump), Welcome/WELCOME_FEATURES if
it fits.

## Open questions
- Role placeholders for assignees are out of scope; assignees are always left empty.
