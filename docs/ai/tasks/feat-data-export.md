# feat/data-export — "Download my data" (GDPR Art. 15 + 20), BRAI-156 follow-up

**Branch:** feat/data-export (into `release/v1.44.0`)
**Status:** done (pending lead review)
**Started:** 2026-10-27

## Goal
The user: "do we download my data" — the legal review (BRAI-156, `docs/legal/data-inventory.md` §14)
listed an account-level data export as missing. Profile → "Your data" → "Download my data": a queued
job writes a ZIP of the signed-in person's own data (JSON + README.txt), then a notification + email
with a signed link that works 24 h, only for that person.

## What was built
1. Migration `2026_11_02_170000_create_data_exports_table` → model `DataExport` (uuid-bound,
   `TTL_HOURS` 24, `COOLDOWN_MINUTES` 60, Prunable 30 days, `expire()`, `present()`), same lifecycle as
   `ProjectExport`; files under `data-exports/<uuid>/` on the private `local` disk.
2. `App\Support\Privacy\PersonalDataArchive::write($user, $zipPath)` — one JSON file per category,
   explicit column allowlists (never `SELECT *`), big tables streamed to work files, README.txt,
   `manifest.json` (format `brainitive-personal-data` v1, counts, not-included list), `whiteboards/`,
   `files/` (profile photo + attachments the person uploaded, copied while ≤ 500 MB, else listed).
3. Job `ExportPersonalData` (after the response on the sync queue; `deleteWhenMissingModels`; a failure
   clears the hourly limit) → `DataExportReadyNotification` (`data_export_ready`, category workspace):
   **always in-app + email** (the person asked; branded *account* mail), push per category. Added to
   `EmailPreviews` (`data-export-ready`).
4. `DataExportController` — routes `profile.data-export.index|store|download` at `/data-exports…`
   (not under `/profile`: account mails must not link `/profile`, `EmailTemplatesTest`). Store:
   `throttle:5,1,profile.data-export.store` + named limiter `data-export:<user id>` (1 per hour) + one
   pending at a time. Download: owner check first (403), then signed relative URL (403), then expired /
   missing file → redirect to `profile.edit#your-data` with a flash; Inertia visits → `Inertia::location`.
   Routes are in the `auth` group (not `verified`), like `/profile`.
