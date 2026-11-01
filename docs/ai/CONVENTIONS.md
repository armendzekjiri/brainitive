# Conventions — Coding Rules & Patterns

The rules every agent follows when writing code. To *locate* code, use
[`ARCHITECTURE.md`](./ARCHITECTURE.md). For step-by-step task recipes, see
[`skills/`](./skills).

---

## Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.2, Laravel 12 |
| Frontend | React 18, TypeScript 5, Inertia.js v2 |
| CSS | Tailwind CSS 3 + shadcn/ui (new-york) + Radix UI |
| Rich text | Editor.js, Tiptap 3, Lexical; Excalidraw whiteboard |
| Auth | Breeze (session) + Socialite (Google/GitHub) + Passport (OAuth2.1 bearer for MCP/CLI/browser extension) |
| Authorization | Explicit policies + `spatie/laravel-permission` v6 |
| Routing | Ziggy (`route()` in JS) |
| Forms | Inertia `useForm` (simple) / react-hook-form + Zod (complex) |
| Icons | Lucide React (only) |
| Toasts | Sonner |
| MCP | `laravel/mcp` |
| DB | PostgreSQL (tests, CI, production, Docker); migrations aren't MySQL/MariaDB-safe |
| Lint | Laravel Pint (PHP) |

## Roles

- **Workspace:** owner > admin > editor > viewer / member
- **Project:** admin > editor > viewer
- **Bookmark:** creator > editor > viewer
- **Instance Admin:** manages accounts, workspaces (metadata) and settings in `/admin`; **no**
  policy bypass and no content access — same workspace / project access as anyone else

## Brand colors

| Name | Hex | Usage |
|---|---|---|
| Blue | `#0E83DC` | Primary buttons, links, active states |
| Dark Slate | `#485E72` | Body text, headings, secondary buttons |
| Green | `#69A748` | Success, checkmarks, publish |
| Light Gray | `#C0C5C0` | Borders, dividers, muted backgrounds |
| Page BG | `#F4F6F8` | Public page backgrounds |

Use hex directly on public pages; app interior uses shadcn/ui CSS variables (light/dark) in
`resources/css/app.css`.

---

## Naming conventions

| What | Convention | Example |
|---|---|---|
| Models | PascalCase singular | `BoardColumn.php` |
| Controllers | PascalCase + Controller | `BoardController.php` |
| Policies | PascalCase + Policy | `BoardPolicy.php` |
| Migrations | snake_case + timestamp | `create_boards_table.php` |
| Page components | PascalCase | `Pages/Boards/Show.tsx` |
| UI components (shadcn) | kebab-case | `components/ui/dropdown-menu.tsx` |
| Custom components | PascalCase | `components/CommandPalette.tsx` |
| Hooks | camelCase, `use` prefix | `hooks/use-mobile.tsx` |
| Routes | dot-notation, **always named** | `tickets.replies.store` |
| DB tables | snake_case plural | `board_columns` |
| DB pivots | alphabetical singular | `project_user` |
| MCP tools | PascalCase + Tool | `CreateSnippetTool.php` |

Path alias: `@/` → `resources/js/`. Ziggy: `ziggy-js` → `vendor/tightenco/ziggy`.

---

## Backend rules (Laravel)

### Models
- Use `HasFactory`, `SoftDeletes` (main entities). **Always `$fillable`, never `$guarded`.**
- `casts()` method for JSON/array/datetime fields.
- Auto-generate `uuid` and `slug` in `boot()` via `static::creating()`.
- `$appends` for computed display attributes (e.g. `avatar_display`, image URLs).
- `getRouteKeyName()` → `'slug'` (Workspace, Project, Board, TaskList, Helpdesk, Snippet) or
  `'uuid'` (Page, Bookmark). Items/tickets/tasks bind by numeric id.
- Explicit relationship return types (`BelongsTo`, `HasMany`, `BelongsToMany`, `HasOne`).

```php
class Example extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['uuid', 'name', 'slug'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_public' => 'boolean'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
            $model->slug ??= Str::slug($model->name);
        });
    }

    public function getRouteKeyName(): string { return 'slug'; }
}
```

### Controllers
- `$this->authorize('ability', $model)` for policy checks.
- **Inline** `$request->validate([...])` — not FormRequest classes (only `ProfileUpdateRequest` exists; see [`KNOWLEDGE.md`](./KNOWLEDGE.md) for why).
- Return `Inertia::render('Module/Page', ['prop' => $data])`. JSON endpoints return arrays/`response()->json()`.
- Eager load to avoid N+1: `->with(['relation.nested'])` or closures.
- Redirect with `redirect()->route('name')` / `back()`; flash via `session()->flash('success', …)`.
- File uploads → `public` disk with UUID filenames (via `FileController`).
- Routes nest by slug: workspace → project → board/page/etc.

