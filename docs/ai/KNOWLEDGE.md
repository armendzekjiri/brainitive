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
(upserts keyed by item+kind+repo+identifier) — GitHub redelivers. Since v1.42.0 this is the legacy
mode; the GitHub App webhook is `/api/webhooks/github-app` (see "GitHub App vs manual webhooks").

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
- **Journeys on dev differ from local:** `APP_ENV=staging`, so no `X-E2E-*` opt-ins (skip with
  `test.skip(REMOTE, …)`); live editing is on (realtime server), so page drafts save via
  `…/collab/draft`; the host is `dev.brainitive.app`, not localhost; and global data (e.g. item
  types with `project_id` null) is shared with whoever uses dev — don't assume it's empty.
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

### Inbound email webhooks: provider answers, replay cache, hidden address (v1.40.0, BRAI-124)
- **Status codes are for the provider's retry logic.** Postmark stops retrying on 403, Mailgun on 406;
  everything else (401, 5xx) is retried for hours. So permanent refusals (unknown recipient, no sender,
  stale / replayed Mailgun signature, too large) answer 403 / 406, a wrong token or signature 401 (a
  misconfigured secret loses nothing once fixed), and a pipeline failure on a sync queue 500.
- **Mailgun replay:** HMAC-SHA256 of `timestamp.token` with the signing key, timestamp within
  `INBOUND_MAIL_REPLAY_WINDOW` (900 s), and each token once (`Cache::add` for 2× the window). The
  token is released again when processing fails, so the provider's retry gets through. Duplicates of
  a *message* are `InboundMail::receive()`'s job (dedupe on Message-ID; a message without one gets a
  stable `postmark-<id>@inbound.invalid` / `noid-<sha256>` id).
- **Recipient = envelope first** (`OriginalRecipient` / `recipient`): a Gmail auto-forward keeps the
  customer's `To: support@their-company`, only the envelope carries our address. `+tag` is ignored
  for the lookup and kept in `InboundMessage::$to` (routing tokens).
- **The address is a capability.** Anyone who knows it can open tickets, and `Helpdesk` is passed whole
  as an Inertia prop on several pages, so `forwarding_local_part` is `$hidden` and only the Email tab's
  JSON endpoint (helpdesk `update`) reveals it. Regenerate = a new random part; the old one stops at once.
- **Attachments go to `storage/app/inbound-mail/<uuid>/`, not `sys_get_temp_dir()`:** the queued job may
  run in another process. Random file names; the client's name is only metadata (`InboundPayloads::filename`).
- **e2e** turns forwarding on in `e2e/env.ts` (`INBOUND_MAIL_*`, Postmark). dev.brainitive.app has no
  inbound domain, so `helpdesk-forwarding.spec.ts` skips there.

### Inbound email pipeline, threading and loops (v1.40.0, BRAI-79)
- **Libraries:** ext-imap is gone from PHP 8.4, so IMAP is `webklex/php-imap` (MIT), used only for its
  socket protocol (`ImapProtocol`: LOGIN, SELECT, UID SEARCH UNSEEN, RFC822.SIZE, `BODY.PEEK[]`, STORE,
  MOVE with COPY+EXPUNGE fallback). Parsing is `zbateson/mail-mime-parser` (BSD-2): webklex's own
  parser leaves RFC 2047 words (subjects, names, file names) undecoded without ext-imap. webklex's
  `fetch()` matches single items by the requested name, so `BODY.PEEK[]` is fetched together with `UID`
  (multi-item path) and read back as `BODY[]`.
- **Dedupe in the same transaction.** `receive()` inserts the `helpdesk_inbound_emails` row (unique
  helpdesk + sha256 of the Message-ID) inside the transaction that creates the ticket / reply: a crash
  rolls both back, a concurrent delivery hits the unique index → DUPLICATE. Ignored / rejected mail is
  recorded too (audit + dedupe). A row with status `failed` (unexpected error) is retried on the next
  delivery. Notifications and DomainEvents run after the commit and never undo it.
- **Poller crash safety:** `BODY.PEEK[]` doesn't set `\Seen`; `markProcessed()` runs after
  `receive()`. A crash in between leaves the mail unread and the next run gets DUPLICATE and only marks
  it. Mail without Message-ID is keyed `imap.<mailbox>.<uidvalidity>.<uid>@inbound`. Only UNSEEN mail
  is imported: a mailbox someone reads in a client loses mail (documented: support-only mailbox).
- **Threading:** In-Reply-To / References matched against `ticket_email_messages` (in + out, this
  helpdesk, not trashed), then the subject tag `[KEY #token]` (token = first 10 hex of
  HMAC(`ticket-mail|uuid`, APP_KEY); rotating APP_KEY breaks only the subject fallback). The subject
  text alone never threads. No `+t<token>` sub-address: plus addressing isn't supported everywhere and
  a bounced reply would be lost.
- **Who may reply:** the ticket's contact or an address that was on Cc of the first message
  (`tickets.metadata.email.participants`); anyone else → REJECTED `not_participant` (visible in the
  mailbox log), and `Authentication-Results: dmarc=fail` → REJECTED `dmarc_fail`. Knowing a ticket key
  or Message-ID is not enough. A forwarded thread answered by a colleague is rejected in v1.
- **Loops:** ignored: `Auto-Submitted` ≠ no, `Precedence: bulk|junk|list|auto_reply`, `X-Autoreply`,
  `X-Autorespond`, `List-Id` / `List-Post`, mailer-daemon / postmaster / `Return-Path: <>` /
  `X-Failed-Recipients` / `multipart/report`, our `X-Brainitive-Loop` header, mail from the mailbox or
  app address, and Message-IDs we sent. Plus at most 10 new tickets per sender, helpdesk and hour.
  Our acknowledgment carries `Auto-Submitted: auto-replied`; agent replies don't (they are personal).
  Exception: Gmail's forwarding confirmation (`forwarding-noreply@google.com`, BRAI-124 setup) is never
  filtered and never acknowledged; no-reply senders get no acknowledgment.
- **Outgoing:** a helpdesk with an enabled mailbox emails agent public replies itself (Message-ID fixed
  before the job, stored on the reply; In-Reply-To / References from the thread; Cc participants) and
  skips `TicketReplyNotification` for the contact — never both. Email tickets get the threaded
  acknowledgment instead of `TicketCreatedNotification`. Internal notes never send.
  `TicketResolvedNotification` is unchanged (link mail from the app address). App-mailer mode sends
  *from the app address* with Reply-To the mailbox (sending as the customer's domain from our servers
  fails SPF / DMARC); SMTP mode sends from the mailbox address. Without a mailbox (forwarding-only
  helpdesks) the old notifications go out; `TicketMailer::replyAddress()` is where that could change.
- **Sockets to user hosts:** `MailHost` = `OutboundUrl::publicAddress()` (all resolved IPs public) +
  mail-port allowlist + TLS required; the socket connects to the checked IP and TLS verifies the host
  name (`peer_name`). Private hosts / no TLS only in e2e, or `APP_ENV=local` +
  `HELPDESK_MAIL_ALLOW_PRIVATE_HOSTS`. Login errors shown to admins have the password scrubbed.
- **e2e:** `HELPDESK_MAIL_FAKE` (e2e only) binds `FakeMailboxConnector` and skips DNS; the password
  `wrong-password` fails the login. `helpdesks.mailbox.e2e-receive` (registered only with
  `APP_ENV=e2e`) feeds a fixture, optionally with `reply_to_ticket` (In-Reply-To = the ticket's last
  outgoing Message-ID). A local GreenMail container (`greenmail/standalone`, IMAP on 143, SMTP on
  2525, `APP_ENV=local` + the private-hosts flag) is handy for trying the real client by hand.

