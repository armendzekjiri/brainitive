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
External site ──▶ routes/web.php (cors, no CSRF) ──▶ Helpdesk widget/portal
```

- **No API layer for the app itself.** Inertia passes controller props straight to React.
- **Three route entry points:** `web.php` (the app, Inertia), `ai.php` (MCP server),
  `api.php` (CLI, Passport bearer). Plus `auth.php` (Breeze + social) and `console.php`.
- **Request bootstrap / global middleware:** `bootstrap/app.php` — registers
  `HandleInertiaRequests`, the `cors` alias, and CSRF exemptions for `widget/*` + `portal/*`.

## Authentication & authorization (read before touching auth)

| Concern | Where | Notes |
|---|---|---|
| Web login (session) | Laravel **Breeze**, `routes/auth.php`, `app/Http/Controllers/Auth/` | Email verification required |
| Social login | `Auth/GoogleAuthController.php`, `Auth/GitHubAuthController.php` | Laravel **Socialite** |
| API/MCP/CLI tokens | Laravel **Passport** (OAuth 2.1 + bearer) | Configured in `AppServiceProvider::boot()` (token TTLs) |
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
- **Pages:** `Pages/Lists/`

### Helpdesk / Ticketing
- **Models:** `Helpdesk`, `HelpdeskColumn`, `HelpdeskField`, `Ticket`, `TicketReply`, `TicketContact`, `TicketFieldValue`, `TicketAttachment`
- **Controllers:** `HelpdeskController` (board/columns/fields config), `TicketController` (tickets, replies, attachments, custom field values), `HelpdeskWidgetController` (embeddable widget)
- **Policy:** `HelpdeskPolicy`
- **Routes:** authed `helpdesks/...` + `tickets/...` under `projects/{project}`; **public** `widget/*` and `portal/*` (cors, CSRF-exempt) for the embedded widget and customer portal
- **Pages:** `Pages/Helpdesks/`, `Pages/Tickets/`, `Pages/Widgets/`
- Tickets support custom fields (`HelpdeskField` → `TicketFieldValue`) and external contacts (`TicketContact`, can be notified by email but not web push).

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
  `SendItemReminders.php` + `ItemReminderNotification` (board/task-list items reaching their
  `start_time` → bell **+** push; assignee only; dedup via `sent_item_reminders`). Both scheduled
  every 5 min in `routes/console.php`.
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
- **Search:** `SearchController` (invokable) → `/search`
- **Notifications:** `NotificationController` (DB-backed list + unread count + mark read) → `Pages/Notifications/`; delivery = `database` + optional `WebPushChannel`. Push subscriptions via `PushSubscriptionController`. See the [add-notification skill](./skills/add-notification.md). **Web push requires `VAPID_*` keys in `.env`** — without them the frontend can't subscribe and nothing is ever delivered (see [`KNOWLEDGE.md`](./KNOWLEDGE.md)).
- **Files:** `FileController` — `/upload/image`, `/upload/document` (public disk, UUID names)

### AI integrations — MCP server & in-app AI
- **In-app AI:** `AiController` (`ai/settings`, `ai/models`, `ai/generate` under `workspaces/{workspace}`) — per-workspace AI provider settings.
- **MCP server:** `routes/ai.php` registers `App\Mcp\Servers\BrainitiveServer` at `/mcp` (Passport `auth:api`) + `Mcp::oauthRoutes()` for OAuth 2.1 discovery/registration.
  - **Tools:** `app/Mcp/Tools/*Tool.php` (~38), registered in the `$tools` array on `BrainitiveServer`.
  - **Shared helpers:** `app/Mcp/Tools/Concerns/InteractsWithBrainitive` (slug→model resolution + policy enforcement, so the AI can only touch what the user can), `ResolvesCalendarConnections`.
  - **Support:** `app/Support/Mcp/` — `ResourceLocator` (the `location` object every write returns), `PageContentConverter` (Markdown↔editor format), `CalendarFormatter`.
  - To add a tool, see the [add-mcp-tool skill](./skills/add-mcp-tool.md).

### CLI API
- **Controller:** `CliController` — `routes/api.php`: `/cli/login` issues a Passport token; `auth:api`-guarded `/cli/...` endpoints expose workspaces + task lists (JSON).

---

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
| A notification | `app/Notifications/` (+ `SendsWebPush` for push) — [add-notification skill](./skills/add-notification.md) |
| A queued job | `app/Jobs/` |
| An artisan command | `app/Console/Commands/` |

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
