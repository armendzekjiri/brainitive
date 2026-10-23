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

#[Description('List the members of a project\'s workspace (name + email) so you know who a card or task can be assigned to. Pass a member email as the "assignee" when creating/updating a board item, or "me" for the signed-in user.')]
class ListProjectMembersTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
        ]);

        $user = $request->user();

        try {
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $members = $project->accessibleUsers()
            ->get(['users.id', 'name', 'email'])
            ->map(fn ($m) => [
                'name' => $m->name,
                'email' => $m->email,
                'is_you' => $m->id === $user->id,
            ])
            ->values()
            ->all();

        return Response::structured([
            'members' => $members,
            'count' => count($members),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'members' => $schema->array()->items($schema->object([
                'name' => $schema->string(),
                'email' => $schema->string(),
                'is_you' => $schema->boolean(),
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
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
        ];
    }
}
