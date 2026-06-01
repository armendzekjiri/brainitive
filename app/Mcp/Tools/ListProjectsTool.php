<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the projects inside a workspace. Provide the workspace slug (from list_workspaces). Returns each project name, slug and key — use the slug to drill into pages, boards and task lists.')]
class ListProjectsTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
        ]);

        $user = $request->user();

        try {
            $workspace = $this->workspaceForUser($user, $validated['workspace']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $projects = $workspace->projects()
            ->get(['id', 'name', 'slug', 'key', 'description'])
            ->filter(fn ($p) => $user->hasProjectAccess($p))
            ->map(fn ($p) => [
                'name' => $p->name,
                'slug' => $p->slug,
                'key' => $p->key ?? '',
                'description' => $p->description ?? '',
            ])
            ->values()
            ->all();

        return Response::structured([
            'workspace' => $workspace->name,
            'projects' => $projects,
            'count' => count($projects),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string(),
            'projects' => $schema->array()->items($schema->object([
                'name' => $schema->string(),
                'slug' => $schema->string(),
                'key' => $schema->string(),
                'description' => $schema->string(),
            ])),
            'count' => $schema->integer(),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()
                ->description('The workspace slug (from list_workspaces).')
                ->required(),
        ];
    }
}
