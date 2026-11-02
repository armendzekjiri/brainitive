<?php

namespace App\Support\MyWork;

use App\Models\Board;
use App\Models\Item;
use App\Models\TaskListItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * "My Work": the board issues and list tasks assigned to a user, in projects they can still open.
 * One query for the browser extension (`ext/v1/my-work`) and the App API (`GET /api/v1/me/work`,
 * `GET /api/v1/me/today`). An issue counts as done when it has `completed_at` or sits in a done
 * column (some older cards have only one of the two), a task when its status is `done`.
 */
final class AssignedWork
{
    public const OPEN = 'open';

    public const DONE = 'done';

    public const ALL = 'all';

    /**
     * @param  array<int, int>  $projectIds  User::accessibleProjectIds() (pass it when you call both)
     * @return Builder<Item>
     */
    public static function issues(User $user, array $projectIds, string $status = self::OPEN): Builder
    {
        return Item::query()
            ->whereIn('board_id', Board::whereIn('project_id', $projectIds)->select('id'))
            ->where('assigned_to', $user->id)
            ->when($status === self::OPEN, fn (Builder $q) => $q
                ->whereNull('completed_at')
                ->whereHas('column', fn (Builder $c) => $c->where('is_done_column', false)))
            ->when($status === self::DONE, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereNotNull('completed_at')
                ->orWhereHas('column', fn (Builder $c) => $c->where('is_done_column', true))));
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return Builder<TaskListItem>
     */
    public static function tasks(User $user, array $projectIds, string $status = self::OPEN): Builder
    {
        return TaskListItem::query()
            ->whereHas('taskList', fn (Builder $q) => $q->whereIn('project_id', $projectIds))
            ->where('assignee_id', $user->id)
            ->when($status === self::OPEN, fn (Builder $q) => $q->where('status', '!=', 'done'))
            ->when($status === self::DONE, fn (Builder $q) => $q->where('status', 'done'));
    }

    /**
     * Both kinds in one list for the App API, soonest due first (no due date last), then issues before
     * tasks, then by id — a unique order, so cursor pages are stable. Rows are {kind, id, sort_due}.
     *
     * @param  list<string>  $due  any of overdue | today | upcoming (none = every date, also none)
     * @param  string  $today  the user's local date (MyDayPlan::today)
     */
    public static function combined(User $user, array $due, string $status, ?CarbonInterface $updatedSince, string $today): QueryBuilder
    {
        $projectIds = $user->accessibleProjectIds();

        $narrow = function (Builder $query, string $table) use ($due, $updatedSince, $today): Builder {
            return $query
                // constants only in the raw SQL (a bound literal has no type in a Postgres UNION)
                ->selectRaw(sprintf("'%s'::text as kind, %s.id, COALESCE(%s.due_date, DATE '9999-12-31') as sort_due", $table === 'items' ? WorkRef::ISSUE : WorkRef::TASK, $table, $table))
                ->when($due !== [], fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->when(in_array('overdue', $due, true), fn (Builder $o) => $o->orWhere("{$table}.due_date", '<', $today))
                    ->when(in_array('today', $due, true), fn (Builder $o) => $o->orWhere("{$table}.due_date", '=', $today))
                    ->when(in_array('upcoming', $due, true), fn (Builder $o) => $o->orWhere("{$table}.due_date", '>', $today))))
                ->when($updatedSince, fn (Builder $q, CarbonInterface $since) => $q->where("{$table}.updated_at", '>=', $since));
        };

        $issues = $narrow(self::issues($user, $projectIds, $status), 'items')->toBase();
        $tasks = $narrow(self::tasks($user, $projectIds, $status), 'task_list_items')->toBase();

        return DB::query()->fromSub($issues->unionAll($tasks), 'work')
            ->orderBy('sort_due')->orderBy('kind')->orderBy('id');
    }

    public static function isDone(Item|TaskListItem $work): bool
    {
        return $work instanceof Item
            ? $work->completed_at !== null || ($work->column?->is_done_column ?? false)
            : $work->status === 'done';
    }
}
