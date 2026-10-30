<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Rename a project or change its description (project admins). The slug and key stay as they are.')]
class UpdateProjectTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:50000',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'update', $project);

            $changes = array_intersect_key($v, array_flip(['name', 'description']));
            if ($changes === []) {
                throw new ToolError('Provide a name and/or a description to change.');
            }
            if (isset($changes['name']) && $project->workspace->projects()->whereKeyNot($project->id)->where('name', $changes['name'])->exists()) {
                throw new ToolError("Another project in this workspace is already named \"{$changes['name']}\".");
            }

            $project->update($changes);

            return Response::structured(['updated' => true, 'location' => ResourceLocator::locate($project->fresh())]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['updated' => $schema->boolean(), 'location' => ResourceLocator::outputSchema($schema)];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('New description (empty to clear).'),
        ];
    }
}
