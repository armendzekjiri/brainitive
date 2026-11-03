# Skill: Add an App API endpoint (`/api/v1`, desktop + mobile)

Add an endpoint to the JSON API of the first-party desktop (Tauri) and mobile (Expo) apps.
Background and the registry of every endpoint: [`../API.md`](../API.md). Module map:
[`ARCHITECTURE.md` → App API v1](../ARCHITECTURE.md#app-api-v1--desktop--mobile-apps-v1490-brai-380).

**Golden rules**
- The App API is **separate from MCP, the CLI and the extension** (`/mcp`, `/api/cli`, `/api/ext`):
  never add an app endpoint there, never accept their Passport tokens here.
- An endpoint may only do what the signed-in user could do in the web app. Reuse the web's Support
  services, Form Request rules and policies; never write a second copy of business logic.
- **Done = documented**: the OpenAPI file, the row in `API.md` and the `## API (ready)` section on
  the mobile card (steps 7–9). Without them the mobile app can't use the endpoint.

## 1. Route — `routes/app-api.php`
Put it in its module's section (Instance, Auth, Account, Devices, Invitations & onboarding, or a new
one). The file is loaded under `/api/v1`, names `app.v1.*`, group `app-api` (JSON, `throttle:app-api`,
no session / CSRF / CORS) — don't add those again.

```php
Route::middleware(['auth:app', EnsureUserIsNotSuspended::class])->group(function () {
    // ── Widgets ────────────────────────────────────────────────────────────────────────────
    Route::get('workspaces/{workspace:slug}/widgets', [WidgetController::class, 'index'])->name('widgets.index');
    Route::post('workspaces/{workspace:slug}/widgets', [WidgetController::class, 'store'])->name('widgets.store');
    Route::delete('widgets/{widget}', [WidgetController::class, 'destroy'])->name('widgets.destroy');
});
```
- Signed-in endpoints go inside the `auth:app` group; public ones (like `GET /instance`) outside it,
  and they are documented as `security: []` automatically. Workspace content goes in the inner
  `verified` group (the web needs a verified address for all of it).
- No `->where()` / `whereUuid()` on POST / PUT / DELETE routes: web.php's GET fallback route turns a
  constraint mismatch into a 405. Check the id in the controller (`abort_unless(Str::isUuid($id), 404)`).
- Auth-like public writes add `throttle:app-auth`.
- Get the device of the call with `AppTokens::currentDevice($request)`.

## 2. Controller — `app/Http/Controllers/AppApi/WidgetController.php`
Thin: authorize, call the Support service / model method the web controller uses, return a Resource.

```php
#[Group('Widgets', weight: 60)]   // the API.md module = the OpenAPI tag (step 7)
class WidgetController extends Controller
{
    /** The widgets of a workspace, newest first. */   // first line = summary in the spec
    public function index(ListWidgetsRequest $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('view', $workspace);
        ...
    }

    /**
     * Create a widget.
     *
     * @throws LimitReached the workspace is at its plan limit (402 limit_reached)
     */
    public function store(StoreWidgetRequest $request, Workspace $workspace): JsonResponse
    {
        $widget = Widgets::create($request->user(), $workspace, $request->validated());

        return WidgetResource::make($widget)->response()->setStatusCode(201);
    }

    public function destroy(Widget $widget): Response
    {
        $this->authorize('delete', $widget);
        $widget->delete();

        return response()->noContent();   // DELETE → 204
    }
}
```
- **Tags:** `#[Group('<Module>', weight: n)]` (Dedoc\Scramble\Attributes\Group) on every controller.
  The name is the module heading in `API.md`; weights: Instance 10, Auth 20, Account 30, Devices 40,
  Invitations 50, new modules after that. `OpenApiSpecTest` fails for an untagged / unknown tag.
