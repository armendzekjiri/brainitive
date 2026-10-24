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
- **Support page:** `/support/{public_slug}` (`HelpdeskWidgetController::supportPage`) renders the embed form full-page with branding; the address is set under Helpdesk settings → Embed.
- **Notifications UI:** every notification type is rendered by `components/NotificationMessage.tsx` (bell dropdown + `/notifications`). A new notification type needs a case there.

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
  is reached; times in the recipient's `User::localTimezone()`; dedup via `sent_item_reminders`).
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
- **Command palette (BRAI-83):** `components/CommandPalette.tsx` is the only launcher — ⌘K/Ctrl+K, Ctrl+. opens command mode (`>`); commands are defined in `AppLayout`. Recent destinations live in localStorage as `{type,id}` per user and are resolved/permission-filtered by `CommandPaletteController@recent` (`POST /command-palette/recent`).
- **Notifications:** `NotificationController` (DB-backed list + unread count + mark read) → `Pages/Notifications/`; delivery = `database` + optional `WebPushChannel`. Push subscriptions via `PushSubscriptionController`. See the [add-notification skill](./skills/add-notification.md). **Web push requires `VAPID_*` keys in `.env`** — without them the frontend can't subscribe and nothing is ever delivered (see [`KNOWLEDGE.md`](./KNOWLEDGE.md)).
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
| A notification | `app/Notifications/` (+ `SendsWebPush` for push) — [add-notification skill](./skills/add-notification.md) |
| A queued job | `app/Jobs/` |
| An artisan command | `app/Console/Commands/` |
| A file upload | `App\Support\Uploads` for the stored name (never the client's). Old risky files: `php artisan uploads:audit` |
| A server-side request to a user URL | `App\Support\OutboundUrl::options()` (public IPs only, pinned, no auto-redirects) |
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
- `docker/entrypoint.sh` — `CONTAINER_ROLE=app|queue|scheduler`; the app role waits for the
  DB, migrates, seeds item types / priorities / instance settings, creates Passport keys
  and runs `optimize`.
- `compose.yaml` + `.env.docker.example` — app, queue, scheduler, MariaDB 11.4, Redis 7.
- **Releases:** `.github/workflows/release.yml` runs on every push to `main`: reads the newest
  `CHANGELOG` version in `HelpModal.tsx` (`.github/scripts/changelog-release.mjs`), creates
  tag + GitHub Release if that version is new, then calls `docker-publish.yml` → image
  `ghcr.io/<owner>/brainitive` (`:edge` + `:sha-…` always; `:X.Y.Z`, `:X.Y`, `:latest` on a
  new version; a hand-pushed `v*` tag builds too).
- User guide: `docs/self-hosting.md`. `TRUSTED_PROXIES` (env, `bootstrap/app.php`) for
  reverse proxies.
