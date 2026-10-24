<?php

namespace App\Support\RecurringIssues;

use App\Models\Board;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\RecurringIssue;
use App\Models\User;
use App\Rules\ProjectMember;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Validation, normalisation and the JSON shapes of the recurring-issues dialog (BRAI-123).
 */
class RecurringIssueForm
{
    /** @return array<string, mixed> */
    public static function scheduleRules(): array
    {
        return [
            'frequency' => ['required', Rule::in(RecurrenceSchedule::FREQUENCIES)],
            'interval' => ['required', 'integer', 'min:1', 'max:'.RecurrenceSchedule::MAX_INTERVAL],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'monthly_mode' => ['nullable', Rule::in(RecurrenceSchedule::MONTHLY_MODES)],
            'month_day' => ['nullable', 'integer', 'between:1,31'],
            'month_week' => ['nullable', 'integer', Rule::in(RecurrenceSchedule::MONTH_WEEKS)],
            'month_weekday' => ['nullable', 'integer', 'between:0,7'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'max_occurrences' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public static function rules(Board $board, Project $project): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:100000'],
            'item_type_id' => ['nullable', 'integer', Rule::in(self::types($project)->pluck('id')->all())], // no subtasks: they need a parent
            'priority_id' => ['nullable', 'integer', Rule::in(ItemPriority::forProject($project)->pluck('id')->all())],
            'assigned_to' => ['nullable', 'integer', new ProjectMember($project)],
            'label_ids' => ['nullable', 'array', 'max:20'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where('project_id', $project->id)],
            'column_id' => ['nullable', 'integer', Rule::exists('board_columns', 'id')->where('board_id', $board->id)],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            ...self::scheduleRules(),
        ];
    }

    /**
     * Only the fields of the chosen frequency are kept; the rest is null. Defaults come from the start
     * date (weekday, day of month), like RecurrenceSchedule::fromArray().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeSchedule(array $data): array
    {
        $schedule = RecurrenceSchedule::fromArray($data);
        $monthly = $schedule->frequency === 'monthly';

        return [
            'frequency' => $schedule->frequency,
            'interval' => $schedule->interval,
            'weekdays' => $schedule->frequency === 'weekly' ? $schedule->weekdays : null,
            'monthly_mode' => $monthly ? $schedule->monthlyMode : null,
            'month_day' => $monthly && $schedule->monthlyMode === 'day' ? $schedule->monthDay : null,
            'month_week' => $monthly && $schedule->monthlyMode === 'weekday' ? $schedule->monthWeek : null,
            'month_weekday' => $monthly && $schedule->monthlyMode === 'weekday' ? $schedule->monthWeekday : null,
            'start_date' => $schedule->start->toDateString(),
            'end_date' => $schedule->end?->toDateString(),
            'max_occurrences' => isset($data['max_occurrences']) ? (int) $data['max_occurrences'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function attributes(array $validated): array
    {
        return [
            'title' => trim($validated['title']),
            'description' => $validated['description'] ?? null,
            'item_type_id' => $validated['item_type_id'] ?? null,
            'priority_id' => $validated['priority_id'] ?? null,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'label_ids' => array_values(array_unique(array_map('intval', $validated['label_ids'] ?? []))),
            'column_id' => $validated['column_id'] ?? null,
            'due_in_days' => $validated['due_in_days'] ?? null,
            ...self::normalizeSchedule($validated),
        ];
    }

    /** @return array<string, mixed> */
    public static function present(RecurringIssue $template): array
    {
        return [
            ...$template->only([
                'id', 'title', 'description', 'item_type_id', 'priority_id', 'assigned_to', 'column_id',
                'due_in_days', 'frequency', 'interval', 'monthly_mode', 'month_day', 'month_week',
                'month_weekday', 'max_occurrences', 'timezone', 'occurrences_count', 'last_error',
            ]),
            'label_ids' => $template->label_ids ?? [],
            'weekdays' => $template->weekdays ?? [],
            'start_date' => $template->start_date?->toDateString(),
            'end_date' => $template->end_date?->toDateString(),
            'last_occurrence_date' => $template->last_occurrence_date?->toDateString(),
            'next_occurrence_date' => $template->paused_at ? null : $template->next_occurrence_date?->toDateString(),
            'paused' => $template->paused_at !== null,
            'finished' => $template->paused_at === null && $template->next_occurrence_date === null,
            'summary' => $template->schedule()->summary(),
            'badge_label' => $template->badge_label,
            'creator' => $template->creator?->only(['id', 'name']),
        ];
    }

    /** Choices of the form: columns, types, priorities, assignable people, labels. */
    public static function options(Board $board, Project $project): array
    {
        return [
            'columns' => $board->columns()->get(['id', 'name'])->values(),
            'types' => self::types($project)->map(fn (ItemType $type) => $type->only(['id', 'name', 'is_default']))->values(),
            'priorities' => ItemPriority::forProject($project)->orderBy('order')->get(['id', 'name', 'is_default'])->values(),
            'members' => self::members($project)->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values(),
            'labels' => $project->labels()->orderBy('name')->get(['id', 'name', 'color'])->values(),
        ];
    }

    /** @return Collection<int, ItemType> */
    private static function types(Project $project): Collection
    {
        return ItemType::forProject($project)->where('slug', '!=', 'subtask')->orderBy('id')->get(['id', 'name', 'is_default']);
    }

    /**
     * People who can open the project — the set ProjectMember accepts.
     *
     * @return Collection<int, User>
     */
    private static function members(Project $project): Collection
    {
        $admins = $project->workspace->users()->wherePivot('role', 'admin')->get(['users.id', 'users.name']);

        return $project->users()->get(['users.id', 'users.name'])
            ->concat($admins)
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
