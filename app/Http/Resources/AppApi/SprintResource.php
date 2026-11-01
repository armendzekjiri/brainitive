<?php

namespace App\Http\Resources\AppApi;

use App\Models\Sprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Scrum sprint of a board (BRAI-408): planned → active (one per board) → completed. `counts` / `points`
 * are its top-level issues (subtasks count with their parent), as the backlog page shows them; a completed
 * sprint also has what it committed and completed (velocity). The controller loads the counts.
 *
 * @mixin Sprint
 */
class SprintResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sprint = $this->resource;
        $board = $sprint->board;
        $project = $board->project;

        return [
            'id' => (int) $sprint->id,
            'board_id' => (int) $sprint->board_id,
            'name' => (string) $sprint->name,
            'goal' => $sprint->goal,
            // planned | active | completed
            'status' => (string) $sprint->status,
            // planning order
            'position' => (int) $sprint->position,
            'start_date' => $sprint->start_date?->format('Y-m-d'),
            'end_date' => $sprint->end_date?->format('Y-m-d'),
            'started_at' => $sprint->started_at?->toIso8601ZuluString(),
            'completed_at' => $sprint->completed_at?->toIso8601ZuluString(),
            'counts' => [
                'issues' => (int) $sprint->getAttribute('issues_count'),
                'done' => (int) $sprint->getAttribute('done_issues_count'),
            ],
            'points' => [
                'total' => (int) $sprint->getAttribute('points_total'),
                'done' => (int) $sprint->getAttribute('points_done'),
            ],
            // completed sprints: what was committed at the start and completed at the end
            'committed_points' => $sprint->committed_points === null ? null : (int) $sprint->committed_points,
            'completed_points' => $sprint->completed_points === null ? null : (int) $sprint->completed_points,
            'committed_issues' => $sprint->committed_issues === null ? null : (int) $sprint->committed_issues,
            'completed_issues' => $sprint->completed_issues === null ? null : (int) $sprint->completed_issues,
            'url' => route('boards.backlog', [$project->workspace->slug, $project->slug, $board->slug]),
        ];
    }
}
