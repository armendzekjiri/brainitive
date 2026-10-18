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

#[Description('List the pages (documents and folders) in a project. Returns each page name, uuid and type. Use the uuid with get_page to read content, or create_page to add a new one.')]
class ListPagesTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
        ]);

        $user = $request->user();

        try {
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $pages = $project->allPages()
            ->visibleTo($user)
            ->orderBy('parent_id')
            ->orderBy('position')
            ->get(['uuid', 'name', 'type', 'parent_id'])
            ->map(fn ($p) => [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'type' => $p->type,
                'parent_id' => $p->parent_id,
            ])
            ->all();

        return Response::structured([
            'project' => $project->name,
            'pages' => $pages,
            'count' => count($pages),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string(),
            'pages' => $schema->array()->items($schema->object([
                'uuid' => $schema->string(),
                'name' => $schema->string(),
                'type' => $schema->string()->description('page or folder.'),
                'parent_id' => $schema->integer()->nullable()->description('Parent page id, or null for a root page.'),
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
            'project' => $schema->string()->description('The project slug.')->required(),
        ];
    }
}
