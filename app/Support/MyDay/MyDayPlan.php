<?php

namespace App\Support\MyDay;

use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\ApiKeys\ApiKeyAccess;
use Illuminate\Support\Collection;

/**
 * A user's My Day (BRAI-74): which tasks they picked for a local date, resolved to the
 * board items / list tasks they can still open. Entries for deleted or no longer
 * accessible work are skipped, not deleted.
 */
class MyDayPlan
{
    /** The user's "today" (Y-m-d) in their own time zone. */
    public static function today(User $user): string
    {
        return now($user->localTimezone())->toDateString();
    }

    /**
     * Today's plan in order: list of ['entry' => MyDayEntry, 'work' => Item|TaskListItem].
     *
     * @return Collection<int, array{entry: MyDayEntry, work: Item|TaskListItem}>
     */
    public static function forDate(User $user, string $date, array $boardWith = [], array $listWith = []): Collection
    {
        $entries = MyDayEntry::where('user_id', $user->id)->where('plan_date', $date)->orderBy('position')->orderBy('id')->get();

        return self::resolve($user, $entries, $boardWith, $listWith);
    }

    /**
     * Unfinished work from earlier days that isn't on today's plan yet (newest day first),
     * offered by "Bring over" — never moved automatically.
     *
     * @return Collection<int, array{entry: MyDayEntry, work: Item|TaskListItem}>
     */
    public static function carryOverCandidates(User $user, string $today): Collection
    {
        $planned = MyDayEntry::where('user_id', $user->id)->where('plan_date', $today)->get()
            ->map(fn (MyDayEntry $e) => "{$e->work_type}:{$e->work_id}")->all();
        $earlier = MyDayEntry::where('user_id', $user->id)->where('plan_date', '<', $today)
            ->where('plan_date', '>=', now()->subDays(30)->toDateString())
            ->orderByDesc('plan_date')->orderBy('position')->get()
            ->unique(fn (MyDayEntry $e) => "{$e->work_type}:{$e->work_id}")
            ->reject(fn (MyDayEntry $e) => in_array("{$e->work_type}:{$e->work_id}", $planned, true))
            ->values();

        return self::resolve($user, $earlier)->filter(fn (array $row) => ! self::isDone($row['work']))->values();
    }

    /**
     * Put work on the user's plan for their local today, at the end (twice is a no-op). The caller has
     * checked that the user can open it.
     */
    public static function add(User $user, string $workType, int $workId): MyDayEntry
    {
        $today = self::today($user);

        return MyDayEntry::firstOrCreate(
            ['user_id' => $user->id, 'work_type' => $workType, 'work_id' => $workId, 'plan_date' => $today],
            ['position' => self::nextPosition($user->id, $today)],
        );
    }

    /** Take work off the user's plan for today (the task itself is never touched). */
    public static function remove(User $user, string $workType, int $workId): void
    {
        MyDayEntry::where('user_id', $user->id)
            ->where('plan_date', self::today($user))
            ->where('work_type', $workType)
            ->where('work_id', $workId)
            ->delete();
    }

    /** "Bring over": unfinished work of earlier days onto today, at the end. Returns how many. */
    public static function carryOver(User $user): int
    {
        $today = self::today($user);
        $position = self::nextPosition($user->id, $today);

        $rows = self::carryOverCandidates($user, $today);
        foreach ($rows as $row) {
            MyDayEntry::firstOrCreate(
                ['user_id' => $user->id, 'work_type' => $row['entry']->work_type, 'work_id' => $row['entry']->work_id, 'plan_date' => $today],
                ['position' => $position++],
            );
        }

        return $rows->count();
    }

    public static function nextPosition(int $userId, string $date): int
    {
        return (int) MyDayEntry::where('user_id', $userId)->where('plan_date', $date)->max('position') + 1;
    }

    public static function isDone(Item|TaskListItem $work): bool
    {
        return $work instanceof Item ? $work->completed_at !== null : $work->status === 'done';
    }

    /**
     * @param  Collection<int, MyDayEntry>  $entries
     * @return Collection<int, array{entry: MyDayEntry, work: Item|TaskListItem}>
     */
    private static function resolve(User $user, Collection $entries, array $boardWith = [], array $listWith = []): Collection
    {
        if ($entries->isEmpty()) {
            return collect();
        }
        $projectIds = $user->accessibleProjectIds();

        $board = ApiKeyAccess::limitIssues($user, Item::whereIn('id', $entries->where('work_type', 'board')->pluck('work_id')))
            ->whereHas('board', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with($boardWith ?: ['board:id,project_id,slug'])
            ->get()->keyBy('id');
        $list = TaskListItem::whereIn('id', $entries->where('work_type', 'list')->pluck('work_id'))
            ->whereHas('taskList', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with($listWith ?: ['taskList:id,project_id,slug'])
            ->get()->keyBy('id');

        return $entries
            ->map(fn (MyDayEntry $e) => ['entry' => $e, 'work' => $e->work_type === 'board' ? $board->get($e->work_id) : $list->get($e->work_id)])
            ->filter(fn (array $row) => $row['work'] !== null)
            ->values();
    }
}