### Policies — **must be registered**
- One policy per main model in `app/Policies/`.
- **Register every policy explicitly** with `Gate::policy(Model::class, ModelPolicy::class)`
  in `AppServiceProvider::boot()`. (This project does *not* rely on auto-discovery.) Note
  `SnippetPolicy` is registered against `SnippetCollection`.
- Methods: `viewAny, view, create, update, delete` + custom (`manageMembers`, `inviteMembers`, …).
- There is **no** `Gate::before()` bypass. Don't add `isInstanceAdmin()` branches to content
  queries or policies — instance admins work only through the admin console.
- Role helpers on `User`: `workspaceRole($ws)`, `projectRole($p)`, `hasWorkspaceAccess()`,
  `hasProjectAccess()`, `isInstanceAdmin()`.

### Routes
- Public: `/`, `/privacy`, `/terms`, `/cookies`, `/refund`, `/imprint`, `/legal/*` (all `LegalController`,
  v1.44.0), `/share/*` (public bookmark/snippet shares).
- App: inside `middleware(['auth', 'verified'])`, nested under workspace/project prefixes.
- Helpdesk **public** widget/portal: `widget/*` + `portal/*` use the `cors` middleware and are
  CSRF-exempt (`bootstrap/app.php`).
- **All routes named** (Ziggy depends on it).

### Security rules (see KNOWLEDGE for the why)
- **Uploads:** store with `App\Support\Uploads::name()` / `imageName()`. Never use
  `getClientOriginalName()` / `getClientOriginalExtension()` in a stored path.
- **Raw HTML:** React renders it only through `safeHtmlProp()` (`@/lib/sanitize`); server-side
  rich text from untrusted sources goes through `App\Support\SafeHtml::clean()`.
- **Foreign ids** in request data are validated against the parent
  (`Rule::exists(...)->where('board_id', $board->id)`) or authorized with a policy.
- **Requests to user-supplied URLs** only via `App\Support\OutboundUrl::options()`.
- **Public tokens** are scoped to one record (`Ticket::publicAccessToken()`), never to a person
  or email address.

### API tokens & scopes
- Passport scopes are declared in `AppServiceProvider` (`Passport::tokensCan`): `mcp:use` (AI
  connectors) and `capture` (browser extension).
- A new token-guarded surface decides which tokens may use it. The extension API requires
  `CheckToken::using('capture')`; anything that can **read** content (`/mcp`, `/api/cli`) adds
  `RejectCaptureTokens`, so a leaked extension token can only save things and search titles.
- Extension endpoints resolve models by numeric id and authorize with the same policies as the
  app — never a new permission rule.

### Migrations
```php
$table->id();
$table->uuid('uuid')->unique();
$table->string('slug')->unique();   // where applicable
$table->timestamps();
$table->softDeletes();              // main entities
$table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
$table->json('settings')->nullable();
```
Keep migrations cross-DB safe (self-hosted installs default to SQLite; production and all tests run PostgreSQL). Branch on the
database driver before using raw engine-specific SQL such as constraint changes or MySQL
`MODIFY` statements.

### Notifications
- Every in-app type is listed in `NotificationCatalog::TYPES` (type → category) and its `via()` is
  `RespectsPreferences::channelsFor($notifiable, '<type>')`: the user picks channels per category.
  Push messages via `SendsWebPush::webPush()`; ticket contacts get mail only. DB payload via
  `toDatabase()` with the contract keys (`type`, `title`, `body`, `url`, `actor_*`, `project_name`,
  `subject_key`). See the [skill](./skills/add-notification.md).
- **Push is sent only through `WebPushChannel` / `PushTester`** (both use `App\Support\Push\WebPushClient`);
  never build a `Minishlink\WebPush\WebPush` elsewhere (v1.44.0, BRAI-154).
- **Mail is branded** — see "Email" below.
- **Activity emails are batched (BRAI-429).** A new member notification type that is "normal activity"
  (not account / guest / time-critical) goes into `MailDigest::TYPES` and its class implements
  `DigestibleMail` (`CatalogNotification`: override `digestSubject()` with the model whose `view` policy
  guards it). Never send mail around `channelsFor()`, or it skips the digest and the preferences.
