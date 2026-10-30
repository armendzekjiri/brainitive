<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create a label in a project (project admins). Calling it again with a name that already exists returns the existing label instead of a duplicate, so it is safe to use when importing. Labels are then set on cards by name.')]
class CreateLabelTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'name' => 'required|string|max:50',
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);

            $name = trim($v['name']);
            $slug = Str::slug($name);
            $existing = $project->labels()->get()->first(fn ($l) => $this->sameName($l->name, $name) || ($slug !== '' && $l->slug === $slug));
            $label = $existing ?? $project->labels()->create(['name' => $name, 'color' => $v['color'] ?? '#6b7280']);

            return Response::structured([
                'created' => $existing === null,
                'label_id' => $label->id,
                'name' => $label->name,
                'color' => $label->color ?? '',
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean()->description('false when a label with that name already existed.'),
            'label_id' => $schema->integer(),
            'name' => $schema->string(),
            'color' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'name' => $schema->string()->description('The label name (≤ 50 chars).')->required(),
            'color' => $schema->string()->description('Hex color like #ef4444. Default grey.'),
        ];
    }
}
