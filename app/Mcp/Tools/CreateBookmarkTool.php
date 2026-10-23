<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Bookmark;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a bookmark group (a collection that holds links, notes, todos and files) in a workspace. Create the group first, then add items to it with create_bookmark_item. Returns the new bookmark slug and its location.')]
class CreateBookmarkTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'visibility' => 'nullable|in:private,public',
        ]);

        $user = $request->user();

        try {
            $workspace = $this->workspaceForUser($user, $validated['workspace']);
            $this->authorizeFor($user, 'create', [Bookmark::class, $workspace]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $bookmark = $workspace->bookmarks()->create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($workspace, $validated['name']),
            'description' => $validated['description'] ?? null,
            'icon' => '🔖',
            'visibility' => $validated['visibility'] ?? 'private',
            'position' => ($workspace->bookmarks()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);

        return Response::structured([
            'created' => true,
            'bookmark_slug' => $bookmark->slug,
            'location' => ResourceLocator::locate($bookmark),
        ]);
    }

    private function uniqueSlug($workspace, string $name): string
    {
        $base = Str::slug($name) ?: 'bookmarks';
        $slug = $base;
        $i = 1;
        while ($workspace->bookmarks()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'bookmark_slug' => $schema->string(),
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
            'name' => $schema->string()->description('The bookmark group name.')->required(),
            'description' => $schema->string()->description('Optional description.'),
            'visibility' => $schema->string()->enum(['private', 'public'])->description('Visibility. Defaults to private.'),
        ];
    }
}
