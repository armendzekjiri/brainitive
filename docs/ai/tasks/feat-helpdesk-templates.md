# Helpdesk templates (BRAI-92)

**Branch:** feat/helpdesk-templates (stacked on feat/template-gallery)
**Status:** done
**Started:** 2026-10-23
**Board card:** BRAI-92 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-201

## Problem / goal
A helpdesk is a configurable public form (widget, support page, portal) plus a ticket board.
People use it for far more than support: website contact forms, event registration, job
applications, quote requests… Creating one should start from a template that brings the
right columns, form fields (with fitting types) and form texts.

## Approach
- **Templates in code:** `App\Support\Helpdesks\HelpdeskTemplates` — registry (key, name,
  category, icon, description, tags) + one builder per template returning `columns`,
  `fields` (label, type, options, required, on card) and `settings` (form texts, default
  fields). ~17 templates in 5 categories: Support, Website forms, Events & bookings,
  People & internal, Product.
- **Create:** `helpdesks.store` takes an optional `template`; in one transaction the
  helpdesk gets the template's settings, its columns replace the three defaults, and its
  fields are created (unique snake_case names). Catalog JSON `helpdesk-templates.index`.
- **Form texts become settings** (today hard-coded): `form_title`, `submit_label`,
  `message_placeholder`, `success_message`, `after_submit` (`portal` = open the request,
  `message` = thank-you screen), `default_field_subject` `{label, hidden}` + `auto_subject`
  (forms like event registration don't need a "Subject"). Read in one place:
  `App\Support\Helpdesks\HelpdeskForm`. Editable in helpdesk settings → General.
- **Public submissions are validated on the server** (`HelpdeskForm::publicRules()`):
  required name/message per settings, required custom fields, numbers, dates, options of
  select / radio / checkboxes. Before, only the browser checked them.
- **Floating widget** gets radio, date and multi-checkbox fields, honours "attachments
  off", the form texts and the thank-you screen.
- **UI:** `components/helpdesk/CreateHelpdeskDialog.tsx` — gallery (categories, cards,
  detail with a live form preview and the columns) → name + description → create. Opened
  from the sidebar "Add content" helpdesk tile and Projects/Show.

Never part of a template: tickets, replies, contacts, public slug, uploaded logo.

## Test plan (tests/Feature/Helpdesk/HelpdeskTemplatesTest.php)
- Every template creates a helpdesk with its columns (one done column at least), fields
  (types valid, options for choice fields) and settings; blank keeps today's defaults.
- Unknown template → 422; only project editors+ create.
- Public form: required custom field missing → 422; select value outside options → 422;
  hidden subject → auto subject; success message / after_submit come from settings.
- formConfig exposes texts and subject config; catalog needs auth.

## Docs to update
ARCHITECTURE (Helpdesk), KNOWLEDGE (public validation now server-side, form text
settings), HelpModal changelog.