- **Time-based notifications** (reminders, digests) compute dates and times in the recipient's
  `User::localTimezone()`, never the app's UTC, and dedup through `sent_item_reminders` with a
  key that contains the absolute time (or the local date for once-a-day messages). Personal
  tasks without an assignee notify their creator (`TaskListItem::reminderRecipient()`).

---

## Frontend rules (React + TypeScript + Inertia)

- **No API layer.** Pages in `resources/js/Pages/<Module>/` map to `Inertia::render('Module/Page')`.
- Wrap pages in `AppLayout` (app) or `GuestLayout` (auth). Always render `<Head title=…>`.
- shadcn/ui from `@/components/ui/*` (new-york). Merge classes with `cn()` from `@/lib/utils`.
- **Lucide React only** for icons. Sizes: `h-4 w-4` small, `h-5 w-5` medium, `h-6 w-6` large.
- Ziggy in JS: `route('name', { param })`, `<Link href={route(...)}>`, `router.visit/post`.
- Forms: Inertia `useForm` for simple CRUD; react-hook-form + Zod for complex. Always show
  server `errors`.
- Toasts: Sonner — `toast.success('…')`. Flash via `usePage().props.flash`.
- **Every entity interface lives in `resources/js/types/index.d.ts`**; type all component props.

```tsx
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';

interface Props { entity: EntityType; }

export default function MyPage({ entity }: Props) {
    return (
        <AppLayout>
            <Head title="Page Title" />
        </AppLayout>
    );
}
```

### UI / styling
- Tailwind 3 JIT. Utility-first; custom CSS only for animations.
- Public card: `bg-white/70 backdrop-blur-sm border border-[#C0C5C0]/30 rounded-2xl`.
- Primary button: `bg-[#0E83DC] text-white shadow-lg shadow-[#0E83DC]/20 hover:bg-[#0b6fbf] rounded-xl`.
- Radii: `rounded-2xl` cards, `rounded-xl` buttons, `rounded-full` badges.
- Animations (`app.css`): `.animate-fade-in`, `.animate-fade-in-up`, `.animate-pulse-slow`.
- Content width `max-w-6xl mx-auto`; section padding `px-6 py-24 sm:py-32`.
- **Page icons:** always render through `components/page/PageIcon.tsx` (`<PageIcon icon=… />`, or
  `isImageIcon()` for a custom element) — an uploaded icon is a `data:` URL, never print it as text.
- **Pages for people outside the workspace** (feedback / client portals) use `Layouts/PortalLayout.tsx`
  and live under `/feedback/` or `/client/` in `routes/portal.php` — see KNOWLEDGE "Guest identities".

---

## Database / Eloquent

- Self-hosted default SQLite; production + tests PostgreSQL (`brainitive_test`). Always eager load; always scope to the user's accessible
  workspaces. Use `create()`/`update()` with validated data.

Key relationships:

| Parent | Child | Type |
|---|---|---|
| User | Workspaces | BelongsToMany (pivot: role) |
| Workspace | Projects / Bookmarks / SnippetCollections | HasMany |
| Project | Boards / Pages / TaskLists / Helpdesks / Labels | HasMany |
| Board | Columns / Items | HasMany |
| Item | Comments / Attachments / Links | HasMany; Labels BelongsToMany; parent/subtask self-ref |
| Page | Children | HasMany (self-referential tree) |
| Helpdesk | Columns / Fields / Tickets | HasMany |
| Ticket | Replies / Attachments / FieldValues | HasMany; Contact BelongsTo |
| SnippetCollection | Snippets | HasMany |
| Bookmark | Items | HasMany |
| CalendarConnection | (external calendar data, fetched live) | via `CalendarProviders` (Google Tasks: `GoogleTasksService`) |

---

## Rich text editors & Excalidraw

Four editors, all in `resources/js/components/editor/`. Content stored as JSON in a `content`
column (cast `array`):

1. **Editor.js** — structured block content (`EditorJS.tsx`).
2. **Tiptap 3** — rich page editing; custom extensions in `editor/extensions/`, slash commands
   via `SlashCommands.tsx` (type `/`).
3. **Lexical** — advanced docs (`LexicalEditor.tsx`, `RichLexicalEditor*.tsx`); nodes in
   `editor/nodes/`, plugins in `editor/plugins/`.
4. **Excalidraw** — whiteboard pages (`is_whiteboard` appended attr), JSON in page `content`,
   standalone build in `excalidraw-build/`, `excalidraw-editor.blade.php`.