- **Docblocks** become the spec: first line = summary, the rest = description.
- **404 instead of 403** when the user must not learn the thing exists (another workspace's record):
  resolve through the user's workspaces / projects (`abort_unless(..., 404)`), then the policy.
- **Errors** are rendered and documented centrally (`AppApiErrors`, `App\Support\AppApi\OpenApi`):
  just throw / `abort()` / `authorize()`. Scramble can't see exceptions thrown deep in domain code:
  declare them with `@throws` on the controller method (plan limits: `@throws LimitReached`).

## 3. Form Request — `app/Http/Requests/AppApi/StoreWidgetRequest.php`
Every input goes through a Form Request (Scramble documents the body / query from `rules()`). Reuse
the web request's rules where the field is the same. Foreign ids are validated against the parent
(`Rule::exists(...)->where('workspace_id', $this->route('workspace')->id)`).

Creates: accept an optional `request_id` (`'request_id' => ['nullable', 'uuid']`) and run the write through
`Concerns\CreatesOnce::createOnce()`; answer 201, or 200 on a replay, with two plain
`return (new XResource($model))->response()` statements (Scramble documents both).

## 4. Resource — `app/Http/Resources/AppApi/WidgetResource.php`
- **Explicit fields only**, never `parent::toArray()` / a model's `toArray()`: no hidden columns,
  token hashes, IPs or other people's emails slip out.
- Wrapped `{data: {...}}` (the Resource default). Dates `->toIso8601ZuluString()` (UTC).
- Add `@mixin \App\Models\Widget` (or `@property` tags) so Scramble knows the model and its column
  types (warning JR001 otherwise).

## 5. Lists: cursor pagination + `updated_since`
`?cursor=&limit=` (default 50, max 100) → `{data: [...], next_cursor}`; `updated_since` (ISO-8601)
for sync. Order by a unique key (`name, id` or `updated_at, id`) so pages are stable. Small bounded
lists (like devices, ≤ `max_devices`) may skip pagination; say so in `API.md`.

```php
public function index(ListRequest $request, Workspace $workspace): WidgetCollection   // extends CursorCollection
{
    $this->visibleWorkspace($request, $workspace);                                     // ResolvesVisible → 404
    $page = $workspace->widgets()
        ->when($request->updatedSince(), fn ($q, $since) => $q->where('updated_at', '>=', $since))
        ->orderBy('name')->orderBy('id')
        ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());

    return new WidgetCollection($page);   // class WidgetCollection extends CursorCollection { public $collects = WidgetResource::class; }
}
```
Extra filters: extend `ListRequest` and merge `parent::rules()` (drop `updated_since` with
`Arr::except` when it can't mean anything, e.g. memberships).

## 6. Tests — `tests/Feature/AppApi/WidgetsTest.php`
Use `RefreshDatabase` + `Tests\Feature\AppApi\Concerns\ActsAsAppClient`. At least:

```php
$token = $this->appToken($user);
$this->getJson('/api/v1/workspaces/acme/widgets', $this->appHeaders($token))->assertOk()
    ->assertJsonStructure(['data' => [['id', 'name', 'updated_at']], 'next_cursor']);

// no token, and a Passport token of MCP / CLI / the extension → 401 in the error shape
$this->getJson('/api/v1/workspaces/acme/widgets')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
$this->getJson('/api/v1/workspaces/acme/widgets', $this->appHeaders($this->passportToken($user)))->assertUnauthorized();

// another workspace's record → 404, invalid input → 422 with errors
$this->deleteJson("/api/v1/widgets/{$foreign->id}", [], $this->appHeaders($token))->assertNotFound();
$this->postJson('/api/v1/workspaces/acme/widgets', [], $this->appHeaders($token))
    ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
```
Plus: suspended user → 403, a plan limit → 402 `limit_reached` where it applies, and an e2e journey
(`e2e/tests/`) when the endpoint changes something a user can see on the web. Run focused:
`TEST_DB_SUFFIX=<name> php artisan test tests/Feature/AppApi/WidgetsTest.php`.

## 7. Regenerate the OpenAPI file
```bash
composer openapi                        # = OPENAPI_WRITE=1 php artisan test --filter=OpenApiSpecTest
TEST_DB_SUFFIX=<name> composer openapi  # in a worktree
```
Commit `docs/api/openapi.json`. It is generated in PHPUnit (the migrated test database) because
Scramble reads column types from the database; `php artisan scramble:export` against a dev database
that lacks a migration gives a different file and the drift test fails in CI. Check the result:
your paths under the right tag, the request body, the `data` fields with types (not `{}`), the error
responses. Look at it live on `/docs/api` (`composer run dev`, local env).

## 8. Row in `docs/ai/API.md`
In the module's table: method + path, auth, desktop use, mobile scope (Full / Read + light edit /
Read-only / web only), mobile card key, status. Below the table: the response shape and anything
an app developer must know (which 4xx mean what, limits, what is web only).

## 9. The mobile card
Find the feature's card on the **Mobile app** board with the Brainitive MCP: `list-boards-tool`
(workspace `ndizeal-everything`, project `brainitive`) → `get-board-tool` board `mobile-app` →
`get-board-item-tool`. Append to its description (`update-board-item-tool`, keep the existing text):

```
## API (ready)
- GET /api/v1/workspaces/{slug}/widgets — the workspace's widgets, cursor-paginated
- POST /api/v1/workspaces/{slug}/widgets — create a widget (402 at the plan limit)
Scope: app · OpenAPI: docs/api/openapi.json#/Widgets · Registry: docs/ai/API.md#widgets
Built in: BRAI-xxx (desktop) · Mobile scope: Full
```
A feature the apps only open in the browser gets `## API: web only — open in browser` instead. Put
the card key in the `Card` column of `API.md` (step 8). No MCP connection: say so in the PR and
leave the card for the lead — don't invent it.

## 10. Docs + gate
New module → ARCHITECTURE "App API v1" entry; a new convention → `API.md` Conventions + CONVENTIONS
"App API v1". Then the gate (WORKFLOW): Pint, tsc, PHPUnit, build, e2e.
