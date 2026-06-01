<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the bookmark groups in a workspace, with item counts. Bookmarks hold links, notes, todos and files. Use a bookmark slug with create_bookmark_item to add to one.')]
class ListBookmarksTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
        ]);

        $user = $request->user();

        try {
            $workspace = $this->workspaceForUser($user, $validated['workspace']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $bookmarks = $workspace->bookmarks()
            ->withCount('items')
            ->orderBy('position')
            ->get(['id', 'name', 'slug', 'description'])
            ->filter(fn ($b) => $b->isViewableBy($user))
            ->map(fn ($b) => [
                'name' => $b->name,
                'slug' => $b->slug,
                'description' => $b->description ?? '',
                'items_count' => $b->items_count,
            ])
            ->values()
            ->all();

        return Response::structured([
            'workspace' => $workspace->name,
            'bookmarks' => $bookmarks,
            'count' => count($bookmarks),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string(),
            'bookmarks' => $schema->array()->items($schema->object([
                'name' => $schema->string(),
                'slug' => $schema->string(),
                'description' => $schema->string(),
                'items_count' => $schema->integer(),
            ])),
            'count' => $schema->integer(),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
        ];
    }
}
