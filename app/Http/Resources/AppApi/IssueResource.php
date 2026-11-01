<?php

namespace App\Http\Resources\AppApi;

use App\Models\Item;
use App\Models\User;
use App\Support\MyWork\WorkRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An issue as a board card (BRAI-403): what the web's card shows. Dates and times are the user's wall
 * clock (Y-m-d / H:i), like on the web. `work_id` is what My Work / My Day take. Load the relations of
 * `IssueResource::eagerLoad()` (and the board with `project.workspace`).
 *
 * @mixin Item
 */
class IssueResource extends JsonResource
{
    /** Relations and counts a card needs. */
    public const WITH = ['column', 'itemType', 'priority', 'assignee', 'labels'];

    public const COUNTS = ['children', 'comments', 'attachments'];

    /**
     * @param  Builder<Item>  $query
     * @return Builder<Item>
     */
    public static function eagerLoad(Builder $query): Builder
    {
        return $query->with(self::WITH)->withCount(self::COUNTS);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $board = $item->board;
        $project = $board->project;

        return [
            'id' => $this->id,
            // issue:<id> — for PATCH /me/work/{id}, My Day and the timer
            'work_id' => WorkRef::of($item),
            'key' => $this->key,
            'title' => $this->title,
            'board_id' => (int) $this->board_id,
            'column_id' => (int) $this->column_id,
            // the column's name
            'status' => $item->column?->name,
            'done' => $this->completed_at !== null || (bool) ($item->column?->is_done_column ?? false),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'type' => $item->itemType ? [
                'id' => (int) $item->itemType->id,
                'name' => (string) $item->itemType->name,
                'icon' => $item->itemType->icon,
                'color' => $item->itemType->color,
            ] : null,
            'priority' => $item->priority ? [
                'id' => (int) $item->priority->id,
                'name' => (string) $item->priority->name,
                'icon' => $item->priority->icon,
                'color' => $item->priority->color,
            ] : null,
            'assignee' => $this->assignee(),
            'labels' => LabelResource::collection($item->labels),
            'start_date' => $item->start_date?->format('Y-m-d'),
            'start_time' => self::time($this->start_time),
            'due_date' => $item->due_date?->format('Y-m-d'),
            'due_time' => self::time($this->due_time),
            'story_points' => $this->story_points === null ? null : (int) $this->story_points,
            // the parent issue (subtasks nest 3 levels at most)
            'parent_id' => $this->parent_id === null ? null : (int) $this->parent_id,
            // Scrum: the sprint (null = backlog)
            'sprint_id' => $this->sprint_id === null ? null : (int) $this->sprint_id,
            // order inside the column
            'position' => (int) $this->position,
            'counts' => [
                'subtasks' => (int) $item->getAttribute('children_count'),
                'comments' => (int) $item->getAttribute('comments_count'),
                'attachments' => (int) $item->getAttribute('attachments_count'),
            ],
            'url' => route('boards.items.show', [$project->workspace->slug, $project->slug, $board->slug, $this->id]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /** @return array{id: int, name: string, avatar_url: string|null}|null */
    private function assignee(): ?array
    {
        $user = $this->resource->assignee;
        if (! $user instanceof User) {
            return null;
        }
        $avatar = $user->avatar_display;

        return ['id' => (int) $user->id, 'name' => (string) $user->name, 'avatar_url' => is_string($avatar) ? $avatar : null];
    }

    private static function time(mixed $time): ?string
    {
        return is_string($time) && $time !== '' ? substr($time, 0, 5) : null;
    }
}