**Whiteboards are edited live** (Miro-style, no draft): the editor autosaves 2.5 s after the
last change with `base_revision`; a stale revision gets 409 → "Load latest / Overwrite"
banner (`force`). History = checkpoints via `Page::saveWhiteboard()`: on Save / Save & exit,
when the newest version is older than `WHITEBOARD_VERSION_INTERVAL_MINUTES`, or when another
user saved last. Templates are element skeletons in `excalidraw-build/src/templates.js`
(add one = add an entry to `TEMPLATES`). Issue cards are rectangles with
`customData.brainitive = 'issue'` + `itemId`, linked to the item; they refresh from
`pages.whiteboard.issues?ids[]=…` when the editor opens (not realtime). After changing
anything in `excalidraw-build/src`, run `cd excalidraw-build && npm run build` and commit
`public/js/excalidraw-dist/`.

When reading page content, check which editor produced it before parsing. MCP writes go
through `app/Support/Mcp/PageContentConverter`.

**Document mode (pages).** `Pages/Pages/Show.tsx` renders `RichLexicalEditor` with
`documentMode` — an Outline/Evernote-style writing surface:
- Typography lives in one scoped block, `.lexical-doc` in `resources/css/app.css` (two-class
  selectors so it beats both the Lexical `theme` utilities and `.prose`). Change document
  styling there — don't add `prose-*` modifiers to the `ContentEditable`.
- Formatting is on demand: `FloatingFormatToolbarPlugin` (bubble above a selection) and
  `EmptyBlockPlaceholderPlugin` ("Type / for commands") are on in `documentMode` + editable.
  The classic `ToolbarPlugin` is opt-in via the header toggle (`localStorage`
  `page-formatting-toolbar`). Block conversions go through the shared `applyBlockType()`.

**Smart links (v1.42.0, BRAI-143).** A link to workspace content is stored as a plain anchor:
`<a href="<canonical url>" data-smart-link="item:12|page:<uuid>|ticket:<id>|plan:<id>" data-smart-link-view="inline|card">text</a>`.
- Never store a smart link in another shape (no custom element, no JSON-only node): everything outside
  the editor (share page, client portal, MCP, exports, search, `SafeHtml`) must keep seeing a working link.
  `SmartLinkNode` imports `a[data-smart-link]` with a higher priority than `LinkNode`.
- Refs are ids / uuids, never titles; the chip's live data comes from `smart-links.index`, batched per
  tick by `smartLinkStore.ts` — never one request per chip.
- Turn smart links on with the editor's `smartLinks={{ workspaceSlug, projectSlug }}` prop; without it the
  node renders as a plain link (comments, tickets, public pages).

---

## Page workflow (draft → publish → versions)

Pages work like Confluence. Keep these rules when touching pages:

- **Never write `content` of a published page directly from an editing flow.** Go through
  `Page::saveDraft()` (fills `draft_*` on published pages, `content` on unpublished ones),
  then `publishChanges()` to make it live. Anything that changes the live version of a
  published page must record a version (`recordVersion()`), like the MCP `update-page` tool.
- **Every page listing uses `->visibleTo($user)`** — tree, sidebar (`HandleInertiaRequests`),
  project page, dashboards, search, MCP. Unpublished pages are author + admin only.
- **Authorize with `PagePolicy`** (`view` / `update` / `delete`, `create` with
  `[Page::class, $project]`). Don't hand-roll role checks.
- **Change a page's parent only through `PageMover::move()`** (BRAI-431) — it checks cycles and the
  project, renumbers positions and keeps the slug unique per parent. Authorize `update` on the page and
  `view` on the new parent first. Never set `parent_id` with a query.
- Autosave is JSON (`PUT pages.draft.save`) with `base_revision`; a mismatch returns `409` —
  the client must show it, never retry silently. Pass `force` only after the user chose
  "Keep mine".
- `draft_content` / `draft_name` / `draft_icon` are `$hidden` on the model; only
  `PageController::show()` reveals them to editors.
- Presence is a cache-backed heartbeat (`pages.presence`, `usePagePresence`), not websockets
  — Reverb/Echo aren't installed.
- **Review required before publish (v1.42.0, BRAI-80).** Anything that publishes or changes the
  live version of a page goes through `ReviewGate::publishing()` (locks the page row, re-reads it,
  refuses unless an approved, open review matches `Page::draftHash()`). Don't add a publish /
  restore / live-write path that skips it; on review-required published pages name / icon edits go
  to the draft. The switch (`pages.review_required`) is for project admins and not for whiteboards.
