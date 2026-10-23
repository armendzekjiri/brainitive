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

### Shared Inertia props must work without a session
A 405 (wrong HTTP method) is rendered before any route middleware, so there is no session.
`HandleInertiaRequests` flash props read `$request->hasSession()` first — otherwise the error
page itself crashed with a 500 (fixed with BRAI-76, test in ErrorPagesTest).

### GitHub webhooks are stateless API routes
`/api/webhooks/github/{uuid}` lives in `routes/api.php` (no session/CSRF); the per-project
secret (encrypted column) is the only auth, checked with `hash_equals` over the raw body.
Deployments are matched by window: what reached the deployed ref after the previous successful
deploy to that environment (`github_connections.deployments`). Keep processing idempotent
(upserts keyed by item+kind+repo+identifier) — GitHub redelivers.

### Saved board views store the URL params
A saved view is the board's own query string, not a second filter language: adding a board filter
means adding its param to `BoardViewConfig::normalize()` (allowlist) and, if it references ids,
to `sanitize()` — and to `boardFilterParams()` / `currentViewParams` in Boards/Show. `assignee=me`
stays literal so it resolves for each viewer. A shared view never grants board access (every
policy check starts with BoardPolicy::view).

### Explicit route binders run before implicit ones
In `Route::bind('page' | 'bookmark', …)` the `{workspace}` / `{project}` parameters are still raw
slugs — an `instanceof Project` check there is always false. `AppServiceProvider::routeProject()` /
`routeWorkspace()` resolve them from the slugs so a page / bookmark only binds inside the URL's own
project / workspace (before 2026-10-23 any page uuid bound under any project URL → cross-tenant reads).

### Workspace editors need project access to write
Policies give workspace *editors* edit rights only in projects they can open
(`hasProjectAccess`) — workspace *admins* everywhere. A workspace editor outside a project must
not create items, tasks, plans, releases or tickets there (CLI and extension included).
User ids from requests (assignees, watchers) use `App\Rules\ProjectMember`, never just
`exists:users,id` (that lets anyone look up any account). Notification links are app paths only
(`NotificationController::appPath`, `safeAppHref` in `NotificationMessage.tsx`, `public/sw.js`).
`Workspace::$hidden = ['settings']` (AI endpoint + encrypted key) — read AI config server-side.

### Uploads keep only allowlisted extensions
`Uploads::extension()` keeps a client extension only if it is in `Uploads::SAFE` (documents,
archives, media); everything else is stored as `.bin`. It used to be a denylist, and `.xhtm`
(served as application/xhtml+xml → script on our origin) slipped through. Exports of page HTML
(PDF / HTML / Markdown) always go through `safeHtml()` — stored page HTML is not trusted.
Visitor text in ticket mails goes through `NewLeadNotification::plain()` (Markdown renders links).
Uploads anyone can make without an account (public ticket form / portal replies) also refuse
programs and scripts outright: `App\Rules\NotExecutableUpload` → `Uploads::isExecutable()` (every
extension in the name + the content-detected MIME type); the browser forms pre-check with
`resources/js/lib/blockedUploads.ts` (same list — change both together). Logged-in uploads keep
the allowlist renaming only.

