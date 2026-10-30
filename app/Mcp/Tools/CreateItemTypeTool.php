<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\ItemType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create an item type for a project (e.g. Epic, Story, Bug, Spike) next to the instance-wide ones (project admins). Same name again returns the existing type. Cards are then given a type by name.')]
class CreateItemTypeTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);

            $name = trim($v['name']);
            // Including instance types switched off for this project: switch them back on instead of
            // creating a second "Bug".
            $existing = ItemType::forProjectSettings($project)->get()->first(fn ($t) => $this->sameName($t->name, $name));
            if ($existing && $existing->project_id === null && in_array($existing->id, $project->disabledItemTypeIds(), true)) {
                $disabled = collect($project->disabledItemTypeIds())->reject(fn (int $id) => $id === $existing->id)->values()->all();
                $project->update(['settings' => array_merge($project->settings ?? [], ['disabled_item_types' => $disabled])]);
            }
            $type = $existing ?? $project->itemTypes()->create([
                'name' => $name,
                'slug' => Str::slug($name) ?: 'type',
                'color' => $v['color'] ?? null,
                'icon' => $v['icon'] ?? null,
                'description' => $v['description'] ?? null,
            ]);

            return Response::structured([
                'created' => $existing === null,
                'type_id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug ?? '',
                'scope' => $type->project_id === null ? 'instance' : 'project',
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean()->description('false when a type with that name was already available.'),
            'type_id' => $schema->integer(),
            'name' => $schema->string(),
            'slug' => $schema->string(),
            'scope' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'name' => $schema->string()->description('The type name.')->required(),
            'color' => $schema->string()->description('Optional hex color.'),
            'icon' => $schema->string()->description('Optional Lucide icon name, e.g. "bug", "bookmark", "zap".'),
            'description' => $schema->string()->description('Optional description.'),
        ];
    }
}
