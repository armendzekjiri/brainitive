# Universal quick capture: private inbox + convert to content (BRAI-70)

**Branch:** feat/quick-capture
**Status:** done
**Started:** 2026-10-24
**Board card:** BRAI-70 — https://www.brainitive.app/workspaces/ndizeal-everything/projects/brainitive/boards/main-board#item-172

## Problem / goal
Capturing an idea today means choosing a project first (Create dialog, extension destinations).
Users want to jot something down **now**, without a project, and organize it later into an issue,
a list task, a doc, a bookmark or a snippet.

Non-goals: email ingestion, offline sync, automatic AI classification, file uploads.

## Approach
**Naming.** The sidebar already has "Inbox" = notifications. The new thing is called **Captures**
in the UI ("your private capture inbox"), model `Capture`, routes `captures.*`, URL `/captures`.

**Data: `captures`** (one row per captured idea, private to its owner)
| column | notes |
|---|---|
| `id`, `uuid` (unique, route key) | uuid in URLs so ids aren't guessable |
| `user_id` → users, cascade on delete | private personal data: goes with the account |
| `text` (text, ≤ 20 000) | required, stored raw, rendered as plain text only |
| `url` (2048, nullable) | `url:http,https` |
| `intended_type` (nullable) | `issue` \| `task` \| `page` \| `bookmark` \| `snippet` — a hint, preselects the destination |
| `source` (255, nullable) | free text "where it came from" (the extension sets the page title) |
| `captured_at` | server time on capture |
| `archived_at`, `processed_at` (nullable) | state, see below |
| `target_type` + `target_id` (nullable) | what the conversion created (same short type names) |
| `target_location` (json, nullable) | `ResourceLocator` snapshot at conversion time (title, breadcrumb, url) |

State is derived: **inbox** = not processed, not archived; **processed** = processed, not
archived; **archived** = archived (processed or not). Archive / restore only toggles
`archived_at`, so a restored processed capture keeps its reference.

**Indexes.** `(user_id, archived_at, processed_at, captured_at)` serves every tab list, count and
the sidebar badge (all start with `user_id`); `(target_type, target_id)` for "which capture made
this". Search is `whereLike` (ilike on Postgres) over the user's own rows only, no full-text index:
the per-user volume is small. Revisit with pg_trgm if inboxes get large.

**Retention.** Nothing is pruned automatically: an archived capture stays until its owner deletes
it (delete is offered for archived captures only), processed captures stay as the record of what
was created. Deleting the account deletes its captures (cascade) — nobody else may read them. No
soft deletes: archive is the soft state.

