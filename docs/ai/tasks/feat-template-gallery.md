# Template gallery + more project templates (BRAI-91)

**Branch:** feat/template-gallery
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-91 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-200

## Problem / goal
Follow-up to BRAI-72. Four built-in templates are too few, and picking one should feel like
Jira's template gallery: a popup with categories, search, a detail view of what you get, and
then name / key / start date — from every "New project" button.

## Approach
- **Catalog:** `BuiltinTemplates::all()` gains `category` and `tags`; `TemplateDocument::summary()`
  gains the detail-view data (issue types used, sample issue titles, doc names, label names).
  `TemplateRuns::catalog()` adds the category (`workspace` for saved ones). New JSON endpoint
  `projects.templates.catalog` (`GET workspaces/{workspace}/project-templates`, `createProjects`)
  so the popup loads the catalog when it opens (not on every page).
- **UI:** `components/projects/TemplateGallery.tsx` (categories, search, cards, detail view,
  step 2 form) used by `CreateProjectDialog.tsx` (mounted once in `AppLayout`, opened with
  `openCreateProject()` — a window event — from the project switcher, the sidebar empty state,
  the workspace page and a "Create project" command palette entry) and by
  `Pages/Projects/Create.tsx` (still there for direct links).
- **New built-ins (16):** Software — Bug tracking, Release & DevOps; Marketing — Marketing
  campaign, Content calendar, Social media, Event planning; Agency & clients — Client
  onboarding, Design requests; HR & people — Recruiting pipeline, Employee onboarding;
  Sales — Sales pipeline; Operations & finance — Budget & purchase approvals, Office & IT
  requests; Personal & team — Personal tasks, Team goals / OKRs, Meeting notes & 1:1s.
  Each: board + columns that fit the work, labels / fields, starter issues, a doc, and a
  checklist or plan where it helps. Default types / priorities by slug only.

## Test plan
- Every built-in still creates a usable project (existing loop test covers new ones).
- Every built-in has a known category; catalog endpoint: editors get it, viewers 403,
  saved templates come back with category `workspace`.
- Summary carries issue types / sample titles / doc names.

## Docs to update
ARCHITECTURE (templates line), HelpModal changelog (minor), KNOWLEDGE if anything surprising.