### Recurring issues: dates, catch-up and the actor (v1.40.0, BRAI-123)
- **Dates are calendar maths, the zone only picks "today".** `RecurrenceSchedule` works on `Y-m-d`
  dates (midnight UTC), so DST never moves an occurrence. An occurrence is due from **local midnight**
  in the template's `timezone` (the creator's `localTimezone()` when it was saved) — Berlin's Monday
  starts at 22:00 UTC in summer and 23:00 UTC in winter. The command runs every 15 minutes, so issues
  appear shortly after local midnight. The scheduler query looks up to UTC today + 1 day (UTC+14).
- **Every date comes from the rule, not from the previous date** (unlike list tasks'
  `TaskListItem::nextDueDate()`, which chains `addMonthNoOverflow` and drifts Jan 31 → Feb 28 → Mar 28).
  Monthly day 29–31 clamps to the month's last day, so 31 = "the last day"; yearly Feb 29 → Feb 28 in
  common years and Feb 29 again in leap years. Monthly "the nth / last X" supports X = 0 (any weekday
  Mon–Fri: first / last business day). Weekly intervals count weeks (Monday-based) from the start date's
  week.
- **Idempotent + no flood.** The runner locks the template row and only looks at dates after
  `last_occurrence_date`; `recurring_issue_occurrences` is unique on (template, date) as a backstop.
  After downtime only the **latest** missed date is created (`latestBetween`), the others are skipped.
  `active_from` (the local day the template was created, its schedule changed or it was resumed) is the
  floor: a start date in the past, an edited schedule or a pause never backfill old dates.
- **Count** (`max_occurrences`) counts issues actually created (skipped dates don't count); `end_date` is
  inclusive. When either is reached `next_occurrence_date` becomes null ("Ended").
- **Actor = the template's creator.** The console has no user, and `Assignments::item()` stays silent
  without one, so the runner signs the creator in for the create (`Auth::setUser`, then `forgetUser` /
  the previous user back). The assignee gets the normal `item_assigned` from `ItemObserver` (silent when
  the creator assigned themself), history / events / automations see the creator. If the creator can no
  longer create issues on the board (or the board is gone / has no columns) the template **pauses** with
  `last_error`; whoever resumes it becomes its creator. An assignee who left the project → unassigned
  issue; a type / priority / label no longer in the project → the default / dropped. Description
  mentions are not re-notified on every occurrence.
- **e2e trigger:** `recurring-issues.e2e-run` is only registered when `APP_ENV=e2e` (and the action
  aborts 404 otherwise); dev and production have no such route. The spec skips the "created on the
  board" part with `REMOTE`. A POST to an unregistered URL answers 405 (the GET `Route::fallback`
  matches), not 404.
- **New subfolders under `resources/js/components/`:** even an explicit lowercase `git add
  resources/js/components/recurring/X.tsx` was recorded as `Components/recurring/X.tsx` (the folder is
  new to git and macOS reports the on-disk `Components`). Fix the index entry with `git rm --cached
  <Components path>` + `git update-index --add --cacheinfo 100644,$(git hash-object -w <file>),<lowercase
  path>`, then check `git ls-files -s | grep -i <name>`.

### Workflow automation: when rules run, as whom, and why they never loop (v1.40.0, BRAI-63)
- **Only events with a subject reach the engine.** `DomainEvents::emit(..., subject: $item|$ticket)`. A new
  path that creates tickets (e.g. `InboundMail::receive()`) must pass `subject: $ticket` on its `ticket.created`
  emit, or rules never see those tickets. Without an enabled rule for the project + trigger it costs one
  indexed `exists` query (`AutomationEngineTest::test_without_an_enabled_rule_the_hook_costs_one_query`).
- **When:** after commit (a rolled-back change starts nothing), and inside an HTTP request only after the
  controller returned (global `DeferAutomations` middleware) — the board's create form syncs labels *after*
  the insert, so an inline run would miss them. Console / queue / plain tests start the run right after the
  commit, which can be inside the observer of the creating save. On the sync queue (dev, e2e) the run
  executes before the response, so the redirect already shows the result.
- **No loops:** while a run executes `AutomationRunner::running()` is true and `seen()` ignores every event,
  so automation changes never start rules (webhooks still fire for them). Recurring issues and other
  scheduled creators are *not* automation-made: their issues start rules like any user's.
- **Idempotency:** unique (rule, version, event id). Event ids are deterministic where the event happens
  once (`item.created:<id>`, `ticket.created:<id>`, `item.commented:<comment id>`, `item.due:<id>:<date>`)
  and fresh uuids for repeatable events (moves, assignments). Conditions are pre-checked before a run is
  created (a non-matching event leaves no row) and checked again when it starts.
- **As whom:** the rule's `actor_id` (whoever saved it last). The runner `Auth::setUser($actor)` for the run
  and restores the previous user, so history and "X assigned you" name the actor. Assigning yourself stays
  silent, as everywhere — **except from a rule** (v1.41.2 fix): a rule acting as you that assigns you still
  notifies you, worded "Automation “<rule>” assigned you" (`AutomationRunner::ruleName()` is non-null only
  while a run's actions execute → `Assignments::item` / the ticket assign action pass it as `$automation` to
  `ItemAssignedNotification` / `TicketAssignedNotification`). Missing / suspended / no-longer-member actor → run `skipped`; a policy denial per
  action → that action `error`. A switched-off rule or a newer version skips queued runs.
- **Retries:** each action runs in its own transaction together with saving its result; a retried run
  skips actions already `ok` / `skipped`. A cache lock (180 s) keeps two workers off one run (the job
  releases itself when busy); `RunAutomation::failed()` marks the run `error`.
- **Due date passed** uses the actor's time zone, only due dates of the last 2 days (no flood when a rule
  is switched on), at most 200 new events per rule and run.
- **e2e:** rules on the shared seeded project affect every parallel spec that creates issues there; scope a
  rule with a unique "Title contains" word and delete it at the end (`automations.spec.ts`).
- **There is no global flash toast.** Pages show `flash.success` themselves (the automation pages use
  `components/automations/useFlashToast.ts`).
- **Conditions are `{field, operator, value}` + rule `condition_match` all | any (v1.42.0, BRAI-144).**
  `AutomationCatalog` (`CONDITION_FIELDS`, `OPERATORS`, `CUSTOM_FIELD_OPERATORS`) is the single source of
  truth for which operators a field has; the builder only renders `forFrontend()`. Legacy `{type, value}`
  conditions are normalised on read (`AutomationDefinition::normalizeCondition`) even after the data
  migration `2026_10_31_180100_convert_automation_conditions` — keep it (its `down()` is a no-op).
- **"Not" operators (`is_not`, `not_in`, `has_none`, `not_contains`, …) are true for an empty value** —
  "label is not Bug" matches an issue without labels. Add `is_not_empty` when that's not wanted.
- **Custom-field conditions (`custom:<item_field id>`) fail closed** when the field is archived / deleted
  or its type no longer fits the operator (described "(field removed)"). Custom fields have no
  multi-select type, so no multi-select family exists yet.
