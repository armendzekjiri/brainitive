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

### E2E suite (Playwright, BRAI-96): how it works and the traps it found
Details in `e2e/README.md`; this lists what isn't obvious.

- **Every run is isolated.** Each local run starts its own `php artisan serve --no-reload` on
  `E2E_PORT` with its own Postgres database, `brainitive_e2e_<port>`. `--no-reload` makes it pass
  the e2e environment through instead of re-reading `.env`. `e2e:reset` refuses anything but
  `APP_ENV=e2e` plus an e2e database.
- **One session per worker** (the worker fixtures in `fixtures.ts`). Laravel's flash data
  (validation errors, toasts) belongs to a session, and workers sharing one session consumed each
  other's flash.
- **A new `request.newContext()` inside the runner inherits the project's `storageState`.** Pass
  an empty one explicitly, or a "fresh" login is really the owner's existing session (`/login` then
  just redirects).
- **Cache `/build/assets` in memory** (`assets.ts`). `php artisan serve` closes every connection,
  an app page loads about 200 chunks, and parallel runs ran out of loopback ports
  (`ERR_ADDRESS_INVALID`, blank pages).
- **Mail.** e2e mail goes to `storage/e2e/mail-<port>.log` (the `mail` log channel), and
  `mail.ts` follows invite and verification links. Laravel's log transport has already decoded
  quoted-printable bodies, so don't decode again (it corrupts `=17…` in links).
- **Every button.** The crawler (`crawler.ts`, project `crawl`) clicks every control on every
  page it can reach, plus their menus and dialogs, and fills and submits form dialogs. Destructive
  requests are answered with a reload (`409` + `X-Inertia-Location`). `networkidle` returns at
  once on a loaded page, so the crawler counts in-flight requests itself.

App bugs it found (all fixed, each with a test). Patterns to keep:

- **Plain `throttle:N,M` shares one counter per IP or user across every route.** Always add a
  prefix: `throttle:10,1,<route name>`. `RouteThrottleTest` fails on an unprefixed one.
- **Laravel writes the whole session at the end of every request; the last write wins.** A
  background fetch that started before a form submit wiped the submit's validation errors.
  Read-only fetches fired on page load or polled get `ReadOnlySession`:
  `page-sticky-notes.index`, `notifications.index`, and `notifications.unread-count` (every 30 s).
  Every new background GET needs it too.
- **Inertia sends `X-Requested-With` too.** "JSON for XHR" endpoints must exclude
  `X-Inertia`, or Inertia forms get JSON (`storeLabel`, `storeItemType`).
- **Key counters:** `Project::bumpCounter()` increments under a row lock. Reading the loaded
  model and incrementing later handed parallel requests the same key. Ticket keys are unique per
  helpdesk, not per instance (project keys repeat across workspaces).
- **The Lexical mention picker only runs with `projectId`.** The issue comment editor missed it,
  so comments couldn't @mention anyone.

### dev.brainitive.app: staging on the production server, isolated
`pre-release` deploys to `/var/www/brainitive-dev` on the same server as production (BRAI-95).
- **Isolation:**
  - **PHP:** its own pool `brainitive-dev` (user `brainitive-dev`, socket
    `php8.4-fpm-brainitive-dev.sock`, `open_basedir` = the dev folder), never the shared
    `www-data` pool.
  - **Files:** `.env` is 640 `ubuntu:brainitive-dev`; `storage` and `bootstrap/cache` are closed
    to others (ACLs for ubuntu + brainitive-dev), except `storage/app/public`.
  - **Database:** Postgres role and DB `brainitive_dev`, with no privileges on production tables.
- **Settings:** `APP_ENV=staging`, `MAIL_MAILER=log`, `QUEUE_CONNECTION=sync`, no scheduler, no
  queue worker.
