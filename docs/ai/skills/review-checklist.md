# Skill: Review a change before the PR

What to check in a diff, split into four lanes: **backend**, **frontend**, **database** and
**security**. Run it after the build step and before the gate (see
[WORKFLOW](../WORKFLOW.md#3--build)). In Claude Code each lane has a subagent in
`.claude/agents/` that reads its section here. Other agents can go through the lanes by hand.

The rules come from [`CONVENTIONS.md`](../CONVENTIONS.md) and [`KNOWLEDGE.md`](../KNOWLEDGE.md).
This file only says what to look for, so when a rule changes, change it there and update
the matching line here. Adapted from the reviewer agents in [ECC](https://github.com/affaan-m/ECC)
(MIT) and trimmed to this stack.

## Scope

- Branch review: `git diff main...HEAD` (use the PR's base branch if it isn't `main`).
  Local work in progress: `git diff` plus `git diff --staged`.
- Review **only changed lines and the code they directly affect**. Read the surrounding file
  before you flag something. Legacy code that the diff doesn't touch is out of scope.
- Reviewers report problems. They don't edit code.

## Not findings here

These are generic rules that this repo doesn't follow on purpose. Don't flag them:
- Missing `declare(strict_types=1)`, missing `final`, missing `readonly`
- No FormRequest: validation is inline `$request->validate()` (KNOWLEDGE: *Inline validation*)
- No PHPStan / Psalm / ESLint, and no Pest (we use PHPUnit 11)
- Sanctum token abilities: tokens are Passport (KNOWLEDGE: *MCP server & auth*)
- Next.js / RSC / Server Actions, Supabase RLS, Livewire, Filament
- Pint style on legacy files the diff doesn't touch (gate with `pint --dirty`)

---

## Backend: PHP / Laravel

**Critical**
- New or changed controller action without `$this->authorize(...)` or an equivalent policy check
- New policy not registered with `Gate::policy()` in `AppServiceProvider::boot()` (it silently does nothing)
- An `isInstanceAdmin()` branch or a `Gate::before()` in content queries or policies
- Raw SQL / `whereRaw` / `DB::raw` with interpolated input instead of bindings
- `$guarded`, or `create($request->all())` instead of validated data
- Writing `content` of a **published** page from an editing flow without going through
  `saveDraft()` / `publishChanges()` / `recordVersion()`
- Page listings without `->visibleTo($user)`

**High**
- N+1: relations used in loops or props without `with()` / `load()`
- Queries not scoped to the user's accessible workspace or project
- Foreign ids in request data that aren't scoped to the parent. Either form counts as scoped:
  `Rule::exists(...)->where('board_id', …)`, or plain `integer` validation followed by an
  intersect with the parent's ids (see `Swimlanes::normalize()`). Plain `exists:` alone isn't scoped
- JSON / `array`-cast columns (`settings`, plan data) written straight from `validated()`.
  `'x' => 'array'` keeps unknown nested keys, so rebuild the value from whitelisted keys,
  bound its size and **merge** into the stored value so sibling keys survive
- A new options list or picker prop that queries the same data differently from the page
  that uses the values (different scope, fallback or order, e.g. `ItemType::forProject`
  with and without the `show()` fallback)
- `$user->delete()` instead of `App\Support\Admin\UserDeletion`
- Counting soft-deleted models with `DB::table()` (it includes trashed rows)
- Whole-issue actions using `children()` instead of `Item::descendantIds()`; `sprint_id` set
  on a subtask alone instead of `Item::moveToSprint()`; sprint status flipped by hand
- `Item` column changes logged by hand (`ItemObserver` does it) or pivot changes not logged
  at all (`ActivityLog::log`); ids stored in the log instead of display values
- A new `Inertia::render('X/Y')` whose page file doesn't exist and that no test asserts
  with `assertInertia(... ->component(...))` (`withoutVite()` hides this). New props on an
  existing render don't need this
- Page prop named after a shared Inertia prop (`workspaces`, `auth`, `flash`, …)
- Route without a name
- Swallowed exceptions (`catch (\Throwable) {}` with no log and no rethrow)

**Medium**
- Missing relationship return types (`BelongsTo`, `HasMany`, …). Other return types: only
  where the surrounding file already declares them (controller redirect actions mostly don't)
- Missing `$fillable` / `casts()` entries for new columns
- `dd()` / `dump()` / `ray()` left in code
- A new user-facing change without a `CHANGELOG` entry in `HelpModal.tsx`

**Tests** (in feature tests, flag what's missing)
- Every new policy method; authorized user succeeds *and* outsider or viewer is denied
- Validation rejects bad input
- Real notifications sent without `Notification::fake()`

## Frontend: React / TypeScript / Inertia

**Critical**
- `dangerouslySetInnerHTML` not going through `safeHtmlProp()` (`@/lib/sanitize`)
- `div.innerHTML = …` to get text out of HTML (use `DOMParser`)
- `href` / `src` from user data without blocking `javascript:` / `data:`
- Secrets in `VITE_*` env vars or in props sent to the client
- Rules of hooks broken (hook in a condition, a loop or after an early return)

**High**
- Missing or wrong effect dependencies; effects without cleanup (listeners, intervals,
  timeouts, `AbortController`)
- Stale closures in handlers that are registered once (see the plan chart listener note in
  KNOWLEDGE)
- Derived state stored with `useEffect` + `setState` instead of computed during render
- Local state seeded from a prop, kept with `preserveState`, and then compared with (or
  edited against) the server's normalized copy of that prop. Reset it from the response in
  `onSuccess`, or compare a canonical form; otherwise "unsaved changes" sticks after a save
- UI state saved in `localStorage` that can go stale against server config (a mode or filter
  that no longer exists): fall back to a valid value
- State mutated in place
- `key={index}` on lists that reorder (cards, rows, sprint lists)
- Entity types (shapes of server models) declared in a page or component instead of
  `resources/js/types/index.d.ts`. Feature config types may live in `lib/`. `any` / `as`
  casts that hide a real mismatch with the controller props; `as never` on an Inertia
  request payload (works around `RequestPayload` typing) is fine
- Props read in camelCase where Eloquent serializes snake_case (`last_editor`, not `lastEditor`)
- Import path case that differs from git (`@/Components/…` vs `@/components/ui/…`)
- Inertia requests (`useForm` or `router.*`) with no visible error handling (no `errors`
  shown, no `onError` → toast/inline message) and saves with no success feedback
- Autosave that retries on a `409` instead of showing the conflict
- Buttons without a handler

**Medium**
- Icons not from `lucide-react`; hard-coded colours where `STATUS_CATEGORY_CLASSES` /
  `StatusLozenge` or the shadcn CSS variables apply (user-chosen palettes, like lane or
  label colours, are data, not styling)
- Click handlers on `div`s instead of `button`s; inputs without a label; `<img>` without
  `alt`; `target="_blank"` without `rel="noopener noreferrer"`
- Pages without `<Head title>`; routes built by hand instead of `route()`
- Components over ~300 lines that gained a new concern (suggest a split; don't require one)

## Database: migrations, queries, Eloquent

Production runs **PostgreSQL**; local and tests run **SQLite**. Most of the bugs in this
lane come from that difference. Query-shaped items in the Backend lane also belong here
(N+1, workspace/project scoping, foreign id scoping, JSON columns, `withTrashed()`).

**Critical**
- Engine-specific SQL (`MODIFY`, `DROP CONSTRAINT`, Postgres-only functions) without a
  driver branch
- Reference rows the app needs (types, priorities, settings) that only arrive through a
  seeder: production deploys don't run seeders (KNOWLEDGE: *Production deploys never run
  seeders*)
- A destructive change (drop column/table, type narrowing) with no backfill and no way back
- A new project-level table that stores `workspace_id` (project moves would drift)

**High**
- Foreign key columns without `constrained()` + an explicit `onDelete` choice; new
  `cascadeOnDelete` on `created_by` / owner columns (deleting a user wipes content)
- A query that returns different results on SQLite and Postgres:
  - `orderBy` on a nullable column. Postgres puts NULLs last, SQLite first; use
    `orderByRaw('col is null')->orderBy('col')`, as `ReleaseController` does
  - no tie-breaker (`->orderBy('id')`) where the order is visible
  - `like` (case-insensitive on SQLite, case-sensitive on Postgres)
  - JSON operators / `whereJsonContains`
  - boolean comparisons written as `= 1`
- New `NOT NULL` column on an existing table without a default or backfill. SQLite lets
  some of these through, Postgres doesn't: test against Postgres (KNOWLEDGE has the
  `docker run` line)
- Unique keys reused without `withTrashed()` (project `slug` / `key` include soft-deleted rows)
- Missing index on a column used in a frequent `where` / `orderBy` / join. **That includes
  foreign keys:** Postgres doesn't index the referencing column, and `foreignId()->constrained()`
  only adds the constraint
- Large writes in a loop instead of one query; bulk position updates that fire model
  events (they should use the query builder, see *Item history*)
- Multi-write changes without `DB::transaction()` where a partial write leaves bad data

**Medium**
- `down()` missing or wrong
- `string` for long free text (use `text`), `float` for money or durations

Useful check: `php artisan migrate --pretend` shows the SQL a migration will run, but
against SQLite it only shows SQLite SQL. To catch Postgres-only failures, run it (or the
tests) against the throwaway Postgres from KNOWLEDGE.

## Security

Go through the rules in CONVENTIONS *Security rules* and *API tokens & scopes*. Look at
these first:

**Critical**
- Missing authorization (see Backend → Critical); a public route (`/share/*`, `widget/*`,
  `portal/*`, webhooks) that returns more than a whitelist of fields: serialize field by
  field, never the model (see `ShareLinkController::show`)
- Uploads stored with `getClientOriginalName()` / `getClientOriginalExtension()` instead
  of `App\Support\Uploads`
- Untrusted rich HTML not cleaned with `App\Support\SafeHtml::clean()` on the server or
  `safeHtmlProp()` in React
- Server-side requests to user-supplied URLs that don't go through `App\Support\OutboundUrl::options()`
- A new token-guarded endpoint that returns content without `RejectCaptureTokens`, or one
  that should be capture-only without `CheckToken::using('capture')`
- Public tokens scoped to a person or email instead of one record
- Webhook or signature checks with `==` instead of `hash_equals`
- Secrets in code, in logs or in Inertia props; `APP_DEBUG` / `display_errors` turned on
- Shell (`exec`, `Process`) with user input; `unserialize()` on user data

**High**
- New CSRF exemptions in `bootstrap/app.php`
- A new public / unauthenticated endpoint, or an expensive one (AI, outbound requests, email,
  token issuing), without `throttle`. Ordinary authenticated settings routes don't need one
- A new nested route outside a `scopeBindings()` group with no manual parent check (the child
  resolves without being tied to its parent, so the policy checks the wrong project)
- A new column or JSON key on a model that's passed **whole** to an Inertia page: check that
  every role that can open the page (viewers included) may see it
- `fillable` that now includes ownership or role columns a user can set
- Errors that echo internal details to the user
- New dependency: AGPL-compatible licence (CONVENTIONS *Licence & dependencies*), and
  `composer audit` / `npm audit` are clean for it

---

## Report format

One block per finding, most severe first:

```text
[CRITICAL|HIGH|MEDIUM] Short title
File: path/to/file.php:42
Issue: what goes wrong, with the concrete input or state that triggers it
Fix: what to change (reference the helper/convention to use)
```

End with a verdict: **Block** (any Critical or High), **Fix before merge** (Medium only)
or **Approve** (nothing found). Say which lanes you checked. If you aren't sure about
something, say so instead of padding the report.
