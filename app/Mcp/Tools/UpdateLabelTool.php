<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Rename or recolour a project label (by id from get_project_config). Cards keep the label.')]
class UpdateLabelTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'label_id' => 'required|integer',
            'name' => 'sometimes|string|max:50',
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);
            $label = $project->labels()->find($v['label_id']);
            if (! $label) {
                throw new ToolError('No label with that id in this project. See get_project_config.');
            }

            $changes = array_intersect_key($v, array_flip(['name', 'color']));
            if ($changes === []) {
                throw new ToolError('Provide a name and/or a color to change.');
            }
            if (isset($changes['name'])) {
                // The slug follows the name (labels are unique per project by slug).
                $slug = Str::slug($changes['name']) ?: $label->slug;
                $taken = $project->labels()->whereKeyNot($label->id)
                    ->where(fn ($q) => $q->where('slug', $slug)->orWhereRaw('lower(name) = ?', [mb_strtolower(trim($changes['name']))]))
                    ->exists();
                if ($taken) {
                    throw new ToolError("Another label in this project is already called \"{$changes['name']}\".");
                }
                $changes['slug'] = $slug;
            }
            $label->update($changes);

            return Response::structured(['updated' => true, 'label_id' => $label->id, 'name' => $label->name, 'color' => $label->color ?? '']);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['updated' => $schema->boolean(), 'label_id' => $schema->integer(), 'name' => $schema->string(), 'color' => $schema->string()];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'label_id' => $schema->integer()->description('The label id.')->required(),
            'name' => $schema->string()->description('New name.'),
            'color' => $schema->string()->description('New hex color.'),
        ];
    }
}
