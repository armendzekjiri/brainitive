# Release v1.41.0: Bring your data (BRAI-65, BRAI-139, BRAI-66, BRAI-81)

## Goal (the user's choices, 2026-10-26)
"byd then" (Bring your data) + "jira import too". Import from Trello, Jira and Notion; export a
project and restore it elsewhere.

Process rule: agents run **focused tests only** (their own and directly affected PHPUnit classes and e2e
specs, plus pint, tsc and build). The lead runs the full gate once, after asking the user.

## Tickets, branches, ownership

| # | Card | Branch | Owns |
|---|---|---|---|
| 1 | BRAI-65 Trello importer | `feat/import-trello` | `app/Support/Imports/Trello/*`, its registry line, `components/imports/sources/TrelloPreview.tsx`, fixtures `tests/Fixtures/imports/trello/*` |
| 2 | BRAI-139 Jira importer | `feat/import-jira` | `app/Support/Imports/Jira/*` (CSV reader, wiki-markup → HTML), its registry line, `components/imports/sources/JiraPreview.tsx`, `tests/Fixtures/imports/jira/*` |
| 3 | BRAI-66 Notion importer | `feat/import-notion` | `app/Support/Imports/Notion/*` (Markdown/CSV → pages), its registry line, `components/imports/sources/NotionPreview.tsx`, `tests/Fixtures/imports/notion/*` |
| 4 | BRAI-81 Project export + restore | `feat/project-archive` | `app/Support/Imports/Archive/*` (export writer + `brainitive` restore importer), its section in `routes/imports.php`, the export UI (project settings), `components/imports/sources/ArchivePreview.tsx` |

Shared files: `ImportRegistry::SOURCES` and `SOURCE_PREVIEWS` get one line per ticket.
`types/index.d.ts` is append-only. `docs/ai/*` is merged by the lead. If an importer needs a shared
helper that doesn't exist (e.g. HTML → editor JSON), check first whether one already exists:
`PageContentConverter`, `SafeHtml`, `ProjectIssues`, `TemplateInstantiator`. Put a new one in
`app/Support/Imports/Shared/` and tell the lead in your report.

## Foundation (on `release/v1.41.0`, build on it, don't redefine it)

- **Tables:** migration `2026_10_30_100000_create_import_runs_tables`
  - `import_runs`: uuid; workspace; project, or null for a restore; user; source; status; private file;
    sha256 fingerprint; the `preview`, `options`, `report` and `result` JSON; progress; error.
  - `import_mappings`: (run, source_type, source_id) → (target_type, target_id), unique.
- **Models:** `ImportRun` (statuses, Prunable after 180 days, route key `uuid`) and `ImportMapping`.
- **`App\Support\Imports\Importer`:** the interface every source implements. Read its docblock:
  - `preview()` is a DRY RUN: it validates everything and writes nothing but temp files under the run's folder.
  - `run()` is resumable.
- **`ImportRegistry`:** `SOURCES` (one line each), `make()`, `catalog()`, and `fake()` for tests.
- **`ImportMappings::once($run, $sourceType, $sourceId, Model::class, fn () => create)`:** the only way to
  create records. A retried or resumed run gets the existing record back. Use `map()` to rewrite links
  after creation (source id → new id).
- **`ImportProgress`:**
  - `total()`, `advance()`;
  - `created('issues')`, `warn('…')`, `unsupported('Custom fields', 3)`;
  - saved to the run's `report` (the UI shows it).
- **`ImportScope`:** `RunImport` runs every importer inside it. While it is active, `Assignments`
  (assignment notifications) and `DomainEvents` (webhooks + automations) do nothing. History entries are
  still written. Don't add per-record notifications in an importer.
- **`ImportFailed`:** a message the user can act on. It fails the run without retrying. Any other exception
  shows a generic "retry to continue" message.
- **`SafeZip::extract($zip, $dir, $limits)`:** the only way to unpack uploads. It rejects traversal,
  absolute paths, symlinks, encryption and bombs, and enforces count and size limits. Notion and restore
  use it.
- **`RunImport` job:**
  - runs as the user who started the import, under a lock, with 3 tries;
  - deletes the upload after success;
  - with `QUEUE_CONNECTION=sync` (dev) it runs after the response.
