<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\SnippetCollection;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a snippet collection (a group that holds snippets) in a workspace. Create the collection first, then add snippets to it with create_snippet. Returns the new collection slug and its location.')]
class CreateSnippetCollectionTool extends Tool
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
            $this->authorizeFor($user, 'create', [SnippetCollection::class, $workspace]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $collection = $workspace->snippetCollections()->create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($workspace, $validated['name']),
            'description' => $validated['description'] ?? null,
            'icon' => '📋',
            'visibility' => $validated['visibility'] ?? 'private',
            'position' => ($workspace->snippetCollections()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);

        return Response::structured([
            'created' => true,
            'collection_slug' => $collection->slug,
            'location' => ResourceLocator::locate($collection),
        ]);
    }

    private function uniqueSlug($workspace, string $name): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        $i = 1;
        while ($workspace->snippetCollections()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'name' => $schema->string()->description('The collection name.')->required(),
            'description' => $schema->string()->description('Optional description.'),
            'visibility' => $schema->string()->enum(['private', 'public'])->description('Visibility. Defaults to private.'),
        ];
    }
}
