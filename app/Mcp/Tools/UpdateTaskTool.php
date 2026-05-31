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

#[Description('Update a task: rename it, change its due date, or mark it done/todo. Marking a recurring task done automatically rolls it forward to its next due date (it stays todo). Identify the task by its numeric id (from get_task_list).')]
class UpdateTaskTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'task_list' => 'required|string',
            'task_id' => 'required|integer',
            'title' => 'nullable|string|max:500',
            'status' => 'nullable|in:todo,done',
            'due_date' => 'nullable|date',
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

        $item = $list->items()->find($validated['task_id']);
        if (! $item) {
            return Response::error('No task found with that id in this list.');
        }

        $changes = array_filter([
            'title' => $validated['title'] ?? null,
            'status' => $validated['status'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
        ], fn ($v) => $v !== null);

        $isCompleting = ($validated['status'] ?? null) === 'done' && $item->status !== 'done';
        $bounced = false;

        if ($isCompleting && $item->recurrence) {
            $nextDate = $item->nextDueDate();
            $item->update(array_merge($changes, [
                'status' => 'todo',
                'due_date' => $nextDate ? $nextDate->format('Y-m-d') : $item->getRawOriginal('due_date'),
            ]));
            $bounced = true;
        } else {
            $item->update($changes);
        }

        $item = $item->fresh();

        return Response::structured([
            'updated' => true,
            'task_id' => $item->id,
            'status' => $item->status,
            'recurrence_rolled_forward' => $bounced,
            'next_due_date' => $bounced ? $item->due_date?->format('Y-m-d') : null,
            'location' => ResourceLocator::locate($item),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'task_id' => $schema->integer(),
            'status' => $schema->string(),
            'recurrence_rolled_forward' => $schema->boolean(),
            'next_due_date' => $schema->string(),
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
            'task_id' => $schema->integer()->description('The numeric task id.')->required(),
            'title' => $schema->string()->description('Optional new title.'),
            'status' => $schema->string()->enum(['todo', 'done'])->description('Optional new status.'),
            'due_date' => $schema->string()->description('Optional new due date, YYYY-MM-DD.'),
        ];
    }
}
