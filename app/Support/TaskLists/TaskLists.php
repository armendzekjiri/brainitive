<?php

namespace App\Support\TaskLists;

use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\User;
use App\Rules\ProjectMember;
use App\Support\Entitlements\LimitGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Task lists and their to-dos, the same way for the web (`TaskListController`), the CLI
 * (`/api/cli/lists*`), the MCP and the App API (`/api/v1/task-lists`): which lists a user may
 * see, how a list and a task are created, the task fields' rules and reordering. Completing a
 * (repeating) task is `WorkCompletion::updateTask`. Callers authorize first (TaskListPolicy).
 */
final class TaskLists
{
    /**
     * The lists of every project the user can open (workspace admins: all of the workspace),
     * with their task counts.
     *
     * @return Builder<TaskList>
     */
    public static function visibleTo(User $user): Builder
    {
        return self::withCounts(TaskList::query()->whereIn('project_id', $user->accessibleProjectIds()));
    }

    /**
     * @param  Builder<TaskList>  $query
     * @return Builder<TaskList>
     */
    public static function withCounts(Builder $query): Builder
    {
        return $query->withCount([
            'items',
            'items as todo_count' => fn ($q) => $q->where('status', 'todo'),
            'items as done_count' => fn ($q) => $q->where('status', 'done'),
        ]);
    }

    /** A new list at the end of the project; its creator watches it. */
    public static function create(Project $project, User $user, string $name, ?string $description = null): TaskList
    {
        // content limit (Codex web review B): every metered type, in the shared create path
        app(LimitGate::class)->ensureContentRoom($project->workspace);
        $list = $project->taskLists()->create([
            'name' => $name,
            'slug' => self::uniqueSlug($project, $name),
            'description' => $description,
            'position' => ($project->taskLists()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);
        $list->watchers()->syncWithoutDetaching([$user->id]);

        return $list;
    }

    /** A slug free in the project — also among deleted lists, which keep theirs (unique index). */
    public static function uniqueSlug(Project $project, string $name, ?int $exceptId = null): string
    {
        $base = Str::slug($name) ?: 'list';
        $slug = $base;
        for ($n = 1; $project->taskLists()->withTrashed()
            ->where('slug', $slug)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /**
     * The task fields everybody may set (the description's format is the caller's: the web
     * editor sends its document, the App API Markdown). Assignees: people who can open the project.
     * `$list` is null only while the OpenAPI generator reads the rules without a request.
     *
     * @return array<string, mixed>
     */
    public static function taskRules(?TaskList $list, bool $update = false): array
    {
        $project = $list?->project;

        return [
            'title' => $update ? 'sometimes|required|string|max:500' : 'required|string|max:500',
            'status' => 'sometimes|in:todo,done',
            // Only people in this workspace who can open the project can be assigned.
            'assignee_id' => $project ? [
                'nullable', 'integer',
                Rule::in($project->workspace->users()->pluck('users.id')->all()),
                new ProjectMember($project),
            ] : ['nullable', 'integer'],
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'recurrence' => 'nullable|in:daily,weekly,monthly,yearly',
            'reminder_minutes' => 'nullable|integer|in:'.implode(',', TaskListItem::REMINDER_OPTIONS),
        ];
    }

    /**
     * A new open task at the end of the list.
     *
     * @param  array<string, mixed>  $data  validated taskRules() fields (+ `description`)
     */
    public static function createTask(TaskList $list, User $user, array $data): TaskListItem
    {
        // content limit (Codex web review B): every metered type, in the shared create path
        app(LimitGate::class)->ensureContentRoom($list->project->workspace);

        return $list->items()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => 'todo',
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'due_time' => $data['due_time'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'start_time' => $data['start_time'] ?? null,
            'recurrence' => $data['recurrence'] ?? null,
            'reminder_minutes' => $data['reminder_minutes'] ?? null,
            'position' => ($list->items()->max('position') ?? 0) + 1,
            'created_by' => $user->id,
        ]);
    }

    /**
     * New positions for tasks of this list (tasks of other lists are ignored).
     *
     * @param  array<int, int>  $positions  task id → position
     */
    public static function reorder(TaskList $list, array $positions): void
    {
        DB::transaction(function () use ($list, $positions) {
            foreach ($positions as $id => $position) {
                $list->items()->whereKey($id)->update(['position' => $position]);
            }
        });
    }
}