- **Access:** behind basic auth (`/etc/nginx/brainitive-dev.htpasswd`; the credentials are in
  `~/.brainitive-dev-basic-auth` on the maintainer's Mac) and noindex.
- **Deploy guard:** the deploy refuses to run unless the folder, `APP_ENV=staging` and
  `DB_DATABASE=brainitive_dev` all match. Keep that guard on anything destructive.
- **E2E on dev:** after every deploy, all journeys run against dev.brainitive.app. Before that,
  `e2e:prune` deletes all `@example.test` users and their data (database cascades), and
  `E2eSeeder` reseeds them. Never invite a test user into a real workspace: prune refuses then,
  to protect shared work.
- **Production `.env`** was world-readable (664; every site's PHP runs as `www-data`). It has
  been 640 since 2026-10-23 (`www-data` reads it through `webdev`). An own FPM pool for production
  would isolate it fully.

### Git worktrees with a symlinked `vendor/` run the MAIN checkout's code (2026-10-24)
PHP resolves `__DIR__` through symlinks, so a worktree whose `vendor` links to the main checkout
autoloads `App\`, `Database\` and `Tests\` classes **from the main checkout**, and PHPUnit even
boots the main checkout's app (`Application::inferBasePath()` = dirname of vendor's real path):
its routes, migrations and code. New classes are "not found", changed ones silently run the old
version — tests can pass without testing your change. **Give every worktree a real copy:**
`cp -R <main>/vendor vendor` (then `composer dump-autoload -o` there); `node_modules` may stay a
symlink. Check with `(new ReflectionClass(SomeController::class))->getFileName()`: it must point
into the worktree. (BRAI-70 worked around it with an `auto_prepend_file` that sets
`APP_BASE_PATH`; the copy is simpler.)

**Per-worktree test DB: create it owned by the app's DB user.** `createdb -h /tmp
brainitive_test_<name>` makes *your OS user* the owner; on Postgres 15+ the app's `DB_USERNAME`
then can't create tables in `public` and every PHPUnit test fails with "permission denied for
schema public". Use `createdb -h /tmp -O <DB_USERNAME> brainitive_test_<name>` (or `ALTER DATABASE
… OWNER TO …` + `ALTER SCHEMA public OWNER TO …` on an existing one).

### Outgoing webhooks: sync queue, signature, private targets (v1.39.0, BRAI-104)
- **Sync queue (dev.brainitive.app, e2e, small self-hosted installs):** `WebhookDispatcher` sends a
  delivery caused by a web request **after the response** (`dispatch()->afterResponse()`), so a slow or
  dead endpoint never slows down or breaks the request. Only one attempt there (a sync job can't be
  released for a retry); Redeliver sends it again. At most 25 per request and 15 s in total, with a
  5 s timeout per attempt (a bulk action, a template or a slow receiver — also behind the public
  ticket / lead endpoints — would otherwise hold the PHP worker for minutes); the rest are logged as
  failed "Not sent" and don't count towards auto-disable. Manual
  sends (Send test, Redeliver) and console runs go inline (`dispatch_sync`). With a real queue:
  queued, 4 tries, backoff 30 s / 2 min / 10 min.
- **Never break the caller:** `DomainEvents` reports (doesn't throw) failures, except in unit tests
  (`runningUnitTests()`) so payload bugs surface. Deliveries are created in `DB::afterCommit` — a
  rolled-back change sends nothing.
- **Signature:** `X-Brainitive-Signature: sha256=` + hex HMAC-SHA256 of `"{X-Brainitive-Timestamp}.{raw body}"`
  with the webhook secret (timestamped against replays; the BRAI-93 lead-form webhook signs the body
  only). `X-Brainitive-Delivery` = the delivery uuid; the envelope `id` stays the same on a redelivery.
- **Private targets** (localhost, 10/8, 169.254…): refused by `OutboundUrl` on save and before every
  attempt. `WEBHOOKS_ALLOW_PRIVATE_TARGETS` exists for the e2e receiver only and is ignored unless
  `APP_ENV=e2e` (`WebhookUrl::allowPrivate()`); https is required in production and staging.
- **Secret:** encrypted + `$hidden`; never an Inertia prop. Returned by store / rotate / reveal as JSON
  with `Cache-Control: no-store`.
- **Payloads** carry ids, keys, titles, URLs, status names and people as `{id, name}` — no member
  emails, no rates (amounts only on `billing_report.*`). Ticket payloads include the contact's name and
  email and lead payloads the lead's fields (agents / admins see them in the app). Builders never load
  relations on the caller's model (it may be serialized into the response afterwards).
- **e2e:** the receiver is a Node server on 127.0.0.1 inside the spec's worker; the spec is skipped
  against dev.brainitive.app. It deletes its webhook at the end — other journeys create issues in the
  same workspace in parallel. The crawler (`e2e:crawl`) fills URL fields with `https://example.com/`,
  so a crawl run can create a webhook that posts to example.com.

### Quick capture: conversion is idempotent by row lock, not by request id (BRAI-70)
`CaptureConversion::convert` locks the capture (`lockForUpdate`) inside one transaction; a capture
that is already processed returns its target (`replayed`, HTTP 200) whatever the second request
asked for, so a double click or a retry never creates a second item. A failure anywhere (policy,
validation, `ProjectIssues` without columns, the final update) rolls the target back and leaves
the capture in the inbox. Inbox ownership grants nothing: the destination's own policy is checked
through `ContentCapture::authorize`. The UI calls the feature **Captures** because the sidebar's
"Inbox" is notifications. The **C** shortcut goes through `isCaptureShortcut()` (`lib/captures.ts`):
no modifiers, not in inputs / contenteditable / textboxes / Excalidraw / open dialogs or menus.

### Meeting notes → issues: the model proposes, the server verifies, a person creates (BRAI-62)
- **Extraction never writes.** Only `meeting-notes.store` creates anything, after the user's review,
  and it re-authorizes board (`createItems`), source doc (`view`) and assignees (`ProjectMember`) on
  every call — also on a retry with a known idempotency key, so a key never outlives a permission.
- **Idempotency reuses `mcp_requests`** through `IdempotentRequest::run` (tool `meeting-notes.create`,
  key = a UUID the dialog makes per review). Same key + same payload replays (200, `replayed`); same
  key + different payload is a 409. No migration was needed; rows are pruned after 7 days.
- **Notes are data.** They go to the provider between a random per-request boundary
  (`<notes-{hex}>`), and the system prompt says to ignore instructions inside them. The real guard is
  `ProposalReview`: a proposal whose excerpt isn't found in the notes (case, whitespace, Markdown
  emphasis and typographic quotes ignored) is dropped and counted (`dropped`, shown in the UI). A
  quote must be at least 3 words / 12 characters or a whole line of the notes ("Fix CI"), so a
  common word can't pass as evidence. A suggested owner must also be named in the notes; otherwise
  the proposal has no assignee.
- **Dates:** a deadline is used only if its phrase is in the notes. With a meeting date + timezone
  the model may resolve "by Friday" (accepted from meeting − 31 days to + 2 years); without them a
  date is kept only when the phrase holds the year and the day (a full calendar date). Otherwise the
  text is shown with a hint and the date stays empty. The dialog's meeting date **defaults to today**
  in the user's zone (`options.today`, v1.39.0): with it empty, every "by Friday" stayed without a
  due date (BRAI-62 feedback). Clearing it keeps the strict full-date rule.
- **Assignees** match only people who can open the project (project members + workspace admins —
  the set `ProjectMember` accepts): one match is suggested, several are `ambiguous` (Create stays
  disabled until the user picks someone or "Unassigned"), none stays unassigned. No emails are sent
  to the browser.
- **Docs:** editors (who see the draft in the editor) extract from `draft_content`, everyone else from
  the live `content`, like `PageController::show`. Whiteboards and folders are refused.
- **E2E fakes the AI in the browser.** There is no provider in e2e or on dev, and no stub route on
  the server: `meeting-notes.spec.ts` patches `ai_configured` on the options response and answers
  `…/extract` with `page.route`; Create is real. `page.route` doesn't see requests of a page that a
  service worker controls (`public/sw.js` has a fetch handler), so specs that intercept app requests
  need `test.use({ serviceWorkers: 'block' })`.

### Customisable sidebar: defaults stay invisible, writes are serialised (BRAI-106)
- **Null means default.** `users.sidebar_preferences` is null until someone changes something, and
  `SidebarPreferences::apply` stores null again when everything is back to default. Every entry and
  group is visible by default, so e2e tests and docs that click `link 'Billing' | 'Timesheet' |
  'Workload' | 'Inbox'` keep working. Hiding is opt-in, and Home + Inbox can't be hidden.
- **Lost updates.** Two saves of the same user (two tabs, or parallel e2e workers signed in as the
  owner) used to read-modify-write the JSON and drop each other's change. `apply` now re-reads the
  column under `lockForUpdate()` inside a transaction, and the frontend store queues its PATCHes.
- **Pins are filtered twice:** on save (`visiblePins`: right project, exists, page visible to the
  user) and on render (resolved against the permission-filtered sidebar payload). A pin to content
  the user lost access to stays stored but never shows.
- **e2e isolation:** per-user settings (`hidden_nav`) are global for that user. Journeys that change
  them run as the member and reset in `finally`; per-project settings and pins use a fresh project
  (`createBlankProject`), so other specs keep the seeded project's default sidebar.
- **Accessible names on the project page:** the header quick links are named "Project health /
  workload / releases" (not "Health"), otherwise `link 'Workload', exact` would match twice. Pinned
  and Recently updated rows use no h3, so `helpdesk.spec.ts`'s card locator (link with an h3) stays
  unique. Every sidebar row now has a `Pin <name>` button, so a non-exact
  `getByRole('button', { name: 'Share' })` also matches "Pin Shared doc…": use `exact: true` for
  generic button names (content.spec's Share button was the one case).
- **Store hydration is silent.** `useSidebarPreferences()` assigns the new server value during render
  (no listener notification) to avoid "update a component while rendering another"; while a write is
  in flight the optimistic state wins over the incoming prop.

### Saved board views store the URL params
A saved view is the board's own query string, not a second filter language: adding a board filter
means adding its param to `BoardViewConfig::PARAM_KEYS` + `normalize()` (allowlist) and, if it
references ids, to `sanitize()` — and to `boardFilterParams()` / `currentViewParams` in
Boards/Show. `assignee=me` stays literal so it resolves for each viewer. A shared view never grants
board access (every policy check starts with BoardPolicy::view).

- **No redirect to the default view.** `BoardController::show` applies it in place
  (`appliedViewParams`) and the page writes the params + `bv` into the URL before its URL-backed
  state reads them. A redirect would drop `#item-N`: Inertia only keeps the hash when the response
  URL equals the request URL. A bare `history.replaceState` isn't enough on a full page load either:
  Inertia's first-page init runs after the page renders and puts `page.url` back, so the page also
  calls `router.replace({ url })` (client-side visit, no request) once on mount.
- **While `bv>0` the URL is the whole truth:** a missing `hide` / `view` means "none" / kanban, and
  nothing is read from or written to the remembered (localStorage) columns and tab — opening a
  teammate's view must not change your own board.
- **POST / PATCH / DELETE keep component state in Inertia 2** (`router.get` doesn't). Actions that
  land on a different URL (save as new, duplicate, delete) pass `preserveState: false`, or the board
  keeps the old filters under the new URL.
- Compare views on normalized params: the server stores `quick` in its fixed order, so the client
  serializes it in the same order (`QUICK_FILTERS`), not click order.

### Bulk edit in the board list view goes through the model, once per issue (BRAI-108)
`PATCH|DELETE boards/{board}/items/bulk` (`BoardBulkItemController` → `Support/Boards/BulkItemEdit`)
changes up to 200 issues of **this** board (one query loads them; a missing, deleted or foreign
id fails `item_ids`, no per-id `exists` rule). Queries grow by a small constant per issue
(asserted in `BulkItemEditTest`): eager-load and resolve shared lookups once, never per issue.
- **One `save()` per issue**, all fields at once, so `ItemObserver` writes one history entry per
  issue (`moved` / `assigned` / `updated`) and any observer hook (notifications, webhooks) fires
  once per issue, never once per field. Labels are a pivot: one `labels_changed` entry per issue; a
  sprint move is a second save (`moveToSprint`), so it adds its own entry.
- **Authorization:** `ItemPolicy::update` depends only on the project, so one check covers all issues;
  `delete` is checked per issue (creators may delete their own) unless the user may delete any issue.
  One forbidden issue = 403 for the whole request, nothing changes. The page gets `canDeleteAnyItem` (policy probe with an unsaved
  item) so the bar disables Delete instead of hitting that 403 (Inertia would show an error modal).
- **Status** follows `Item::moveToColumn` (`completed_at` set on entering a done column, cleared on
  leaving) but an issue that is already done keeps its date. Moved cards go to the bottom of the
  column. Subtasks aren't completed with their parent (no prompt in bulk).
- **Sprint** uses `moveToSprint()` (subtree travels along); selected subtasks are skipped and
  counted in the flash ("follow their parent's sprint"). Kanban boards refuse `sprint_id`.
- **Notifications:** "assigned you" comes from `ItemObserver` → `Support/Notifications/Assignments`
  (after commit, never for yourself), like every other path; the bulk code sends none itself.
  `ItemStatusChangedNotification` goes to watchers (≠ actor) after the commit, the same rule as
  `BoardController::moveItem` (`BulkItemEdit::notifyStatusChange()`).
- `bulk` routes are registered **before** `items/{item}`; otherwise `bulk` would bind as an item id.

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
`exists:users,id` (that lets anyone look up any account). Notification links are built server-side with `route()` and
the UI opens app paths only (`safeAppHref` in `NotificationMessage.tsx`, `public/sw.js`); the
old client-called `notify-mention` endpoint is gone (mentions are sent when content is saved).
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

### Docker self-hosting runs PostgreSQL — the migrations don't run on MySQL / MariaDB
`compose.yaml` shipped MariaDB 11.4 until 2026-10-25, but no fresh install ever got past
`2026_02_23_140000_move_bookmarks_to_workspace` (MariaDB error 1553: the unique index it drops
backs a foreign key) — and MySQL DDL isn't transactional, so every container restart then failed
on "Duplicate column". Tests, CI and production all run PostgreSQL, so Docker does too
(`postgres:16-alpine`, like CI) on a new `pgdata` volume — the old `db` volume may hold MariaDB
files, and Postgres refuses a non-empty data directory ("initdb: directory exists but is not empty"). Don't reintroduce MySQL in compose without running all
migrations against it.

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

### Admin insights: aggregates per tab, never per project (BRAI-105, 2026-10-24)
- **Same counting as project health.** Issue = live issue/subtask on a live board; done = `completed_at` or
  a done column; overdue = open and due before the viewer's today. The SQL lives once in
  `Support/Insights/InsightsQueries` — change a definition there and in `HealthMetrics` together.
- **No `HealthMetrics` per project.** It runs ~5 queries per project; Insights → Projects groups the same
  numbers by `boards.project_id` in one query, and "health" is the latest saved report's status (a person's
  choice; the app never computes one). Every tab has a fixed query count, asserted in `InsightsTest`
  (overview 8, team 6, projects 4, helpdesk 7, usage 6). No cache.
- **Ranges are local days.** `from`/`to` are the viewer's dates; SQL compares `[startUtc, endUtc)`. Weekly
  buckets group by the local day (`at time zone` on Postgres; UTC date elsewhere) and **`group by 1`**:
  Postgres treats the select's and the group by's `?` as different expressions and refuses the query.
- **Definitions that may surprise:** completion rate = share of the issues *created* in the range that are
  done now (bounded 0–100 %); median cycle time = median `completed_at − created_at` of the issues *completed*
  in the range; storage counts files of soft-deleted issues/tickets (still on disk); the lead funnel only
  reaches back 90 days (`lead_form_events` are pruned).
- **e2e numbers are per project.** The shared e2e workspace grows with every parallel spec, so
  `insights.spec.ts` picks its own new project in the filter before asserting numbers.

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
- **All tests run on PostgreSQL** (since BRAI-96), like production:
  - PHPUnit uses the database `brainitive_test`, forced in phpunit.xml, so tests never touch
    `devdb`. Create it once with `createdb -O <your .env DB user> brainitive_test`.
  - e2e uses `brainitive_e2e_<port>`.
  - There is no SQLite test run; SQLite stays only as the zero-config default for self-hosted
    installs.
  - Many parallel local e2e servers can exhaust loopback ports ("Can't assign requested
    address"). `DB_HOST=/tmp` (the Unix socket) avoids that for PHPUnit.

  Traps the Postgres runs caught:
  - **`where(col, 'like', …)` is case-sensitive on Postgres.** Use `whereLike` / `orWhereLike`
    (`ilike` on pgsql). Search must not depend on case.
  - **A caught unique violation aborts the surrounding Postgres transaction**, including the
    test's RefreshDatabase transaction. Use `createOrFirst` / `firstOrCreate` (savepoint) or
    wrap the insert in `DB::transaction`, rather than catching
    `UniqueConstraintViolationException` and querying on.
  - **Sequences don't restart between tests** (rollback keeps them), so never hard-code ids
    like `=== 4`. Keep the created model's id.
- **Notification payloads are rendered in one place.** `describeNotification()` in
  `components/NotificationMessage.tsx` turns a payload into a row. The bell and the page used to fall
  back to "{actor} moved {item_key}", so ticket notifications (`ticket_*`, with no `item_*` keys)
  showed as "moved". Since BRAI-102, unknown types render from the payload contract keys (`title`,
  `body`, `url`, `actor_*`, `project_name`, `subject_key`): give new types those keys instead of a
  frontend case. Old rows keep their old keys forever (they are only pruned after 180 days).
- **Notifications inbox invariants (BRAI-102).** An archived notification is always read (archive
  sets `read_at`; "mark unread" clears `archived_at` too), so the shared `unreadNotificationsCount`
  and `unreadNotifications()` never need an `archived_at` filter. Filtering by category reads
  `data->type` from the `text` column (`data::jsonb ->> 'type'` on Postgres, `json_extract` on
  SQLite — `NotificationController::whereDataType`). `NotificationCatalog::category()` throws on
  unknown types; listing code uses `TYPES[$type] ?? null` instead. Only the bell owns the tab-title
  badge (`useUnreadCount({ manageTitle: true })`): two owners rewrite each other's title forever
  through the MutationObserver and crash the tab.
- **Tailwind only scans `.tsx` files** (`tailwind.config.js` content). Class names that live in a
  `.ts` file (a style map, a constants file) are never generated — that's why
  `components/notifications/categories.tsx` is a `.tsx`.
- **New files under `resources/js/components/` on macOS:** git's index has both `Components/` (old
  Breeze files) and `components/`. A plain `git add` may record a new file under `Components/`,
  which breaks the `@/components` import on Linux (CI). Add with
  `git -c core.ignorecase=false add resources/js/components/...` and check `git ls-files`.
- **Notification coverage (BRAI-103, v1.39.0): who is told, and never twice.**
  - **Assignments fire from the observers, nowhere else.** `ItemObserver::created/updated` (on an
    `assigned_to` change) → `Support\Notifications\Assignments::item()` sends `ItemAssignedNotification`;
    `TaskListItemObserver` → `Assignments::task()` sends `task_assigned`. So the board UI, bulk edit,
    MCP create/update, meeting-note action items, plan import, whiteboard cards and duplicates all
    notify exactly once. `BoardController::storeItem/updateItem` no longer send it: don't add a
    controller-side send back. Leads: `LeadController::update` → `Assignments::lead()`.
  - **The actor is `auth()->user()`.** Without one (seeders, `E2eSeeder`, console) assignment
    notifications stay silent; assigning yourself is silent; assignees without project access are
    skipped. Sent with `DB::afterCommit`, so a rolled-back import tells nobody.
  - **Mentions are diffed.** `ItemMentions::notify($html, $item, $actor, $previousHtml)` only tells
    people new compared with the previous description (re-saving re-notified everyone before).
    Page mentions are sent **on save** by `Page::saveDraft()` → `PageMentions` (diff of the content
    before the save), no longer at insert time from the editor (the old `notify-mention` endpoint
    was removed in BRAI-102).
  - **Comments:** `ItemComments::add()` → `ItemCommentNotifications`: watchers + assignee + reporter,
    minus the author, minus everyone @mentioned in that comment (they get the mention instead).
  - **Due dates:** `app:send-item-reminders` sends `item_due` "due tomorrow" from 08:00 the day before
    and "overdue" once the due time (or the end of the due day) passed, both in the assignee's
    `localTimezone()`. Dedup key `item_due:{id}:{kind}:{due_date}` in `sent_item_reminders`, so a
    new due date is reminded again. Overdue only within 2 days, so old issues never flood anyone.
  - **Sprints:** recipients are the sprint's assignees, read **before** `Sprint::complete()` moves
    open issues out.
  - New types extend `CatalogNotification` and store the payload contract
    (`type, title, body, url, actor_name, actor_avatar, project_name, subject_key`); e2e asserts on
    `/api/notifications` (`e2e/helpers/notifications.ts`), not on the bell's wording.
- **Project data hangs off `project_id`; only `projects` stores `workspace_id`.** Moving a
  project is a single update (see `ProjectTransfer`). Keep it that way: a new project-level
  table must not copy `workspace_id`, or moves (and access checks) drift. Workspace-level
  things (bookmarks, snippets, invitations) are not project data and stay behind.
  Exception: `time_entries` carry `workspace_id` (billing is per workspace). On a move,
  `ProjectTransfer` moves the **unbilled** entries and maps their activity by name in the target
  (none → null); entries on a finalized report stay with the old workspace (v1.39.0, BRAI-107).
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
- **`projects.next_item_number` holds the last issue number used.** `getNextItemKey()` bumps it
  first, under a row lock. The default is 0 since BRAI-97, so the first issue is KEY-1; before
  that, blank projects started at KEY-2. `next_ticket_number` is the other way round: it holds
  the next number to use.
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
- **Linked content (BRAI-71): derived references are written by model hooks, never by the UI.**
  Plan rows and whiteboard cards linked to an issue become `content_references` rows with
  `origin = derived` in `Plan::saved` / `Page::saved`, so every write path (editor, MCP
  `update_plan`, version restore, duplicate, templates) keeps them in step. Writing plan `data` or
  whiteboard `content` with the query builder would skip the hook — go through the model.
  Visibility is decided in batch in `ContentReferences` and mirrors the view policies inside a
  project the user can open (pages via `visibleTo`, the rest: not trashed, board / helpdesk not
  trashed). If a view policy gets a new rule, mirror it there, or backlinks leak titles. Soft
  deletes keep references (hidden, back on restore); hard deletes and cross-project issue moves
  prune them (`pruneMissing`). Whiteboard vs doc labels come from a `content like
  '{"type":"excalidraw"%'` check so lists don't load whole scenes.
- **Workload planning (BRAI-75) never changes an allocation by itself.** Allocations are
  explicit minutes per issue + person + week; estimate, assignee, status, deletion or membership
  changes only show up as "Needs review" flags (`WorkloadGrid::review`, open weeks only). Keep it
  that way: no observer on `Item` rewrites `workload_allocations`. Rules worth knowing:
  - "Capacity not set" (no row) and 0h (leave) are different states; 0h capacity with planned time
    is `over` with no percentage. Load is computed from integer minutes; only display rounds.
  - Logged time lowers the remaining estimate once; it is compared with *open* allocations (this
    week and later) only — never subtract `time_spent` from allocations as well.
  - An issue and its ancestors / descendants can't both have allocations from the current (or
    target) week on (`WorkloadPlanner::guardSubtree`, checked inside the transaction after locking
    the tree's root issue); older weeks are history, so a parent planned last month can be split
    into subtasks. In a person's open work a parent with estimated open subtasks is a container.
    Logged time counts on the issue it was logged on (`time_spent` doesn't roll up).
  - Done issues count up to their completion week (viewer's zone); trashed issues / boards are
    ignored (restore brings them back). Rows of an issue moved to another project are inert; the
    first plan for it in the new project deletes them (`allocate()`), and every read and lookup is
    scoped to `project_id`, so the old project's minutes never show or collide.
  - Override notes ("parental leave") and former members' names in the review reach planners only.
  - Weeks are Monday `date`s; build them with `WorkloadWeeks` (UTC calendar math), never by adding
    seconds to a timestamp — DST weekends would shift them. Week labels use the ISO year (`o`):
    2026-12-28 is W53 2026, 2024-12-30 is W1 2025.
  - Writes from the UI send `expected_minutes` (what the planner saw); a mismatch is a 422 on
    `minutes`, not a silent overwrite. `createOrFirst` keeps parallel inserts to one row.
  - The page is labelled "This project only": capacity is declared per project, so it is *not* a
    person's company-wide availability. Don't sum it across projects without a new design.
- **Time ledger (v1.38.0, BRAI-99): every piece of logged work is a `TimeEntry`, written only
  through `Item::logWork()` (issues) or `TimesheetController` (project time without an issue).**
  Rules worth knowing:
  - Never touch `items.time_spent` directly any more: the entry's model events add / move / take
    off the minutes. Saving with the query builder skips them (only the legacy backfill does that,
    on purpose). Totals from before the ledger stay, so `time_spent` ≥ Σ non-legacy entries.
  - `legacy` entries (backfilled from old `work_logged` logs) never change `time_spent`: their
    minutes and issue are read-only and they can't be deleted (mark them not billable instead);
    date, activity and note can change. The backfill is idempotent through `legacy_log_id` (unique)
    and skips logs that carry `metadata.time_entry_id` (written with a ledger entry) — so new logs
    are never backfilled twice.
  - The activity is **required once the workspace has an active activity** and optional before,
    so workspaces that don't bill aren't blocked. Archived activities can't be picked, but an
    entry keeps the one it has. `billable` follows the activity; only **workspace admins**
    (`manageBilling`) can override it — a member's `billable` input is ignored, and on update their
    entry keeps its flag unless the activity changes (v1.39.0, BRAI-107).
  - **Members never get a rate:** activity options for log forms come from `timesheet.activities`
    (`TimeLogActivities::option()`: name, colour, pricing type label, billable) — never a shared
    Inertia prop, never `rate_cents`. Tests assert it on the JSON and the page.
  - Locked entries (`billing_report_id`) answer 422 on `entry` for update / delete, also for admins.
  - The timer's stop validates the activity inside its transaction: a bad activity rolls back,
    so the timer keeps running and the user fixes the form.
  - `spent_on` is a calendar day in the user's zone; forms send the browser's date, the server
    allows up to one day ahead for zone slack.
  - E2E: `E2eSeeder` gives `e2e-workspace` two activities, so any e2e that logs work there must
    send `activity_id` (`e2e/helpers/workload.ts` `newIssue` fetches the default).
- **Billing reports (v1.38.0, BRAI-101): a draft is computed live, a final report is frozen.**
  - A draft's snapshot comes from `ReportBuilder` on every view; only finalize stores `snapshot` and
    the totals columns. Draft totals columns are a cache for the list, refreshed on view / update.
    Pages and documents show a final report's stored `snapshot` — never recompute it (rates change).
  - **Finalize locks what the admin reviewed, nothing else.** The page sends `entry_ids` (the
    preview's entries). `ReportFinalizer` locks the report row, the settings row and the candidate
    entries `FOR UPDATE` in id order (unbilled ones in scope **plus** the reviewed ids, whatever
    their state), then refuses with 422 and changes nothing when a reviewed entry was billed by
    another report meanwhile, or the set differs (time logged / edited / removed since the
    preview). A parallel finalize waits on the row locks and then sees the other report's id —
    a plain `whereNull('billing_report_id') FOR UPDATE` would silently drop those rows instead.
    The claim itself is `update … whereNull('billing_report_id')` with a count check.
  - A second finalize of a final report returns it unchanged (double click: one number).
  - **Fixed prices bill once per ticket through `billing_fixed_charges`** (unique item + activity).
    Concurrent finalizes charging the same ticket → unique violation → 422 "try again"; the retry
    sees the charge and bills 0 (`fixed_already_billed`). No ticket = once per report and project
    (NULL item ids never collide). Void deletes the report's charges, so a later report bills them.
  - **Invoice numbers** = `InvoiceNumber::format(prefix, next_number)` → "2026-014", taken under the
    settings row lock, unique per workspace, never reused (void keeps the number). A next number
    that is already used is a 422, not a silent skip. Plain time reports have `number = null`.
  - **Lines:** non-billable entries are only in `entries` (billed 0), never in lines / totals.
    Entries without an activity (also `legacy` ones) are "No activity", rate 0, and warned about;
    finalizing them locks them at 0 €, so the page offers "Leave them out". Left-out entries stay
    unbilled. Fixed-price entries stay one line per ticket in every grouping. `show_people` /
    `show_notes` off also hide names / notes in `entries` (the Excel detail sheet), not only lines.
  - Status: draft → finalized → sent (documents) → paid; void from finalized / sent / paid (paid
    needs `confirm`). Void keeps the snapshot for the record and unlocks the entries.
  - E2E: only `billing-reports.spec.ts` bills Muster GmbH's seeded entries; it starts by voiding /
    deleting leftovers of an earlier attempt (CI retries) and voids its report at the end. Don't
    log time on `website` last month in other specs, or its totals change.
- **Share links expose only a whitelist.** `ShareLinkController::show` builds the payload
  field by field (title, content / description, status, type, priority, dates) and never
  serializes the model, so new columns can't leak into public pages. Docs share the live
  `content`, never `draft_*`; whiteboards aren't shareable yet (they need the Excalidraw viewer).
- **Project health (BRAI-64): numbers are evidence, never a score.** There is no computed
  health status; a person picks On track / At risk / Off track when saving. Missing data is
  `null` + a reason ("Unknown"), never 0: no boards → every issue metric; open issues but none
  with a due date → overdue / due soon; no plans → milestones. *Completed* = `completed_at` set
  **or** in a done column (other modules use only `completed_at`; done-column issues without it
  count as completed but have no completion date for the period). Due dates are wall-clock days
  compared with *today in the chosen zone* (due today is not overdue); `completed_at` /
  `created_at` periods convert the local day bounds to UTC. Blocked = open issue + open blocker
  via `blocks` / `is_blocked_by` (a pair stored both ways counts once), and the blocker must be
  in a project the viewer can open. Plan rows with a valid `itemId` *are* the issue and never
  count twice. Nothing is cached — every number is for the current viewer.
- **Health reports store ids, not titles.** `metrics.lists` keeps `item:<id>` refs only, and
  every source is resolved for the reader (`ReportSources::resolve`); deleted, moved or
  inaccessible sources render as "Unavailable source" without title or link. The claim *text*
  is what the author reviewed and saved, so it can still mention a title — the AI snapshot only
  contains this project's issues (blockers elsewhere are anonymous) to keep that small. Save
  rejects any ref that isn't a live issue / plan of the project or a known metric (422).
- **The AI health narrative is optional and treats project content as data.** `HealthNarrator`
  sends the bounded snapshot inside `<project_data>` with a system prompt that forbids following
  instructions in it, asks for JSON claims, and drops claims whose source ids aren't in the
  snapshot. Without AI settings the page still works (`aiConfigured` false, narrative 422
  `ai_unavailable`); e2e has no provider, so only that state is tested there — PHPUnit fakes
  the provider with `Http::fake`.
- **Billing documents (v1.38.0, BRAI-101) render the snapshot, they never compute.** `BillingDocument`
  only formats cents / minutes / basis points from the snapshot v1 array (contract in
  `tasks/release-v1.38.0-billing.md`); fix a wrong amount in the report builder, never in the PDF /
  Excel code. Traps worth knowing:
  - **openspout turns any string starting with `=` into a formula** (`Cell::fromValue()` /
    `Row::fromValues()`). Ticket titles and notes are user text, so every text cell is an explicit
    `StringCell` (`BillingDocument::text()`). Any other xlsx export must do the same.
  - **dompdf runs locked down:** remote, PHP and JS off, only `data://` allowed, chroot to
    `resources/views/billing`. The logo goes in as a data URI read server-side, only for a path
    `BillingLogo::owns()` (the workspace's own `billing-logos/<id>/<uuid>.png`) — a snapshot is data,
    not a path to trust. DejaVu Sans (ships with dompdf) prints € and accents; its metrics cache is
    `storage/framework/cache/dompdf`. Page numbers are drawn on the canvas after `render()`.
  - **Billing logos are private.** `local` disk, re-encoded to PNG with GD (drops metadata and
    anything appended; max 2500 px per side before decoding, scaled into 1200 × 600). The settings
    page shows it through the admin-only `billing.settings.logo` route. A replaced logo is deleted
    unless a non-draft report's snapshot still prints it.
  - **Drafts need the reports ticket's builder.** `BuildsReportData` is bound (`BillingServiceProvider`)
    only when `App\Support\Billing\ReportBuilder` exists; until then a draft's PDF / Excel is 409.
    Sending is for finalized / sent / paid only and moves finalized → sent; the PDF is rendered in
    the queue worker from the frozen snapshot (a report voided while the mail waits in the queue
    still goes out, stamped VOID — accepted race).
  - **Sending is abuse-capped.** Anyone can register and own a workspace, so `billing.reports.send`
    uses the named limiter `throttle:billing-send` (`BillingServiceProvider`): 10 a minute per admin
    plus 50 a day per workspace. The mail comes from the app's address but is named
    "<admin> via Brainitive", with reply-to the admin; addresses are `email:rfc,strict`.
  - **Hidden names / notes stay hidden in Excel too.** `show_people` / `show_notes` drop the Person
    and Note columns of the "Entries" sheet, not just the PDF lines — the client receives the file.
    Draft / void documents carry a stamp (PDF heading, Excel "Status" row) and no payment request.
  - **Testing PDFs:** `BillingDocumentTest::pdfText()` inflates the content streams and decodes the
    UTF-16BE strings, so assertions can look for the printed text (dompdf compresses everything).

- **Billing time overview (BRAI-100): one joined query, reduced in PHP.** `TimeOverview::query()`
  joins entries with project, client, the *live* item (`items.deleted_at IS NULL` in the join, so
  a trashed issue falls back to the `item_key` / `item_title` snapshot like a deleted one),
  person, activity and report, and `summary()` walks it with `cursor()`. The page costs the
  same number of queries for 5 or 5 000 entries (`test_the_page_runs_a_fixed_number_of_queries`
  — measure after one warm-up request, the first one fills per-request caches). Don't add
  per-group lookups; add a column to the join. The **estimate** counts billable entries only;
  a fixed price is charged once per project + ticket (or "no ticket") + activity and credited
  to the group of the earliest entry, so groups always add up to the total. It doesn't know
  which fixed prices an earlier report already billed (BRAI-101 owns that), hence the label
  "estimate before rounding / VAT".
- **openspout turns any string starting with `=` into a formula.** `Cell::fromValue()` /
  `Row::fromValues()` build a `FormulaCell` for `"=…"`, so a time-entry note like
  `=HYPERLINK(…)` became a live formula in the client's Excel. Exports of user text build
  `StringCell` / `NumericCell` / `EmptyCell` explicitly (`TimeExport::text()`). Stream big
  exports: `Writer::openToFile('php://output')` inside `response()->streamDownload()`, rows
  from a `cursor()`; read back in tests with `$response->streamedContent()` + the XLSX `Reader`.
- **Controlled checkboxes that trigger an Inertia reload need local state.** A checkbox whose
  `checked` comes only from props flips after the round-trip, and Playwright's `check()` fails
  with "Clicking the checkbox did not change its state". The overview's project picker and
  "Show entries" keep an optimistic `useState` that re-syncs from props.
- **Billing and project moves / trash (BRAI-100).** `ProjectTransfer::move()` clears
  `projects.client_id` (clients belong to the old workspace). Time entries keep their
  `workspace_id`, so the old workspace still has that logged work; the overview joins project and
  client only inside the entry's workspace and only while not trashed, and otherwise shows
  "Deleted or moved project" with the snapshot ticket key / title — never the new workspace's
  names. Whether unbilled entries should move with the project is an open decision (activities
  are per workspace, so moving them would need the activity cleared or mapped).
