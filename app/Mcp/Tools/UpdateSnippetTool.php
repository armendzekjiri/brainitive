<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\SnippetCollection;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Edit an existing snippet: change its title, body content, language, or description. Identify it by workspace + collection slug + snippet slug (from list_snippets).')]
class UpdateSnippetTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'collection' => 'required|string',
            'snippet' => 'required|string',
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|nullable|string',
            'language' => 'sometimes|nullable|string|max:50',
            'description' => 'sometimes|nullable|string|max:2000',
        ]);

        $user = $request->user();

        try {
            $workspace = $this->workspaceForUser($user, $validated['workspace']);
            $this->authorizeFor($user, 'create', [SnippetCollection::class, $workspace]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $collection = $workspace->snippetCollections()->where('slug', $validated['collection'])->first();
        if (! $collection) {
            return Response::error('No snippet collection found with that slug in this workspace.');
        }

        $snippet = $collection->snippets()->where('slug', $validated['snippet'])->first();
        if (! $snippet) {
            return Response::error('No snippet found with that slug in this collection.');
        }

        $changes = array_filter(
            $request->only(['title', 'content', 'language', 'description']),
            fn ($v, $k) => array_key_exists($k, $validated),
            ARRAY_FILTER_USE_BOTH
        );

        if (empty($changes)) {
            return Response::error('Provide at least one field to update (title, content, language or description).');
        }

        $snippet->update($changes);

        return Response::structured([
            'updated' => true,
            'snippet_slug' => $snippet->slug,
            'location' => ResourceLocator::locate($snippet->fresh()),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'snippet_slug' => $schema->string(),
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
            'collection' => $schema->string()->description('The snippet collection slug.')->required(),
            'snippet' => $schema->string()->description('The snippet slug (from list_snippets).')->required(),
            'title' => $schema->string()->description('New title.'),
            'content' => $schema->string()->description('New body / code.'),
            'language' => $schema->string()->description('New syntax language, e.g. php, javascript.'),
            'description' => $schema->string()->description('New description.'),
        ];
    }
}