### Accounts, invitations and connected apps
- An email is "proven" only by `email_verified_at` (raw column — `hasVerifiedEmail()` is always
  true when the instance doesn't require verification), a provider-verified social email, or the
  invitation link. Sign-up no longer joins invited workspaces; the `Verified` listener in
  `AppServiceProvider` (or `/invite/{token}`) does — guests opening an invite are sent back to it
  after sign-up / login (`url.intended`). `acceptPendingForUser` skips existing memberships. A social login that matches a never-verified
  account resets it (`SignOutEverywhere`) before linking; Google needs `email_verified`.
- `App\Http\Middleware\GuardOAuthFlows` runs globally (for `/oauth/register`, which has no group)
  AND in the web group — the `/oauth/authorize` verification check needs the session, and global
  middleware runs before StartSession (`$request->user()` is null there). `POST /oauth/register` is throttled and https
  redirect hosts must be in `mcp.allowed_redirect_hosts` (+ `MCP_REDIRECT_HOSTS`) or loopback —
  the package's own `redirect_domains` stays `*` because it can't do wildcard hosts (extension
  ids). Unverified users can't reach `/oauth/authorize`. The consent page shows the redirect host.
- Password change / reset → `SignOutEverywhere` (sessions, remember token, all OAuth + CLI tokens).
  Users revoke apps under Profile → Connected apps.
- Web push endpoints must be a known push service (`PushSubscriptionController::PUSH_HOSTS`).
- `FrameProtection` (web): pages without their own CSP get `frame-ancestors 'self'`; embeds keep
  `frame-ancestors *`.

### Auth links and login limits (security hardening, 2026-10-23)
- Email verification links are `signed:relative` temporary signed URLs (built in
  `VerifyEmailNotification`, host from `APP_URL`, signature over path + query only, so proxies /
  another host don't break them). The route never signs anyone in — don't add `Auth::login()` back.
- Reset links come from `APP_URL` (`ResetPassword::createUrlUsing` in `AppServiceProvider`), never
  from the request host.
- `App\Support\AccountLoginThrottle`: 20 failed password logins per account / 15 min, any IP —
  web login and `/api/cli/login`; a password reset clears it. Trade-off: anyone who knows an email
  can keep its password login locked (reset / social login still work). Real fix for IP spoofing is
  a correct `TRUSTED_PROXIES` (never `*` when the app port is reachable directly).

### Lead form logic lives twice
Which steps / questions a visitor sees is evaluated in the browser (`lib/leadForms.ts`) *and*
on submit (`LeadFormDefinition::visibleFields`) — hidden questions are never validated and
their answers are dropped. Keep the operators identical (a condition on a hidden question
counts as unanswered). Lead answers are keyed by field id; `leads.meta.labels` keeps the
question labels at submit time so answers to deleted questions stay readable.
`/f/*` is CSRF-exempt (stateless submit + events); deleting a form clears `public_slug`
and renames `slug`, because both unique indexes include soft-deleted rows.

### Production deploys never run seeders
`deploy.yml` runs `migrate --force` only; `docker/entrypoint.sh` seeds item types and
priorities, the git-deployed server didn't. Reference rows the app needs (types, priorities)
must arrive through a migration that calls the idempotent seeder (see
`2026_10_23_150000_ensure_default_item_priorities.php`, BRAI-85) — otherwise pickers are
empty in production while tests and Docker look fine.

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
- **Docker image: never print PHP errors into responses.** With `display_errors` on, a
  warning before the headers turns every 404/500 into a 200. `docker/php.ini` sets
  `display_errors = Off` and logs to stderr.
- **There is no automatic first admin.** Instance admins are made with
  `php artisan make:instance-admin <email>` (also in the self-hosting guide).
- **Cloud is free during early access; the prices are planned, not live.** There is no
  billing and no plan-limit enforcement in the code, and the homepage, Terms and Refund
  policy all say so. Don't add copy that implies payment is taken until billing ships. Building billing needs a new dependency (e.g. Cashier) and must
  stay off for self-hosted instances.

- **~66 feature tests fail with `RouteNotFoundException`?** A stale local route cache
  (`bootstrap/cache/routes-v7.php`, git-ignored) hides routes added since it was built. Run
  `php artisan route:clear` before `php artisan test`. Same for "Class … not found" at boot:
  `vendor/` is behind `composer.lock` — run `composer install`.
- **npm `overrides` patch Excalidraw's pinned dependencies.** `@excalidraw/excalidraw`
  0.18 pins exact old `nanoid`, `sass` and (via mermaid/chevrotain) `lodash-es` versions
  with known advisories. Root `package.json` **and** `excalidraw-build/package.json` force
  patched versions (`nanoid` 3.x → ≥3.3.20, 4.x → 5.x — only the named `nanoid()` export is
  used). `katex` is forced to the root's direct `^0.18` (`"$katex"`; mermaid still asks for
  0.16) and `postcss-selector-parser` to 7.x under Tailwind 3 (built CSS is byte-identical).
  Drop the overrides once upstream ships patched pins. Changing `excalidraw-build` deps
  means rebuilding and committing `public/js/excalidraw-dist/`.
- **`braces` (GHSA-vfj7-8cjw-p6xm) has no fixed release.** Every version is flagged; it only
  comes in through Tailwind 3's build-time globbing (chokidar / micromatch / fast-glob) over
  our own `tailwind.config.js` patterns, so it isn't reachable from user input. It goes away
  with the Tailwind 4 migration (Tailwind 4 doesn't use it).
- **Tiptap is not in the shipped app.** `components/editor/PageEditor.tsx` (+
  `SlashCommands`, `TableOfContents`, `extensions/Callout`) is the only Tiptap consumer and
  nothing imports it — pages use Lexical. The `@tiptap/*` packages are removal candidates.
- **`excalidraw-build/node_modules` used to be committed** (`.gitignore` had `/node_modules`,
  root only). Now `node_modules/` is ignored everywhere; run `npm install` in
  `excalidraw-build/` before rebuilding the whiteboard bundle.
- **Instance admins don't see content (since v1.15.0).** The old `Gate::before()` bypass and
  every `isInstanceAdmin()` branch in content queries were removed on purpose. Admin tooling
  goes in `/admin` and shows metadata only.
- **Deleting a user cascades far.** `created_by` on projects, boards, items, task lists,
  task list items, helpdesks, snippet collections and snippets is `cascadeOnDelete`, and so
  is `workspaces.owner_id`. A plain `$user->delete()` wipes content in *other* people's
  workspaces. Use `App\Support\Admin\UserDeletion` (reassigns to the workspace owner, keeps
  other people's comment replies). The self-service delete in `ProfileController` still
  calls `$user->delete()` directly.
- **Workspaces / projects / boards / items are soft-deleted.** Count them through Eloquent
  (`Workspace::count()`, relations) — `DB::table(...)->count()` includes trashed rows.
- **Admin SMTP is applied lazily, never at boot.** `config:cache` boots the app, so applying
  it in `boot()` would write the SMTP password into `bootstrap/cache/config.php`.
  `InstanceMailConfig::apply()` runs on `mail.manager` resolve and before each queued job.
- **`InstanceSettings::current()` is memoised per request** and flushed in
  `AppServiceProvider::boot()`, on save and per queued job — call `flushCache()` in tests
  after changing settings out of band.
- **Don't name page props after shared Inertia props.** A page prop called `workspaces`
  replaces the shared sidebar `workspaces` array and crashes `AppLayout` (`t.map is not a
  function`). The admin workspace list is `managedWorkspaces` for that reason.
- **`require_email_verification` defaults to true.** It was seeded `false` but never
  enforced; a migration sets existing rows to `true` and the seeder now writes `true`, so
  verification keeps being required until an admin turns it off.
- **Browser-extension tokens are `capture`-scoped and kept off read APIs.** Every Passport
  token passes `auth:api`, so scopes are the only thing separating the extension from MCP.
  `RejectCaptureTokens` (on `/mcp` and `/api/cli`) rejects tokens that can `capture` but not
  `mcp:use`. Old MCP tokens without scopes still work there (an empty scope list can't
  `capture`). Add the middleware to any new endpoint that returns content.
- **The `bookmark` route parameter has an explicit slug binding** (`AppServiceProvider`), which
  overrides `{bookmark:id}`. The extension route uses `{bookmarkGroup:id}` for that reason.
- **`ProjectIssues::create()` loads a slim `board` relation** (no `project_id`).
  `ResourceLocator::locate()` then falls back to `url('/')`: set the full board first
  (`$item->setRelation('board', $board)`).
- **Extension OAuth redirect URIs are per browser and per install.** Chrome/Edge use
  `https://<extension-id>.chromiumapp.org/`, Firefox `https://<uuid>.extensions.allizom.org/`.
  The extension registers one Passport client per server and re-registers when the redirect
  changes, so `oauth_clients` gains a "Brainitive for Chrome/Edge/Firefox" row per install.
  This is expected.
- **Testing the extension in Chrome 137+:** branded Chrome ignores `--load-extension`. Load the
  unpacked build over the DevTools pipe (`--remote-debugging-pipe
  --enable-unsafe-extension-debugging`, CDP `Extensions.loadUnpacked`), then connect Playwright
  over `--remote-debugging-port`. Automation gets no `activeTab` grant, so add `<all_urls>` to
  a *test copy* of the manifest for screenshots. Don't add it to the shipped manifest.
- **Passport keys in tests:** `storage/*.key` is gitignored, so token-guard tests generate a
  throwaway key pair into `passport.private_key` / `public_key` (see `ExtensionApiTest`).
- **Uploads: never store the client's file name or extension.** The public disk is served
  from `/storage`. FrankenPHP (and a typical nginx `location ~ \.php$`) *executes*
  `*.php` there, and browsers render HTML/SVG on our origin. Use `App\Support\Uploads`:
  `name()` for attachments (harmless extensions kept, active ones become `.bin`),
  `imageName()` / `imageExtension()` for images (extension from the content). Keep the
  client name only as display text (`Uploads::displayName()`). `docker/Caddyfile` adds a
  404 for PHP under `/storage` plus a sandbox CSP for markup as a second layer;
  `php artisan uploads:audit` finds old risky files.
- **Rich HTML from other people is sanitized twice.** Ticket descriptions and replies go
  through `App\Support\SafeHtml` (symfony/html-sanitizer) in the model accessors (on write
  *and* read, so old rows are covered). Every React `dangerouslySetInnerHTML` uses
  `safeHtmlProp()` from `resources/js/lib/sanitize.ts` (DOMPurify). To get plain text from
  HTML, use `DOMParser`, never `div.innerHTML` (that fires `<img onerror>`).
- **Public ticket links use per-ticket tokens.** `Ticket::publicAccessToken()` is an HMAC of
  the ticket UUID with `APP_KEY`: it opens one ticket and never rotates. Contacts are
  global (matched by email across workspaces), so anything contact-wide is open to anyone
  who types that email into a public form. Rotating `APP_KEY` invalidates every ticket link.
- **Server-side requests to user URLs go through `App\Support\OutboundUrl::options()`**:
  http(s) only, public IPs only (`FILTER_FLAG_GLOBAL_RANGE`), no redirects (follow by hand and
  re-check each hop), and the connection is pinned to the checked IP (`CURLOPT_RESOLVE`).
- **Foreign ids in requests must be scoped to the parent.** `exists:board_columns,id` accepts
  any workspace's column; use `Rule::exists(...)->where('board_id', …)`. For ids that only
  get *displayed* (issue links), check the user can `view` the target.
- **`scopeBindings()` guesses the child relation from the parameter name.**
  `{item}/links/{link}` called `Item::links()`, which doesn't exist, so unlinking issues
  returned 500. Use `->withoutScopedBindings()` plus an ownership check in the controller, or
  add the relation. The CLI API group is scoped (`{taskList}/items/{item}`).
- **Default (global) item types are shared by every workspace.** Projects switch them off
  in `projects.settings.disabled_item_types` (`Project::disabledItemTypeIds()`, honoured by
  `ItemType::forProject`). Projects never edit the global row.
- **Feature tests don't catch a missing Inertia page.** `withoutVite()` skips the manifest,
  so `Inertia::render('Tickets/Show')` passed tests while production returned 500 (the file
  never existed). For a new render, assert `assertInertia(fn ($p) => $p->component(...))` and
  that the file exists. Ticket links (`tickets.show`) render `Helpdesks/Show` with
  `openTicketId`; there is no separate ticket page.
- **SQLite in tests is lax about NOT NULL in some paths; production is PostgreSQL.** To test a
  DB-specific failure, run the tests against a throwaway Postgres:
  `docker run -d -p 55432:5432 -e POSTGRES_PASSWORD=test -e POSTGRES_DB=brainitive_test postgres:17-alpine`,
  then `DB_CONNECTION=pgsql DB_PORT=55432 DB_DATABASE=brainitive_test DB_USERNAME=postgres DB_PASSWORD=test php artisan test`.
- **Notification payloads are rendered in one place.** `components/NotificationMessage.tsx`
  maps every `data.type` to a sentence. The bell and the page used to fall back to
  "{actor} moved {item_key}", so ticket notifications (`ticket_*`, with no `item_*` keys)
  showed as "moved". Add a case for each new type.
- **Project data hangs off `project_id`; only `projects` stores `workspace_id`.** Moving a
  project is a single update (see `ProjectTransfer`). Keep it that way: a new project-level
  table must not copy `workspace_id`, or moves (and access checks) drift. Workspace-level
  things (bookmarks, snippets, invitations) are not project data and stay behind.
- **`projects` unique keys `(workspace_id, slug)` and `(workspace_id, key)` include
  soft-deleted rows.** Check with `withTrashed()` before reusing a slug or key in a workspace.
- **Error pages are Inertia (`Pages/Error.tsx`), wired in `bootstrap/app.php`
  (`withExceptions` → `respond`).** JSON requests keep JSON; 5xx keep Laravel's debug page
  while `APP_DEBUG=true`; 419 redirects (to login for guests, back for users).
  Two traps: unknown URLs never enter the `web` group (no session), hence
  `Route::fallback(fn () => abort(404))` at the end of `routes/web.php`. And route-model
  binding throws before `HandleInertiaRequests` shares props, so the responder shares them
  itself.
- **Import paths must match git's case, not the Mac's** (guarded by
  `tests/Unit/ImportPathCaseTest.php`, which reads `git ls-files`, so run the tests after
  `git add`). `resources/js/components/` and
  `Components/` are two folders on Linux (one on macOS). Check with
  `git ls-files | grep -i <name>` before importing, e.g. `@/Components/ApplicationLogo`, but
  `@/components/ui/button`.
- **Poll votes live in `poll_votes`, not in the document.** `PollNode` keeps only the
  question, options and a `pollId`. Votes are stored per `(scope_type, scope_id, poll_id,
  user)`, with scope `page` (uuid) or `item` (id), via `PollVoteController` (`polls.*`).
  Anyone who can `view` the doc or issue can vote. The editor host passes
  `pollScope` to `RichLexicalEditor`; without one (comments, tickets) the poll shows "voting
  works in docs and issue descriptions". Old polls without `pollId` derive it from their
  option uids. The old in-document `votes` arrays are ignored and cleared on the next edit.
- **Item soft deletes don't cascade.** The `parent_id` FK cascades only on hard deletes, so
  `destroyItem` soft-deletes `descendantIds()` explicitly. Before that, a deleted issue's
  subtasks stayed behind, although the confirm dialog said they'd be deleted.
- **Focus timers can't double-log.** `work_timers.user_id` is unique (two tabs can't start two
  timers), and stopping deletes the timer row and calls `Item::logWork()` in one transaction
  — a retried stop finds no timer and logs nothing. Stop / discard carry the `timer_id` the
  user reviewed, so a stale tab can't log onto (or delete) a timer started later elsewhere. Only board items have a work log
  (`items.time_spent`); list tasks can be on My Day but can't be timed.
- **The app runs in UTC; task dates and times are the user's wall clock.** `due_date` /
  `due_time` / `start_*` on items and list tasks have no zone. Anything that turns them into a
  moment (reminders, "today", the morning digest) must use the recipient's
  `User::localTimezone()` (browser zone, synced on page load) — the old start reminders
  read them as UTC and fired 1–2 h late in Europe. List-task dates serialize as `Y-m-d`
  (`date:Y-m-d` cast); the old ISO output left `<input type="date">` empty. Browsers still
  report old zone names (`Asia/Calcutta`, `Europe/Kiev`): validate with `timezone:all_with_bc`.
  "1 day before" is `subDay()` (same clock time), not `subMinutes(1440)`, which is an hour off
  on DST weekends. `due_time` comes back as `HH:MM:SS` on Postgres; send `HH:MM` back.
- **An empty JSON object comes back as `[]` from an `array` cast.** `(object) []` is stored
  as `{}`, but the cast decodes with `json_decode(…, true)`, so React gets `[]`. Client code
  that compares or spreads such values (`rules: {}` vs `rules: []`) must accept both.
- **Public helpdesk submissions are validated on the server** (`HelpdeskForm::publicRules()`, since BRAI-92): required name / message per settings, required custom fields, numbers, dates and choices from the field's options. Before, only the browser checked them. Multi-choice answers arrive as a JSON string and are decoded first (`HelpdeskForm::prepare`).
- **A hidden subject is filled by the server** as "<auto_subject or helpdesk name> — <name or email>"; forms like registrations don't show one.
- **SQLite kept the old enum CHECK on `helpdesk_fields.type`** until 2026_10_24_130000 (radio / date / checkboxes failed to save on SQLite installs); it's a plain string there now.
- **The embed form used to send the first radio's value and an unticked checkbox's value**; it now reads only `:checked` inputs.
- **Project templates copy structure, never people or history.** Captured: labels, project
  item types (Lucide icon names only), custom fields, boards + columns + swimlanes, and by
  choice issues (no assignee / reporter), published docs (live content, never drafts —
  docs under an unpublished parent move up), task lists (reset to "todo", no assignee) and
  plans (no "Who", no share token). Mentions become text, whiteboard issue cards lose their
  issue link, inline-diagram embeds are dropped, archived custom-field options aren't kept.
  Sprints, releases, helpdesks and integrations never travel. Day 0 = the earliest captured
  date (issue start / due, custom date fields, tasks, plan bars), or today when there is none.
- **Saving a template shares its content with every project creator of the workspace**, also
  people who aren't members of the source project (only published docs, issues, lists and
  plans that were included). That's why only workspace admins save templates, and the save
  dialog says so.
- **On PostgreSQL, two tests fail on `main` too** (`CommandPaletteTest` search ranking,
  `GithubDevelopmentTest` signatures) and `ItemCommentToolsTest` pagination is flaky in the
  full run; SQLite (the default test DB) is green.
- **Issue keys start at KEY-2 in blank projects.** `projects.next_item_number` stores the
  last number used and `getNextItemKey()` increments first, but the column defaults to 1.
  Template-created projects set it to 0 (first issue KEY-1). Fixing the blank-project path
  needs a default of 0 (existing projects must keep their counter).
- **The `projects/{project:slug}` route group isn't scope-bound** (labels, item types,
  fields, templates): a project of another workspace binds by slug. Controllers there must
  check `$project->workspace_id === $workspace->id` when the workspace matters (templates do).
