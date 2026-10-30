<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\ItemType;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Change an item type. A project\'s own type can be renamed / recoloured; an instance-wide type can only be switched on or off for this project (is_active).')]
class UpdateItemTypeTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'type_id' => 'required|integer',
            'name' => 'sometimes|string|max:100',
            'color' => 'sometimes|nullable|string|max:20',
            'icon' => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|nullable|string|max:500',
            'is_active' => 'sometimes|boolean',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);

            $type = ItemType::forProjectSettings($project)->find($v['type_id']);
            if (! $type) {
                throw new ToolError('No item type with that id for this project. See get_project_config.');
            }

            if ($type->project_id === null) {
                // Default types are shared by every workspace on the instance: a project can only
                // switch one off or on for itself, never rename or restyle it for everyone.
                if (! array_key_exists('is_active', $v)) {
                    throw new ToolError("\"{$type->name}\" is an instance-wide type: only is_active can be changed for this project. Create a project type with create_item_type instead.");
                }
                $disabled = collect($project->disabledItemTypeIds())->reject(fn (int $id) => $id === $type->id);
                if (! $v['is_active']) {
                    $disabled->push($type->id);
                }
                $project->update(['settings' => array_merge($project->settings ?? [], ['disabled_item_types' => $disabled->values()->all()])]);
            } else {
                $changes = array_intersect_key($v, array_flip(['name', 'color', 'icon', 'description']));
                if ($changes === []) {
                    throw new ToolError('Provide a name, color, icon or description to change.');
                }
                $type->update($changes);
            }

            return Response::structured(['updated' => true, 'type_id' => $type->id, 'name' => $type->fresh()->name]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['updated' => $schema->boolean(), 'type_id' => $schema->integer(), 'name' => $schema->string()];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'type_id' => $schema->integer()->description('The type id (from get_project_config).')->required(),
            'name' => $schema->string()->description('New name (project types only).'),
            'color' => $schema->string()->description('New hex color (project types only).'),
            'icon' => $schema->string()->description('New Lucide icon name (project types only).'),
            'description' => $schema->string()->description('New description (project types only).'),
            'is_active' => $schema->boolean()->description('Instance-wide types: show (true) or hide (false) the type in this project.'),
        ];
    }
}