5. Cleanup: `exports:cleanup` (hourly) also expires data exports; `model:prune` daily for
   `DataExport`; `UserDeletion::delete()` expires the person's exports before deleting the account
   (the rows cascade, files wouldn't).
6. UI: `Pages/Profile/Partials/DownloadDataForm.tsx` in the "Your data" card (`#your-data`, before
   Delete account): button, status list (Preparing / Ready + Download + expiry / Failed / Expired),
   "You can request a new copy in N minutes". Types `DataExportData`, `DataExportState`.
7. Legal: Privacy → "Your rights" paragraph; `docs/legal/README.md` (follow-up → done section);
   `docs/legal/data-inventory.md` §14.

## What is in the ZIP
account (profile, settings, notification choices, sign-in **provider names**, terms acceptance, admin
flag), memberships (workspaces + projects, roles), issues created / assigned / reported (fields,
description HTML + text, your roles only), comments the person wrote (issues, pages, client portals as
team), pages they authored (+ their own unpublished draft), whiteboards (`.excalidraw`), task-list items
created / assigned, captures, snippets + collections, bookmarks + items, time entries, My Day, sticky
notes, notifications of the last 365 days (type, title, project, link without query — no body), push
devices (label, UA, dates), database sessions (IP, UA, last activity — no id / payload), calendar
connections (provider, account, calendar name, server **host**) + feed options, app tokens / personal
tokens / OAuth clients (names, scopes, dates), own activity (2 years, ≤ 10 000, field names only),
admin actions on the account (action + date), guest-portal identities (+ portal access, decisions,
comments, ideas, votes, follows), uploads.

## Decisions
- **Allowlists, not denylists.** Every query names its columns, so a new secret column can't leak.
- **Own data only.** Other people never appear as records: no other people's comments, no assignee /
  reporter names (only "your roles"), no notification bodies (excerpts of others' comments), no admin
  name / IP on audit entries, activity lists changed field *names* only.
- **Workspace content only where the person still has access** (workspace member; project member or
  workspace owner/admin). Content in workspaces they left is the workspace's (controller) — README +
  privacy policy say to ask the workspace; account-level data is always included.
- **Guest identities by email only for verified accounts** (else anyone could register an unverified
  account with someone's address and read their portal activity); `user_id`-linked always.
- **Trashed own content included, flagged** (`in_trash`, `deleted_at`).
- **Uploads ≤ 500 MB copied**, else listed with name / size (attachments only; zip names are
  `files/attachments/<id>-<slug>.<ext>` — no `../` from user file names).
- **Self-hosted = Cloud**: no Cloud-only logic.

## For the lead — KNOWLEDGE.md candidate
### "Download my data": own data, allowlisted, 24 h (BRAI-156 follow-up)
`PersonalDataArchive` builds the GDPR Art. 15/20 ZIP from explicit column lists (never `SELECT *`), so a
new table/column is **not** exported until someone adds it there — when you add personal data, add it
to the archive (and its README) and to `docs/legal/data-inventory.md`. Workspace content only from
workspaces / projects the person can still open; guest identities by email only for verified accounts;
other people only as text inside the person's own content. Lifecycle like project exports (signed
relative URL + owner session + unexpired row; `exports:cleanup` deletes files; rows pruned after 30 d;
`UserDeletion` deletes files). Limit: named limiter `data-export:<user id>` 1/h (cleared when the job
fails). Routes live at `/data-exports` (account mails must not link `/profile`).

## ARCHITECTURE.md candidate
- **Download my data (BRAI-156):** `DataExportController` (`profile.data-export.index|store|download`,
  `/data-exports`) → `DataExport` (`data_exports`) → job `ExportPersonalData` →
  `App\Support\Privacy\PersonalDataArchive` → `DataExportReadyNotification` (`data_export_ready`). UI
  `Pages/Profile/Partials/DownloadDataForm.tsx` (Profile → Your data). Tests
  `tests/Feature/Profile/DataExportTest.php`, e2e `e2e/tests/data-export.spec.ts`.

## Review fixes
- security-reviewer: guest identities only for accounts with a verified email (raw column) — also the
  `user_id`-linked ones (`PortalAuth::current()` links via the relaxed `hasVerifiedEmail()` when the
  instance doesn't require verification → **follow-up ticket**: that link should check the raw column);
  an account deleted while the job runs leaves no ZIP (re-check row + user after writing; `failed()`
  deletes the folder by the serialized uuid); a new request expires the person's earlier files (only the
  newest copy exists); store's checks + limiter + insert under `Cache::lock`; job middleware
  `WithoutOverlapping(...)->dontRelease()` (timeout 1800 s > queue `retry_after`); time entries scoped
  to projects the person can open.
- php-reviewer: comments / snippets / bookmark items streamed (split into `comments.json`,
  `page_comments.json`, `client_portal_comments.json`, `snippet_collections.json` + `snippets.json`,
  `bookmarks.json` + `bookmark_items.json`); `lazyById` everywhere (pages 25 at a time — whiteboard
  scenes can be big); `in_trash` also for trashed boards / lists / projects; `exports:cleanup` walks data
  exports with `lazyById`; tests for the workspace-admin scope branch, the "already being prepared"
  branch and the rendered Profile page.
- frontend-reviewer: the hourly wait refreshes itself and re-enables the button; a queued export older
  than an hour no longer locks the button (server rule); an expired ready row shows "Expired" without
  the link; `aria-live` on the list; the e2e removes its temp folder.
- Not done here (owner rule: don't touch HelpModal / Welcome.tsx): changelog + homepage entry → lead.

## Convention exception (for CONVENTIONS / KNOWLEDGE)
`DataExportReadyNotification::via()` ignores the category's in-app / email choice: the person asked for
the copy, so it always arrives in the app and by email (a transactional *account* mail, no "Manage
notifications" footer); only push follows the "Workspace" category.

## Tests
- PHPUnit `tests/Feature/Profile/DataExportTest.php` (11): expected files + contents for a seeded user;
  no secrets / other people's data (grep of every file for seeded secrets and secret field names);
  unverified accounts don't get guest identities by email; notification (in-app + mail, signed URL) +
  email preview; download signed / expiring / owner only / login required + cleanup; 1 per hour (other
  people unaffected); list shows own exports only, auth required; prune + account deletion removes
  files; failed job cleans up and lifts the limit; profile section; removed-from-project content left out.
- e2e `e2e/tests/data-export.spec.ts`: fresh account → Profile → Download my data → Ready → button
  throttled → ZIP downloads with account.json / README / manifest → email link (local) → another
  signed-in user gets 403, a visitor is sent to login.

## Changelog line
- **Download my data:** Profile → Your data gives you a copy of everything Brainitive holds about your
  account — profile, settings, the issues, comments, pages, whiteboards and tasks you created, time
  entries, notifications, devices, connected calendars and apps, and your uploaded files — as a ZIP of
  JSON files with a README. The link arrives in the app and by email and works for 24 hours, only for you.