- **Reviews read the snapshot, not the draft.** Show reviewers `page_reviews.snapshot_*` through
  `PageReviewPolicy`; never widen `PagePolicy::view` for them. "Stale" is computed from the hash,
  never stored.
- **Inline comments anchor by text quote** (`quote` + ≤ 64-char prefix / suffix), never by editing
  the page HTML. Change the matching rule in `QuoteAnchor.php` **and** `components/page-review/anchors.ts`
  together. Details: [`tasks/feat-document-review.md`](./tasks/feat-document-review.md).

## Board & issue view design (Jira-style)

- Status colours come from `statusCategory()` in `components/board/ItemPrimitives.tsx`
  (first column = To Do grey, `is_done_column` = Done green, everything else = In Progress
  blue). Use `STATUS_CATEGORY_CLASSES` / `StatusLozenge` instead of column colours for status.
- Cards: summary first, metadata in the footer (type icon + key left; points, priority,
  assignee right). Keep new card metadata in that footer, not in extra rows.
- No buttons without handlers — wire them or leave them out.
- **`items.completed_at` follows the column** (`Item::saving`, BRAI-346): don't compute it in a feature when you
  change `column_id` through the model. Set it yourself only to carry a known date (an import's resolved date);
  query-builder column moves must set it and call `BoardLive::itemIds()`. A new board-filter-like rule for the
  Kanban view goes into the client (boards load every card) and, if AI clients read the board, into `GetBoardTool`.
- Integration-driven moves (GitHub merge transitions, v1.42.0) live in `boards.settings.github.merge_column_id`
  and save the item model (history marked with `ActivityLog::via()`), never raw column updates.

## Sprints (Scrum boards)

- Move issues between sprints/backlog only via `Item::moveToSprint()` — it carries the
  whole subtree along and appends to the target list. Never set `sprint_id` on a subtask alone.
- **Subtasks nest 3 levels** (`Item::MAX_DEPTH = 2`; `lib/itemTree.ts` mirrors it). Anything
  that acts on an issue as a whole (delete, move to sprint / board, duplicate, complete) uses
  `Item::descendantIds()`, not just `children()`.
- Sprint lifecycle goes through `Sprint::start()` / `Sprint::complete()` (they snapshot
  committed / completed numbers and the final burndown). Don't flip `status` by hand.
- Burndown/velocity numbers come from `SprintReport`; a sprint without story points burns
  down by issue count.
- Report charts use the `.viz-root` colour roles in `app.css` (validated with the dataviz
  validator): `--viz-series-1` for the measured series, `--viz-reference` grey for the
  guideline / committed.

## Calendar providers (v1.42.0, BRAI-140)

- Everything calendar-related (page, reschedule, event dialog, quick create, MCP tools, reminders)
  talks to `App\Support\Calendar\CalendarProviders`, never to a provider service directly. Writes go
  through `CalendarProviders::writable()`.
- **Adding a provider** (Outlook, CalDAV / iCloud, ICS link): one class in
  `app/Support/Calendar/Providers/` extending `BaseCalendarProvider` + one line in `PROVIDERS`. Follow
  the recipe in [`tasks/feat-calendar-providers.md`](./tasks/feat-calendar-providers.md#how-to-add-a-provider-contract-for-the-outlook-and-caldav--ics-branches)
  and the `CalendarProvider` docblock (normative). Google Tasks stay outside the contract.
- **User-entered URLs (CalDAV servers, ICS links) only through `App\Support\Calendar\SafeRemoteUrl`**
  (`send()`, `allowed()`), never `Http::` directly. Private / loopback addresses are refused everywhere
  except `APP_ENV=e2e` + `CALENDAR_LINKS_ALLOW_PRIVATE`; don't add another switch. Never log or show an ICS
  URL (it is a secret) — show the host.
- **OAuth providers** (Google, Outlook): authorization code + PKCE S256, `state` / verifier one-time in the
  session; tokens in encrypted columns, saved with `forceFill()->save()`. Only Google deactivates a
  connection on a refused refresh.
- **Conferencing is optional** (`conferenceProviders()` + `createEvent(conference, attendees)`); the extra
  normalized keys `conference_url` / `conference_provider` are present only with a call — don't add them
  to `NormalizedEvent::KEYS`.
- Every throttle on a calendar / meetings route gets its own key (`throttle:N,1,<route name>`).

## Workflow automation (BRAI-63, conditions BRAI-144)

- **`AutomationCatalog` is the single source of truth** for triggers, actions and the condition matrix
  (`CONDITION_FIELDS`, `OPERATORS`, `CUSTOM_FIELD_OPERATORS`). The builder (`ConditionRow.tsx`,
  `conditions.ts`) renders what `forFrontend()` sends — never hard-code a field / operator list in React.
- Definitions are stored only through `AutomationDefinition::validate()`; conditions are read through
  `normalizeCondition()` (legacy `{type, value}` → `{field, operator, value}`), so engine, describe and
  builder never see the old shape.
- A field's value is read in one place, `ConditionFacts::get()`; evaluation + wording in
  `AutomationConditions` (the client wording in `conditions.ts` mirrors `describe()` — change both).

## Search (v1.43.0, BRAI-55)

- **Every new searchable model gets a Source + observer registration:** a `SearchSource` in
  `app/Support/Search/Sources/` (query builder only, a fixed number of queries per batch; `documents($ids)`
  omits anything not indexable — that removes it), listed in `SearchIndexer::sources()`, and the model (plus
  any container that decides its visibility) in `SearchServiceProvider`'s observed list. Don't edit model
  files for indexing; don't write to `search_entries` directly.
- **Visibility mirrors the view policies, in SQL** (`TableSearchEngine::visibleEntries`): a new type adds its
  rule there, never a PHP filter after the query (counts and pages must agree). Live names / URLs come from
  `SearchPresenter`, batched per kind of record.
- Writes that bypass Eloquent call `SearchIndexer::touch*()`; a source change bumps
  `SearchReindex::VERSION`. Search callers (page, palette, MCP, extension) go through `SearchService`,
  never a direct `search_entries` query.

## Offline (v1.43.0, BRAI-82)

- **Writes from the offline outbox go through `OfflineSync`:** a new mutation type is an applier method in
  `app/Support/Offline/Appliers/` + a line in `OfflineSync::TYPES` + an action in `lib/offline/actions.ts`
  (+ `applyLocally` in `lib/offline/sync.ts`). It carries **base values per field** and checks them with
  `FieldMerge`, uses the **same policy and validation as the online endpoint**, answers 410 for anything
  deleted or no longer visible, and reports conflicts per field — never resolved on the server.
- Offline data is per account and device: IndexedDB `brainitive-offline-<userId>` through `lib/offline/db.ts`,
  cleared on sign-out / another account. Nothing offline-related is stored on the user row.
- `public/sw.js` stays network-only for everything except the shell, failed navigations / full Inertia visits
  and `/build/assets`; documents and live routes are never intercepted.

## Live (v1.44.0, BRAI-151/152/153)

- **Publish live events only through `Live::publish()` with allow-listed keys, never content.** Payloads carry
  ids / uuids, counts, revisions, positions, `*_at`, status / type keys and `fields` (`Live::KEY`) — never
  titles, names, bodies or labels. Clients refetch through the normal permission-checked endpoints. A new
  payload key is a deliberate change of `Live::KEY` plus a test. Signals carry `{}` or a field name at most.
- **Channels are authorized in one place:** a new type is an entry in `LiveChannels::TYPES` + an arm in
  `load()` running the existing `view` policy (parents not trashed) + cases in `LiveChannelsTest`. Never add
  a permission rule in `realtime/` (channel names are opaque there).
- **Publish where the change happens, from model events when you can** (`LiveThreads`, `BoardLive`), so every
  write path (UI, MCP, automations, imports, offline sync, portal, inbound mail) is covered; a write that
  bypasses model events calls `BoardLive::itemIds()` / `columnsReordered()` / `itemsLeft()`.
- **Subscribers handle `onResync`** (refetch after a reconnect gap), ignore their own echo (`X-Live-Client`
  + actor), and never apply remote changes over a user's in-progress gesture (drag, open editor, pending
  save) — apply after, or let the existing conflict guard (409 banner) decide.
- **Live documents (Yjs) keep Laravel as the source of truth:** live saves go through the same model method
  as the single-user path with `collabWrite` set (`Page::saveDraft`, `Page::saveWhiteboard`,
  `$item->update`); any other content write resets the epoch. Every live feature has a single-user fallback
  when `COLLAB_URL` / live is off or the server is unreachable.
- Every live change adds e2e coverage with two browser contexts; specs with their own realtime server take
  their own port offset (KNOWLEDGE → "Live channels").

## Email (v1.44.0, BRAI-155)

- **Every new email uses `brandedMail()` / `BrandedMailMessage` and is added to `EmailPreviews`.** Member
  notifications go through `CatalogNotification` or `$this->brandedMail($notifiable, TYPE)`
  (`RespectsPreferences`) so they get "Manage notifications"; mail to customers / guests uses
  `BrandedMailMessage::guest($n, 'You get this email because …')`; account mail `::account()`. Mailables
  use `markdown:` with `<x-mail::message>`. Add the email to `EmailPreviews::entries()` —
  `EmailTemplatesTest` renders every entry and Admin → Emails previews it.
- User text goes into `->quote()` or plain lines (secured encoding escapes it); never build Markdown links in
  lines — use `->action()` or `->footerLink()`. Links come from APP_URL (`BrandedMail::appRoute()`), never
  the request host.
- Mail to a workspace's visitors (lead confirmation, portal / ticket contact mail) appends
  `PublicOperator::mailLine()`.

## Legal & privacy (v1.44.0, BRAI-156)

- **Public pages a customer runs show `PublicOperator`:** pass `operator` (`PublicOperator::for($workspace)`;
  `nameFallback: true` only where the page already showed the organisation — support page, widget, lead
  forms; token links stay generic) and render `OperatorNotice` (`PortalLayout` does it for portal pages).
  Add the page's path prefix to `Analytics::EXCLUDED_PREFIXES`.
- **No third-party requests from the browser without consent.** Self-host fonts and libraries (bundle them
  with Vite / @fontsource, copy assets into `public/build`), no CDN `<script>` / `<link>`; iframes or scripts
  from other services in content render inside `ExternalEmbedGate` (click to load). `LegalPagesTest` fails on
  known CDN hosts.
- **Every new processor / integration / outbound host, cookie or storage key → update
  `docs/legal/data-inventory.md`, the sub-processor list (`Pages/Legal/Subprocessors.tsx` /
  `LegalInfo::providers()`) and the privacy policy (`Pages/Privacy.tsx`, cookies / storage in
  `Pages/Cookies.tsx`), and bump the document version in `LegalInfo::DOCUMENTS`.**
- Legal facts come from `config/legal.php` through `LegalInfo` only; Cloud-only facts live in
  `LegalInfo::CLOUD_DEFAULTS`, never in config defaults (self-hosted installs must not show them).
- Accounts are deleted through `App\Support\Admin\UserDeletion`, never `$user->delete()`.

## Licence & dependencies

- Brainitive is licensed under the **Business Source License 1.1** since v1.46.0 (`LICENSE`: free
  production use under €2M annual revenue, no competing hosted service, each version AGPL-3.0 after
  four years; v1.14.0–v1.45.3 stay AGPL-3.0). Contributions need the CLA (`CLA.md`, bot in
  `.github/workflows/cla.yml`) so the project can also be licensed commercially. Licence fields in
  `composer.json` / `package.json` say `BUSL-1.1`. We still call it "open source" in marketing; the
  README, docs (`docs-site/self-hosting/licence.md`) and Terms name the BSL and its terms exactly.
- **New dependencies must allow use in a source-available / commercial product:** MIT, BSD, ISC,
  Apache-2.0, MPL-2.0, LGPL (used as a library). **No GPL / AGPL** (they'd force our code under
  their licence), no SSPL / BUSL / Commons Clause / non-commercial licences, no "pro"/paid packages.
  Dual-licensed packages are fine when one option is permissive (nette: BSD or GPL).
  Dependency changes still need the maintainer's approval.
- Anything that only makes sense for Brainitive Cloud (billing, plan limits) must be optional
  and off by default, so self-hosted instances keep every feature.

## Plans (Gantt planner)

- **One JSON document per plan** (`plans.data` = `{ rows, dependencies }`), edited client-side and
  saved whole: `PUT plans.save` with `base_revision` (409 on a stale revision unless `force`),
  1.5 s after the last change. The server validates with `PlanDocument::rules()` and repairs with
  `PlanDocument::normalize()` (end ≥ start, levels, dangling / duplicate dependencies) — never
  store client input unnormalised.
- **Rows are a flat outline**: `level` = indent; a row is a group when the next row is deeper.
  All outline changes go through the pure functions in `components/plan/ops.ts` (indent,
  outdent, move, duplicate, delete with sub-rows) so undo/redo stays one snapshot per action.
- **Dates are Y-m-d strings, inclusive**; the frontend computes with UTC day numbers
  (`dates.ts`) so time zones never shift a bar. Durations = Mon–Fri working days.
- View changes (zoom, columns, collapse, grid width) are saved but not undoable
  (`editor.apply(fn, { history: false })`). A drag is one undo step (`beginGesture` /
  `endGesture`).
- History = checkpoints like whiteboards: "Save version", every 30 min while editing, or when
  another user saved last. The slug never changes on rename (links and share URLs stay valid).
- Templates live in `PlanTemplates` (PHP) and reach the UI as the shared `planTemplates` prop.
- **Rows linked to issues** (`row.itemId`): the **issue is the source of truth**. On open the
  page applies issue title / assignee / status / dates / progress to linked rows
  (`components/plan/issues.ts`, saved without an undo step; missing issues are unlinked).
  Name and date edits on a linked row are written to the issue on save by
  `PlanIssueSync::writeThrough()`, **only for fields that changed against the stored plan**
  (a stale tab must never revert board changes) and only with item update rights. Dates:
  bars' span = start / due; a lone milestone = due date only. Status is read-only on linked
  rows; Who is read-only once the issue has an assignee.
- Reuse `ProjectIssues` for anything that shows or creates issues outside the board.

## Project templates (BRAI-72)

- **A template document never contains database ids.** Every object carries a local `ref`,
  references use refs, dates are day offsets from day 0. Build documents only through
  `TemplateCapture` / `BuiltinTemplates` and always pass them through
  `TemplateDocument::normalize()` before use; never accept a document from a request.
- **New project data that should travel with templates** (a new board setting, a new item
  column, …) needs a capture line in `TemplateCapture`, a field in `TemplateDocument`, and a
  create line in `TemplateInstantiator` — plus a test in `ProjectTemplatesTest`. Anything
  personal or secret (people, comments, tokens, integrations) stays out on purpose.
- **A new built-in template** = a builder method in the matching `Support/Templates/Builtin/*Templates` class (refs, day offsets, default types/priorities by `slug:`) plus one line in `BuiltinTemplates::TEMPLATES` (name, category, icon, description, tags). The loop test creates a project from every built-in.
- **Never instantiate outside `TemplateRuns::createProject()`** — it keeps the run record
  (idempotency) and turns failures into a message; the project is created in one transaction.

## Custom fields (BRAI-77)

- **Write values only through `App\Support\Boards\ItemFieldValues::apply()`** — it validates
  every value against the field type, scopes field ids to the item's project and board, and
  writes the `field_changed` history. Never set `item_field_values` rows by hand in a feature.
- **No value = no row.** `null` clears (deletes the row); `0` and `false` are stored values.
  Read values with `fieldValue()` from `lib/customFields.ts` (TS) or `ItemFieldValues::valueOf()`
  (PHP), never by guessing the typed column.
- **Never delete options or used fields.** Removing an option archives it; a field with values
  can only be archived. A field's type changes only while it has no values.
- **Filters/sorting are client-side** (boards load every card). URL params: `f{fieldId}` for
  filters (format in `lib/customFields.ts`), `sort=[-]f{fieldId}` in the list view. Empty values
  sort last in both directions — use `compareNullable()` for any new sortable column.

## Item history (activity log)

- Changes to `Item` columns are logged automatically by `ItemObserver` — don't log them by
  hand. New tracked field → add it to `ItemObserver::TRACKED_FIELDS` (with the model used to
  resolve its display value) and a label in `HISTORY_FIELD_LABELS` (`ItemDetail.tsx`).
- Changes that aren't a model update (pivot syncs, related rows) need an explicit
  `ActivityLog::log($item, '<action>', $changes, $metadata)` in the controller; add the
  action to `ItemHistoryEntry` and `HistoryEntryBody`.
- Store **display values** (names, keys, formatted durations) in the log, not ids — the
  history must stay readable after renames and deletes.
- Bulk position updates use the query builder on purpose (no events → no history noise).
- Durations: parse/format with `Item::parseDuration()` / `Item::formatDuration()` (PHP) and
  `lib/duration.ts` (TS) — keep both in sync.

## Testing

- PHPUnit 11; base `tests/TestCase.php`. Feature tests use `RefreshDatabase` + factories +
  `actingAs($user)`. Test names: `test_user_can_create_board()`.
- Cover: every policy method, CRUD, validation rejects bad data, authz denies unauthorized
  users, soft deletes, unique slug + uuid generation.
- Board tests share `tests/Feature/Boards/InteractsWithBoards.php` (editor / viewer /
  outsider, board with To Do / In Progress / Done). Factories: `Board`, `Item`.
- Page tests share the role matrix in `tests/Feature/Pages/InteractsWithPages.php`
  (author / editor / viewer / admin / outsider). Factories: `Workspace`, `Project`, `Page`
  (`published()`, `folder()`), `PageVersion`.
