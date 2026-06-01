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

#[Description('Read a task list and its tasks. Returns each task id, title, status (todo/done) and due date. Identify the list by its slug within a project.')]
class GetTaskListTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'task_list' => 'required|string',
        ]);

        $user = $request->user();

        try {
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $list = $project->taskLists()->where('slug', $validated['task_list'])->first();
        if (! $list) {
            return Response::error('No task list found with that slug in this project.');
        }

        $items = $list->items()
            ->orderByRaw("CASE status WHEN 'todo' THEN 0 ELSE 1 END")
            ->orderBy('position')
            ->get(['id', 'title', 'status', 'due_date', 'recurrence'])
            ->map(fn ($i) => [
                'id' => $i->id,
                'title' => $i->title,
                'status' => $i->status,
                'due_date' => $i->due_date?->format('Y-m-d') ?? '',
                'recurrence' => $i->recurrence ?? '',
            ])
            ->all();

        return Response::structured([
            'task_list' => $list->name,
            'tasks' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'task_list' => $schema->string(),
            'tasks' => $schema->array()->items($schema->object([
                'id' => $schema->integer(),
                'title' => $schema->string(),
                'status' => $schema->string()->description('todo or done.'),
                'due_date' => $schema->string(),
                'recurrence' => $schema->string(),
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
            'task_list' => $schema->string()->description('The task list slug.')->required(),
        ];
    }
}
