# Architecture — Module Map

**Purpose:** let an agent locate the right files for a task *without* scanning the codebase.
For each module: what it does + the files to open first. For coding rules see
[`CONVENTIONS.md`](./CONVENTIONS.md).

> Keep this current. When you add/rename a module or move its entry points, update the
> matching row here as the last step of your task (see [`WORKFLOW.md`](./WORKFLOW.md)).

---

## System shape

```
Browser ──HTTP──▶ routes/web.php ──▶ Controller ──▶ Inertia::render('Page', props)
                                          │
                                          ├─ Policy (authorize)        app/Policies/
                                          ├─ Eloquent models           app/Models/
                                          └─ Notification (queued)     app/Notifications/
                  ▲
React page  ◀─────┘  resources/js/Pages/**  (props are typed in types/index.d.ts)

AI client ──OAuth2.1/Bearer──▶ routes/ai.php  ──▶ BrainitiveServer ──▶ app/Mcp/Tools/*
CLI       ──Bearer token────▶ routes/api.php  ──▶ CliController
Browser extension ──OAuth2.1 (capture scope)──▶ routes/api.php /api/ext/v1 ──▶ Extension\*Controller
External site ──▶ routes/web.php (cors, no CSRF) ──▶ Helpdesk widget/portal
Visitor / iframe ──▶ routes/web.php /f/{slug} (no CSRF) ──▶ PublicLeadFormController
Other tools ──Bearer inbound token──▶ routes/api.php /api/lead-forms/{uuid}/inbound
Postmark / Mailgun ──token / HMAC──▶ routes/api.php /api/inbound-mail/{postmark|mailgun} ──▶ InboundMail::receive()
IMAP mailboxes ◀──app:poll-helpdesk-mailboxes (every minute)── MailboxPoller ──▶ InboundMail::receive()
```

- **No API layer for the app itself.** Inertia passes controller props straight to React.
- **Three route entry points:** `web.php` (the app, Inertia), `ai.php` (MCP server),
  `api.php` (CLI + browser extension, Passport bearer). Plus `auth.php` (Breeze + social) and `console.php`.
- **Request bootstrap / global middleware:** `bootstrap/app.php` — registers
  `HandleInertiaRequests`, the `cors` alias, and CSRF exemptions for `widget/*` + `portal/*`.

## Authentication & authorization (read before touching auth)

| Concern | Where | Notes |
|---|---|---|
| Web login (session) | Laravel **Breeze**, `routes/auth.php`, `app/Http/Controllers/Auth/` | Email verification required |
| Social login | `Auth/GoogleAuthController.php`, `Auth/GitHubAuthController.php` | Laravel **Socialite** |
| API/MCP/CLI tokens | Laravel **Passport** (OAuth 2.1 + bearer) | Configured in `AppServiceProvider::boot()` (token TTLs, scopes `mcp:use` + `capture`) |
| Token scopes | `capture` = browser extension, only `/api/ext/v1` (`CheckToken::using('capture')`) | `RejectCaptureTokens` keeps capture tokens off `/mcp` and `/api/cli/*` |
| Policy registration | **`app/Providers/AppServiceProvider.php`** | Policies are **explicitly** `Gate::policy(...)`-registered, *not* auto-discovered |
| Instance admin | `InstanceAdmin` model, `instance.admin` middleware, `/admin/*` | **No content access** — runs the instance from the admin console (see below). Suspended users are signed out by `EnsureUserIsNotSuspended` (web group) |
| Global Inertia props | `app/Http/Middleware/HandleInertiaRequests.php` | `auth.user`, `flash`, `workspaces`, `currentWorkspace`, `currentPageUuid` |

> Sanctum is still installed but **Passport** is the token mechanism for MCP + CLI. See
> [`KNOWLEDGE.md`](./KNOWLEDGE.md).

---

## Feature modules

Data spine: `Workspace → Project → {Board, Page, TaskList, Helpdesk}`, plus workspace-level
`Bookmark` and `SnippetCollection`, plus per-user `CalendarConnection`.

### Core — Workspaces, Projects, Members
- **Models:** `Workspace`, `Project`, `WorkspaceInvitation`, `User` (pivot roles)
- **Controllers:** `WorkspaceController`, `ProjectController`, `InvitationController`, `OnboardingController`
- **Policies:** `WorkspacePolicy`, `ProjectPolicy`
- **Routes:** `Route::resource('workspaces')` + `workspaces/{workspace}/...`, `projects/{project:slug}/...` groups in `web.php`
- **Pages:** `Pages/Workspaces/`, `Pages/Projects/`, `Pages/Invitations/`
- Projects also own **Labels** and **Item Types** (configured via `ProjectController`).
- **Project templates (BRAI-72):** `app/Support/Templates/` — `TemplateDocument` (versioned JSON format with local refs + day offsets: `normalize()` validates / repairs / upgrades, `only()` drops sections, `summary()`), `TemplateCapture` (project → document, sanitized via `ContentSanitizer`), `TemplateInstantiator` (document → new project in one transaction), `BuiltinTemplates` (registry: name, category, icon, tags per key + `CATEGORIES`; the 20 documents live in `Support/Templates/Builtin/{Software,Marketing,Business,Personal}Templates.php` with the `Blocks` helpers), `TemplateRuns` (catalog for the create page, resolve `builtin:<key>` / `template:<uuid>`, idempotent runs). Models `ProjectTemplate` (workspace, uuid-bound) → immutable `ProjectTemplateVersion` (`document`, `summary`); `ProjectTemplateRun` (`request_id` unique). `ProjectController@store` takes `template`, `start_date`, `include[]`, `request_id`; `ProjectTemplateController` (`projects.templates.preview|store`, `project-templates.destroy`), `ProjectTemplatePolicy` (use = createProjects, save/delete = workspace admin). UI: the Jira-style gallery `components/projects/TemplateGallery.tsx` (categories, search, detail view, name step) in `CreateProjectDialog.tsx` — mounted once in `AppLayout`, opened with `openCreateProject()` from every "New project" button and the command palette; catalog JSON `project-templates.catalog` — and on `Pages/Projects/Create.tsx` (direct links); `components/projects/{TemplateSummary,SaveAsTemplateCard}.tsx` (settings → General).
- **Moving a project to another workspace:** `App\Support\Projects\ProjectTransfer` (`preview` / `move`), `ProjectController::movePreview` / `move` (`projects.move.*`), UI `components/projects/MoveProjectCard.tsx` (Project settings → Danger zone). Only `projects.workspace_id` changes; everything else hangs off the project. Admin of both workspaces; members not in the target are detached; links to issues outside the project are removed; the key must be free in the target, and a taken slug gets `-2`.

### Kanban — Boards, Columns, Items
- **Models:** `Board`, `BoardColumn`, `Item`, `ItemType`, `ItemPriority`, `ItemLink`, `Label`, `Comment`, `Attachment`
- **Controller:** `BoardController` (fat — boards, columns, items, reorder, move, watch, links, comments, attachments, `itemDetails` JSON, `logWork`)
- **History:** `App\Observers\ItemObserver` (`#[ObservedBy]` on `Item`) writes `activity_logs` for every model change; the controller logs labels / comments / links / attachments / work logs. Read via `Item::activities()` → `boards.items.details`.
- **Payload:** board + full-item pages load card data only (`withCardData()`: counts instead of comments/attachments); `ItemDetail` fetches comments, attachments and history from `boards.items.details` when an item opens.
- **Scrum (opt-in, `boards.type = scrum`):** `Sprint` (planned → active → completed, one active per board), `items.sprint_id` (null = backlog) + `items.backlog_position`. `SprintController` → `Pages/Boards/Backlog` (`boards.backlog`, rank via `boards.backlog.rank`) and `Pages/Boards/Reports` (`boards.reports`, numbers from `App\Support\Boards\SprintReport`). Scrum boards show only the active sprint (`ActiveSprintBar`); shared header/tabs in `components/board/BoardHeader.tsx`.
- **Board UI extras:** WIP limit per column (soft, header `n/limit`), quick filters + all filters mirrored in the URL (`?q=&assignee=&status=&labels=&priority=&type=&quick=`), time tracking (`original_estimate` / `time_spent` in minutes, Jira durations: 1d = 8h, 1w = 5d).
- **Bulk edit (BRAI-108):** list view multi-select (`ListView` in Boards/Show: row checkboxes, shift-click range, select all visible, Esc clears) + sticky `components/board/BulkEditBar.tsx` (status, assignee, priority, type, sprint, due date, labels add/remove, delete with confirmation). Server: `BoardBulkItemController` (`boards.items.bulk.update` / `.destroy`, ≤ 200 ids of this board) → `Support/Boards/BulkItemEdit`. Tests: `tests/Feature/Boards/BulkItemEditTest.php`, e2e `e2e/tests/board-bulk-edit.spec.ts`.
- **Policies:** `BoardPolicy`, `ItemPolicy`
- **Routes:** `boards/{board:slug}/...` under `projects/{project}` in `web.php`
- **Pages:** `Pages/Boards/`, `Pages/Items/`
- Items have a parent/subtask relationship (see `tasks/2026-02-26-subtask-parent-implementation.md`).