- **`ImportController` + `routes/imports.php`** (inside `workspaces/{workspace}`):
  - `imports.index`: source cards, project picker, recent runs;
  - `imports.store`: upload → preview;
  - `imports.show`: preview / progress / report;
  - `imports.start`: confirm options; also retry after a failure;
  - `imports.status`: JSON for polling;
  - `imports.destroy`: cancel.
  - A run is visible to its starter and to workspace admins.
- **Frontend:**
  - `Pages/Imports/Index.tsx` and `Show.tsx`, `lib/imports.ts`.
  - The per-source preview and options component is registered in `components/imports/sources.tsx`
    (`SOURCE_PREVIEWS`). It gets `{run, workspaceSlug, busy, onStart(options)}` and must render the
    importer's `preview` and call `onStart` with options matching `optionRules()`.
  - The account menu has an **Import data** entry. Ticket 4 adds the export entry in project settings.
- **Files:** the private `local` disk, under `imports/<run uuid>/`. Uploads are deleted after success and
  after 7 days in any case (`routes/console.php`). Runs are kept 180 days.
- **Tests:** `tests/Feature/Imports/ImportFoundationTest.php` shows the pattern: `Storage::fake('local')`,
  plus a fake importer via `ImportRegistry::fake()`.

## Rules for all tickets
- **Permissions:**
  - Trello and Jira create a **new board** in the chosen project, so they need `createBoards`.
  - Notion creates pages, so it needs `PagePolicy::create` on the project.
  - Restore creates a **new project**, so it needs `ProjectPolicy::create` on the workspace.
  - Export needs `update` on the project (project admins) and includes only what the exporter may see.
- **No merging into existing boards or pages** in v1: always a new board, a new page tree under a
  chosen parent, or a new project.
- **People:**
  - Map source users to **existing project members** by email when the export has it. Otherwise the
    user maps them explicitly in the preview, or they stay unassigned.
  - Never invite anyone and never create users.
  - Keep the original author as text where it matters ("Originally by Jane Doe on 3 Mar 2024").
- **Content safety:**
  - All HTML goes through `SafeHtml`.
  - Markdown and wiki markup are converted, then sanitized.
  - Never fetch external URLs. Links stay links. Attachments come only from inside an uploaded archive.
  - Cap sizes and counts, and fail in `preview()` before any write.
- **Keys:**
  - New issues get new project keys via the normal key path (`getNextItemKey`).
  - The source key or id is kept in `import_mappings` and, for Jira, also shown on the issue (e.g. a
    "Jira: ABC-12" line), never as the Brainitive key.
- **Idempotency:** create everything through `ImportMappings::once()`, and test "crash halfway →
  retry → no duplicates".
- **Accessible names stay stable.** Use `exact: true` for generic button names in e2e.
- **New frontend files:** put them under lowercase `resources/js/components/…` and stage them by
  explicit lowercase path, then check `git ls-files`. In a NEW sub-folder on this Mac, git may record
  `Components/…`; fix with `git rm --cached` + `git update-index --add --cacheinfo` (KNOWLEDGE.md).
- **Fixtures:** put them under `tests/Fixtures/imports/<source>/` (capital F). Make them **synthetic**
  (no real customer data), small, and include Unicode, empty fields and malformed variants.
- **Docs:** each ticket ships PHPUnit + e2e and updates `docs/ai/ARCHITECTURE.md` / `KNOWLEDGE.md`, plus
  a short "Import from X" section in `docs/` for users if the export steps aren't obvious. The lead
  writes the changelog and homepage.

## Ticket specs

### 1. BRAI-65 Trello (board JSON export) — see the card
- **Input:** Trello "Print and export → Export as JSON" (one board). Limits: 25 MB (the preview decodes in the web request), 5 000 cards.
- **Preview:**
  - board name; lists with card counts; labels; checklists; members with email when present; archived
    cards (include toggle, default off);
  - unsupported: comments, custom fields, attachments and power-ups, each with a count;
  - mapping options: a member → project member select (or unassigned), and the new board's name.
- **Run:**
  - creates a new Kanban board in the project: lists → columns in order, the last list or a "Done"-named
    list → the done column (configurable);
  - cards → issues in order, with title, description (Trello Markdown → rich text), due date,
    labels (created or reused in the project, matched by name), assignee (mapped);
  - checklists → a checklist in the description (or subtasks if simple and the user chooses);
  - closed cards → in the done column, or skipped;
  - attachment URLs → links in the description only when the user chooses so; never downloaded.
- **Tests:** fixture round-trip (order, due dates, labels, mapping); malformed or oversized → fails in
  preview; Unicode; resume without duplicates; no notifications.