- **Dates in conditions** ("within the next n days", before / after, overdue) use the rule actor's time
  zone (`User::localTimezone()`, like `DueDateTrigger`); the dry run uses the current user's. Overdue =
  open (no `completed_at`) and due day over or due time today passed. "Reporter" = `reporter_id`, else
  `created_by`. Runs store the checked conditions (`automation_runs.conditions`); older runs show none.

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
- **Sign in with Microsoft (v1.42.0, BRAI-148) — which email is trusted.** The email only links or creates
  an account, so it is security-critical ("nOAuth": an Entra tenant admin can put ANY address in a user's
  `mail`). Trusted only from the **id_token `email` claim** of a personal account (`tid` = consumer tenant
  `9188040d-…`) or of a work / school account with **`xms_edov` = true** (optional claim, must be switched
  on in the app registration), never for a B2B guest (`acct` = 1). Never Graph `mail`, `userPrincipalName`
  or `preferred_username`. Without a trusted email an account already linked by `microsoft_id` still
  signs in; linking / sign-up is refused ("We couldn't confirm the email address…"). The key is
  `users.microsoft_id = "{tid}:{oid}"` (not `sub`, not the Graph id, never the email). Emails are
  lower-cased and matched with `lower(email)`. Suspended users are refused before `Auth::login`. The
  id_token signature isn't verified (token endpoint over TLS + secret + PKCE, OIDC Core 3.1.3.7); its
  claims are (`aud`, `iss`, `exp`, `nonce`, GUID `tid` / `oid`, tenant pin). Entra setup:
  [`tasks/feat-microsoft-login.md`](./tasks/feat-microsoft-login.md).
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

### Helpdesk SLA: derived dates, business-time maths, recompute points (v1.40.0, BRAI-125)
- **Never write the `sla_*` ticket columns by hand.** `TicketSla::apply()` derives all of them from the
  ticket, its helpdesk's policy and "now"; the `Ticket` `saving` hook runs it on create and when priority,
  column, `completed_at` or `first_responded_at` change. Bulk writes through the query builder skip it — call
  `TicketSla::recompute()` for those. Policy saves recompute the **open** tickets only; resolved tickets keep
  their historical result.
- **First response = first public reply with a `user_id`** (`TicketReply` `created` hook), so every agent
  path counts: the reply box, the resolve dialog's closing message, emailed replies (ticket 3). Internal
  notes and contact replies don't. Resolving a ticket before its first-response due time also meets it.
- **Business time is built from local wall-clock windows per date** (`BusinessCalendar`), then turned into
  instants: a DST day's window is an hour shorter / longer, opening times never shift. `to < from` = an
  overnight shift that belongs to the day it opens. A "day" target = one business day (`to − from`), 24 h
  when 24/7.
- **Pauses only stop the resolution clock** (pause columns, e.g. "Waiting on customer"). Paused business
  minutes are added to the resolution target when the ticket leaves the column; a ticket paused past its due
  time counts as breached from the moment the pause began.
- **`warn_at` doubles as "when the checker looks"** (= due when warnings are off), so the checker's query is
  two indexed comparisons. Notifications are once per (ticket, warning|breached, target, person) via
  `sent_item_reminders` keys `ticket_sla:<id>:<kind>:<target>`; reassigning sends the new assignee their own.
- **The badge also computes "breached" in the browser** (`lib/sla.ts`), because dev.brainitive.app runs no
  scheduler. Keep `lib/sla.ts` and `TicketSla` in sync. e2e triggers the checker through
  `helpdesks.sla.e2e-check`, which only exists with `APP_ENV=e2e`, so that journey is skipped on dev.

### Trello import: what maps to what (v1.41.0, BRAI-65)
- **Always a new Kanban board.** `Board::created` adds To Do / In Progress / Done; the importer deletes
  them in the same transaction and creates one column per **open** list, in list order. Archived lists
  never become columns; their cards count as archived. Done column: the last open list named "done" /
  "complete(d)" / "finished" (also "erledigt", "fertig"), else the last open list; the user can change it.
- **Archived cards** (card closed, or on an archived list) are skipped by default, else they go to the done
  column. Cards in the done column get `completed_at`.
- **Order and keys:** cards sorted by list position, then card `pos`; keys come from `getNextItemKey()` in that
  order. `position` per column and `backlog_position` are derived from that order, so a resumed run writes
  the same values.
- **People:** auto-suggested only by email (Trello rarely exports emails); otherwise the user maps each
  member in the preview. The first mapped member becomes the assignee, the others are named in the
  description ("Also on this card in Trello: …"). Option values pass `ProjectMember`; `run()` filters them
  against the current project members again.
- **Descriptions:** `PageContentConverter::markdownToHtml()` (bare URLs become links first) → `SafeHtml`.
  Checklists are appended **after** sanitizing, as Lexical check lists built from escaped text
  (`<li role="checkbox" aria-checked>`): `SafeHtml` strips `aria-checked`, and the issue view toggles by it.
  Attachment links (http/https only) are added only when the user asks; files are never downloaded.
- **Subtasks mode:** each checklist item → a subtask (type `subtask`, done items in the done column,
  "Checklist: item" titles when a card has several checklists); cards with more than 50 items keep a
  check list instead.
- **Labels:** only labels the imported cards use. Matched to project labels by name (case-insensitive) or
  slug, otherwise created with Trello's colour as hex; unnamed Trello labels are named after their colour.
- **Not imported (counted in the report):** comments (Trello's JSON export only holds the newest 1 000
  actions anyway), custom field values, attachments, power-ups.
- **Memory:** uploads are capped at 25 MB (exports are usually a few MB). Decoding needs ~16× the file size
  in PHP arrays, and the preview runs inside the web request, so `TrelloExport` raises `memory_limit` to at
  most 512 MB (never lowers it). Raise both together if bigger boards ever need importing.

### Jira import: what maps to what (v1.41.0, BRAI-139)
- **Columns are read by position.** Jira repeats a column name once per value (`Labels`, `Sprint`,
  `Comment`, `Watchers`, `Attachment`, …), so `CsvReader` rows are plain lists and `JiraExport` looks names
  up case-insensitively. Backslash is not a CSV escape (`\\` is a wiki line break). An odd number of `"`
  in the file = a cut-off cell → the preview fails. Uploads are capped at 25 MB; the file is streamed
  with `fgetcsv` (preview runs in the web request).
- **Statuses → columns:** one column per status in status-category order (the `Status Category` column,
  else the resolution, else the name); every done-category status → one "Done" column. The user renames /
  merges columns by typing the same name and picks the done column; it always exists and comes last.
- **Hierarchy:** `Parent id` / `Parent` (sub-tasks, Cloud parents) or `Epic Link` (a key) → `parent_id`.
  Issues are created parents first (sorted by depth); cycles are cut, deeper than `Item::MAX_DEPTH`
  moves one level up, parents missing from the file → top level, each with a warning.
- **Sprints** (optional; makes the board Scrum) come over as **planned** sprints (the CSV has no sprint
  state). An issue's sprint is the last one Jira lists. Because an issue and its sub-issues share one
  sprint in Brainitive, a top-level issue takes its own sprint, else the sprint most of its sub-issues
  were in (warning when they differed). The result link opens the backlog for Scrum boards (the board
  page shows only the active sprint).
- **People:** assignee / reporter cells are display names (Cloud) or user names (Server, sometimes
  emails): matched by email, else by a unique member name; the rest stays unassigned and the reporter
  name stays in the "Jira: KEY · Reported by X on date" line. Comment authors are account ids; they
  are resolved through the `Assignee Id` / `Reporter Id` / `Creator Id` columns, else "a Jira user".
- **Comments** are created directly (Comment + `commented` history entry), not via `ItemComments::add`,
  which would send mention / comment notifications (ImportScope only silences Assignments and DomainEvents).
