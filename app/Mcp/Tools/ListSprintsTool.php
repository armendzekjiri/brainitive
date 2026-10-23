<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithSprints;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the sprints of a Scrum board (planned, active, completed) with dates and issue / point counts, plus how many issues wait in the backlog.')]
class ListSprintsTool extends Tool
{
    use InteractsWithSprints;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->boardRules());

        try {
            $board = $this->scrumBoard($request->user(), $v, 'view');
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'sprints' => $board->sprints()->orderBy('position')->get()->map(fn ($s) => $this->sprintSummary($s))->all(),
            'backlog_issues' => $board->items()->whereNull('parent_id')->whereNull('sprint_id')->count(),
            'backlog_url' => $this->backlogUrl($board),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'sprints' => $schema->array()->items($this->sprintSchema($schema)),
            'backlog_issues' => $schema->integer(),
            'backlog_url' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->boardArgs($schema);
    }
}
