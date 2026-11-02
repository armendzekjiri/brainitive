<?php

namespace App\Http\Resources\AppApi;

use App\Models\Item;
use App\Models\TaskListItem;
use App\Support\MyWork\AssignedWork;
use App\Support\MyWork\WorkRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One piece of the user's work — a board issue or a list task — in My Work, My Day and Today. Needs
 * the relations of WorkRef::ISSUE_WITH / TASK_WITH (loaded once per page).
 *
 * @property-read Item|TaskListItem $resource
 */
class WorkItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $work = $this->resource;
        $isIssue = $work instanceof Item;
        $project = $isIssue ? $work->board->project : $work->taskList->project;
        $done = AssignedWork::isDone($work);

        return [
            // issue:<id> | task:<id>: use it for PATCH /me/work/{id} and My Day
            'id' => WorkRef::of($work),
            'type' => $isIssue ? WorkRef::ISSUE : WorkRef::TASK,
            'key' => $this->key($work),
            'title' => (string) $work->title,
            'done' => $done,
            // issues: the board column; tasks: To do / Done
            'status' => $isIssue ? $work->column?->name : ($done ? 'Done' : 'To do'),
            'priority' => $isIssue && $work->priority ? [
                'id' => (int) $work->priority->id,
                'name' => (string) $work->priority->name,
                'color' => $work->priority->color,
            ] : null,
            // the user's wall clock: Y-m-d and H:i
            'due_date' => $work->due_date?->format('Y-m-d'),
            'due_time' => $this->time($work->due_time),
            'project' => [
                'id' => (int) $project->id,
                'name' => (string) $project->name,
                'key' => $project->key,
                'color' => $project->color,
            ],
            'workspace' => [
                'slug' => (string) $project->workspace->slug,
                'name' => (string) $project->workspace->name,
            ],
            // where it lives: a board or a task list
            'source' => $isIssue
                ? ['type' => 'board', 'id' => (int) $work->board->id, 'name' => (string) $work->board->name]
                : ['type' => 'list', 'id' => (int) $work->taskList->id, 'name' => (string) $work->taskList->name],
            'url' => $this->url($work),
            'updated_at' => $work->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function key(Item|TaskListItem $work): ?string
    {
        return $work instanceof Item ? $work->key : $work->taskList->project->key.'-L'.$work->id;
    }

    private function url(Item|TaskListItem $work): string
    {
        if ($work instanceof Item) {
            $project = $work->board->project;

            return route('boards.items.show', [$project->workspace->slug, $project->slug, $work->board->slug, $work->id]);
        }
        $project = $work->taskList->project;

        return route('task-lists.show', [$project->workspace->slug, $project->slug, $work->taskList->slug]);
    }

    private function time(mixed $time): ?string
    {
        return is_string($time) && $time !== '' ? substr($time, 0, 5) : null;
    }
}
