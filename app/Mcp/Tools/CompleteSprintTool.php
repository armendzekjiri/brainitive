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
#[Description('Complete the active sprint. Say where unfinished issues go: move_open_to = 0 for the backlog or the id of a planned sprint (required, no default). Stores the final burndown and velocity. Pass a unique request_id.')]
class CompleteSprintTool extends Tool
{
    use InteractsWithSprints;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->boardRules() + [
            'sprint_id' => 'required|integer',
            'move_open_to' => 'required|integer|min:0',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        try {
            $board = $this->scrumBoard($user, $v, 'update');
            $sprint = $this->sprintOn($board, (int) $v['sprint_id']);
            $target = $v['move_open_to'] > 0 ? $this->sprintOn($board, (int) $v['move_open_to']) : null;
            $run = IdempotentRequest::run($user, 'complete_sprint', $v['request_id'], ['sprint' => $sprint->id, 'to' => $target?->id], function () use ($board, $sprint, $target) {
                $moved = SprintLifecycle::complete($board, $sprint, $target);
                $fresh = $sprint->fresh();

                return ['moved_open' => $moved, 'completed_issues' => (int) $fresh->completed_issues, 'completed_points' => (int) $fresh->completed_points, 'sprint' => $this->sprintSummary($fresh)];
            });
        } catch (AuthorizationException|RequestIdConflict|DomainException $e) {
            return Response::error($e->getMessage());
        }

        $project = $board->project;

        return Response::structured($run['result'] + [
            'completed' => ! $run['replayed'],
            'open_moved_to' => $target?->name ?? 'Backlog',
            'report_url' => route('boards.reports', [$project->workspace->slug, $project->slug, $board->slug, 'sprint' => $sprint->id]),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'completed' => $schema->boolean(),
            'moved_open' => $schema->integer(),
            'completed_issues' => $schema->integer(),
            'completed_points' => $schema->integer(),
            'open_moved_to' => $schema->string(),
            'sprint' => $this->sprintSchema($schema),
            'report_url' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->boardArgs($schema) + [
            'sprint_id' => $schema->integer()->description('The active sprint.')->required(),
            'move_open_to' => $schema->integer()->description('0 = backlog, or a planned sprint id for unfinished issues.')->required(),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
