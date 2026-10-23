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
#[Description('Read one sprint with its top-level issues (status, points, assignee, subtask count). Pass sprint_id 0 to read the backlog instead.')]
class GetSprintTool extends Tool
{
    use InteractsWithSprints;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->boardRules() + ['sprint_id' => 'required|integer|min:0']);

        try {
            $board = $this->scrumBoard($request->user(), $v, 'view');
            $sprint = $v['sprint_id'] > 0 ? $this->sprintOn($board, (int) $v['sprint_id']) : null;
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $items = $board->items()->whereNull('parent_id')->where('sprint_id', $sprint?->id)
            ->with(['column:id,name', 'assignee:id,name'])->withCount('children')
            ->orderBy('backlog_position')->limit(500)->get();

        return Response::structured([
            'sprint' => $sprint ? $this->sprintSummary($sprint) : ['id' => 0, 'name' => 'Backlog', 'goal' => '', 'status' => 'backlog', 'start_date' => '', 'end_date' => '', 'issues' => $items->count(), 'open_issues' => $items->whereNull('completed_at')->count(), 'story_points' => (int) $items->sum('story_points')],
            'issues' => $items->map(fn ($i) => $this->issueSummary($i))->values()->all(),
            'backlog_url' => $this->backlogUrl($board),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'sprint' => $this->sprintSchema($schema),
            'issues' => $schema->array()->items($this->issueSchema($schema)),
            'backlog_url' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->boardArgs($schema) + [
            'sprint_id' => $schema->integer()->description('Sprint id from list_sprints, or 0 for the backlog.')->required(),
        ];
    }
}
