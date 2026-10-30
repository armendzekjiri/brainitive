<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\ItemPriority;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Rename, recolour or reorder a priority that belongs to this project. Instance-wide priorities can\'t be changed.')]
class UpdatePriorityTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'priority_id' => 'required|integer',
            'name' => 'sometimes|string|max:50',
            'color' => 'sometimes|nullable|string|max:20',
            'icon' => 'sometimes|nullable|string|max:100',
            'order' => 'sometimes|integer|min:0|max:1000',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);

            $priority = ItemPriority::where('project_id', $project->id)->find($v['priority_id']);
            if (! $priority) {
                throw new ToolError('No project-owned priority with that id. Instance-wide priorities can\'t be changed; see get_project_config.');
            }
            $changes = array_intersect_key($v, array_flip(['name', 'color', 'icon', 'order']));
            if ($changes === []) {
                throw new ToolError('Provide a name, color, icon or order to change.');
            }
            $priority->update($changes);

            return Response::structured(['updated' => true, 'priority_id' => $priority->id, 'name' => $priority->name]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['updated' => $schema->boolean(), 'priority_id' => $schema->integer(), 'name' => $schema->string()];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'priority_id' => $schema->integer()->description('The priority id (from get_project_config).')->required(),
            'name' => $schema->string()->description('New name.'),
            'color' => $schema->string()->description('New hex color.'),
            'icon' => $schema->string()->description('New Lucide icon name.'),
            'order' => $schema->integer()->description('New sort order.'),
        ];
    }
}
