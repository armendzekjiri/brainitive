<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Board;
use App\Models\Item;
use App\Models\Sprint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Shared bits of the MCP sprint tools: resolve a Scrum board + sprint with the UI's
 * permissions and describe sprints / issues the same way everywhere.
 */
trait InteractsWithSprints
{
    use InteractsWithBrainitive;

    /**
     * @throws AuthorizationException
     */
    protected function scrumBoard(Authenticatable $user, array $v, string $ability): Board
    {
        $board = $this->boardForUser($user, $v['workspace'], $v['project'], $v['board']);
        $this->authorizeFor($user, $ability, $board);

        if (! $board->isScrum()) {
            throw new AuthorizationException('This is a Kanban board; sprints only exist on Scrum boards.');
        }

        return $board;
    }

    /**
     * @throws AuthorizationException
     */
    protected function sprintOn(Board $board, int $sprintId): Sprint
    {
        $sprint = $board->sprints()->find($sprintId);
        if (! $sprint) {
            throw new AuthorizationException('No sprint with that id on this board. Call list_sprints.');
        }

        return $sprint;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sprintSummary(Sprint $sprint): array
    {
        $items = $sprint->items()->whereNull('parent_id');

        return [
            'id' => $sprint->id,
            'name' => $sprint->name,
            'goal' => $sprint->goal ?? '',
            'status' => $sprint->status,
            'start_date' => $sprint->start_date?->toDateString() ?? '',
            'end_date' => $sprint->end_date?->toDateString() ?? '',
            'issues' => (clone $items)->count(),
            'open_issues' => (clone $items)->whereNull('completed_at')->count(),
            'story_points' => (int) (clone $items)->sum('story_points'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function issueSummary(Item $item): array
    {
        return [
            'id' => $item->id,
            'key' => $item->key ?? '',
            'title' => $item->title,
            'status' => $item->column?->name ?? '',
            'done' => $item->completed_at !== null,
            'story_points' => (int) ($item->story_points ?? 0),
            'assignee' => $item->assignee?->name ?? '',
            'subtasks' => (int) ($item->children_count ?? 0),
        ];
    }

    protected function sprintSchema(JsonSchema $schema)
    {
        return $schema->object([
            'id' => $schema->integer(),
            'name' => $schema->string(),
            'goal' => $schema->string(),
            'status' => $schema->string()->description('planned | active | completed'),
            'start_date' => $schema->string(),
            'end_date' => $schema->string(),
            'issues' => $schema->integer(),
            'open_issues' => $schema->integer(),
            'story_points' => $schema->integer(),
        ]);
    }

    protected function issueSchema(JsonSchema $schema)
    {
        return $schema->object([
            'id' => $schema->integer(),
            'key' => $schema->string(),
            'title' => $schema->string(),
            'status' => $schema->string(),
            'done' => $schema->boolean(),
            'story_points' => $schema->integer(),
            'assignee' => $schema->string(),
            'subtasks' => $schema->integer(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    protected function boardArgs(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The Scrum board slug.')->required(),
        ];
    }

    protected function boardRules(): array
    {
        return ['workspace' => 'required|string', 'project' => 'required|string', 'board' => 'required|string'];
    }

    protected function backlogUrl(Board $board): string
    {
        $project = $board->project;

        return route('boards.backlog', [$project->workspace->slug, $project->slug, $board->slug]);
    }
}