**Shared capture behaviour (reuse, don't recreate).** The five create paths of
`Extension/CaptureController` move into `App\Support\Captures\ContentCapture` (validation rules,
authorization through the existing policies, create). The extension controller and the
conversion both call it:
- issue → `BoardPolicy::createItems`, `ProjectIssues::create` (Markdown → HTML via `PageContentConverter`)
- task → `TaskListPolicy::manageItems`
- page → project access + `PagePolicy::create` (a private draft, like the extension's clip)
- bookmark → `BookmarkPolicy::update` (link when there is a URL, else a note; metadata job after commit)
- snippet → `SnippetPolicy::create` on the collection's workspace
Destination lists for the convert dialog reuse the extension's `destinations` payload, moved to
`App\Support\Captures\CaptureDestinations` (only targets the user can write to).

**Conversion** (`App\Support\Captures\CaptureConversion::convert`):
1. Owner check (`CapturePolicy::convert`; other users get 404).
2. In one `DB::transaction`: lock the capture row (`lockForUpdate`). Already processed →
   return the existing target (`replayed: true`, HTTP 200) — a double click or a retry never
   creates a second target. Archived → 422 (restore first).
3. Resolve the destination by type + numeric id (missing → 422), authorize with the policy above
   (403), validate the chosen fields with the shared rules (422), create, then mark the capture
   processed with `target_*` + location snapshot. Any failure rolls everything back: the capture
   stays in the inbox. Inbox ownership grants nothing on the destination.
4. Response: `{ replayed, location }` — the dialog shows the canonical link.

**Preview / fields.** `GET captures.preview?type=` returns the default fields for that type,
computed in one place (`CaptureFields::defaults`): title = first line (Markdown markers stripped,
≤ 255; the whole text goes to the body if the title had to be cut), body = the rest, the URL
appended as a Markdown link unless the text already contains it. Snippets take the first fenced
code block (and its language) or the whole text; bookmarks become a link with the URL or a note.
The user edits them in the dialog before converting.

**UI**
- Global **Capture** button in the top bar + a floating round button on small screens
  (`md:hidden`, `aria-label="Quick capture"`), command-palette command "Quick capture".
- Shortcut **C** (no modifiers) opens the capture dialog — ignored while typing: inputs,
  textareas, selects, contenteditable (Lexical), `role=textbox`, Excalidraw, inside an open
  dialog, IME composition, key repeat, or when something else already handled the key.
- Capture dialog: text (⌘/Ctrl+Enter saves), optional URL, "Organize as" (intended type), source.
- `/captures` page: tabs Inbox / Processed / Archived with counts, search, rows with text, URL,
  type, source, captured time; actions Organize (convert), Edit, Archive / Restore, Delete
  (archived only). Processed rows link to what was created. Sidebar "Captures" with an inbox count.
- Convert dialog: destination type → destination (workspace / project / board …, column for
  issues) → editable fields → Create → success state with "Open …" link.

**Extension "Save to inbox"** (only if the app flow is done): `POST /api/ext/v1/captures`
(capture scope, create only — the inbox is never readable with an extension token) + a "Save to
inbox" action in the popup; every existing direct-capture action stays.

## Changes
- Migration `2026_10_25_100000_create_captures_table`.
- Model `Capture` (+ `CaptureFactory`); `CapturePolicy` (owner only, `denyAsNotFound`),
  registered in `AppServiceProvider`.
- Support `app/Support/Captures/{ContentCapture,CaptureDestinations,CaptureConversion,CaptureFields}.php`.
- `CaptureInboxController` (index, store, update, archive, restore, destroy, destinations,
  preview, convert); routes `captures.*` in the auth group; throttles on store / convert.
- `Extension/CaptureController` + `ExtensionController::destinations` use the shared classes;
  `ext.captures.store`.
- `HandleInertiaRequests`: `captureInboxCount` (lazy).
- Frontend: `Pages/Captures/Index.tsx`, `components/captures/{QuickCaptureDialog,ConvertCaptureDialog,CaptureButton}.tsx`,
  `lib/captures.ts` (shortcut guard, labels), AppLayout wiring, types in `types/index.d.ts`.
- E2E: `e2e/tests/quick-capture.spec.ts`, `e2e/helpers/quick-capture.ts`.

## Test plan
PHPUnit (`tests/Feature/Captures/`):
- capture without a project → private row; another user can't list / update / archive / convert
  / preview it (404); validation (empty text, bad URL, javascript: URL, unknown type).
- edit, archive / restore, delete only when archived, search (case-insensitive), tabs + counts.
- conversion to each destination: text, Markdown code blocks and links preserved; snippet code
  verbatim; bookmark link vs note; location in the response; capture marked processed with a
  reference.
- policy matrix: viewer / outsider / workspace editor without project access → 403, capture
  stays unprocessed; destination of another workspace; missing destination → 422; column of
  another board → 422.
- permission loss between capture and conversion → 403, unprocessed.
- rollback: create fails mid-way (no board columns → `DomainException`) → nothing created,
  capture unprocessed.
- idempotency: second convert returns the same target (`replayed`), one item in the DB.
- sanitization: `<script>` in text is escaped in the issue HTML; capture text stays raw.
- extension: `ext.captures.store` needs the capture scope; a real capture token can't open
  `/captures`; existing extension tests stay green.
E2E (`quick-capture.spec.ts`): capture via button and via **C**; **C** inside a doc editor types
"c" and opens nothing; member can't see the owner's capture (list + direct URL); convert to
issue / task / doc / bookmark / snippet and the item shows up at its link; archive / restore;
mobile button on a phone viewport.

## Docs to update
ARCHITECTURE (Captures module, extension endpoint), KNOWLEDGE (idempotent conversion via row
lock; naming vs notification "Inbox"; shortcut guard), tasks index.

## Open questions
- Bulk organize / multi-select: later.
- MCP tool "capture to inbox": later (would reuse `Capture::rules()` + the store path).