### Pages — wiki / docs / whiteboard
- **Models:** `Page` (self-referential tree, `uuid`-bound; workflow methods `saveDraft` / `publish` / `publishChanges` / `discardDraft` / `unpublish` / `restoreVersion`; `visibleTo($user)` scope), `PageVersion` (immutable snapshot per publish), `PageStickyNote` (per-user, per-URL)
- **Policy:** `PagePolicy` — view (unpublished = author + admins; folders always), create/update (workspace admin, project admin/editor), delete (admins or author)
- **Controllers:** `PageController` (CRUD, tree, reorder, draft autosave / discard, publish / publish-changes, unpublish, archive, duplicate, presence heartbeat, excalidraw save + conflict guard), `PageVersionController` (history list / show / restore), `WhiteboardController` (issue search / refresh for issue cards, stickies → issues), `PageStickyNoteController`
- **Routes:** `pages/...` under `projects/{project}` (`pages.draft.save|discard`, `pages.publish-changes`, `pages.presence`, `pages.versions.*`); sticky notes under `/api/page-sticky-notes`
- **Workflow:** Confluence-style draft → publish → versions; see [`CONVENTIONS.md`](./CONVENTIONS.md#page-workflow-draft--publish--versions)
- **Pages (UI):** `Pages/Pages/`
- **Editors:** Editor.js / Tiptap / Lexical / Excalidraw — all in `resources/js/components/editor/`; content stored as JSON in `content`. Whiteboard pages use `excalidraw-editor.blade.php` (autosave, conflict banner, template picker, issues modal) + the `excalidraw-build/` bundle (`src/main.jsx` API, `src/templates.js`, `src/issueCards.js`); read-only canvas = `components/page/ExcalidrawViewMode.tsx`. See [`CONVENTIONS.md`](./CONVENTIONS.md#rich-text-editors--excalidraw).

### Plans — Tom's Planner–style Gantt
- **Models:** `Plan` (`slug`-bound, several per project; `data` = rows/bars/dependencies JSON, `settings` = zoom/columns, `edit_revision`, `share_token`; `saveDocument` / `restoreVersion` / `recordVersion`), `PlanVersion` (checkpoints)
- **Support:** `app/Support/Plans/PlanDocument` (validation rules + normalisation), `PlanTemplates` (starting points built from the current week), `PlanIssueSync` (rows linked to issues: project scoping, write-through); `app/Support/Boards/ProjectIssues` (issue query / summary / progress / bulk create, shared with whiteboard issue cards)
- **Policy:** `PlanPolicy` — same as task lists (members view, editors edit, admins delete)
- **Controllers:** `PlanController` (store, show, update, save + 409 guard + issue write-through, document, duplicate, destroy, share/unshare, versions/restore, issues search / create), `PublicPlanController` (`/share/plans/{token}`)
- **Routes:** `plans/...` under `projects/{project}` (`plans.*`), public `plans.public`
- **Pages (UI):** `Pages/Plans/Show.tsx` (editor, print view on `?print=1`), `Pages/Plans/Public.tsx`; editor in `components/plan/` (`PlanChart`, `usePlanEditor`, `ops`, `dates`, `csv`, dialogs)

### Task Lists
- **Models:** `TaskList` (`slug`-bound), `TaskListItem`
- **Controller:** `TaskListController` (also exposed via CLI — see CLI module)
- **Policy:** `TaskListPolicy`
- **Routes:** `task-lists/...` under `projects/{project}`
- **Pages:** `Pages/Lists/Show.tsx` (Todoist-style list, BRAI-89) built from `components/lists/`:
  `TaskRow`, `TaskComposer` (quick add), `TaskDetailSheet` (side panel, saves on blur),
  `TaskPickers` (date / assignee / repeat / reminder popovers), `taskDates.ts` (labels, tones,
  `nextOccurrence()` mirroring `TaskListItem::nextDueDate()`)
- **Reminders:** `TaskListItem::reminderRecipient()` (assignee, else creator), `dueAt()` /
  `remindAt()` (due time or 09:00, minus `reminder_minutes` ∈ `REMINDER_OPTIONS`), sent by
  `SendItemReminders`. Morning summary: `SendTaskDigest` + `TaskDigestNotification` (once per
  user per local day from 08:00, due today + overdue, off via `users.task_digest`)
- **User settings:** `PATCH profile.preferences` (`timezone` — synced from the browser by
  `hooks/useTimezoneSync.ts` in `AppLayout`; `task_digest` — `Profile/Partials/TaskDigestSetting`)

### Helpdesk / Ticketing
- **Models:** `Helpdesk`, `HelpdeskColumn`, `HelpdeskField`, `Ticket`, `TicketReply`, `TicketContact`, `TicketFieldValue`, `TicketAttachment`
- **Controllers:** `HelpdeskController` (board/columns/fields config), `TicketController` (tickets, replies, attachments, custom field values), `HelpdeskWidgetController` (embeddable widget)
- **Policy:** `HelpdeskPolicy`
- **Routes:** authed `helpdesks/...` + `tickets/...` under `projects/{project}`; **public** `widget/*` and `portal/*` (cors, CSRF-exempt) for the embedded widget and customer portal
- **Pages:** `Pages/Helpdesks/`, `Pages/Tickets/`, `Pages/Widgets/`
- Tickets support custom fields (`HelpdeskField` → `TicketFieldValue`) and external contacts (`TicketContact`, can be notified by email but not web push).
- **Helpdesk templates (BRAI-92):** `app/Support/Helpdesks/HelpdeskTemplates` (17 templates in 5 categories: columns, typed form fields, form texts) applied by `HelpdeskController@store` (`template`), catalog JSON `helpdesk-templates.index`, picker `components/helpdesk/HelpdeskTemplatePicker.tsx` in both "New helpdesk" forms. Form texts + subject field + after-submit behaviour live in `helpdesks.settings`, read by `app/Support/Helpdesks/HelpdeskForm` (also the server-side rules for public submissions), edited in `components/helpdesk/HelpdeskFormTextsCard.tsx` (settings → General).
- **Agents create tickets** in `components/helpdesk/NewTicketDialog.tsx` → `TicketController::store`: customer email required, and the public form's required rules (`settings.default_field_*`, required `HelpdeskField`s) apply.
- **Resolving** = moving into an `is_done_column` column: the board asks for a closing message (`components/helpdesk/ResolveTicketDialog.tsx`); `TicketController::move` saves it as a reply and sends `TicketResolvedNotification` to the contact.
- **Ticket links** (`tickets.show`) render `Helpdesks/Show` with `openTicketId`; there is no separate ticket page.
- **Forwarding address (BRAI-124, v1.40.0):** `helpdesks.forwarding_local_part` (unique, `$hidden`) + `config('services.inbound_mail.domain')` = `<slug>-<10 random>@domain`, managed by `app/Support/Helpdesks/Inbound/ForwardingAddress` (`for()` creates on first use, `regenerate()`, `resolve($recipient)` ignores `+tag`, `providerEnabled()`). Webhooks `InboundMailController` (`inbound-mail.postmark` basic-auth/`?token=`, `inbound-mail.mailgun` HMAC + replay cache; 404 until configured) → `InboundPayloads::postmark|mailgun` (payload → `InboundMessage`, attachments via `AttachmentWriter` into `InboundTempFiles` = `storage/app/inbound-mail/<uuid>/`) → queued `App\Jobs\ProcessInboundMail` → `InboundMail::receive()`, then deletes the temp folder. Settings JSON `HelpdeskForwardingController` (`helpdesks.forwarding.show|regenerate`, `update` policy) for `components/helpdesk/email/ForwardingAddressSection.tsx` in the Email tab. Fixtures `tests/Fixtures/inbound/`; tests `InboundMailWebhookTest`, `HelpdeskForwardingAddressTest`, e2e `helpdesk-forwarding.spec.ts`. Self-hosting setup: `docs/self-hosting.md` → "Email to helpdesk".
- **Inbound pipeline + connected IMAP mailbox (BRAI-79, v1.40.0):** every transport ends in `app/Support/Helpdesks/Inbound/InboundMail::receive(Helpdesk, InboundMessage): RESULT_*` (dedupe row `helpdesk_inbound_emails` in the same transaction as the ticket / reply → `InboundFilters::ignoreReason` (auto-reply, bounce, list, own mail) → `TicketMailThread::find` (Message-IDs in `ticket_email_messages`, else the HMAC subject tag `[KEY #token]`) → `isParticipant` (contact or Cc recorded in `tickets.metadata.email.participants`) → reply or new ticket; `EmailBody` (SafeHtml / text paragraphs, quoted history stripped on replies), attachments via `Uploads`; `InboundMail::reject()` records refusals like `too_large`). `MimeParser::parse(raw)` = raw RFC 5322 → `InboundMessage` (zbateson/mail-mime-parser). Mailbox: `HelpdeskMailbox` (`helpdesk_mailboxes`, one per helpdesk, `Helpdesk::mailbox()`; IMAP + optional SMTP, passwords `encrypted` + `$hidden`), `app/Support/Helpdesks/Mail/`: `MailboxConnector` (bound in `AppServiceProvider`: `ImapMailboxConnector` = webklex/php-imap socket protocol, `FakeMailboxConnector` in e2e / PHPUnit), `MailboxPoller` (+ command `app:poll-helpdesk-mailboxes`, every minute, backoff after failures), `MailHost` (host + port guard, pinned IP via `OutboundUrl::publicAddress()`), `MailTransports` (app mailer or per-mailbox `EsmtpTransport`), `TicketMailer` (outgoing: `queueReply` from `TicketController::storeReply`, `queueAcknowledgment` for email tickets, `retryReply`; job `App\Jobs\SendTicketEmail`; `replyAddress()` = the one hook for "where ticket mail comes from"). Reply email state on `ticket_replies.email_message_id|email_status|email_error|email_sent_at`. Settings JSON `HelpdeskMailboxController` (`helpdesks.mailbox.show|update|destroy|test`, `tickets.replies.resend-email`; e2e-only `helpdesks.mailbox.e2e-receive` feeds a `tests/Fixtures/mail/*.eml`) for `components/helpdesk/email/ImapMailboxSection.tsx`; reply badges `components/helpdesk/email/ReplyEmailStatus.tsx` in the ticket dialog. Config `config/helpdesk_mail.php`. Tests `InboundMailTest`, `TicketReplyEmailTest`, `HelpdeskMailboxTest` (fixtures `tests/Fixtures/mail/`), e2e `helpdesk-email.spec.ts`.
- **Support page:** `/support/{public_slug}` (`HelpdeskWidgetController::supportPage`) renders the embed form full-page with branding; the address is set under Helpdesk settings → Embed.
- **SLA (v1.40.0, BRAI-125):** policy per helpdesk in `helpdesk_sla_policies` (`HelpdeskSlaPolicy`, `Helpdesk::slaPolicy()`): on/off, `targets` per priority id or `"none"` (`{first_response, resolution}` = `{value, unit}` minutes/hours/days, a day = one business day), `business_hours` (null = 24/7; ISO days + from/to, `to < from` = overnight) in `timezone`, `pause_column_ids`, `warn_percent`. Maths: `app/Support/Helpdesks/Sla/BusinessCalendar` (pure: add business minutes, business minutes between) and `TicketSla::apply()` (every SLA column from ticket + policy + now). Ticket columns: `first_responded_at`, `sla_{first_response,resolution}_{due,warn}_at`, `sla_paused_at` / `sla_paused_minutes`, `sla_breached_at` + `sla_{first_response,resolution}_breached`. Recomputed by the `Ticket` `saving` hook (create; priority, column, `completed_at`, `first_responded_at` changes), by `HelpdeskSlaController::update` (open tickets) and by the checker; `first_responded_at` comes from the `TicketReply` `created` hook (first public reply with a `user_id`). Settings: `components/helpdesk/HelpdeskSlaTab.tsx` → `helpdesks.sla.show|update` (JSON, `helpdesks.update` policy; section in `routes/automation.php`). Badges: `components/helpdesk/SlaBadge.tsx` (`SlaBadge`, `SlaDetails`) + `lib/sla.ts` (state, labels, filter, sort, shared minute clock) on cards, the list's SLA column and the ticket panel; filter + "SLA: most urgent first" sort in the board's Filter popover. Checker `app:check-helpdesk-sla` (`CheckHelpdeskSla`, every minute) → `TicketSlaWarningNotification` (`sla_warning`) / `TicketSlaBreachedNotification` (`sla_breached`), category `helpdesk`. e2e trigger `helpdesks.sla.e2e-check` (registered only with `APP_ENV=e2e`). Tests: `tests/Unit/BusinessCalendarTest.php`, `tests/Feature/Helpdesk/HelpdeskSlaTest.php`, e2e `e2e/tests/helpdesk-sla.spec.ts` (+ `e2e/helpers/helpdesk-sla.ts`).
- **Notifications UI:** `describeNotification()` in `components/NotificationMessage.tsx` turns every payload into a row (bell + `/notifications`). Older types have their own case; any other type renders from the payload contract keys (`title`, `body`, `url`, `actor_name`, `actor_avatar`, `project_name`, `subject_key`), so a new type that follows the contract needs no frontend change. The icon comes from the type's catalog category.

### Lead forms (BRAI-93)
- **Models:** `LeadForm` (route key `slug`, public address `public_slug`), `LeadFormColumn` (its leads board), `Lead` (a card; `answers` by field id), `LeadFormEvent` (anonymous analytics: view/start/step/submit per visit session)
- **Format:** `app/Support/LeadForms/LeadFormDefinition.php` — steps → fields (12 types, choice options with lucide icon names or emoji), `visible_if` conditions on steps and fields (only earlier fields). `normalize()` repairs builder input; `validateAnswers()` validates only *visible* fields. The browser mirror is `resources/js/lib/leadForms.ts` (`visibleSteps`, `passes`) — change both together.
- **Theme / settings:** `LeadFormOptions` (whitelisted keys + defaults; `publicSettings()` strips webhook / notify / email settings from public props). **Templates:** `LeadFormTemplates` (blank + 12, with board columns).
- **Intake:** `LeadIntake::create()` (public submit and inbound webhook) → first column, next number (`takeLeadNumber`, row lock), contact copied from fields with `maps_to`, then `NewLeadNotification` (picked members), `LeadConfirmationNotification` (lead), queued `DeliverLeadWebhook` (HMAC `X-Brainitive-Signature`, `OutboundUrl`).
- **Controllers:** `LeadFormController` (CRUD, builder autosave JSON `PUT`, publish, columns, assets, inbound token, analytics JSON, template catalog), `LeadController` (move / update / delete leads), `PublicLeadFormController` (`/f/{slug}`, `/f/{slug}/embed` with `frame-ancestors *`, submit with honeypot, events), `LeadFormInboundController` (API).
- **Policy:** `LeadFormPolicy` — members view; workspace admins + project admins/editors build and work leads; project admins delete.
- **Pages:** `Pages/LeadForms/Show.tsx` (tabs Leads / Builder / Share / Analytics / Settings, components in `components/leadForms/`), `Pages/LeadForms/Public.tsx` (no layout; renders `LeadFormRenderer`, also used as the builder preview).

### Snippets
- **Models:** `SnippetCollection` (policy target), `Snippet`
- **Controller:** `SnippetController` (incl. public share routes `/share/snippets/...`)
- **Policy:** `SnippetPolicy` (registered for `SnippetCollection`)
- **Pages:** `Pages/Snippets/`

### Bookmarks
- **Models:** `Bookmark` (`uuid`-bound), `BookmarkItem`; metadata fetched async by `app/Jobs/FetchLinkMetadataJob.php`
- **Controller:** `BookmarkController` (incl. public share `/share/bookmarks/{uuid}`, icon upload)
- **Policy:** `BookmarkPolicy`
- **Pages:** `Pages/Bookmarks/`

### Calendar (Google Calendar + Google Tasks)
- **Model:** `CalendarConnection` (per-user, OAuth via Socialite — *not* workspace-scoped)
- **Controllers:** `CalendarController` (app view, reschedule), `CalendarConnectionController` (connect/redirect/callback, events & tasks CRUD)
- **Services:** `app/Services/GoogleCalendarService.php`, `app/Services/GoogleTasksService.php`
- **Console:** `SendCalendarReminders.php` + `CalendarReminderNotification` (Google events/tasks, push-only);
  `SendItemReminders.php` + `ItemReminderNotification` (bell **+** push: board / task-list items
  reaching their `start_time`, and task-list items whose `reminder_minutes` before the due time
  is reached; times in the recipient's `User::localTimezone()`; dedup via `sent_item_reminders`),
  plus `ItemDueNotification` (`item_due`: assigned board issues due tomorrow / overdue, BRAI-103).
  Both scheduled every 5 min in `routes/console.php`. Task-list digest: see Task Lists.
- **Routes:** `calendar/connections/...` controller group in `web.php`
- **Pages:** `Pages/Calendar/`

### Instance admin console
- **Routes:** `admin.*` in `web.php` (`/admin`, `users`, `workspaces`, `settings`, `audit-log`), middleware `auth`, `verified`, `instance.admin` (`EnsureInstanceAdmin`)
- **Controllers:** `app/Http/Controllers/Admin/` — `OverviewController`, `UserController` (reset link / email, verify, suspend, admin role, create, delete), `WorkspaceController` (list, members, transfer, delete; route param `{managedWorkspace:id}`), `SettingsController` (sign-up, verification, SMTP + test email), `AuditLogController`
- **Models / support:** `InstanceSettings` (singleton row; `allowsRegistration()`, `requiresEmailVerification()`, `mailSettings()` — SMTP password encrypted), `AdminAuditLog::record()`, `app/Support/Admin/UserDeletion` (guards + reassigns created content to the workspace owner before deleting), `InstanceMailConfig` (applies admin SMTP on `mail.manager` resolve + per queued job)
- **Pages:** `Pages/Admin/{Overview,Users,Workspaces,Settings,AuditLog}.tsx`, shell `components/admin/AdminShell.tsx`; entry in the `AppLayout` user menu when `auth.isInstanceAdmin`
- **Metadata only:** names, emails, counts, dates. Never page / board / item content.
- **Promote via CLI:** `php artisan make:instance-admin <email>` (also verifies the email)

### MyWork, Search, Notifications, Files
- **MyWork:** `MyWorkController` → `Pages/MyWork/` — cross-workspace "assigned to me" dashboard
  - **My Day** (BRAI-74): `MyDayEntry` (`my_day_entries`: user + board item / list task + local
    `plan_date` + position), `MyDayController` (`my-day.*`: add / remove / reorder /
    carry-over), `Support/MyDay/MyDayPlan` (today in the user's zone, resolves entries to
    work the user can still open, carry-over candidates). UI: `components/MyDaySection.tsx`
    on top of My Work + ☀ toggle on every row. Adding / ordering never changes the task itself.
    The circle (`components/WorkDoneToggle.tsx`, My Day + My Work rows) completes / reopens it:
    board issues go through `boards.items.move` to the board's first done / open column
    (`board.done_column_id` / `open_column_id` in the My Work payload), list tasks through
    `task-lists.items.update` `status`. Done tasks sink to the bottom of My Day (v1.39.0).
  - **Focus timer:** `WorkTimer` (`work_timers`, unique `user_id` = one running timer),
    `WorkTimerController` (`work-timer.start/stop/discard`), shared prop `activeTimer` →
    `components/WorkTimerPill.tsx` in the top bar. Stop logs through `Item::logWork()` (also
    used by `BoardController::logWork`) after a review, with activity + day (v1.38.0, `source =
    timer`); board items only.
- **Quick capture (BRAI-70):** private per-user inbox of ideas captured without a project. `Capture` model (`captures`: uuid route key, `text`, `url`, `intended_type`, `source`, `captured_at`, `archived_at`, `processed_at`, `target_type` + `target_id` + `target_location` snapshot), `CapturePolicy` (owner only, others 404), `CaptureInboxController` (`captures.*`: index with Inbox / Processed / Archived + search, store, update, archive / restore, destroy (archived only), `destinations` + `preview` JSON, `convert`). Domain: `app/Support/Captures/` — `ContentCapture` (the shared create path of the extension **and** the conversion: policy per destination type, rules, create), `CaptureDestinations` (writable targets, also `ext.destinations`), `CaptureFields` (preview fields per type), `CaptureConversion` (one transaction on the locked capture row: replay when processed, authorize, validate, create, mark processed), `CaptureAccess` (which created targets the owner can still open, from access sets loaded once — never live details of content they lost access to). Shared prop `captureInboxCount` (sidebar badge). UI: `components/captures/QuickCaptureDialog.tsx` (mounted in `AppLayout`; `openQuickCapture()`, shortcut **C** via `lib/captures.ts` `isCaptureShortcut`, `QuickCaptureFab` on phones), `components/captures/ConvertCaptureDialog.tsx`, `Pages/Captures/Index.tsx`. Tests: `tests/Feature/Captures/`, e2e `e2e/tests/quick-capture.spec.ts`.
- **Search:** `SearchController` (invokable) → `/search` (workspaces, projects, boards, plans, issues, documents, snippets, bookmarks; optional `project` param ranks that project first)
- **GitHub development panel (BRAI-61):** per-project webhook (`GithubConnection`, settings in `ProjectGithubController` → Projects/Edit "Integrations", `components/projects/GithubIntegrationCard.tsx`). `POST /api/webhooks/github/{uuid}` (`GithubWebhookController`: HMAC check, `GithubDelivery` dedupe) → `ProcessGithubDelivery` job → `Support/Github/DevelopmentSync` writes `ItemDevLink` rows (branch / commit / pull_request / deployment) for keys found by `IssueKeys`. Shown by `ItemDevelopment` in the item-details JSON and `components/item/DevelopmentPanel.tsx`.
- **Swimlanes (BRAI-87):** setup in `board.settings.swimlanes` (validated by `Support/Boards/Swimlanes`, saved via `BoardController@updateSwimlanes`), builder `components/board/SwimlaneSettings.tsx` on Boards/Edit, rules + grouping in `resources/js/lib/swimlanes.ts`, rendering `SwimlaneBoard` in Boards/Show (personal mode in localStorage `board-{id}-lane-mode`).
- **Custom fields (BRAI-77):** `ItemField` (`item_fields`: project, name, type text/number/date/checkbox/select, `board_ids` null = all boards, position, `archived_at`), `ItemFieldOption` (archived, never deleted), `ItemFieldValue` (`item_field_values`: one typed column per type, unique item + field). Definitions: `ItemFieldController` (`projects.fields.*`, ProjectPolicy::update) → Projects/Edit "Fields" tab (`components/projects/ItemFieldsCard.tsx`). Values: `BoardController@updateItemFields` (`boards.items.fields.update`) → `Support/Boards/ItemFieldValues` (validation per type, writes, `field_changed` history, copy on duplicate). UI: `components/item/ItemFieldsSection.tsx` (issue sidebar), `components/board/CustomFieldFilters.tsx` (Filter popover), list-view columns + sorting in Boards/Show; pure helpers in `resources/js/lib/customFields.ts`. Cards carry `field_values`; `customFields` = `ItemField::forBoard()`.
- **Saved views (BRAI-78):** `BoardView` (`board_views`: board, user, name, `is_shared`, `config` = `{version, params}`) + `board_view_defaults` (user + board → view). `config.params` are the board's own URL view params (`q`, `assignee`, `status`, `labels`, `priority`, `type`, `quick`, `sort`, `view`, `hide`, `f<id>`), allowlisted by `Support/Boards/BoardViewConfig::normalize()` and reduced to what still exists by `sanitize()` when opened. `BoardViewController` (`boards.views.*`; open = redirect to the board URL + `bv=<id>`, flash `warning` when parts were dropped), `BoardViewPolicy` (personal = owner only; shared = board viewers see, board `update` changes). `BoardController::show` applies the user's default view to the plain board URL in place (`appliedViewParams`, the page writes them into the URL; `bv=0` skips it) and sends the open view's params sanitized. UI: `components/board/SavedViewsMenu.tsx`; Boards/Show builds `currentViewParams` (list sort + hidden list columns are page state now).
- **Project health (BRAI-64):** `ProjectHealthController` → `Pages/Projects/Health.tsx` (`projects.health`, `?tz=&from=&to=&report=`; `health`, `reports`, `openReport` are lazy props for partial reloads), `projects.health.narrative` (JSON AI draft), `projects.health.reports.store|destroy`. Domain in `app/Support/ProjectHealth/`: `HealthMetrics` (live counts + lists in a fixed number of queries, definitions in its docblock and the UI), `HealthSnapshot` (bounded AI input, ≤10 per list, this project only, no assignee names), `HealthNarrator` (workspace BYOK AI with `OutboundUrl`, own class — `AiController` untouched; JSON claims with source ids, invalid ids dropped), `ReportSources` (`item:`/`plan:`/`metric:` refs: `invalid()` on save, `resolve()` per reader on read, `storedMetrics()` = numbers + ids only). Model `ProjectHealthReport` (`project_health_reports`, soft deletes), `ProjectHealthReportPolicy` (view = project access, create = `createBoards`, delete = author / project admin). UI: `components/health/{HealthBits,HealthReportEditor,HealthReportSheet}.tsx`; nav link "Health" under the project's Boards next to Releases. Tests: `tests/Feature/ProjectHealth/`, e2e `e2e/tests/project-health.spec.ts`.
- **Releases / fix versions (BRAI-76):** `Release` model (`releases`, `items.release_id`), `ReleaseController` (project routes `releases.*`), `ReleasePolicy` (view = project view, manage = `createBoards`), notes draft `Support/Releases/ReleaseNotes`, public snapshot via `PublicReleaseController` (`/r/{token}`, `/changelog/{projects.changelog_token}`). Pages `Releases/{Index,Show,Public}`, shared bits `components/releases/ReleaseBits.tsx`; issue sidebar "Fix version" uses `releases` from the item-details JSON.
- **Read-only rich text:** use the `rich-html` class (app.css) — the Tailwind typography plugin isn't installed, so `prose` alone styles nothing.
- **Issue deep links:** `…/boards/{board}#item-{id}` opens the issue popup on the board (or `boards.items.show` if it isn't on the board).
- **Customisable sidebar (BRAI-106):** top-level nav is data — `components/sidebar/navItems.tsx` (`useSidebarNavItems`: key, label, icon, href, active, availability; Home + Inbox can't be hidden). `AppLayout` renders the visible entries and builds the palette's "Go to" commands from the same list, so hidden entries stay in ⌘K. Preferences in `users.sidebar_preferences` = `{hidden_nav: string[], projects: {"<id>": {groups: {boards|lists|plans|helpdesks|lead_forms|pages|whiteboards|folders: bool}, pins: [{type: board|list|plan|helpdesk|lead_form|page, id}], pinned_only: bool}}}` (null = defaults), normalised by `Support/Sidebar/SidebarPreferences` (`normalize`, `apply` under a row lock, `visiblePins`), saved by `SidebarPreferencesController` (`PATCH sidebar.preferences`: partial `hidden_nav` / `project: {id, groups?, pins?, pinned_only?}` / `reset`), shared as the `sidebarPreferences` prop. Frontend store `lib/sidebarPreferences.ts` (module-level, optimistic, queued writes; `useSidebarPreferences`, `togglePin`, `setNavHidden`, `setGroupVisible`, `setPinnedOnly`, `resetSidebar`, `openCustomizeSidebar`). Pins resolve against the permission-filtered sidebar payload (`components/sidebar/pins.tsx`), so pins the user can't see never render. UI: "Pinned" group first in `ProjectContent` (with pins or "pinned only" the groups collapse into "Show all in project"), `components/sidebar/PinButton.tsx` (`Pin <name>` / `Unpin <name>`), `components/sidebar/CustomizeSidebarSheet.tsx` (account menu, pencil at the sidebar bottom, ⌘K "Customize sidebar"). Tests: `tests/Feature/Sidebar/`, e2e `e2e/tests/custom-sidebar.spec.ts`.
- **Project page (BRAI-106 order):** `Pages/Projects/Show.tsx` — header (name, key, description, member avatars, quick links Health · Workload · Releases with aria-labels "Project health/workload/releases"), Pinned, Recently updated (`Support/Projects/RecentContent::for`: 5 entries across types, a board/list/helpdesk counts its issues/tasks/tickets; 4 bounded queries), then Work (Boards, Lists, Plans), Docs (Pages tree from the sidebar payload, Whiteboards), Customer-facing (Helpdesks, Lead forms), Tools (AI assistant, meeting notes). Empty subsections are one compact row; each subsection's h3 shares its parent with the "Add" button; card titles stay h3 with a pin toggle beside the link. `ProjectController@show` adds `members` (≤8) and `recentlyUpdated`.
- **Command palette (BRAI-83):** `components/CommandPalette.tsx` is the only launcher — ⌘K/Ctrl+K, Ctrl+. opens command mode (`>`); commands are defined in `AppLayout`. Recent destinations live in localStorage as `{type,id}` per user and are resolved/permission-filtered by `CommandPaletteController@recent` (`POST /command-palette/recent`).
- **Notifications (inbox redesign BRAI-102):** `NotificationController` serves one JSON feed for the bell and the page: `notifications.index` (`?filter=all|unread|mentions|assigned&archived=1&cursor=…` → `{data: [{id, type, category, data, read_at, archived_at, created_at}], next_cursor, unread_count}`; `category` from `NotificationCatalog::TYPES`, `null` for unknown types), `notifications.read|unread|archive|unarchive` (`{id}` is a uuid), `notifications.bulk` (`ids[]`, `action`), `notifications.read-all`, `notifications.preferences` (PATCH, saves `users.notification_preferences`). `/notifications` (`notifications.page`) renders `Pages/Notifications/Index` with the first page and does **not** mark anything read. Archive = `notifications.archived_at` (archiving also marks read; "mark unread" un-archives). `App\Models\InboxNotification` (extends `DatabaseNotification`) prunes archived rows after 90 days and every row after 180 (`model:prune`, `routes/console.php`). Channels per category: `NotificationCatalog` + `Concerns\RespectsPreferences` (every catalog class's `via()`); Profile → Notifications (`Profile/Partials/NotificationPreferencesForm.tsx`, prop `notificationCategories`). UI: `components/NotificationsDropdown.tsx` (bell: popover, full-screen sheet below 768 px) and `components/notifications/` (`NotificationRow`, `NotificationList` day groups + load more, `useNotifications` feed + unread count with 30 s poll / focus refresh / `(3) …` tab title, `categories.tsx` icon per category, `filters.ts`). Delivery = `database` + optional `WebPushChannel` + `mail`. Push subscriptions via `PushSubscriptionController`. See the [add-notification skill](./skills/add-notification.md).
  **Who gets what (BRAI-103):** catalog of types → categories in `NotificationCatalog`; new types extend
  `CatalogNotification` (payload contract). Senders: `Support/Notifications/` (`Assignments` — called by
  `ItemObserver` / `TaskListItemObserver`, `ItemCommentNotifications`, `PageMentions` — called by
  `Page::saveDraft`, `SprintNotifications` — called by `SprintLifecycle`, `WorkspaceNotifications`),
  `Support/Boards/ItemMentions` (diffed), `TicketController::move` (`ticket_status_changed`). **Web push requires `VAPID_*` keys in `.env`** — without them the frontend can't subscribe and nothing is ever delivered (see [`KNOWLEDGE.md`](./KNOWLEDGE.md)).
- **Files:** `FileController` — `/upload/image`, `/upload/document` (public disk, UUID names)

### AI integrations — MCP server & in-app AI
- **In-app AI:** `AiController` (`ai/settings`, `ai/models`, `ai/generate` under `workspaces/{workspace}`) — per-workspace AI provider settings.
- **MCP server:** `routes/ai.php` registers `App\Mcp\Servers\BrainitiveServer` at `/mcp` (Passport `auth:api`) + `Mcp::oauthRoutes()` for OAuth 2.1 discovery/registration.
  - **Tools:** `app/Mcp/Tools/*Tool.php` (~38), registered in the `$tools` array on `BrainitiveServer`.
  - **Shared helpers:** `app/Mcp/Tools/Concerns/InteractsWithBrainitive` (slug→model resolution + policy enforcement, so the AI can only touch what the user can), `ResolvesCalendarConnections`.
  - **Support:** `app/Support/Mcp/` — `ResourceLocator` (the `location` object every write returns), `PageContentConverter` (Markdown↔editor format), `CalendarFormatter`.
  - **Comments / sprints / plans (BRAI-67/68/69):** `ListItemCommentsTool`, `AddItemCommentTool`; `ListSprintsTool`, `GetSprintTool`, `CreateSprintTool`, `UpdateSprintTool`, `MoveItemsToSprintTool`, `StartSprintTool`, `CompleteSprintTool` (trait `InteractsWithSprints`); `ListPlansTool`, `GetPlanTool`, `UpdatePlanTool` (trait `InteractsWithPlans`, engine `App\Support\Plans\PlanOperations`). Shared domain actions with the UI: `Support/Boards/ItemComments`, `ItemMentions`, `SprintLifecycle`.
  - **Writes with `request_id`** go through `App\Support\Mcp\IdempotentRequest` (`mcp_requests`, pruned after 7 days).
  - To add a tool, see the [add-mcp-tool skill](./skills/add-mcp-tool.md).
- **In-app AI (workspace AI settings):** `AiController` — `generate` (editor toolbar; optional `project_id` adds the project's name + description as context), `projectAssist` / `saveProjectAssist` (project overview assistant: summary / task / implementation prompt → review → copy, draft doc or replace description). All calls go through the private `chat()` helper (SSRF-guarded via `OutboundUrl`). UI: `components/projects/ProjectAiAssistant.tsx`.
- **Meeting notes → issues (BRAI-62):** `MeetingNotesController` — `meeting-notes.options` (GET, ReadOnlySession: writable boards, project members, AI state, source doc), `meeting-notes.extract` (proposals only, writes nothing), `meeting-notes.store` (creates the reviewed proposals) under `workspaces/{workspace}/projects/{project}`. Domain in `app/Support/MeetingNotes/`: `MeetingNotesSource` (doc by uuid with `PagePolicy::view`; editors read the draft they see, everyone else the live doc; pasted text; 20 000-char cap), `ActionItemExtractor` (its own OpenAI-compatible call to the workspace BYOK provider through `OutboundUrl`; notes inside a random per-request boundary; `parse()`; `ExtractionFailed` codes), `ProposalReview` (excerpt must be in the notes, assignees only project members / ambiguous / unknown, date rules, visible dedup vs batch + open board issues), `ActionItemCreator` (`ProjectIssues::create` inside `IdempotentRequest::run`, tool `meeting-notes.create`; optional quote + doc link + `ContentReference`). UI: `components/meetingNotes/ExtractActionItemsDialog.tsx` (setup → review → result), opened from the doc's ⋯ menu (`Pages/Pages/Show`) and the "Meeting notes → issues" card on `Pages/Projects/Show` (`canCreateIssues`). Tests: `tests/Feature/MeetingNotes/`, e2e `e2e/tests/meeting-notes.spec.ts`.

### CLI API
- **Controller:** `CliController` — `routes/api.php`: `/cli/login` issues a Passport token; `auth:api`-guarded `/cli/...` endpoints expose workspaces + task lists (JSON).

### Browser extension (Chrome, Edge, Firefox)
- **Extension source:** `browser-extension/` (WXT + React + TS, own `package.json`, not part of
  the app build) — see its `README.md`. `entrypoints/` = popup, side panel, options, background
  worker; `lib/` = OAuth (`auth.ts`), API client, page reading / screenshots, Markdown clipping.
- **Sign-in:** OAuth 2.1 + PKCE via Dynamic Client Registration (`/oauth/register` from
  `Mcp::oauthRoutes()`), scope `capture`; consent screen `resources/views/mcp/authorize.blade.php`.
- **API:** `routes/api.php` → `/api/ext/v1/*` (`ext.*` routes):
  `Extension/ExtensionController` (me, destinations, search, my-work) and
  `Extension/CaptureController` (issue + screenshot, bookmark item, doc, task, snippet, and
  `ext.captures.store` = "Save to inbox", create only). Create logic + policies are shared with
  the capture inbox (`App\Support\Captures\ContentCapture`). Ids are numeric; same policies as the app. Tests: `tests/Feature/Extension/ExtensionApiTest.php`.

---

### Linked content and backlinks (BRAI-71)
- **Model:** `ContentReference` (`content_references`: `project_id`, `source_type` / `source_id`, `target_type` / `target_id`, `origin` = `manual` | `derived`, `created_by`; unique per source + target + origin). Types are stable keys `item`, `page` (docs + whiteboards, not folders), `ticket`, `plan` — `ContentReference::TYPES`.
- **Support:** `App\Support\References\ContentReferences` — `resolve` (endpoint of the project, live), `link` (no self-links, no issue → issue: that stays an `ItemLink`), `forEndpoint` (related + referenced-by, visibility in batch, manual + derived merged), `candidates` (picker search), `syncDerived` / `syncPlan` / `syncWhiteboard`, `pruneMissing`, `summary` (canonical URLs = `ResourceLocator`, which now also locates plans and tickets).
- **Derived references** come from model hooks: `Plan::saved` (rows with `itemId`) and `Page::saved` (whiteboard issue cards). Pruning: `Page::deleted`, `forceDeleted` on Item / Ticket / Plan, `BoardController::moveItemToBoard` across projects.
- **Controller / routes:** `ContentReferenceController` — `references.index` / `references.candidates` (GET, ReadOnlySession), `references.store`, `references.destroy` under `projects/{project}`.
- **UI:** `components/references/RelatedContent.tsx` (sections + "Link related content" picker), mounted in `ItemDetail`, `Pages/Pages/Show`, the ticket dialog in `Helpdesks/Show` and the "Related" sheet on `Plans/Show`. Types `ContentReference*` in `types/index.d.ts`.
- **Tests:** `tests/Feature/References/ContentReferencesTest.php`, `e2e/tests/backlinks.spec.ts`.

### Workload planning (BRAI-75)
- **Models:** `WorkloadCapacity` (`workload_capacities`: project + user → `weekly_minutes`; no row = not set), `WorkloadCapacityOverride` (`workload_capacity_overrides`: project + user + `week_start` → `minutes`, `note`; leave = 0), `WorkloadAllocation` (`workload_allocations`: project, item, user, `week_start`, `minutes`, `estimate_snapshot`; unique item + user + week). Weeks are the Monday's `date`; all amounts are minutes.
- **Support:** `app/Support/Workload/` — `WorkloadWeeks` (ISO weeks, Monday keys, "this week" in the viewer's `localTimezone()`), `WorkloadPlanner` (every write: capacity, overrides, `allocate()` with the `expected` concurrency check + parent/subtask guard, `remaining()` = max(estimate − logged, 0), `projectItems()`), `WorkloadGrid` (page payload: cells with state `unset` / `unavailable` / `ok` / `over`, per-person open work, team totals, "needs review" flags), `WorkloadFormat::hours()` (mirrors `formatHours` in `lib/workload.ts`).
- **Policy:** `WorkloadPolicy`, registered for `WorkloadAllocation` — `viewAny` = project view, `plan` = `ProjectPolicy::update` (project admins + workspace admins).
- **Controller / routes:** `WorkloadController` under `projects/{project}`: `workload.index` (page; `?from=<any day>&weeks=4|6|8|12`), `workload.items` (GET JSON issue search, ReadOnlySession), `workload.capacity.update`, `workload.overrides.update`, `workload.allocations.update` (PUT, `minutes` 0 removes, `expected_minutes` required), `workload.allocations.destroy` (`{allocation}` = plain id looked up inside the project, so `{project}` stays workspace-scoped).
- **UI:** `Pages/Workload/Index.tsx` (grid, team totals, review, "How it's calculated"), `components/workload/` (`WorkloadWeekSheet` = one person's week: availability override, planned work, plan work with the remaining-estimate suggestion; `CapacityDialog`; `WorkloadReview`; `workloadActions`), helpers `lib/workload.ts`, types `Workload*` in `types/index.d.ts`. Entry: project sidebar → Boards → "Workload" (after Releases).
- **Tests:** `tests/Feature/Workload/WorkloadTest.php`, e2e `e2e/tests/workload.spec.ts` (+ `e2e/helpers/workload.ts`). Rules: [`tasks/feat-workload-planning.md`](./tasks/feat-workload-planning.md), traps in KNOWLEDGE.

### Admin insights (v1.39.0, BRAI-105)
Workspace admins only (`Gate::authorize('update', $workspace)`, members 403); sidebar "Insights" (`ChartColumn`) in `AppLayout`. Admins can open every project, so the scope is every live project of the workspace, or the one in the project filter. Plan: [`tasks/feat-admin-insights.md`](./tasks/feat-admin-insights.md).
- **Routes:** `routes/insights.php` — `insights.index` (redirect to Overview, keeps the query), `insights.overview|team|projects|helpdesk|usage` (`?period=this_month|last_month|last_30|last_90|custom&from=&to=&project=`), `insights.export` (`insights/{tab}/export`, CSV, `throttle:30,1,insights.export`).
- **Controller:** `InsightsController` (`TABS` = tab → Inertia page + computing class; `compute($tab, $filters)`).
- **Support:** `app/Support/Insights/` — `InsightsFilters` (period in the viewer's `localTimezone()`, custom ≤ 366 days, `startUtc()`/`endUtc()` half-open, `projects` = the one query of live projects reused by every tab), `InsightsQueries` (issue scope + done/open SQL like `HealthMetrics`, `localDate()`, `medianHours()` = `percentile_cont` on Postgres, PHP elsewhere), one class per tab with its definitions in the docblock: `OverviewInsights`, `TeamInsights`, `ProjectInsights`, `HelpdeskInsights`, `UsageInsights`; `InsightsCsv` (tables separated by an empty line, UTF-8 BOM, formula cells prefixed with `'`).
- **UI:** `Layouts/InsightsLayout.tsx` (tabs, period buttons + custom range, project select, Export CSV), `Pages/Insights/{Overview,Team,Projects,Helpdesk,Usage}.tsx`, `components/insights/InsightsBits.tsx` (stat tiles, SVG weekly chart, inline bars on the `.viz-root` tokens; no chart library), types `Insights*` in `types/index.d.ts`.
- **Tests:** `tests/Feature/Insights/InsightsTest.php` (numbers, time zone, 403, fixed query count per tab, CSV), e2e `e2e/tests/insights.spec.ts` (+ `e2e/helpers/insights.ts`).
- **Helpdesk SLA (v1.40.0, BRAI-125):** `HelpdeskInsights` adds `sla` (breaches due in the range, met % of the tickets with an SLA resolved in the range) and per-helpdesk `sla_*` columns inside its existing per-helpdesk query (still 7 queries); tiles `kpi-sla-breaches` / `kpi-sla-met`.

### Billing settings + documents (v1.38.0, BRAI-101)
Contract (snapshot v1, money rules): [`tasks/release-v1.38.0-billing.md`](./tasks/release-v1.38.0-billing.md); plan: [`tasks/feat-billing-documents.md`](./tasks/feat-billing-documents.md). Workspace admins only (`Gate::authorize('manageBilling', $workspace)`), routes in the documents section of `routes/billing.php`.
- **Settings:** `Billing\BillingSettingsController` — `billing.settings.edit|update` (one `BillingSetting` row, `forWorkspace()`), `billing.settings.logo` (GET, admin-only preview), `billing.settings.logo.store|destroy`. Page `Pages/Billing/Settings.tsx` inside `BillingLayout`.
- **Logo:** `App\Support\Billing\BillingLogo` — re-encodes png / jpg / webp to PNG with GD, stores on the private `local` disk as `billing-logos/<workspace>/<uuid>.png`, `dataUri()` for documents, keeps a replaced logo while a finalized snapshot still prints it.
- **Documents:** `App\Support\Billing\BillingDocument` renders the snapshot array — `pdf()` (dompdf, `resources/views/billing/document.blade.php`), `xlsx()` (openspout: "Summary" + "Entries"), `filename()`; it formats, never recomputes. Data source: `ReportData::for($report)` = `snapshot` once finalized, else `App\Contracts\Billing\BuildsReportData` (bound in `BillingServiceProvider` to `LiveReportData` → the reports ticket's `ReportBuilder`, only when that class exists; else drafts answer 409).
- **Controller:** `Billing\BillingDocumentController` — `billing.reports.pdf|xlsx` (download) and `billing.reports.send` (finalized / sent / paid only; queued `App\Mail\BillingReportMail` with the PDF, reply-to the admin, views `emails/billing-report*.blade.php`; finalized → sent).
- **Components for the report page:** `components/billing/ReportDocumentButtons.tsx` (PDF, Excel, "Send to client") and `components/billing/SendReportDialog.tsx`.
- **Tests:** `tests/Feature/Billing/BillingSettingsTest.php`, `BillingDocumentTest.php`, shared fixture `tests/Fixtures/billing-snapshot-v1.php`; e2e `e2e/tests/billing-settings.spec.ts`.

### Billing: clients + team time overview (BRAI-100, v1.38.0)
Shared foundation (models, `manageBilling`, `routes/billing.php`, `BillingLayout`): [`tasks/release-v1.38.0-billing.md`](./tasks/release-v1.38.0-billing.md). Everything here is workspace admins only (`Gate::authorize('manageBilling', $workspace)`).
- **Clients:** `Billing\ClientController` — `billing.clients.index` (`?archived=1` shows archived), `billing.clients.store|update` (VAT entered as percent → `tax_rate_bp`, empty = workspace default; name unique per workspace), `billing.clients.archive|restore` (no delete), `billing.clients.projects.update` (PUT `project_ids`: the client's projects become exactly that set, workspace projects only, moves a project from another client). Project settings: `ProjectController::edit` passes `billingClients` (admins only, else null), `update` sets `client_id` only when the request carries it **and** the user can `manageBilling` (validated against the workspace's clients; `client_id` stays non-fillable).
- **Time overview:** `Billing\TimeOverviewController` — `billing.time.index` (page), `billing.time.export` (xlsx). Logic in `app/Support/Billing/TimeOverview.php`: `fromRequest()` (query string → filters; invalid values fall back; periods `last_month` default / `this_month` / `custom` ≤ 366 days, in the viewer's `localTimezone()`), `query()` (the one joined query: entries + project + client + live item (not trashed) + person + activity + report), `summary()` (cursor → groups: project → ticket rows, or person / activity / day; minutes, billable minutes, entries, estimate; entry detail capped at 2 000), `options()` (filter lists). `TimeExport::download()` streams the same query into openspout (`php://output`), one row per entry, `time-YYYY-MM.xlsx` for a whole month.
- **UI:** `Pages/Billing/Time/Index.tsx` (+ `components/billing/TimeOverviewFilterBar.tsx`), `Pages/Billing/Clients/Index.tsx` (+ `components/billing/ClientDialogs.tsx`), helpers `lib/timeOverview.ts` (decimal hours, money, calendar days), types `BillingClient*` / `TimeOverview*` in `types/index.d.ts`; client select in `Pages/Projects/Edit.tsx` (General).
- **Tests:** `tests/Feature/Billing/ClientsTest.php`, `TimeOverviewTest.php` (incl. query count + xlsx read back), e2e `e2e/tests/time-overview.spec.ts` (+ `e2e/helpers/time-overview.ts`). Plan: [`tasks/feat-time-overview.md`](./tasks/feat-time-overview.md).

### Billing → Activities (v1.38.0, BRAI-99)
Part of time tracking & client billing; the shared contract is [`tasks/release-v1.38.0-billing.md`](./tasks/release-v1.38.0-billing.md).
- **Models:** `BillingActivity` (`hourly` | `fixed`, `rate_cents`, `billable`, `color`, `position`, `archived_at`; name unique per workspace), `BillingActivityDefault` (workspace + item type → activity).
- **Controller / routes:** `Billing\BillingActivityController`, `routes/billing.php` (section "agent: activities"): `billing.activities.index|store|update|archive|restore|reorder|destroy` and `billing.activities.defaults.update` (one item type at a time; null clears). All `Gate::authorize('manageBilling', $workspace)`.
- **Rules:** the price is sent in the currency's major unit (`price`, ≤ 2 decimals) and stored as cents. Names are unique case-insensitively, archived ones included. A used activity can't be deleted (a validation message says to archive it). Archiving also deletes the defaults that point at it, so a default always names an active activity. Usable item types = active global types + the types of the workspace's projects.
- **UI:** `Pages/Billing/Activities/Index.tsx` inside `Layouts/BillingLayout.tsx`; types `BillingActivity`, `BillingActivityItemType` in `types/index.d.ts`.
- **Tests:** `tests/Feature/Billing/BillingActivitiesTest.php`, e2e `e2e/tests/billing-activities.spec.ts`.

### Time log & My timesheet (v1.38.0, BRAI-99 part 2)
Builds on the billing foundation (`docs/ai/tasks/release-v1.38.0-billing.md`: `TimeEntry`, `BillingActivity`, `BillingActivityDefault`).
- **One choke point:** `Item::logWork($minutes, $note, $activity, $spentOn, $source, $billable)` creates the `TimeEntry` (workspace / project from the issue, signed-in user, `spent_on` = today in the user's zone, `item_key` / `item_title` snapshot, `billable` from the activity) and the `work_logged` history entry (`metadata.time_entry_id`, `activity`). The entry's model events add to `time_spent`. Callers: `BoardController::logWork` ("Log work"), `WorkTimerController::stop` (`source = timer`), `TimesheetController::store`.
- **Support:** `app/Support/Billing/` — `TimeLogActivities` (active activities, `required()` once the workspace has any, member-facing `options()` without rates, `defaultFor()` = ticket type default → last used, `resolve()` validates against the workspace + `archived_at`), `TimeLog` (`spentOnRules`, `loggableProjectIds` = ItemPolicy::update rules, `canChange` = author or `manageBilling`), `LegacyTimeBackfill` (old `work_logged` logs → `legacy` entries; `php artisan time-entries:backfill-legacy`, run once by migration `2026_10_27_120000`, which also adds `time_entries.legacy_log_id`).
- **Controller / routes** (`routes/billing.php`, section *log-time*): `Billing\TimesheetController` — `timesheet.index` (week page, `?week=<any day>`), `timesheet.activities` (JSON options + `default_activity_id` for `?item_id=`, ReadOnlySession), `timesheet.items` (JSON issue search of a loggable project, ReadOnlySession), `timesheet.entries.store|update|destroy`.
- **UI:** `Pages/Timesheet/Index.tsx` (sidebar "Timesheet"), `components/billing/TimeEntryDialog.tsx` (add / edit with project + issue search), `components/billing/LogActivityPicker.tsx` (`useLogActivities` + the select, shared by every log form), `components/item/LogWorkPopover.tsx` (issue sidebar "Log work"), the stop dialog in `WorkTimerPill.tsx`; helpers `lib/timesheet.ts`; types `TimeLogActivity*`, `Timesheet*`. Item history shows `work_log_updated` / `work_log_removed` from timesheet edits.
- **Tests:** `tests/Feature/Billing/{TimeLogTest,TimesheetTest,LegacyTimeBackfillTest}.php`, e2e `e2e/tests/time-log.spec.ts` (activities from E2eSeeder's "v1.38.0 billing fixtures").

### Billing reports: builder, finalize, status (v1.38.0, BRAI-101 reports)
Snapshot v1 shape, money rules and locking: [`tasks/release-v1.38.0-billing.md`](./tasks/release-v1.38.0-billing.md); decisions in [`tasks/feat-billing-reports.md`](./tasks/feat-billing-reports.md).
- **Support:** `app/Support/Billing/` — `ReportBuilder` (implements `App\Contracts\Billing\BuildsReportData`, bound in `AppServiceProvider`; `build()` = snapshot v1, `compute()` = snapshot + entry ids + admin rows + left-out rows + fixed charges + warnings; `projects()` / `draftScope()` / `options()` / `totals()` / `defaultTitle()`; 9 queries whatever the size), `ReportComputation`, `ReportFinalizer` (`finalize` / `void` / `markPaid`, one transaction each on the locked report row), `InvoiceNumber::format()` (prefix + 3 digits).
- **Model:** `BillingFixedCharge` (`billing_fixed_charges`: workspace, project, item nullable, activity, report cascade, amount; unique item + activity), `BillingReport::fixedCharges()` / `isFinal()` / `LOCKING_STATUSES` / `GROUP_BY`.
- **Controller / routes** (`routes/billing.php`, section *reports*): `Billing\BillingReportController` — `billing.reports.index` (`?status=&client=`), `.store`, `.show`, `.update` (draft options: title, period, `project_ids`, `group_by`, `show_people`, `show_notes`, `excluded_entry_ids`), `.destroy` (drafts), `.finalize` (`entry_ids` = what the admin reviewed), `.paid`, `.void` (`confirm` for paid ones). `Gate::authorize('manageBilling')`; reports are looked up inside the workspace (404). PDF / Excel / send (`billing.reports.pdf|xlsx|send`) belong to the documents part.
- **UI:** `Pages/Billing/Reports/{Index,Show}.tsx` in `BillingLayout`; `components/billing/reports/ReportDocument.tsx` (the snapshot as a document table, expandable entries, "Leave out"), `ReportBits.tsx` (status badge, options panel, send dialog); helpers `lib/billingReports.ts`; types `BillingReport*`, `BillingSnapshot*` in `types/index.d.ts`.
- **Tests:** `tests/Feature/Billing/{BillingReportBuilderTest,BillingReportFlowTest}.php` (fixture `InteractsWithBilling`), e2e `e2e/tests/billing-reports.spec.ts` (+ `e2e/helpers/billing-reports.ts`; Muster GmbH + last month's entries from E2eSeeder's "v1.38.0 billing fixtures").

### Outgoing webhooks + domain events (v1.39.0, BRAI-104)
Contract (envelope, event list): [`tasks/release-v1.39.0.md`](./tasks/release-v1.39.0.md) → "Domain events"; plan [`tasks/feat-outgoing-webhooks.md`](./tasks/feat-outgoing-webhooks.md).
- **Events:** `app/Support/Events/DomainEvents` — `EVENTS` (name → label), `dispatch(Workspace, event, data, ?actor)` (envelope `{id, event, occurred_at, workspace, actor, data}` built at once, deliveries created in `DB::afterCommit`, never throws into the request), `emit(workspace|id, event, fn () => data, ?actor, ?subject, context)` (payload only built when an active webhook listens: one query otherwise; with a `subject` model automation rules see the event too, BRAI-63), `envelope()`, `grouped()` (picker areas). Payload builders: `EventPayloads` (item, comment, page, sprint, ticket, reply, time entry, billing report; they work on a copy of the model's attributes, never loading relations on the caller's model). Item changes → `ItemEvents` (from `ItemObserver` created / updated / deleted).
- **Hook points:** `ItemObserver` (item.created / .moved / .completed / .assigned / .updated / .deleted), `ItemComments::add` (item.commented), `Page::publish` / `publishChanges` (page.published), `SprintLifecycle::start` / `complete`, `TicketController` `store` / `storePublic` (ticket.created), `storeReply` / `storePublicReply` (ticket.replied), `move` into a done column (ticket.resolved), `LeadIntake::create` (lead.created, `payload()` minus `event`), `TimeEntry::booted` created (not legacy rows), `ReportFinalizer::finalize` / `markPaid` (billing_report.*). Each is one `DomainEvents::emit(...)` line marked `// webhooks (BRAI-104)`.
- **Models:** `Webhook` (`webhooks`: workspace, name, url, `secret` encrypted + hidden, `events` json names or `["*"]`, active, failure_count, disabled_at, last_delivery_at, created_by; `scopeListeningTo`, `generateSecret()` = `whsec_` + 48 hex), `WebhookDelivery` (`webhook_deliveries`: uuid, event, event_id, payload = the envelope, status pending|success|failed, attempts, response_code, response_body ≤ 2 KB, duration_ms, error, delivered_at; `Prunable` 30 days in `routes/console.php`).
- **Delivery:** `Support/Webhooks/WebhookDispatcher::queue()` (row + `DeliverWebhook`; sync-queue rules in KNOWLEDGE), `Jobs/DeliverWebhook` (headers `X-Brainitive-Event|Delivery|Timestamp|Signature`, `sha256=hmac(secret, "{timestamp}.{body}")`, 10 s timeout, 5xx / 429 / network retried 30 s / 2 min / 10 min, other codes fail; 10 failed deliveries in a row → inactive + `WebhookDisabledNotification` (`webhook_disabled`, category workspace) to the admins), `Support/Webhooks/WebhookUrl` (`OutboundUrl` + https in production / staging + the e2e-only private switch, checked on save and before every attempt).
- **Controller / routes** (`routes/webhooks.php`): `WebhookController` — `webhooks.index` (Inertia `Workspaces/Webhooks`), `.store` (JSON, returns the secret once), `.update`, `.destroy`, `.secret` (reveal), `.rotate`, `.test` (`ping`), `.deliveries` (JSON, 25 per page, `?before=`), `.redeliver` (same envelope, new delivery id). `Gate::authorize('update', $workspace)`; a webhook / delivery of another workspace is 404. Max 20 webhooks per workspace.
- **UI:** `Pages/Workspaces/Webhooks.tsx` (linked from Workspace settings' tab list), `components/webhooks/{WebhookFormDialog,DeliveriesPanel,WebhookDocs}.tsx`; types `WorkspaceWebhook`, `WebhookDelivery`.
- **Tests:** `tests/Feature/Webhooks/*` (management, delivery, events per hook point, billing events), e2e `e2e/tests/webhooks.spec.ts` (+ `e2e/helpers/webhooks.ts`: Node receiver on 127.0.0.1).

### Recurring issues (v1.40.0, BRAI-123)
Contract: [`tasks/release-v1.40.0.md`](./tasks/release-v1.40.0.md) → "2. BRAI-123". Rules and edge cases in KNOWLEDGE.
- **Models:** `RecurringIssue` (`recurring_issues`, per board: issue fields `title` (`{{date}}`), `description`, `item_type_id`, `priority_id`, `assigned_to`, `label_ids` json, `column_id` (null = first), `due_in_days`; schedule `frequency` daily|weekly|monthly|yearly, `interval`, `weekdays` json ISO 1–7, `monthly_mode` day|weekday, `month_day`, `month_week` 1–4 / -1, `month_weekday` 1–7 / 0 = Mon–Fri, `start_date`, `end_date`, `max_occurrences`, `timezone` (creator's); state `active_from`, `paused_at`, `occurrences_count`, `last_occurrence_date`, `next_occurrence_date` (null = ended), `last_error`; `created_by` = the actor of every run; `badge_label` appended) and `RecurringIssueOccurrence` (`recurring_issue_occurrences`: unique (template, `occurrence_date`), `item_id` → the issue). `Item::recurringOccurrence()` (HasOne) gives the card badge; `Board::recurringIssues()` serves the scoped binding.
- **Support:** `app/Support/RecurringIssues/` — `RecurrenceSchedule` (pure date maths: `fromArray`, `firstOnOrAfter`, `firstAfter`, `latestBetween`, `upcoming`, `summary`, `badge`), `RecurringIssueRunner::run($template, $now)` (creates the due occurrence as the creator, row lock, catch-up, pause on problems), `RecurringIssueForm` (rules, `normalizeSchedule`, `attributes`, `present`, `options`).
- **Command:** `app:create-recurring-issues {--template=}` (`CreateRecurringIssues`), every 15 min in `routes/console.php`, `withoutOverlapping()`.
- **Controller / routes** (`routes/automation.php`, ticket 2 section, under `projects/{project:slug}/boards/{board:slug}/recurring-issues`): `RecurringIssueController` — `recurring-issues.index` (JSON: templates + form options + the user's zone), `.store`, `.update`, `.destroy`, `.pause`, `.resume`, `.preview` (next 5 dates + summary for a schedule being edited), `.e2e-run` (registered **only** when `APP_ENV=e2e`). All `authorize('createItems', $board)`.
- **UI:** `components/recurring/RecurringIssuesDialog.tsx` (list, pause / resume, delete, create / edit), `RecurringIssueForm.tsx` (fields, schedule, server preview), `recurrence.ts` (defaults, helpers). Opened from `BoardHeader`'s "Board menu" → "Recurring issues" (`canCreateItems` prop: board + backlog pages) and from the issue sidebar's "Make this recurring" (`ItemDetailSidebar`, pre-filled). Cards show "Repeats weekly" (`withCardData` loads `recurringOccurrence.recurringIssue:id,frequency,interval`). Types `RecurrenceRule`, `RecurringIssueFields`, `RecurringIssueTemplate`, `RecurringIssueOptions`.
- **Tests:** `tests/Unit/RecurrenceScheduleTest.php`, `tests/Feature/RecurringIssues/RecurringIssuesTest.php`, e2e `e2e/tests/recurring-issues.spec.ts`.

### Workflow automation (v1.40.0, BRAI-63)
Plan [`tasks/feat-automation.md`](./tasks/feat-automation.md); contract `tasks/release-v1.40.0.md` → "1. BRAI-63".
- **Models:** `AutomationRule` (`automation_rules`: project, name, `enabled` (false on create), `version`, `actor_id` = whoever saved it last (the rule runs as them), `trigger`, `trigger_config` `{column_id?}`, `conditions` `[{type, value}]`, `actions` `[{type, value, text}]`, `last_run_at` / `last_run_status`; `scopeEnabledFor`), `AutomationRun` (`automation_runs`: unique (rule, `rule_version`, `event_id`), subject type / id / "KEY title" snapshot, `context`, status queued|running|success|skipped|error, `message`, `results` = one `{action, label, status ok|skipped|error, message}` per action index, `attempts`; `Prunable` 30 days). `Project::automationRules()` serves the scoped binding.
- **Support (`app/Support/Automation/`):** `AutomationCatalog` (triggers with their subject item|ticket, conditions, actions, value kinds, `MAX_ACTIONS` = 10, `EVENT_TRIGGERS` = DomainEvents name → trigger), `AutomationDefinition::validate()` (the only way a definition is stored; ids scoped through `AutomationOptions`, subject fit), `AutomationOptions` (project boards / helpdesks + columns, types, priorities, labels, members; `valid()`, `name()`), `AutomationSubject` (item/ticket helpers, `render()` placeholders), `AutomationConditions` (`check`, `describe`), `AutomationActions::plan()` (checks + an `apply` closure; shared by runs and the dry run), `AutomationEvents` (`seen` → after commit → `defer`/`flush` → `start`), `AutomationRunner` (`run`, `finish`, `preview`, `running()` origin flag), `DueDateTrigger`.
- **Event path:** `DomainEvents::emit(..., subject: $model, context: [...])` → `AutomationEvents::seen()` (one `exists` query per event; nothing while a run executes) → `DB::afterCommit` → held until the request's controller finished (global `Http/Middleware/DeferAutomations`), immediate elsewhere → `start()`: per enabled rule, trigger config + conditions pre-check, `createOrFirst` run, `Jobs/RunAutomation` (3 tries; inline on the sync queue). Hook points passing a subject: `ItemEvents::created/updated` (item.created / .moved with from/to column / .assigned), `ItemComments::add` (item.commented + comment id), `TicketController::store/storePublic` (ticket.created). Scheduled: `app:run-due-date-automations` (`RunDueDateAutomations`, every 5 min) → `DueDateTrigger::run()` (`item.due:<id>:<date>`).
- **Controller / routes** (`routes/automation.php`, ticket 1 section): `AutomationController` — `automations.index` (Inertia `Automations/Index`), `.create` / `.edit` (`Automations/Edit`), `.store`, `.update` (definition change → version + 1; actor = saver), `.toggle`, `.destroy`, `.preview` (JSON dry run by issue / ticket key, throttled). All `authorize('update', $project)`.
- **UI:** `Pages/Automations/{Index,Edit}.tsx`, `components/automations/{RunLog,ValueField,TestRulePanel}.tsx`, `useFlashToast.ts`; linked from the project page's Tools ("Open automations", admins) and Project settings → Integrations. Types `AutomationRule`, `AutomationRun`, `AutomationCatalog`, `AutomationOptions`, `AutomationPreview`.
- **Notification:** `AutomationNoticeNotification` (`automation_notice`, category status) for the Notify action; assignments / comments / status changes made by rules notify through the usual paths (`ItemObserver` → `Assignments`, `ItemComments`).
- **Tests:** `tests/Feature/Automation/{AutomationEngineTest,AutomationRulesTest}.php`, e2e `e2e/tests/automations.spec.ts`.

### Trello import (v1.41.0, BRAI-65)
- `app/Support/Imports/Trello/`: `TrelloImporter` (registry key `trello`), `TrelloExport` (reads +
  validates the board JSON, limits, normalized lists / cards / checklists / labels / members),
  `TrelloDescription` (card Markdown → sanitized HTML + check lists / attachment links).
- Preview + options UI: `components/imports/sources/TrelloPreview.tsx`. Fixture:
  `tests/Fixtures/imports/trello/board.json`. Tests: `TrelloImporterTest`, `e2e/tests/import-trello.spec.ts`.

### Notion import (v1.41.0, BRAI-66)
Plan [`tasks/feat-import-notion.md`](./tasks/feat-import-notion.md).
- `app/Support/Imports/Notion/`: `NotionImporter` (registry key `notion`, needs `PagePolicy::create`),
  `NotionArchive` (SafeZip unpack incl. nested `…-Part-N.zip`, `Title <id>` names → `NotionNode` tree,
  link resolution, limits), `NotionContent` (page / CSV table → sanitized HTML, links + files
  rewritten; shared by preview and run), `NotionAssets` (archive files → editor uploads).
- Shared: `app/Support/Imports/Shared/MarkdownHtml` — GFM Markdown → `SafeHtml` HTML with Lexical
  check lists and code languages; for any importer that needs tables / images / nested lists.
- Preview + options UI: `components/imports/sources/NotionPreview.tsx`. Fixture:
  `tests/Fixtures/imports/notion/`. Tests: `NotionImporterTest`, `e2e/tests/import-notion.spec.ts`.

### Sharing (read-only links)
- **Docs and issues:** `ShareLink` (morph `shareable`: `Page` / `Item`, random token, `expires_at`, `revoked_at`, views), `ShareLinkController` — manage via `share-links.*` (needs `update` on the item), public view `/s/{token}` → `Pages/Share/Show.tsx` (`noindex`, 410 when revoked / expired / deleted). UI: `components/share/ShareButton.tsx`.
- **Plans** keep their own `share_token` (`/share/plans/{token}`); bookmarks / snippets have `visibility = public`.

## Frontend layout (`resources/js/`)

| Dir | What |
|---|---|
| `app.tsx` / `bootstrap.ts` | Inertia entry + Axios setup |
| `Pages/` | One subdir per module (see rows above); each file = an Inertia page |
| `Layouts/` | `AppLayout` (sidebar app shell), `GuestLayout` (auth) |
| `components/` | Custom components; `components/ui/` = shadcn/ui; `components/editor/` = the 4 editors |
| `Components/` | Legacy Breeze components (PascalCase dir) |
| `hooks/`, `lib/` | Hooks; `lib/utils.ts` has `cn()`, plus `pageTree` helpers |
| `types/index.d.ts` | **All** entity interfaces + `PageProps`. Path alias `@/` → `resources/js/` |

## Where to add things (quick index)

| Adding… | Go to |
|---|---|
| A model | `app/Models/` (+ migration, factory) — [add-crud-feature skill](./skills/add-crud-feature.md) |
| A controller / route | `app/Http/Controllers/` + the right group in `routes/web.php` |
| A policy | `app/Policies/` **and register it** in `AppServiceProvider` |
| An Inertia page | `resources/js/Pages/<Module>/` + type in `types/index.d.ts` — [add-inertia-page skill](./skills/add-inertia-page.md) |
| An MCP tool | `app/Mcp/Tools/` + `BrainitiveServer::$tools` — [add-mcp-tool skill](./skills/add-mcp-tool.md) |
| A browser-extension capture | `Support/Captures/ContentCapture` (rules, policy, create — also used by the capture inbox) + `Extension/CaptureController` + `ext.*` route, then `browser-extension/lib/api.ts` + a form in `components/forms.tsx` |
| A notification | `app/Notifications/` — new types extend `CatalogNotification` + a `NotificationCatalog::TYPES` entry; senders in `app/Support/Notifications/` — [add-notification skill](./skills/add-notification.md) |
| A queued job | `app/Jobs/` |
| An artisan command | `app/Console/Commands/` |
| A file upload | `App\Support\Uploads` for the stored name (never the client's). Old risky files: `php artisan uploads:audit` |
| A server-side request to a user URL | `App\Support\OutboundUrl::options()` (public IPs only, pinned, no auto-redirects) |
| Something webhooks should hear about | a name in `DomainEvents::EVENTS` + one `DomainEvents::emit()` at the hook point + a builder in `EventPayloads` + a case in `WebhookEventsTest` |
| A new automation trigger / condition / action | `AutomationCatalog` (+ `EVENT_TRIGGERS` and `subject:` on the hook point's `emit()` for an event trigger) + `AutomationConditions::passes` / `AutomationActions::plan` + a case in `AutomationEngineTest` |
| Rendering stored HTML | `safeHtmlProp()` (`resources/js/lib/sanitize.ts`); server: `App\Support\SafeHtml` |

## Testing
- **PHPUnit** (`tests/`): PostgreSQL in the forced database `brainitive_test` (phpunit.xml).
- **E2E** (`e2e/`, Playwright, see `e2e/README.md`):
  - `env.ts`: target, per-port Postgres database, app environment;
  - `fixtures.ts`: `uid`, `urls`, per-worker owner/member sessions, `memberPage`, page-error guard;
  - `assets.ts`: in-memory `/build/assets` cache;
  - `mail.ts`: links from the e2e mail log;
  - `crawler.ts`: the every-button crawler;
  - `tests/*.spec.ts`: one spec per area, plus collaboration and crawl;
  - `helpers/*.ts`.
- Seed: `Database\Seeders\E2eSeeder`. Reset: `php artisan e2e:reset` (`App\Console\Commands\E2eReset`,
  e2e databases only).
- **CI:**
  - `tests.yml`: Pint, tsc + build, PHPUnit, and e2e in Chromium on every push;
  - `e2e-nightly.yml`: Firefox and WebKit;
  - `pre-release.yml`: the gate, then a deploy to dev.brainitive.app;
  - `deploy.yml`: production, only after the gate.

## Self-hosting (Docker)
- `Dockerfile` — multi-stage: composer → node (frontend; needs `vendor/tightenco/ziggy` for
  `tsc`) → `dunglas/frankenphp` runtime (Caddy + PHP 8.4, non-root, extensions installed
  with `install-php-extensions`).
- `docker/entrypoint.sh` — `CONTAINER_ROLE=app|queue|scheduler`; without `APP_KEY` the app role
  generates one into `storage/app.key` (the others wait for it); the app role waits for the
  DB, migrates, seeds item types / priorities / instance settings, creates Passport keys
  and runs `optimize`.
- `compose.yaml` + `.env.docker.example` — app, queue, scheduler, PostgreSQL 16, Redis 7.
  Queue and scheduler disable the image's healthcheck (it probes Caddy, which only `app` runs).
  compose sets `DB_CONNECTION/HOST/PORT` itself, so an old `.env` can't point at MariaDB.
- **Releases:** `.github/workflows/release.yml` runs on every push to `main`: reads the newest
  `CHANGELOG` version in `HelpModal.tsx` (`.github/scripts/changelog-release.mjs`), creates
  tag + GitHub Release if that version is new, then calls `docker-publish.yml` → image
  `ghcr.io/<owner>/brainitive` (`:edge` + `:sha-…` always; `:X.Y.Z`, `:X.Y`, `:latest` on a
  new version; a hand-pushed `v*` tag builds too).
- User guide: `docs/self-hosting.md`. `TRUSTED_PROXIES` (env, `bootstrap/app.php`) for
  reverse proxies.
