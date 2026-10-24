# Meeting notes to tasks: AI extraction with editable review and safe creation (BRAI-62)

**Branch:** feat/meeting-notes-to-tasks (release v1.37.0)
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-62 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-164

## Problem / goal
After a meeting, the action items sit in a doc (or in someone's paste buffer) and have to be typed
into the board by hand. Turn meeting notes — a doc the user can read, or pasted text — into
**proposed** issues, let the user review and edit every proposal next to the sentence it came
from, then create only the selected ones, once.

Non-goals: recording / transcription, meeting bots, autonomous assignment, external messages,
calendar invites.

## Approach
Three steps, three endpoints, nothing written before the last one.

1. **Options** (`GET meeting-notes.options`, ReadOnlySession): boards the user can create issues on
   (`BoardPolicy::createItems`), project members who can open the project (assignee picker),
   the user's timezone, whether workspace AI is configured, and — for the doc entry point — the
   source doc (title, length, whether the user's unpublished changes are used).
2. **Extract** (`POST meeting-notes.extract`, `throttle:10,1,meeting-notes.extract`): the user has
   picked the destination board first. Server re-authorizes board + source, reads the text,
   calls the workspace BYOK provider through `App\Support\MeetingNotes\ActionItemExtractor` (own
   class; `AiController` is untouched), then `ProposalReview` validates and enriches the output.
   Returns proposals only — **no writes**.
3. **Create** (`POST meeting-notes.store`, `throttle:30,1,meeting-notes.store`): selected,
   edited proposals + `idempotency_key`. Re-checks every permission (board `createItems`, source
   doc `view`, assignees via `ProjectMember`), then creates all issues in one transaction through
   `ProjectIssues::create`, wrapped in `IdempotentRequest::run` (tool `meeting-notes.create`, the
   existing `mcp_requests` table — no migration). A retry with the same key replays the stored
   result; the same key with different content is a 409.

**Source text.** Doc: `PagePolicy::view`; editors who have unpublished changes extract from the
draft they see, everyone else from the live content (drafts are never shown to readers).
Converted with `PageContentConverter` (content → HTML → Markdown). Whiteboards/folders are refused.
Pasted text: max 20 000 chars. Longer → 422 `too_long` (never silently truncated).

**Prompt-injection safety.** The notes go in the user message between a random, per-request
boundary (`<notes-{hex}>…</notes-{hex}>`); the system prompt says the notes are data and any
instructions inside them must be ignored. The model can't act: its output is only parsed into
proposals that a human reviews. Every proposal must quote its evidence: an excerpt that isn't
found in the notes (whitespace/case-insensitive), or is shorter than 3 words / 12 characters and
not a whole line, is dropped and counted (`dropped`, shown). An owner the notes don't name is ignored.

**Validation of model output** (`ProposalReview`):
- JSON object `{"action_items": [...]}` (code fences / prose around it are stripped); anything
  else → 422 `invalid_output`, nothing created, the form keeps the user's input.
- Max 25 proposals (`truncated` flag), strings capped (title 200, description 2000, excerpt 500).
- **Assignee:** the name as written is matched only against project members (full name, or all
  mention words are words of exactly one member's name). One match → suggested; several →
  `ambiguous` with candidates (the UI requires a choice or "leave unassigned"); none → stays
  unassigned with a note. Emails are never sent to the client.
- **Due date:** `due_text` must appear in the notes. With a meeting date + timezone, the model
  may resolve relative dates (prompt carries "meeting on Tue 2026-10-20, Europe/Berlin"), and the
  server accepts dates from meeting − 31 days to meeting + 2 years. Without a meeting date, a
  date is kept only when `due_text` is an explicit calendar date (contains the year and the day);
  otherwise it stays blank with a "needs meeting date" hint.
- **Dedup, visible:** proposals with the same normalized title or excerpt as an earlier one get
  `duplicate_of` (unselected by default, badge); proposals whose title matches an open issue on
  the destination board get `existing` (key, link; unselected by default, badge). Nothing is
  removed.

**Provider errors:** not configured → `not_configured` (UI shows set-up state, settings link for
workspace admins); blocked endpoint (`OutboundUrl`) → `endpoint_blocked`; timeout /
connection → `timeout`; non-2xx → `provider_error`; cut-off or non-JSON → `invalid_output`.
Notes are never logged.

**Source link (optional, default on).** Each card's description gets the evidence quote and
"From meeting notes: <doc link>"; with a doc source, a manual `ContentReference` item → page is
added too (shows under "Related" on both sides). The notes are never modified.

## Changes
- Migrations: none (idempotency reuses `mcp_requests` through `IdempotentRequest`).
- Support: `app/Support/MeetingNotes/{ActionItemExtractor,ExtractionFailed,ProposalReview,MeetingNotesSource,ActionItemCreator}.php`.
- Controller / routes: `MeetingNotesController` (`options`, `extract`, `store`) under
  `workspaces/{workspace}/projects/{project}` (scoped bindings). No new model → no new policy;
  board / page / project policies are reused.
- `ProjectController::show`: `canCreateIssues` prop (any board with `createItems`).
- Frontend: `components/meetingNotes/ExtractActionItemsDialog.tsx` (setup → review → result),
  entry points in `Pages/Pages/Show.tsx` (more menu → "Extract action items", docs only) and
  `Pages/Projects/Show.tsx` ("Meeting notes → issues" card, pasted text). Types in
  `types/index.d.ts`.

## Test plan
- PHPUnit `tests/Feature/MeetingNotes/MeetingNotesTest.php` with `Http::fake`:
  3-action fixture → 3 traceable proposals; missing owners/dates unset; ambiguous name →
  candidates; relative date without meeting date blank, with date + tz set; invented excerpt
  dropped; prompt-injection text stays inside the boundary and its fake commitment without
  evidence is dropped; malformed JSON / timeout / provider error / not configured → error and no
  writes; extraction creates nothing; create only selected; retry same key → no duplicates;
  same key, different payload → 409; viewer / outsider / board of another project / unreadable
  draft doc → 403/404 even after a permission change between extract and create; foreign
  assignee → 422; source link + reference.
- E2E `e2e/tests/meeting-notes.spec.ts`: not-configured state (real server); review → edit →
  reject one → create (extract mocked with `page.route`, options patched to `ai_configured`);
  lost response + retry creates no duplicates; doc entry point.
- Mocked AI only; no live-model wording asserted.

## E2E: how the AI is faked
The extraction call goes from the browser to our server, so the spec intercepts it with
Playwright's `page.route` (and patches `ai_configured` on the options response). No stub route
and no provider exists on the server at all — nothing to leak outside e2e, and the same spec runs
unchanged against dev.brainitive.app. The create step is real (server, database, idempotency).

## Docs to update
ARCHITECTURE (module row), KNOWLEDGE (decisions: idempotency via `mcp_requests`, evidence check,
date rule, e2e faking).

## Open questions
- Scrum boards: issues land in the backlog (as `ProjectIssues::create` does elsewhere).
