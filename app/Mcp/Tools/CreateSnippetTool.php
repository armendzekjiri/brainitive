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

#[Description('Create a snippet (reusable code or text block) inside a collection. Provide the workspace and collection slugs, a title, and the content. Optionally set a language (e.g. php, javascript, sql). Returns the snippet location.')]
class CreateSnippetTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'collection' => 'required|string',
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'language' => 'nullable|string|max:50',
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

        $snippet = $collection->snippets()->create([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? '',
            'language' => $validated['language'] ?? 'plaintext',
            'visibility' => 'inherit',
            'position' => ($collection->snippets()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);

        return Response::structured([
            'created' => true,
            'snippet_slug' => $snippet->slug,
            'location' => ResourceLocator::locate($snippet),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
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
            'title' => $schema->string()->description('The snippet title.')->required(),
            'content' => $schema->string()->description('The snippet body / code.'),
            'language' => $schema->string()->description('Optional syntax language, e.g. php, javascript. Defaults to plaintext.'),
        ];
    }
}
