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

#[Description('List the task lists in a project, with open/total task counts. Use the returned slug with get_task_list to read tasks, or create_task to add one.')]
class ListTaskListsTool extends Tool
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

        $lists = $project->taskLists()
            ->withCount([
                'items',
                'items as open_count' => fn ($q) => $q->where('status', 'todo'),
            ])
            ->orderBy('position')
            ->get(['id', 'name', 'slug'])
            ->map(fn ($l) => [
                'name' => $l->name,
                'slug' => $l->slug,
                'open' => $l->open_count,
                'total' => $l->items_count,
            ])
            ->all();

        return Response::structured([
            'project' => $project->name,
            'task_lists' => $lists,
            'count' => count($lists),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string(),
            'task_lists' => $schema->array()->items($schema->object([
                'name' => $schema->string(),
                'slug' => $schema->string(),
                'open' => $schema->integer(),
                'total' => $schema->integer(),
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
