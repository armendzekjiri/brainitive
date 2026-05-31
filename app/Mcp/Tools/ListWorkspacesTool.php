<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the Brainitive workspaces the signed-in user belongs to. Workspaces are the top of the hierarchy (workspace → project → content). Start here to discover what is available, then list projects within a workspace.')]
class ListWorkspacesTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        $workspaces = $user->workspaces()
            ->withCount('projects')
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug'])
            ->map(fn ($ws) => [
                'name' => $ws->name,
                'slug' => $ws->slug,
                'projects_count' => $ws->projects_count,
                'role' => $ws->pivot->role ?? null,
            ])
            ->values()
            ->all();

        return Response::structured([
            'workspaces' => $workspaces,
            'count' => count($workspaces),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'workspaces' => $schema->array()->items($schema->object([
                'name' => $schema->string(),
                'slug' => $schema->string(),
                'projects_count' => $schema->integer(),
                'role' => $schema->string(),
            ])),
            'count' => $schema->integer(),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
