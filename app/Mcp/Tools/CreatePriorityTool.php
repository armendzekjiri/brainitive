<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\ItemPriority;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Add a priority to a project next to the instance-wide ones (Highest, High, Medium, Low, Lowest). Only needed when an import uses a priority that has no match. Same name again returns the existing one.')]
class CreatePriorityTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'name' => 'required|string|max:50',
            'color' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0|max:1000',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);

            $name = trim($v['name']);
            $all = ItemPriority::forProject($project)->orderBy('order')->get();
            $existing = $all->first(fn ($p) => $this->sameName($p->name, $name));
            $priority = $existing ?? ItemPriority::create([
                'project_id' => $project->id,
                'name' => $name,
                'slug' => Str::slug($name) ?: 'priority',
                'color' => $v['color'] ?? '#6b7280',
                'icon' => $v['icon'] ?? 'minus',
                'order' => $v['order'] ?? (($all->max('order') ?? 0) + 1),
                'is_default' => false,
            ]);

            return Response::structured([
                'created' => $existing === null,
                'priority_id' => $priority->id,
                'name' => $priority->name,
                'slug' => $priority->slug ?? '',
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean()->description('false when a priority with that name already existed.'),
            'priority_id' => $schema->integer(),
            'name' => $schema->string(),
            'slug' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'name' => $schema->string()->description('The priority name.')->required(),
            'color' => $schema->string()->description('Optional hex color.'),
            'icon' => $schema->string()->description('Optional Lucide icon name (e.g. "chevron-up").'),
            'order' => $schema->integer()->description('Sort order; lower = more urgent. Default: after the existing ones.'),
        ];
    }
}
