<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithSprints;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\RequestIdConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create a planned sprint on a Scrum board (name defaults to "<KEY> Sprint <n>"). Pass a unique request_id; retrying with it never creates a second sprint.')]
class CreateSprintTool extends Tool
{
    use InteractsWithSprints;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->boardRules() + [
            'name' => 'nullable|string|max:255',
            'goal' => 'nullable|string|max:2000',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        try {
            $board = $this->scrumBoard($user, $v, 'update');
            $run = IdempotentRequest::run($user, 'create_sprint', $v['request_id'], ['board' => $board->id, 'name' => $v['name'] ?? null, 'goal' => $v['goal'] ?? null], function () use ($board, $v, $user) {
                $project = $board->project;
                $sprint = $board->sprints()->create([
                    'name' => $v['name'] ?? $project->key.' Sprint '.($board->sprints()->count() + 1),
                    'goal' => $v['goal'] ?? null,
                    'position' => ($board->sprints()->max('position') ?? 0) + 1,
                    'created_by' => $user->id,
                ]);

                return ['sprint' => $this->sprintSummary($sprint->fresh())];
            });
        } catch (AuthorizationException|RequestIdConflict $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured(['created' => ! $run['replayed'], 'sprint' => $run['result']['sprint'], 'backlog_url' => $this->backlogUrl($board)]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['created' => $schema->boolean(), 'sprint' => $this->sprintSchema($schema), 'backlog_url' => $schema->string()];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->boardArgs($schema) + [
            'name' => $schema->string()->description('Optional sprint name.'),
            'goal' => $schema->string()->description('Optional sprint goal.'),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
