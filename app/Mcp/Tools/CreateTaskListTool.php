<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\TaskList;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ResourceLocator;
use App\Support\TaskLists\TaskLists;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create a task list (simple checklist of to-dos) in a project. Returns its slug for create_task.')]
class CreateTaskListTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'create', [TaskList::class, $project]);

            $run = IdempotentRequest::run($user, 'create_task_list', $v['request_id'], ['project' => $project->id, 'name' => $v['name'], 'description' => $v['description'] ?? null], function () use ($project, $user, $v) {
                $list = TaskLists::create($project, $user, $v['name'], $v['description'] ?? null);

                return ['task_list_slug' => $list->slug, 'location' => ResourceLocator::locate($list)];
            });

            return Response::structured(['created' => ! $run['replayed']] + $run['result']);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['created' => $schema->boolean(), 'task_list_slug' => $schema->string(), 'location' => ResourceLocator::outputSchema($schema)];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'name' => $schema->string()->description('The list name.')->required(),
            'description' => $schema->string()->description('Optional description.'),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
