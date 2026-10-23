# Lead Generation forms (BRAI-93)

**Branch:** feat/lead-forms
**Status:** in-progress
**Started:** 2026-10-23
**Board card:** BRAI-93 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-202

## Problem / goal (from the product owner)
A new project module **Lead Generation**, next to Boards / Lists / Helpdesks in the sidebar
(shown with the helpdesks). A project has many lead forms; **each form has its own leads
board** and every submission becomes a card there (like helpdesk tickets). The builder is
very customisable: multi-step, many field types, choice cards with icons, conditional logic,
branding (logo, colours, cover, font, button style). Published at **/f/{slug}** (full page to
promote) and as an **embed** (iframe snippet). After submit: thank-you screen or redirect,
confirmation email to the lead, notification to the team. **Analytics** (views, starts,
completions, drop-off per step). **Webhooks out** (each new lead) and **in** (other tools push
leads). Different **templates** to start from.

## Data model
- `lead_forms`: uuid, project_id, name, slug (unique per project), public_slug (unique,
  `/f/{public_slug}`), status `draft|published`, `definition` json (steps → fields, logic),
  `theme` json, `settings` json (thank-you, emails, notify users, webhook url), `webhook_secret`
  (encrypted, signs outgoing payloads), `inbound_token` (hashed; shown once), created_by,
  position, timestamps, soft deletes.
- `lead_form_columns`: lead_form_id, name, slug, color, position, is_done (first column = inbox).
- `leads`: uuid, lead_form_id, column_id, number (per form, `#12`), name, email, phone,
  company, `answers` json (field id → value), `source` `form|webhook`, `meta` json (utm,
  referrer), assigned_to, position, timestamps, soft deletes.
- `lead_form_events`: lead_form_id, session_id (random, client), type `view|start|step|submit`,
  step (index), created_at — analytics only, no personal data.

## Definition format (validated + normalised by `App\Support\LeadForms\LeadFormDefinition`)
```
{ version: 1,
  steps: [{ id, title, description?, visible_if?, fields: [
    { id, key (snake_case, for webhooks/inbound), type, label, help?, placeholder?, required,
      maps_to? name|email|phone|company, options?: [{ id, label, icon? }],
      layout?: cards|list, min?, max?, visible_if? } ] }] }
visible_if: { field: <field id>, op: equals|not_equals|includes|gt|lt|answered|not_answered, value? }
```
Field types: `short_text, long_text, email, phone, number, date, select, choice` (single, cards
with icons), `multi_choice`, `rating` (1–5), `consent`, `hidden` (filled from `?key=` URL param).
Logic is evaluated the same way on the server (`LeadFormLogic`) and in the browser
(`lib/leadForms.ts`): hidden steps / fields are skipped and never validated.

## Endpoints
- App (auth): `projects/{project}/lead-forms` CRUD (`lead-forms.*`, policy like helpdesks:
  project editors+ manage, members view), builder save (definition / theme / settings),
  publish, columns CRUD, leads move / update / delete, analytics JSON, inbound token rotate.
- Public: `GET /f/{public_slug}` (page), `GET /f/{public_slug}/embed` (iframe), `POST
  /f/{public_slug}/submit` (throttled, honeypot), `POST /f/{public_slug}/events` (analytics,
  throttled), `POST /api/lead-forms/{uuid}/inbound` (Bearer inbound token).
- Webhooks out: queued `DeliverLeadWebhook` (HMAC-SHA256 `X-Brainitive-Signature`, retries,
  URL through `OutboundUrl`).

## UI
- Sidebar: "Lead forms" group under the project (with helpdesks); create from the "Add
  content" modal and Projects/Show with a template picker.
- `Pages/LeadForms/Show.tsx` tabs: **Leads** (board: columns, drag & drop, lead sheet),
  **Builder** (steps + fields list, live preview, field settings, logic, theme), **Share**
  (URL, publish, embed code), **Analytics**, **Settings** (thank-you, emails, webhooks,
  inbound token, columns).
- Public `Pages/LeadForms/Public.tsx`: branded, multi-step with progress bar, choice cards
  with icons, mobile-first; embed variant without page chrome.

## Templates (`App\Support\LeadForms\LeadFormTemplates`)
B2B demo request, Free trial sign-up, Agency project brief, Quote calculator, Real-estate
inquiry, Consultation booking, Course / workshop enrolment, Webinar registration, Event
lead, Newsletter with interests, Contact sales, Job interest pool.

## Phases
1. Backend: migrations, models, policy, definition + logic, templates, CRUD, public submit,
   tests.
2. Builder + leads board UI.
3. Public page + embed, thank-you, emails, analytics, webhooks in/out, docs, changelog.

## Test plan
Definition validation (bad ids, unknown types, options), logic (hidden step not validated),
public submit (required, options, email, honeypot, draft forms 404), leads land in first
column with number + mapped name/email, inbound webhook (token), outgoing webhook job (signed,
OutboundUrl), analytics counts, permissions (viewer read-only, outsider 403), templates create
usable forms.