### 2. BRAI-139 Jira (CSV export, all fields) — see the card
- **Input:** Jira Cloud/Server "Export issues → CSV (all fields)". Duplicate column names repeat for
  multi-values (`Labels`, `Comment`, `Sprint`, `Attachment`), so read them positionally. Limits: about
  50 MB, 10 000 issues. Detect `;` / `,` delimiters and UTF-8 with or without BOM.
- **Preview:**
  - issue count, Jira projects, statuses, issue types, priorities, sprints and people;
  - mappings: status → column (default: one column per status in status-category order, done
    statuses → the done column); issue type → project item type (match by name, else create);
    priority → project priority; person (email or name) → project member or unassigned;
  - toggles: import comments (default on); create sprints (Scrum board when sprints exist);
  - unsupported: custom fields, attachments, watchers, worklogs, components and versions, each with a
    count. Versions → offer releases if trivial, else unsupported.
- **Run:**
  - creates a new board ("Jira import <date>", renamable) and issues with title, description (Jira wiki
    markup and Markdown → HTML → rich text), due date, priority, type, labels, assignee and reporter
    (mapped), story points / original estimate when present;
  - parent / Epic Link / sub-tasks → nested issues (parent_id), the parent created first or linked in a
    second pass via `ImportMappings::map`;
  - comments → issue comments by the importing user, prefixed "Originally by X on date";
  - issue links ("blocks" / "relates") → item links when both ends were imported;
  - the Jira key is kept as a reference line.
- **Tests:** a fixture with an epic, a story, a sub-task, multi-value columns, comments, quotes and
  newlines inside cells, Unicode and a semicolon variant; mapping; resume without duplicates.

### 3. BRAI-66 Notion (Markdown & CSV ZIP export) — see the card
- **Input:** Notion "Export → Markdown & CSV" ZIP, possibly with a nested ZIP. Unpack only via
  `SafeZip`.
- **Notion names:** files are named `Title <32-hex id>.md`, folders likewise. Strip the id for titles
  and keep it as the mapping id.
- **Preview:**
  - the page tree, CSV databases (→ table documents), assets (images and files inside the archive),
    and broken links;
  - options: the parent folder in the target project (or the project root), import as drafts (always,
    see the card), skip empty pages.
- **Run:**
  - creates pages (and folders for pages with children) with the existing page model and editor
    format; Markdown → HTML (sanitized) → editor content;
  - relative links between pages are rewritten to the new page URLs (second pass via `map()`);
  - images inside the archive are stored like editor uploads (same disk and rules) and their links
    rewritten;
  - CSV → a readable table page with a note that databases come over as tables;
  - pages stay drafts / unpublished, visible like other drafts.
- **Tests:** a nested fixture with links, images, a table, code and lists; the ZIP attack cases (via
  `SafeZip`); resume; drafts not visible to others.

### 4. BRAI-81 Project export + restore — see the card
- **Export:**
  - project settings → **Export project** (project admins) queues an export job;
  - it writes a ZIP to the private disk with `manifest.json` (format `brainitive-project`, version 1,
    app version, created_at, checksums sha256 per file, counts, and what is omitted and why) plus one
    JSON per module:
    - boards + columns + issues (incl. subtasks, labels, types, custom fields, comments with author
      name/email as metadata, links within the project);
    - pages / folders / whiteboards (published content; drafts only when the exporter may see them);
    - task lists, plans, helpdesk structure (no tickets / contacts: personal data, listed as omitted);
    - attachments as files;
  - never exported: tokens, secrets, share links, integrations, memberships;
  - a private, expiring download link (24 h) in the UI and in an in-app notification (`export_ready`,
    category `workspace`, via CatalogNotification);
  - the export link and routes go in the ticket 4 section of `routes/imports.php`.
- **Restore** (`brainitive` importer, `needsProject() === false`):
  - upload the ZIP (via `SafeZip`); verify the manifest version and every checksum before any write;
  - preview: counts, people found (email → workspace member or unassigned), name / key for the new
    project (key must be free);
  - run: creates a new project with fresh ids, keys and slugs, rewrites internal references (links,
    parents, page tree, plan rows linked to issues) via `ImportMappings::map`, and restores attachments.
- **Tests:** a round trip (export → restore → same structure and content, new ids); a tampered checksum
  → refused; an unsupported version → refused; traversal in the archive; permissions; resume without
  duplicates.
