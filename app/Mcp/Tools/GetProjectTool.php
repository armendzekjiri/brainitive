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

#[Description('Get an overview of a single project: its pages (documents/folders), boards and task lists. Use this to understand what content exists in a project before reading or creating items.')]
class GetProjectTool extends Tool
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

        $pages = $project->pages()
            ->get(['uuid', 'name', 'type', 'icon'])
            ->map(fn ($p) => ['uuid' => $p->uuid, 'name' => $p->name, 'type' => $p->type])
            ->all();

        $boards = $project->boards()
            ->get(['slug', 'name'])
            ->map(fn ($b) => ['slug' => $b->slug, 'name' => $b->name])
            ->all();

        $taskLists = $project->taskLists()
            ->withCount(['items', 'items as open_count' => fn ($q) => $q->where('status', 'todo')])
            ->get(['id', 'slug', 'name'])
            ->map(fn ($l) => ['slug' => $l->slug, 'name' => $l->name, 'open' => $l->open_count, 'total' => $l->items_count])
            ->all();

        return Response::structured([
            'project' => $project->name,
            'key' => $project->key ?? '',
            'pages' => $pages,
            'boards' => $boards,
            'task_lists' => $taskLists,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string(),
            'key' => $schema->string(),
            'pages' => $schema->array()->items($schema->object([
                'uuid' => $schema->string(),
                'name' => $schema->string(),
                'type' => $schema->string(),
            ])),
            'boards' => $schema->array()->items($schema->object([
                'slug' => $schema->string(),
                'name' => $schema->string(),
            ])),
            'task_lists' => $schema->array()->items($schema->object([
                'slug' => $schema->string(),
                'name' => $schema->string(),
                'open' => $schema->integer(),
                'total' => $schema->integer(),
            ])),
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
