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
#[Description('Rename a board, change its description, or switch it between kanban and scrum. The slug stays as it is.')]
class UpdateBoardTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'type' => 'sometimes|in:kanban,scrum',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $board = $this->boardForUser($user, $v['workspace'], $v['project'], $v['board']);
            $this->authorizeFor($user, 'update', $board);

            $changes = array_intersect_key($v, array_flip(['name', 'description', 'type']));
            if ($changes === []) {
                throw new ToolError('Provide a name, description or type to change.');
            }
            if (($changes['type'] ?? $board->type) === 'kanban' && $board->activeSprint()->exists()) {
                throw new ToolError('Complete the active sprint before turning sprints off (complete_sprint).');
            }
            $board->update($changes);

            return Response::structured(['updated' => true, 'location' => ResourceLocator::locate($board->fresh())]);
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
            'board' => $schema->string()->description('The board slug.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('New description (empty to clear).'),
            'type' => $schema->string()->description('"kanban" or "scrum".'),
        ];
    }
}
