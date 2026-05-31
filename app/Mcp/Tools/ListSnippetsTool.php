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

#[Description('List the snippet collections in a workspace, with the snippets inside each. Snippets are reusable code/text blocks. Use a collection slug with create_snippet to add one.')]
class ListSnippetsTool extends Tool
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

        $collections = $workspace->snippetCollections()
            ->with(['snippets:id,collection_id,title,language,slug'])
            ->orderBy('position')
            ->get(['id', 'name', 'slug'])
            ->map(fn ($c) => [
                'name' => $c->name,
                'slug' => $c->slug,
                'snippets' => $c->snippets->map(fn ($s) => [
                    'title' => $s->title,
                    'slug' => $s->slug,
                    'language' => $s->language,
                ])->all(),
            ])
            ->all();

        return Response::structured([
            'workspace' => $workspace->name,
            'collections' => $collections,
            'count' => count($collections),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string(),
            'collections' => $schema->array()->items($schema->object([
                'name' => $schema->string(),
                'slug' => $schema->string(),
                'snippets' => $schema->array()->items($schema->object([
                    'title' => $schema->string(),
                    'slug' => $schema->string(),
                    'language' => $schema->string(),
                ])),
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
