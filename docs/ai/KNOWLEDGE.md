# Shared Knowledge — Decisions & Gotchas

The team's shared memory. Anything non-obvious that would otherwise be re-discovered the
hard way belongs here: environment traps, architectural decisions and their *why*, and
known issues. All agents read this before starting work.

**Adding an entry:** append under the right section with a date. Keep each entry short —
what it is, and what to do about it. Promote recurring plan-file gotchas up here.

---

## Environment & setup gotchas

### Brainitive project keys are Brainitive cards
`BRAI-*` keys in this project (for example `BRAI-41`) identify cards on the Brainitive
project board, not Jira issues. When a user references one, start with the Brainitive MCP
board tools. Only check Jira/Atlassian if the user explicitly asks for that external
tracker.

### SQLite-incompatible migrations
- `2026_02_03_145210_add_member_role_to_workspace_user_table` uses
  `ALTER TABLE … DROP CONSTRAINT`, which SQLite doesn't support. Breaks `php artisan
  migrate` on a fresh DB **and** all `RefreshDatabase` feature tests (which use `:memory:`).
  **Workaround:** recreate the `workspace_user` table with `'member'` in the CHECK
  constraint, then fake the migration.
- `2026_02_23_153119_extend_bookmarks_icon_for_url` uses MySQL `MODIFY` syntax with no
  SQLite branch. SQLite ignores varchar length, so this can be safely faked locally.

### Web push silently never delivers without VAPID keys
`config/webpush.php` reads `VAPID_SUBJECT` / `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` from the
env. If they're unset (the default `.env` ships them blank), the Inertia `push.vapidPublicKey`
prop is empty so **no browser can ever subscribe**, and the server can't sign payloads — so
*all* browser notifications silently fail with no error. Generate keys once with
`php artisan webpush:vapid` (writes the two keys to `.env`) and set `VAPID_SUBJECT` (a
`mailto:` or URL) manually — the command does **not** write the subject. Then `php artisan
config:clear`. The in-app bell (database channel) is unaffected by VAPID.

### Email verification blocks login in dev
Registration requires email verification. With the default `MAIL_MAILER=log`, the
verification email is written to `storage/logs/`. To bypass during development:
```bash
php artisan tinker --execute="\App\Models\User::where('email','...')->update(['email_verified_at'=>now()]);"
```

---

## Architectural decisions

### Inline validation over FormRequest classes
Controllers use inline `$request->validate([...])` rather than dedicated FormRequest
classes (only `ProfileUpdateRequest` exists). **Why:** keeps validation co-located with the
single action that uses it; the app has no shared/reused validation rule sets that would
justify the extra classes.

### `$fillable` everywhere, never `$guarded`
Every model declares an explicit `$fillable`. **Why:** explicit allow-lists are safer than
`$guarded = []` and make the writable surface of each model obvious at a glance.

### slug vs uuid route binding
Workspace/Project/Board bind by `slug` (human-friendly, shareable URLs); Page/Bookmark bind
by `uuid` (titles change often and aren't unique enough for stable URLs).

### MCP server & auth — Passport, not Sanctum
A remote MCP server (`app/Mcp/Servers/BrainitiveServer`, route `/mcp` in `routes/ai.php`)
exposes Brainitive to ChatGPT/Claude connectors over OAuth 2.1. Token auth migrated from
**Sanctum to Passport** — Sanctum is still in `composer.json` but Passport issues the bearer
tokens for both MCP and the CLI API (`routes/api.php`). Token TTLs are set in
`AppServiceProvider::boot()`. (See git history under `feat/mcp-server`.)

### Policies are explicitly registered (not auto-discovered)
`AppServiceProvider::boot()` calls `Gate::policy(Model::class, Policy::class)` for each
policy. A new policy that you forget to register there **silently does nothing**. Note
`SnippetPolicy` guards `SnippetCollection`.

### MCP tool visibility — pagination
`BrainitiveServer` sets `defaultPaginationLength`/`maxPaginationLength` to 200 on purpose:
some clients (ChatGPT connectors) ignore `nextCursor`, so a tool on page 2 would look
"missing." Keep these above the tool count when adding tools.

---

## Known issues / watch-outs

_(Add as discovered — e.g. flaky tests, N+1 hotspots, third-party quirks.)_

- Lexical/Tiptap/Editor.js all store content as JSON in a `content` column. When adding a
  feature that reads page content, check which editor produced it before parsing.
- **Scrum boards hide everything outside the active sprint.** If a Scrum board "looks
  empty", there's no running sprint — issues live in the backlog. Kanban boards ignore
  `sprint_id` completely.
- **Completed sprints keep their own burndown** (`sprints.burndown`), because open issues
  move to the next sprint on completion and a live recomputation would change history.
- **`activity_logs` spans all workspaces.** Anything listing a user's activity must scope it
  (e.g. `memberProfile` limits it to items of the current workspace's projects).
- **Board payload is slim on purpose.** Cards don't carry `comments` / `attachments` any
  more — read `comments_count` / `attachments_count`, and fetch details from
  `boards.items.details`. The card badge used `comments_count` before it was ever loaded,
  so it never showed.
- **Eloquent relations serialize in snake_case.** `$page->load('lastEditor')` reaches React
  as `page.last_editor`, not `page.lastEditor` (the old page header read `lastEditor` and
  never showed a name). Same for `draftEditor` → `draft_editor`.
- **Published page = two states.** `content`/`name`/`icon` are what readers see;
  `draft_*` is the shared unpublished draft. `updated_at` moves on every autosave, so for
  "last changed" on a published page use the newest `page_versions.created_at`
  (`last_published_at` prop), not `updated_at`.
- **Whiteboard (Excalidraw) pages bypass the draft.** `saveExcalidraw` writes the live
  `content` directly (via `Page::saveWhiteboard`), guarded by `edit_revision`; only document
  pages have draft/publish separation. Whiteboard versions store the full scene incl.
  embedded image `files` (data URLs) — big boards make big `page_versions` rows.
- **Issue cards keep their element id when redrawn.** `replaceWithCards` in
  `excalidraw-build/src/main.jsx` swaps the card with `regenerateIds: false` and copies the
  arrow bindings, so connectors stay attached. A new card id would orphan the arrows.
- **Tests that send real notifications fail without GMP/BCMath** — `minishlink/web-push`
  raises an `ErrorException` warning. Use `Notification::fake()` in feature tests.
- **Repo-wide `pint --test` is red on untouched legacy files** (old migrations,
  notifications, BoardController…). Gate your change with `pint --dirty`; clean up legacy
  files in a dedicated chore branch.
- **Plan chart layout = one scroll container.** The grid is `position: sticky; left: 0` inside
  the same scroller as the timeline, so both scroll together. Anything drawn on the grid
  must be **opaque** (row tints are painted as `background-image` over `bg-background`),
  otherwise bars scrolled under the grid shine through.
- **Plan chart window listeners** are stable wrappers that call the latest handlers through a
  ref; adding/removing a per-render function leaks listeners after the gesture.
- **Playwright `page.mouse.click` ignores `modifiers`** — hold Shift with `keyboard.down` when
  testing multi-select in the harness.
- **`npm run build:widget` used to copy `public/` into `public/js/`** (no `publicDir: false`), which is why favicons, `.htaccess`, `hot` and logos are tracked under `public/js/`. Fixed in `vite.widget.config.js`; the old copies are left in place until someone checks the server doesn't rely on them.
