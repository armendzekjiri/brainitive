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
| Auth | Breeze (session) + Socialite (Google/GitHub) + Passport (OAuth2.1 bearer for MCP/CLI) |
| Authorization | Explicit policies + `spatie/laravel-permission` v6 |
| Routing | Ziggy (`route()` in JS) |
| Forms | Inertia `useForm` (simple) / react-hook-form + Zod (complex) |
| Icons | Lucide React (only) |
| Toasts | Sonner |
| MCP | `laravel/mcp` |
| DB | SQLite default (PostgreSQL/MySQL production-ready) |
| Lint | Laravel Pint (PHP) |

## Roles

- **Workspace:** owner > admin > editor > viewer / member
- **Project:** admin > editor > viewer
- **Bookmark:** creator > editor > viewer
- **Instance Admin:** bypasses **all** policy checks (`Gate::before()` in `AppServiceProvider`)

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
| Custom components | PascalCase | `components/SearchModal.tsx` |
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
- Instance admins bypass everything via `Gate::before()`.
- Role helpers on `User`: `workspaceRole($ws)`, `projectRole($p)`, `hasWorkspaceAccess()`,
  `hasProjectAccess()`, `isInstanceAdmin()`.

### Routes
- Public: `/`, `/privacy`, `/terms`, `/cookies`, `/refund`, `/share/*` (public bookmark/snippet shares).
- App: inside `middleware(['auth', 'verified'])`, nested under workspace/project prefixes.
- Helpdesk **public** widget/portal: `widget/*` + `portal/*` use the `cors` middleware and are
  CSRF-exempt (`bootstrap/app.php`).
- **All routes named** (Ziggy depends on it).

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
Keep migrations cross-DB safe (the default is SQLite, tests use `:memory:`). Branch on the
database driver before using raw engine-specific SQL such as constraint changes or MySQL
`MODIFY` statements.

### Notifications
- Channels via `via()`. Use the `App\Notifications\Concerns\SendsWebPush` trait + its
  `withWebPush()` / `webPush()` helpers to add browser push **only for `User` notifiables**
  (never `TicketContact`). DB payload via `toDatabase()`. See the [skill](./skills/add-notification.md).

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

---

## Database / Eloquent

- Default SQLite; tests `:memory:`. Always eager load; always scope to the user's accessible
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
| CalendarConnection | (Google data, fetched live) | via Services |

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
- Autosave is JSON (`PUT pages.draft.save`) with `base_revision`; a mismatch returns `409` —
  the client must show it, never retry silently. Pass `force` only after the user chose
  "Keep mine".
- `draft_content` / `draft_name` / `draft_icon` are `$hidden` on the model; only
  `PageController::show()` reveals them to editors.
- Presence is a cache-backed heartbeat (`pages.presence`, `usePagePresence`), not websockets
  — Reverb/Echo aren't installed.

## Board & issue view design (Jira-style)

- Status colours come from `statusCategory()` in `components/board/ItemPrimitives.tsx`
  (first column = To Do grey, `is_done_column` = Done green, everything else = In Progress
  blue). Use `STATUS_CATEGORY_CLASSES` / `StatusLozenge` instead of column colours for status.
- Cards: summary first, metadata in the footer (type icon + key left; points, priority,
  assignee right). Keep new card metadata in that footer, not in extra rows.
- No buttons without handlers — wire them or leave them out.

## Sprints (Scrum boards)

- Move issues between sprints/backlog only via `Item::moveToSprint()` — it carries the
  subtasks along and appends to the target list. Never set `sprint_id` on a subtask alone.
- Sprint lifecycle goes through `Sprint::start()` / `Sprint::complete()` (they snapshot
  committed / completed numbers and the final burndown). Don't flip `status` by hand.
- Burndown/velocity numbers come from `SprintReport`; a sprint without story points burns
  down by issue count.
- Report charts use the `.viz-root` colour roles in `app.css` (validated with the dataviz
  validator): `--viz-series-1` for the measured series, `--viz-reference` grey for the
  guideline / committed.

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
