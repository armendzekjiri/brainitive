<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithSprints;
use App\Support\Boards\SprintLifecycle;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\RequestIdConflict;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Start a planned sprint with explicit start and end dates. Fails if another sprint is active or the sprint has no issues. Records the committed scope for burndown and velocity. Pass a unique request_id.')]
class StartSprintTool extends Tool
{
    use InteractsWithSprints;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->boardRules() + [
            'sprint_id' => 'required|integer',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date',
            'goal' => 'nullable|string|max:2000',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        try {
            $board = $this->scrumBoard($user, $v, 'update');
            $sprint = $this->sprintOn($board, (int) $v['sprint_id']);
            $run = IdempotentRequest::run($user, 'start_sprint', $v['request_id'], ['sprint' => $sprint->id, 'start' => $v['start_date'], 'end' => $v['end_date'], 'goal' => $v['goal'] ?? null], function () use ($board, $sprint, $v) {
                $started = SprintLifecycle::start($board, $sprint, $v['start_date'], $v['end_date'], $v['goal'] ?? null);

                return ['sprint' => $this->sprintSummary($started->fresh())];
            });
        } catch (AuthorizationException|RequestIdConflict|DomainException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured(['started' => ! $run['replayed'], 'sprint' => $run['result']['sprint'], 'board_url' => route('boards.show', [$board->project->workspace->slug, $board->project->slug, $board->slug])]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['started' => $schema->boolean(), 'sprint' => $this->sprintSchema($schema), 'board_url' => $schema->string()];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->boardArgs($schema) + [
            'sprint_id' => $schema->integer()->required(),
            'start_date' => $schema->string()->description('YYYY-MM-DD')->required(),
            'end_date' => $schema->string()->description('YYYY-MM-DD, after start_date')->required(),
            'goal' => $schema->string(),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
