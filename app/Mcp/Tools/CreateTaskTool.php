<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add a task to a task list. Optionally set a due date (YYYY-MM-DD) and a recurrence (daily/weekly/monthly/yearly). Returns the new task id and its location.')]
class CreateTaskTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'task_list' => 'required|string',
            'title' => 'required|string|max:500',
            'due_date' => 'nullable|date',
            'recurrence' => 'nullable|in:daily,weekly,monthly,yearly',
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

        try {
            $this->authorizeFor($user, 'manageItems', $list);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $item = $list->items()->create([
            'title' => $validated['title'],
            'status' => 'todo',
            'due_date' => $validated['due_date'] ?? null,
            'recurrence' => $validated['recurrence'] ?? null,
            'position' => ($list->items()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);

        return Response::structured([
            'created' => true,
            'task_id' => $item->id,
            'location' => ResourceLocator::locate($item),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'task_id' => $schema->integer(),
            'location' => \App\Support\Mcp\ResourceLocator::outputSchema($schema),
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
            'title' => $schema->string()->description('The task title.')->required(),
            'due_date' => $schema->string()->description('Optional due date, YYYY-MM-DD.'),
            'recurrence' => $schema->string()->enum(['daily', 'weekly', 'monthly', 'yearly'])->description('Optional recurrence.'),
        ];
    }
}