- **Custom field values are typed rows, not strings** (unlike helpdesk `ticket_field_values`).
  `item_field_values` has one column per type; "empty" means *no row*, so a budget of 0 or an
  unticked "Needs QA" stays distinct from "not filled in". Changing a field's type is refused
  while values exist (422) — the documented migration path is "add a new field, archive the
  old one"; there is no automatic conversion. Values of archived fields, of fields limited to
  other boards and of archived options are kept and come back on restore / when the issue moves
  back. Moving an issue to *another project* deletes its old project's values (logged in the
  history, `ItemFieldValues::dropForeign`). Values on soft-deleted issues still count as "in
  use", so the field can't be deleted while trashed issues might come back. Limits: 50 active
  fields per project, 100 options per field, text 1000 chars, numbers below 10^14
  (`decimal(20,6)`, but read back as a PHP float: ~15 significant digits survive).
- **New components land in `Components/` on disk.** macOS shows `resources/js/components` as
  `Components`, so `git add` of a *new* file there records the capital-C path, which breaks
  the lowercase import on Linux. After adding a component, check `git ls-files | grep -i <name>`
  and re-stage with `git update-index --add --cacheinfo` under `resources/js/components/…`.
- **Share links expose only a whitelist.** `ShareLinkController::show` builds the payload
  field by field (title, content / description, status, type, priority, dates) and never
  serializes the model, so new columns can't leak into public pages. Docs share the live
  `content`, never `draft_*`; whiteboards aren't shareable yet (they need the Excalidraw viewer).