- **Report counts** come from the run's `import_mappings` at the end of `run()`, minus what an earlier
  attempt already reported, so a crash + resume neither loses nor doubles counts.
- **Not imported (counted):** custom fields (other than Epic Link/Name, Story Points, Start date, Rank, …),
  attachments, watchers, work log entries, components, versions. Images and `[^attachments]` in the
  markup become text; external images become links (nothing is fetched).

### Notion import: tree, links and files (v1.41.0, BRAI-66)
- **Tree:** a Notion page with subpages becomes a **folder** (same name) holding the page first, then the
  subpages; a database (CSV) becomes a table page (+ folder when its rows have pages). Folders without
  a page of their own (teamspace folders) stay folders; Notion's `Export-<uuid>` wrapper folder is
  dropped. Children are ordered as the parent page links them (database rows: CSV order), then by title.
- **Drafts:** every page is created `status = draft` by the importing user and never published, so only
  the author and admins see them. **Folder names are visible to the whole project** (folders always are).
- **Two passes:** pass 1 creates the tree (`notion_folder` / `notion_page`, source id = Notion id, or a
  hash of the path when the name has none); pass 2 writes each page's content once (`notion_content`
  mapping, in the same transaction as the update + report save) with links via `ImportMappings::map`.
  Links to skipped (empty) pages keep their words only; links to files / pages missing from the export
  become text and are reported (preview + report).
- **Paths are compared in NFC** (macOS zip tools write NFD names, Notion links are NFC).
- **Files:** only from inside the archive, stored like editor uploads: FileController's validation rules,
  `Uploads::imageExtension` / `extension`, the public disk, `Storage::url`. Names are UUIDv5 of
  (run uuid, path), and `imports/<run>/notion-stored.json` lists what was stored, so a retry neither
  duplicates nor double-counts. SVG, video etc. aren't accepted by the editor and are reported.
- **Markdown:** `Imports\Shared\MarkdownHtml` (league/commonmark GFM, single newline → `<br>` for Notion),
  not `PageContentConverter::markdownToHtml` (no tables / images / nested lists). HTML longer than
  500 000 characters is refused (`ImportFailed`, page named) because `SafeHtml` would truncate it.
  Tables stop at 1 000 rows / 400 000 characters with a note; the original CSV is linked. CSVs are read
  with the Jira import's streaming `CsvReader` (a damaged CSV fails the preview, naming the database).
- **Limits (fail in the preview):** 2 000 pages / databases / folders, 256 KB Markdown per page, 5 MB per
  CSV, SafeZip 5 000 files / 512 MB. Upload rule 200 MB, but `docker/php.ini` caps uploads at 50 MB
  (`post_max_size` 55M): raise both for bigger exports. Memory stays bounded: page bodies are read from
  disk when needed, never all held at once.

### Project export + restore: what travels and why (v1.41.0, BRAI-81)
- **An archive is content, not a backup of the instance.** Included: boards, columns, swimlanes,
  sprints, releases (without public token / published page), issues (subtasks, labels, types,
  priorities, custom fields incl. archived ones, comments, attachments, links inside the project), docs,
  folders and whiteboards (live content; drafts only if the exporter can see them; no unpublished changes
  of published docs, no version history), task lists, plans (rows linked to issues by ref, no share
  token), helpdesk structure (columns, form fields, form texts from `HelpdeskForm::SETTING_KEYS` only).
  Never: members / roles, tickets / contacts, leads, time entries, history / watchers, integrations,
  webhooks, automations, share links / tokens, board views, recurring issues, snippets / bookmarks.
  `ProjectArchive::NEVER` lists them and the manifest repeats them with counts.
