<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add an item to a bookmark group: a link (with url), a note, or a todo. Provide the workspace and bookmark slugs. Returns the item location. (File uploads are not supported via MCP.)')]
class CreateBookmarkItemTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'bookmark' => 'required|string',
            'type' => 'required|in:link,note,todo',
            'title' => 'nullable|string|max:500',
            'url' => ['nullable', 'string', 'max:2048', 'required_if:type,link', 'regex:#^https?://[^\s\x00-\x1f]+$#i'],
            'content' => 'nullable|string|max:10000',
        ]);

        $user = $request->user();

        try {
            $workspace = $this->workspaceForUser($user, $validated['workspace']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $bookmark = $workspace->bookmarks()->where('slug', $validated['bookmark'])->first();
        if (! $bookmark) {
            return Response::error('No bookmark found with that slug in this workspace.');
        }

        try {
            $this->authorizeFor($user, 'update', $bookmark);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $item = $bookmark->items()->create([
            'type' => $validated['type'],
            'title' => $validated['title'] ?? null,
            'url' => $validated['url'] ?? null,
            'content' => $validated['content'] ?? null,
            'position' => ($bookmark->items()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);

        return Response::structured([
            'created' => true,
            'item_id' => $item->id,
            'location' => ResourceLocator::locate($item),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'item_id' => $schema->integer(),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'bookmark' => $schema->string()->description('The bookmark slug.')->required(),
            'type' => $schema->string()->enum(['link', 'note', 'todo'])->description('The item type.')->required(),
            'title' => $schema->string()->description('Optional title.'),
            'url' => $schema->string()->description('URL — required when type is link.'),
            'content' => $schema->string()->description('Optional text content (for notes/todos).'),
        ];
    }
}
