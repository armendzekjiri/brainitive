# Skill: Add an MCP tool

Expose a capability to remote AI clients (ChatGPT/Claude connectors) via the Brainitive MCP
server. Background: [`ARCHITECTURE.md` → AI integrations](../ARCHITECTURE.md#ai-integrations--mcp-server--in-app-ai).

**Golden rule:** a tool may only do what the signed-in user could do in the UI. Always
resolve models through the access helpers and run the same policy check the controller would.

## 1. Create the tool — `app/Mcp/Tools/CreateWidgetTool.php`
Model it on an existing tool (`CreateSnippetTool` is a good template):
```php
namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Widget;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a widget in a project. Provide workspace + project slugs and a name. Returns the widget location.')]
class CreateWidgetTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project'   => 'required|string',
            'name'      => 'required|string|max:255',
        ]);

        $user = $request->user();

        try {
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
            $this->authorizeFor($user, 'create', [Widget::class, $project]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $widget = $project->widgets()->create(['name' => $validated['name']]);

        return Response::structured([
            'created'     => true,
            'widget_slug' => $widget->slug,
            'location'    => ResourceLocator::locate($widget),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created'     => $schema->boolean(),
            'widget_slug' => $schema->string(),
            'location'    => ResourceLocator::outputSchema($schema),
        ];
    }
}
```

## 2. Conventions
- **Auth:** resolve via `workspaceForUser()` / `projectForUser()` (from `InteractsWithBrainitive`)
  — they throw `AuthorizationException` if the user lacks access. Then `authorizeFor($user,
  $ability, $args)` for the policy. Catch and return `Response::error($e->getMessage())`.
- **Identifiers:** workspaces/projects/boards/task-lists/snippet-collections/bookmarks by
  `slug`; pages by `uuid`; tasks/board items by numeric id (read the parent first).
- **Every write returns a `location`** via `ResourceLocator::locate($model)` so the client can
  tell the user *where* it created the thing + the URL. Mirror it in `outputSchema()`.
- **Never return `null` for a `string`/`integer` output field.** Strict MCP clients (e.g.
  OpenClaw) validate `Response::structured(...)` against `outputSchema()` and reject the *whole*
  response if a declared `string` comes back `null` (ChatGPT/Claude silently tolerate it). For
  any value that can be null — model attributes, `?->` chains, `?? null`, `?->format(...)` —
  coerce to `''` (e.g. `$item->due_date?->format('Y-m-d') ?? ''`). If the field is a genuinely
  nullable **integer** (no sensible empty value, e.g. `parent_id`), mark the schema
  `$schema->integer()->nullable()` instead.
- Calendar tools (personal Google data) use `ResolvesCalendarConnections` + always take an
  IANA timezone on writes.
- `#[Description]` is the model-facing doc — make it precise; that's how the AI picks the tool.

## 3. Register it — `app/Mcp/Servers/BrainitiveServer.php`
Add the class to the `$tools` array (group it with related tools). It won't be exposed
otherwise. The server returns all tools in one page (`defaultPaginationLength = 200`) so
clients that ignore pagination still see it.

## 4. Verify
- `php artisan test` (add a test exercising the tool's handler if practical).
- Manually: connect via an MCP client or call `/mcp` with a Passport bearer token and confirm
  the tool appears in `tools/list` and runs.
- The server `#[Instructions]` block guides clients on navigation — update it if you add a new
  content type.

## 5. Update docs
Bump the tool count / add the capability in [`ARCHITECTURE.md`](../ARCHITECTURE.md).
