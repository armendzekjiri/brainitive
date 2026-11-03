<?php

namespace App\Http\Resources\AppApi;

use App\Models\TaskListItem;
use App\Models\User;
use App\Support\Mcp\PageContentConverter;
use App\Support\MyWork\WorkRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A task (to-do) of a list (BRAI-397). `notes` is Markdown (the web stores the editor's HTML);
 * dates and times are the user's wall clock, as on the web. `work_id` is what My Work / My Day take.
 * Needs `assignee` and `taskList.project.workspace`.
 *
 * @mixin TaskListItem
 */
class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $list = $this->resource->taskList;
        $project = $list->project;

        return [
            'id' => $this->id,
            // task:<id> — for PATCH /me/work/{id} and My Day
            'work_id' => WorkRef::of($this->resource),
            'list_id' => (int) $this->task_list_id,
            'title' => $this->title,
            // todo | done
            'status' => $this->status,
            'done' => $this->status === 'done',
            'notes' => $this->notes(),
            'assignee' => $this->assignee(),
            // Y-m-d / H:i
            'due_date' => $this->resource->due_date?->format('Y-m-d'),
            'due_time' => self::time($this->due_time),
            'start_date' => $this->resource->start_date?->format('Y-m-d'),
            'start_time' => self::time($this->start_time),
            // daily | weekly | monthly | yearly | null: completing it moves the due date on
            'recurrence' => $this->recurrence,
            // minutes before the due time (0, 10, 60, 1440) or null
            'reminder_minutes' => $this->reminder_minutes,
            'position' => (int) $this->position,
            'url' => route('task-lists.show', [$project->workspace->slug, $project->slug, $list->slug]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    private function notes(): ?string
    {
        $markdown = trim(PageContentConverter::htmlToMarkdown(PageContentConverter::pageContentToHtml($this->resource->description)));

        return $markdown === '' ? null : $markdown;
    }

    /** @return array{id: int, name: string, avatar_url: string|null}|null */
    private function assignee(): ?array
    {
        $user = $this->resource->assignee;

        return $user instanceof User ? ['id' => (int) $user->id, 'name' => (string) $user->name, 'avatar_url' => self::avatar($user)] : null;
    }

    private static function avatar(User $user): ?string
    {
        $url = $user->avatar_display;

        return is_string($url) ? $url : null;
    }

    private static function time(mixed $time): ?string
    {
        return is_string($time) && $time !== '' ? substr($time, 0, 5) : null;
    }
}