- **People are metadata.** `people.json` has name + email of everyone the content points at. Restore maps
  them to workspace members (the preview's choice, else the same email) and adds mapped people to the
  new project as editors. It never invites or creates anyone. Comments are written by the restoring user
  with "Originally by Jane Doe on 3 Mar 2024".
- **Verify before writing:** `ProjectArchiveReader` unpacks through `SafeZip`, then refuses a wrong
  format, a newer version, a missing / changed (sha256) file, an unlisted file and unsafe manifest paths.
  The preview decodes one module at a time (web request, memory ≤ 512 MB); a module is capped at 48 MB,
  and the export refuses a project that would exceed it (`ArchiveTooLarge`).
- **Resume:** every record is `ImportMappings::once` with its archive ref; embedded media get
  deterministic names (`uploads/images/<run uuid>-<ref>.png`), so a retry reuses them. Report counts
  come from the mappings at the end, not from the progress counters (those are short after a crash).
- **Download security:** temporary signed *relative* URL (proxies), plus the exporter's session, plus an
  unexpired row with a file. An expired notification link redirects to the settings with a message.
  Files go after 24 h (`exports:cleanup`; dev has no scheduler, so a new export also cleans up).

### Guest identities: not members, and never under `/portal` (v1.42.0)
Contract: [`tasks/release-v1.42.0.md`](./tasks/release-v1.42.0.md) → "Foundation".
- **A `PortalIdentity` is an email, not a member.** It gets no workspace / project access and no policy
  passes for it. A verified Brainitive user is always their own identity (linked by email), so team
  members can use portals without a second sign-in.
- **`portal.identity` only proves "this email is theirs".** Every portal checks its own access on top
  (portal enabled, post visible, active client membership, …). Signing in is open to any email.
- **Prefixes:** guest sign-in is `/guest/*`; portal pages live under `PortalAuth::RETURN_PREFIXES`
  (`/feedback/`, `/client/`) or the sign-in link can't return to them. **Never `/portal/*`** — that is
  the stateless, CSRF-exempt helpdesk ticket portal.
- Opening a sign-in link shows a confirm page; only the POST spends the token (mail scanners open links).
- Mails to guests are plain mail-only `Notification`s outside `NotificationCatalog` (guests have no
  preferences), like `TicketContact` mail.

### Feedback portal: public shape, votes, merges (v1.42.0, BRAI-60)
- **The public side only sees `FeedbackPresenter` output** — never the linked card, author emails,
  moderation data or internal ids. Text is plain (`FeedbackText`); no HTML is stored.
- Disabled / unknown portal → 404. Pending posts are visible only to their author.
- **Votes count once:** unique (post, identity) + `insertOrIgnore` + `votes_count` changed in the same
  transaction under a post row lock — so votes and a merge of the same post serialize, no deadlock.
- **Merge** unions votes and followers without double counting; the old URL 301s to the target only if
  it was public. Create card / link card are idempotent; link card = same project only.
- A status change mails each follower once per post + status (`feedback_status_mails`); the unfollow
  link is a signed URL that works without signing in.

### Client portal: frozen deliverables, 404 vs 403 (v1.42.0, BRAI-73)
- **Deliverables are frozen at publish** (`client_deliverables.content`): a published page version run
  through `DeliverableHtml` (internal links / mentions → plain text, stickies and whiteboard embeds
  dropped, `SafeHtml`), or a plan snapshot without hidden columns and `itemId`. Never drafts, never live
  content — later edits reach the client only by publishing again.
- **Approvals never carry over:** publishing the same page / plan again supersedes the current
  deliverable (its decision stays as history; the new one awaits review). Decisions on superseded /
  withdrawn deliverables are refused under a row lock (no race with a new publish).
- **404 vs 403:** unknown / disabled portal, another portal's ids, a withdrawn deliverable and a
  signed-in but **never invited** identity → 404 (signing in is open to anyone, so "not invited" must not
  confirm the portal exists to strangers). Revoked / expired membership → 403 (`ClientPortal/Denied`).
- Team comments reach the client only via "Reply to client" (`visibility = client`); internal notes stay
  internal.

### Document review: snapshots, anchors and the publish gate (v1.42.0, BRAI-80)
Plan: [`tasks/feat-document-review.md`](./tasks/feat-document-review.md).
- **Reviewers see the snapshot through the review** (`PageReviewPolicy`), not the draft — they get no
  `PagePolicy::view` on an unpublished page.
- **Stale is computed, never stored:** `review.content_hash !== $page->draftHash()`. The hash covers
  name + icon + content but **excludes `excalidrawEmbeds`** (embedded scenes change without the text
  changing; snapshots don't copy them either).
- Status is recomputed lazily on every read / decision / publish check, so a reviewer who lost project
  access drops out without hooks; a deleted user keeps `user_name` on the history.
- **Quote anchors live twice and must stay in sync:** `App\Support\PageReviews\QuoteAnchor` (PHP) and
  `components/page-review/anchors.ts`. 0 matches → outdated; several → the one whose prefix and suffix
  match, else outdated — never attach a thread to the wrong text. Saved page HTML never changes.
- **`ReviewGate` publishing rule:** with `pages.review_required`, publish / publish changes run in a
  transaction that locks the page row, re-reads it, applies the pending editor changes, and refuse unless
  an approved, open review has the current draft hash. Requests / decisions / cancels lock the same row.
  Side doors are closed too: MCP `update-page`, restoring a version, `PageController::update` name / icon
  (go to the draft), `saveExcalidraw` on non-whiteboards. Any new path that changes a published
  review-required page must go through the gate.

### GitHub App vs manual webhooks (v1.42.0, BRAI-61)
Plan + production setup: [`tasks/feat-github-app.md`](./tasks/feat-github-app.md).
- **Two modes.** Manual webhooks (`/api/webhooks/github/{uuid}`, per-project secret, `mode = webhook`)
  vs the GitHub App (`/api/webhooks/github-app`, app secret, routed by installation + repository id;
  `github_repositories.repo_id` unique = one project per repository). Each instance (prod, dev, every
  self-hosted one) needs its own app — the URLs differ.
- **Installation access check:** the setup state lives in the session (project + user); ownership is
  proven by OAuth during install + `GET /user/installations`. GitHub lists an installation for anyone who
  can read ONE of its repositories, so a project may only follow repositories the connecting person can
  read (`GET /user/installations/{id}/repositories` → `github_connections.allowed_repository_ids`).
- The GitHub App is separate from GitHub social login (`services.github`) — never widen login scopes.
- **The manifest must not set `setup_url` / `setup_on_update` together with `request_oauth_on_install`.**
  GitHub shows the create screen but refuses the conversion with HTTP 422 (hit on brainitive.app,
  2026-10-27); `callback_urls` alone brings people back to `/github/app/setup`. A refused conversion is
  logged and shown with GitHub's reason (`GithubApi::refusalReason`); `FakeGithub` / `Http::fake` don't
  enforce GitHub's rules, so check real-GitHub constraints in their docs.
  Installation tokens are cached encrypted (`github-app:installation-token:*`), never shown.
- **Merge transitions don't notify watchers.** The move is a model save (history via `ItemObserver`
  marked `ActivityLog::via('github')`, outgoing webhooks, automations), but `item_status_changed` to
  watchers is sent by `BoardController::move`, which the integration doesn't call. Each merged PR moves
  the card at most once (`meta.merge_transition`), never out of a done column.

### Calendar providers: normalized events, read-only calendars (v1.42.0, BRAI-140)
Recipe: [`tasks/feat-calendar-providers.md`](./tasks/feat-calendar-providers.md) → "How to add a provider".
- **`NormalizedEvent` all-day `end_date` is INCLUSIVE.** iCal `DTEND;VALUE=DATE` and Graph all-day
  `end` are exclusive → pass end − 1 day. MCP `list_calendar_events` also returns the inclusive last day.
- **Read-only providers / calendars:** writes go through `CalendarProviders::writable()`; a read-only
  connection throws `CalendarReadOnly` (422 / "This calendar is read-only." in MCP). Per-calendar
  read-only = `credentials.read_only` from the picker. The UI never lets read-only events be dragged.
- A failing provider throws `CalendarSyncFailed` (never returns `[]`); the page stays 200 and shows
  `sync_error`. Only Google deactivates a connection on a failed token refresh — others must not.
- Save rotated tokens / `credentials` with `forceFill()->save()` (a query-builder update bypasses the
  `encrypted` cast). User-supplied URLs (CalDAV, ICS) go through `SafeRemoteUrl` (below), never a bare
  `Http::get`.
- **Outlook (BRAI-140):** tokens refresh 60 s before expiry and once on a 401; a refused refresh keeps the
  connection ("Outlook access expired. Reconnect Outlook." on every load until reconnect — the picker
  `store` copies new tokens onto all rows of the account). 429 / 503 → friendly `CalendarSyncFailed` (one
  short retry when Retry-After ≤ 2 s). Graph ids may contain `/` → `b64.` + base64url in our routes.
  Recurring series: each calendarView instance has its own id, so an edit changes one occurrence. Logs
  carry connection id + status + Graph error code only.
- **Named throttles on calendar routes:** two routes with an unnamed `throttle:10,1` share a counter (and
  `RouteThrottleTest` fails) — give each its own key (`throttle:10,1,calendar.connections.manual.store`).

### Calendar links + CalDAV: SSRF guard, caps, recurrences (v1.42.0, BRAI-141)
Details + limits: [`tasks/feat-calendar-caldav.md`](./tasks/feat-calendar-caldav.md).
- **Every request to a user-entered calendar URL goes through `App\Support\Calendar\SafeRemoteUrl`**
  (public IPs only, pinned resolve via `OutboundUrl`; webcal → https; ports 80 / 443 + `CALENDAR_ALLOWED_PORTS`
  (default 8443, 5001, 5232); manual redirects, max 3, each hop re-checked; credentials only to the same
  origin; 5 s connect / 20 s total; 5 MB cap via a `CappedStream` sink — Guzzle's `progress` callback can't
  abort a chunked body). Transport errors become a generic `CalendarSyncFailed`: their messages carry URLs,
  and ICS URLs are secrets — never shown, never logged.
- **No private / loopback targets outside e2e.** `CALENDAR_LINKS_ALLOW_PRIVATE` works only with
  `APP_ENV=e2e` (fixture server `e2e/helpers/calendar-server.ts`); never pass `$allowPrivate = true` anywhere
  else. Self-hosted CalDAV must be reachable from the internet over https.
- **CalDAV app password:** sent only over https and only to the "site" of the entered server
  (`CaldavClient::site()` — a heuristic, not the Public Suffix List; iCloud's `pNN-caldav.icloud.com` is
  fine); an answer from another origin is refused and its hrefs never followed; multistatus XML with a
  DOCTYPE is refused; event ids are confined to the collection also after percent-decoding. 401 → "Check
  your username and app password." (`sync_error`, connection stays active).
- **No sabre/vobject** (dependency approval): our `Ics/` reader skips BYHOUR / BYMINUTE / BYSECOND,
  BYWEEKNO / BYYEARDAY, sub-daily FREQ, RANGE=THISANDFUTURE and VTIMEZONE rules (unknown zones → standard
  offset). Hard caps: 2000 occurrences per expansion, 50 000 periods per call shared by all series, 20 000
  VEVENTs, 200 000 lines / 30 000 components per file.
- **Recurring CalDAV events are expanded locally** (servers differ in `<C:expand>`, and edits need the
  master). One occurrence is edited through a RECURRENCE-ID override and deleted with an EXDATE; moving a
  whole series is refused (writing DTSTART in UTC would lose its zone). Timed writes are UTC. Floating
  times are keyed by wall clock (no `Z`), so ids match in every viewer zone.
- **ICS:** each link is its own connection (`sha1(url)`, URL only in encrypted `credentials`, UI shows the
  host only; change = remove + add). Cached 15 min with ETag / If-Modified-Since, but a link still failing
  after 15 min raises `CalendarSyncFailed` — the cache never hides it. Several one-off VEVENTs with one UID
  are all shown (DTSTART as occurrence key).

### Online meetings: conferencing is optional on the contract (v1.42.0, BRAI-147)
- `conferenceProviders()` + `createEvent(conference, attendees)`; normalized events carry `conference_url`
  (https only) / `conference_provider` only when there is a call — `NormalizedEvent::KEYS` is unchanged,
  so read them with `?? null`.
- **Google** ignores `conferenceData` without `?conferenceDataVersion=1`; Meet creation can come back
  `pending` (read once more). Deletes send `sendUpdates=all` (guests get the cancellation, as with Outlook).
- **Teams only for work / school accounts:** Graph offers it only when the calendar's
  `allowedOnlineMeetingProviders` has `teamsForBusiness`; personal Outlook.com accounts usually don't →
  "Teams isn't available for this account." `Calendars.ReadWrite` is enough (no `OnlineMeetings.*`).
- Meeting rows aren't soft-deleted; cancel goes through the organiser's own calendar only, others can only
  unlink. Attendee addresses are shown only to the organiser (the notes doc lists member names + "+N
  guests"); members are sent by id and turned into addresses server-side. An issue moved to another
  project keeps its meetings (`meetings.project_id` = where it was scheduled). The notes doc is created
  once (row lock); an unpublished one gives others a 403, never a second doc.
- **One host for the issue's Schedule-meeting dialog (v1.42.1):** `schedulingMeeting` lives in the shared
  `useItemDetail` state, so the "Meeting" quick action and the sidebar open the same dialog. `ItemDetailSidebar`
  is rendered twice on boards / full issue pages (always-mounted sidebar + phone slide-out copy); only the
  always-mounted one hosts the dialog — the sheet copy gets `inSheet` (`scheduleOpen={!inSheet && …}`).
  Without it the dialog opens twice. Same rule for any dialog whose open state moves into `useItemDetail`.

### Calendar feed: stateless, deterministic, throttled per feed (v1.42.0, BRAI-142)
- `/calendar/feed/{token}.ics` is in `web.php` but excludes the session / cookie / CSRF / Inertia
  middleware with `withoutMiddleware` (no `Set-Cookie`; calendar apps send none). Use the same pattern
  for any public route that must not touch the session.
- **Throttle:** per IP is loose (`throttle:600,1`) because Google / Outlook fetch every subscriber from a
  few shared IPs; the real cap is per feed (`CalendarFeedController::FETCHES_PER_MINUTE` = 30, keyed on
  the feed id), which also limits a leaked token.
- **Bodies must stay deterministic** (`DTSTAMP` = `updated_at`, never `now()`), or the ETag changes on
  every fetch and apps re-download everything. `Last-Modified` = `content_changed_at`, so removals and
  lost access count as changes. Google ignores the ICS `URL` property — the link is the last line of
  `DESCRIPTION`.

### Smart links: plain anchors, derived from published content only (v1.42.0, BRAI-143)
- **Saved as a normal `<a>`** with `data-smart-link="<ref>"` + `data-smart-link-view`, so share pages,
  the client portal, MCP, exports and search see a plain link; only the Lexical editor with the
  `smartLinks` prop turns it into a chip / card (comments, tickets, public pages render a link). `SafeHtml`
  allows exactly these two `data-*` attributes on `a`, nothing else.
- **Refs are ids, not URLs:** `item:<id>`, `page:<uuid>`, `ticket:<id>`, `plan:<id>`; a pasted plan URL gives
  `plan:<project-slug>/<plan-slug>`, the endpoint answers with the canonical `plan:<id>` and the editor
  rewrites it.
- **Visibility mirrors ContentReferences:** what exists but the viewer can't open answers `restricted`,
  everything else `not_found` — never a title. Cross-project links render but make no reference.
- **Backlinks derive from published content only.** `Page::saved` syncs a doc's smart links from `content`
  (the published version, or the doc itself before its first publish) — autosaved `draft_content` doesn't
  count until published. Issues sync from `description` in `Item::saved`. Same project only; removing the
  link prunes the derived row.
- The quick-create dialog (`GlobalCreateModal`, `RichLexicalEditor.tsx`) is a separate small editor: a
  pasted URL stays a plain link there.
- **Side "+" block menu (fixed with BRAI-143):** the handle portal unmounted when the pointer left the
  handle for the popover (`onMouseLeave` cleared `hoveredKey`). Keep the handle + popover mounted while the
  menu is open (`RichLexicalEditorFull.tsx`).
- **Docs open full width by default (v1.42.0):** `Pages/Pages/Show.tsx` reads localStorage
  `page-full-width-<uuid>`; only an explicit `'false'` ("Exit full width") narrows it (e2e
  `document-width.spec.ts`).

### Page icons are images too (v1.41.2)
An uploaded page icon is stored as a long `data:` URL. Always render page icons through
`components/page/PageIcon.tsx` (`PageIcon` / `isImageIcon`), never as `{page.icon}` text — the workspace
home and project page printed the base64 across the layout (e2e `page-icons.spec.ts`).

### Search index: hooks touch keys, flush re-reads (v1.43.0, BRAI-55)
- **Hooks only touch `(type, id)`;** `SearchIndexer::flush()` reads the record again and writes what is true
  then (a document, or a removal when it is gone / trashed / hidden). Flushes happen after the response, after a
  queued job, after commit in commands / seeders / tests, and **before every search** (a request finds its own
  writes). Errors are reported, never thrown.
- **Writes that bypass models must touch the indexer.** Query-builder bulk updates / raw SQL skip the observer:
  call `app(SearchIndexer::class)->touch($type, $ids)` (or `touchItems` / `touchTickets` / `touchBoard` /
  `touchHelpdesk` / `touchWhere`) in the same code path; for one-off data fixes run
  `php artisan search:reindex --type=…`. Item type names are not indexed.
- **Bump `SearchReindex::VERSION` whenever a source writes something new** (new field, new type, changed
  rule) — `search:ensure` (every 5 min + Docker entrypoint) sees the unbuilt version and rebuilds; nobody
  runs anything by hand on deploy.
- **Visibility lives in SQL** (`TableSearchEngine::visibleEntries`) and must mirror the view policies (like
  ContentReferences): counts and pages use the same builder, there is no post-filtering. Drafts are visible
  only to the author and project admins; never index `draft_content` (docs index the published version).
- **Extensions are optional.** `pg_trgm` + `unaccent` are created by the migration only when the DB user may
  (savepoint — a privilege error never aborts it); without them search works without typo tolerance /
  accent folding, and `search_unaccent()` returns the input unchanged. A superuser can create them later,
  then `php artisan search:reindex --setup`. SQLite installs use `BasicSearchEngine` (LIKE).
- **Queries never reach tsquery syntax raw:** `QueryParser` keeps letters / digits only, drops modifier
  letters (`\p{Lm}\p{Sk}`) that `unaccent` would turn into `'` / `:`; a database error on a query becomes an
  empty result (reported), never a 500. Highlights use private-use markers U+E000 / U+E001, stripped from
  indexed text, escaped server-side and filtered again by `onlyMarks()`.
- **Capture tokens search titles only** (`ext.search`: `ts_filter(tsv, '{a}')`, no comments) — CONVENTIONS →
  API tokens.
- `DB::table()->whereKey()` is a dynamic where on a `key` column, not the primary key (found writing the
  search tests).

### Offline mode: per-field conflicts, idempotency, session-free shell (v1.43.0, BRAI-82)
- **sw.js never `respondWith`s documents or live routes.** `public/sw.js` handles only same-origin GET
  navigations, full Inertia visits (not partial reloads / polls / prefetches) and `/build/assets/*`; documents,
  realtime / co-editing, JSON and every write get no `respondWith`, i.e. behave as without a worker. A feature
  that needs caching adds its own explicit branch there. Because a fetch handler exists, Playwright
  `page.route` misses requests of controlled pages — use `test.use({ serviceWorkers: 'block' })`.
- **The shell is session-free:** `offline.shell` runs `withoutMiddleware` (`EncryptCookies`, `StartSession`,
  `ShareErrorsFromSession`, `ValidateCsrfToken`) + `Inertia::flushShared()`, so the cached HTML holds no
  account, workspaces or counters (not even a remember-me sign-in). Never cache a page rendered with the
  session or shared props.
- **Conflicts are per field, three-way** (`FieldMerge`): a field the device changed is written only if the
  server value still equals the device's base (or already equals mine); otherwise `{base, mine, theirs}` →
  409, with the non-conflicting fields of the same mutation applied (`applied`). Untouched fields are never
  written; `updated_at` is informational. "Keep mine" is a new mutation based on *theirs*, so a third edit
  meanwhile is a new conflict, never an overwrite. Reorder has no conflict check. Conflicts are never
  resolved on the server.
- **Idempotent per (user, uuid)** in `offline_mutations`: the row is inserted first inside the transaction
  (a concurrent retry waits on the unique index, then replays the stored answer); same uuid + different
  payload → 422. Only 200 / 409 are stored; 410 / 424 / 403 / 422 wrote nothing. Offline creates are
  referenced by uuid (`target.ref`), resolved only against the same user's rows: not there yet → 424 (retry),
  failed → 410. Deleted *or no longer visible* → 410 either way (no probing). Side effects (watchers,
  notifications) run in `DB::afterCommit`, so a replay tells nobody twice.
- **Appliers load the whole parent chain** (`board.project.workspace`, `taskList.project.workspace`) and answer
  410 when a link is missing or trashed — policies type-hint non-null `Project` / `Workspace`.
- **`validated()` regroups wildcard keys** (`items.*.id` / `items.*.ref` came back out of order): `ksort`
  before relying on the index.
- **Data is cleared on sign-out or another account:** an explicit sign-out (Inertia `before` on
  `POST /logout`, confirm when changes are waiting), turning the setting off, deleting the account, or
  another account signing in on the device deletes `brainitive-offline-<userId>` and the shell cache. An
  *expired* session is not a sign-out (the same person signs in and it syncs). Every read / write checks the
  device owner (`brainitive-offline:user`) and a `storage` listener drops other tabs' state; a sync with a
  `user_id` ≠ the session user gets 401 `wrong_account` and is never applied.
- **Outbox writes count when committed** (IndexedDB `transaction.oncomplete`); actions queue the change
  before touching the local copy. Coalescing never folds into a change already sent (`enqueue` and the
  engine's `takeNext` share one Web Lock across tabs).
- Limits: only what was opened online (My Day, 20 lists, 50 issues); issue description read-only offline;
  Safari evicts IndexedDB after ~7 days unused; Firefox < 126 can't list databases (the `localStorage`
  account list covers clearing).

### Live channels: no content in payloads, publishing never fails a request (v1.44.0, BRAI-151/152)
- **Payloads tell clients what to refetch, never what changed.** A message reaches every socket on the
  channel — including one whose access was revoked < 60 s ago (the server re-checks once a minute). So
  `Live::publish()` refuses any key outside `Live::KEY` (ids, counts, revisions, `*_at`, status / type keys,
  `fields`); clients refetch through the normal policy-checked endpoints. A new key is a conscious change of
  `Live::KEY` + a test. Presence carries id / name / avatar only; signals carry `{}` or a field name.
- **Publishing is after commit, once per request, and never throws.** A rolled-back change publishes nothing;
  messages go out in one POST after the response. After a failed POST publishing **pauses 15 s** (cache
  flag) — so in e2e the live server must be up before anything publishes: `e2e/global-setup.ts` starts it
  **before seeding** (seeding publishes cards / notifications; a refused publish paused live for the first
  journeys). In this sandbox the e2e app's very first publish sometimes still fails (cURL 7): live specs run
  alone may then time out — run them together with other specs.
- **`LIVE_PUBLISH_URL` is the realtime server's own address** (prod `http://127.0.0.1:6201`, dev `:6202`,
  Docker `http://realtime:1234`), never the public URL: the publish endpoint refuses proxied requests
  (`X-Forwarded-*` / `Forwarded` / `X-Real-IP`) and non-loopback peers (`LIVE_PUBLISH_FROM=private` for
  Docker). Without it presence + typing still work and the bell polls every 30 s. Restart the realtime
  service after a deploy that changes `realtime/`.
- **Writes that bypass model events must touch `BoardLive`** (query-builder updates, pivot syncs, mass
  deletes): `BoardLive::itemIds()` / `columnsReordered()` / `itemsLeft()`, or open boards miss the change.
  Inside `ImportScope` nothing is published per record — one `board.refreshed` per board at the end.
- **Echo suppression = `X-Live-Client` + actor.** A tab ignores an event only when `client_id` **and**
  `actor_id` match its own (another member reusing a tab id can't hide changes).
- **Events during a disconnect are lost** (no replay): every subscriber refetches in `onResync`.
- **Tests:** `phpunit.xml` forces `LIVE_ENABLED=false` (a developer's `COLLAB_URL` never publishes from other
  tests). Publishing tests use `Http::fake` + `defer()->invoke()`; avoid `withoutDefer()` for `BoardLive` (it
  defeats the per-request coalescing being tested).
- **e2e realtime ports** (each spec that stops / restarts its server owns a port; the app learns it per
  request from `X-E2E-Collab-Port`, honoured only with `APP_ENV=e2e`): `COLLAB.port` (E2E_PORT + 100)
  live-docs, `LIVE.port` (E2E_PORT + 101) the global live-channel server (opt in with `X-E2E-Live: 1`),
  `COLLAB.port + 3` live whiteboards, `COLLAB.port + 4` live issue descriptions. New live specs pick the
  next free offset.

### Live whiteboards + live issue descriptions: epochs, saves, side effects (v1.44.0, BRAI-153/151)
- **Any content write outside a live save starts a new epoch.** Whiteboards: `Page::saved` with `content`
  changed without `collabWrite` (single-user / fallback save, version restore, MCP, duplicate) → epoch + 1,
  live browsers get "This whiteboard was replaced … Load latest". Descriptions: `Item::updated` with the
  description changed without `$item->collabWrite` (single-user save, MCP, checkbox toggle in view mode,
  automations) → epoch + 1, live editors reopen on the new text. A fallback save always wins.
- **Live description saves are normal item updates** (`$item->update` + `ItemMentions::notify` with the
  previous text): history, webhooks, automations, smart links, search all run — so `item.updated` webhooks /
  rules fire once per live save (every few seconds while people type). Only the history collapses
  description-only saves of one person within 10 min. `itemDetails` returns `title` + `description` so
  viewers follow via refetch (~2–4 s), not keystroke by keystroke.
- **Whiteboard checkpoints live:** only the 30-min interval + explicit Save / Save & exit (the "someone else
  saved last" rule would create a version per save with two people drawing). Deleted elements stay as
  tombstones until the next epoch. Live images come from `/storage/…` on this site only — with the public
  disk on S3 they appear after a reload.
- Offline Y copies (`brainitive-whiteboards-u…`, `item-<id>`) are per user and cleared on sign-out
  (`clearAllOfflineData` → `forgetLiveWhiteboardCopies`).

### Notification inbox: live merges check the view they were made for (v1.44.0, BRAI-151)
`useNotificationFeed` (`components/notifications/useNotifications.ts`) keys every reload / merge by its
view (`filter|archived`): an answer for another view is dropped, the archive never merges live arrivals, and
a merge while a load is running retries 500 ms later instead of reloading. Before the fix a merge started in
the inbox could land after switching to "Show archived" and replace the archive with inbox rows. Live merges
also wait for read / archive actions in flight (a page older than the action is asked again).

### Web push: expired devices are flagged, delivered ≠ shown (v1.44.0, BRAI-154)
- **404 / 410 from the push service flags the row** (`push_subscriptions.expired_at`) instead of deleting it
  (the package's `ReportHandler` deleted silently; ours is `PushReportHandler`): it stays in the device list
  with the reason, gets no more pushes (`User::routeNotificationForWebPush()`), and is pruned after 30 days.
  Re-registering the same endpoint clears the flag; "Register this device" subscribes fresh.
- **A test that says *delivered* only means the push service accepted it.** If nothing shows, the OS /
  browser hides it (Windows: Chrome not running / "Continue running background apps" off, Focus; macOS:
  System Settings → Notifications; iPhone: Home Screen app only, iOS 16.4+).
- **One client:** `WebPushClient::make()` for real pushes and the test — never build a `WebPush` elsewhere.
  `ChannelManager` keeps the `WebPushChannel` (and its client) once built: tests that change
  `webpush.client_options` between notifications must queue all mock answers up front; queue workers need
  a restart after VAPID changes.
- **e2e:** the local server has a test-only VAPID pair (`e2e/env.ts`) and `WEBPUSH_FAKE` (`FakePushService`,
  `APP_ENV=e2e` only; endpoint `e2e-expired` → 410, `e2e-rejected` → 403). Headless Chromium reports
  notification permission `denied`, so specs stub `Notification` / `PushManager` (`push-devices.spec.ts`).

### Branded email: secured encoding, inlined CSS, logos (v1.44.0, BRAI-155)
- **`Markdown::withSecuredEncoding()` is on** (`BrandedMail::register()`): every `{{ }}` echo in mail templates
  escapes `[ ] < > \`, so user text can't form Markdown links / images / HTML. Never put a Markdown link in a
  line — use `->action()` or `->footerLink()`. Quoted user text goes through `->quote()` (`mail::quote`:
  escaped, `nl2br`, never parsed — a code fence could otherwise close the panel).
- **HTML blocks in mail Markdown end at a blank line:** keep raw HTML (billing message) on lines without
  blank lines (nl2br).
- **CssToInlineStyles drops `@media` rules from the theme:** dark-mode / phone rules live in the layout's
  `<style>` with `!important`, not in `themes/brainitive.css`.
- **`InstanceMailConfig::apply()` rebuilds the mailer:** with the array mailer in tests, messages sent before
  it ran are gone.
- **"Laravel" never reaches a mail:** templates say "Brainitive"; `config('app.name')` only shows as footer
  context when it isn't a default, and `PublicOperator::platformName()` falls back to "Brainitive". Set
  `MAIL_FROM_NAME=Brainitive` in production (env / admin settings).
- **Email logos** (`public/images/email/logo.png`, `logo-dark.png`) must be RGBA PNGs: the first 256-colour
  versions had no tRNS chunk and transparent pixels rendered black. Keep explicit width / height (220 px).

### Legal & privacy: one config, customer pages, no third-party loads (v1.44.0, BRAI-156)
- **Cloud vs self-hosted:** `config/legal.php` is read only through `LegalInfo`. Cloud = `LEGAL_CLOUD` or an
  APP_URL host in `LegalInfo::CLOUD_HOSTS`; the operator's own facts are `CLOUD_DEFAULTS` — never config
  defaults, so a self-hosted install can't show them. Self-hosted: `/legal/dpa`, `/legal/subprocessors`,
  `/refund` 404; `/imprint` 404 until an operator field or `LEGAL_IMPRINT_URL` is set. e2e runs as Cloud
  (`LEGAL_CLOUD=true` in `e2e/env.ts`).
- **Customers' public pages carry `operator`** (`PublicOperator::for($workspace)`, https URLs only, re-checked
  on read). The workspace name is a fallback **only** with `nameFallback: true` (support page / widget / lead
  forms, which always showed the organisation); token links (share links, plans, changelogs, portals, ticket
  links) stay generic so they never reveal internal workspace names. Visitor mails append
  `PublicOperator::mailLine()` (plain, Markdown-safe). A new public page for a workspace's visitors passes
  `operator` and adds its path prefix to `Analytics::EXCLUDED_PREFIXES` (Umami never runs there).
- **Lead consent is proof, not a field:** the server renders the consent text, enforces "required" (422
  `consent`) and stores exactly that text + `consent_at`; `consent: true` without a checkbox stores nothing.
  The lead-form visit id is memory-only (no sessionStorage, § 25 TDDDG) — a reload counts as a new visit.
- **No third-party requests from our pages:** fonts are self-hosted (`resources/fonts/`, @fontsource);
  Excalidraw fonts need `window.EXCALIDRAW_ASSET_PATH` (`app.blade.php` + `excalidraw-editor.blade.php`) —
  without it Excalidraw silently loads them from esm.sh. Embeds render in `ExternalEmbedGate`.
  `LegalPagesTest` greps the sources for known CDN hosts.
- **Self-service account deletion uses `UserDeletion`:** `$user->delete()`'s FK cascades wiped other people's
  projects / boards / items. `ProfileController::destroy` refuses while the user owns a workspace with other
  members, then hands their content in other workspaces to those owners.

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
- **One folder, lowercase: `resources/js/components/` (v1.40.1).** git used to track the old Breeze
  files under `Components/` and everything else under `components/`. A fresh clone on macOS (case-
  insensitive) merged both into one folder named `Components`, and a **Docker build from that Mac
  checkout** failed with hundreds of `Cannot find module '@/components/…'` (Linux is case-sensitive;
  CI never saw it because a Linux checkout keeps both). Now git tracks only `components/`, imports use
  `@/components/…`, the Dockerfile renames a `Components` folder before `npm run build`, and
  `ImportPathCaseTest` fails if two tracked folders differ only in case.
  - An existing Mac checkout keeps its on-disk name `Components` (git doesn't rename folders on a
    case-insensitive disk). That's harmless locally; to match git:
    `mv resources/js/Components resources/js/components_ && mv resources/js/components_ resources/js/components`.
  - Adding files: stage **one explicit lowercase path** and check `git ls-files`. A file in a new
    sub-folder can still land as `Components/…` on a Mac whose folder is named `Components`; fix with
    `git rm --cached <Components path>` + `git update-index --add --cacheinfo 100644,$(git hash-object -w <file>),resources/js/components/…`.
    Never combine `core.ignorecase=false` with `add -A` / `add .` (stages the whole on-disk folder as duplicates).
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
