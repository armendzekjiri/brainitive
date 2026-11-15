<?php

namespace App\Support\MyDay;

use App\Models\Item;
use App\Models\MyDayEntry;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\ApiKeys\ApiKeyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A user's My Day (BRAI-74) / My Week (v1.53.0): which tasks they picked for a local date, resolved
 * to the board items / list tasks they can still open, plus free-form notes. Entries for deleted or
 * no longer accessible work are skipped, not deleted.
 *
 * `forDate()` / `carryOverCandidates()` return linked work only — the App API, offline sync, the
 * change feed and the Today screen read them and don't know notes. The web reads `forRange()`.
 */
class MyDayPlan
{
    /** How far from today a line can be planned (days, both ways). */
    public const MAX_DAYS_AWAY = 366;

    /** What the web sends for the "Someday" list (stored as a null `plan_date`). */
    public const SOMEDAY = 'someday';

    /** At most this many lines per day / on Someday (what one reorder call may send). */
    public const LIST_LIMIT = 200;

    /** How far back "Bring over" looks (days). */
    public const CARRY_OVER_DAYS = 30;

    /** The user's "today" (Y-m-d) in their own time zone. */
    public static function today(User $user): string
    {
        return now($user->localTimezone())->toDateString();
    }

    /** Monday (Y-m-d) of the week holding `$date` (the user's today when null or not a date). */
    public static function weekStart(User $user, ?string $date = null): string
    {
        $day = self::validDate($date) ? CarbonImmutable::parse($date) : CarbonImmutable::parse(self::today($user));

        return $day->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }

    /** Whether `$date` is a real Y-m-d date within MAX_DAYS_AWAY of the user's today. */
    public static function plannable(User $user, string $date): bool
    {
        if (! self::validDate($date)) {
            return false;
        }

        return abs(CarbonImmutable::parse(self::today($user))->diffInDays(CarbonImmutable::parse($date))) <= self::MAX_DAYS_AWAY;
    }

    /**
     * Every line from `$from` to `$to` (inclusive) in date + position order: linked work the user can
     * still open (`work` set) and notes (`work` null).
     *
     * @return Collection<int, array{entry: MyDayEntry, work: Item|TaskListItem|null}>
     */
    public static function forRange(User $user, string $from, string $to, array $boardWith = [], array $listWith = []): Collection
    {
        $entries = MyDayEntry::where('user_id', $user->id)->whereBetween('plan_date', [$from, $to])
            ->orderBy('plan_date')->orderBy('position')->orderBy('id')->get();

        return self::resolve($user, $entries, $boardWith, $listWith, withNotes: true);
    }

    /**
     * The "Someday" list: lines without a day, in their order (linked work the user can still open
     * and notes).
     *
     * @return Collection<int, array{entry: MyDayEntry, work: Item|TaskListItem|null}>
     */
    public static function someday(User $user, array $boardWith = [], array $listWith = []): Collection
    {
        $entries = MyDayEntry::where('user_id', $user->id)->whereNull('plan_date')
            ->orderBy('position')->orderBy('id')->limit(self::LIST_LIMIT)->get();

        return self::resolve($user, $entries, $boardWith, $listWith, withNotes: true);
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

    /** Unfinished notes of the last CARRY_OVER_DAYS days before `$today` (oldest day first). */
    public static function carryOverNotes(User $user, string $today): Collection
    {
        return MyDayEntry::where('user_id', $user->id)->where('work_type', MyDayEntry::NOTE)
            ->whereNull('completed_at')
            ->where('plan_date', '<', $today)
            ->where('plan_date', '>=', CarbonImmutable::parse($today)->subDays(self::CARRY_OVER_DAYS)->toDateString())
            ->orderBy('plan_date')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Put work on the user's plan for `$date` (default: their local today), at the end (twice is a
     * no-op). The caller has checked that the user can open it.
     */
    public static function add(User $user, string $workType, int $workId, ?string $date = null): MyDayEntry
    {
        $date ??= self::today($user);
        $date = $date === self::SOMEDAY ? null : $date;

        return MyDayEntry::firstOrCreate(
            ['user_id' => $user->id, 'work_type' => $workType, 'work_id' => $workId, 'plan_date' => $date],
            ['position' => self::nextPosition($user->id, $date)],
        );
    }

    /** A free-form line at the end of `$date` (null = Someday). */
    public static function addNote(User $user, ?string $date, string $title, ?string $color = null): MyDayEntry
    {
        return MyDayEntry::create([
            'user_id' => $user->id,
            'work_type' => MyDayEntry::NOTE,
            'work_id' => null,
            'plan_date' => $date,
            'title' => $title,
            'color' => $color,
            'position' => self::nextPosition($user->id, $date),
        ]);
    }

    /**
     * Put the user's lines `$ids` on `$date` (null = Someday) in this order (lines from other days move
     * there). A linked task that is already planned on `$date` keeps that line and drops the moved duplicate.
     * Ids of other users are ignored. Returns the ids that were dropped as duplicates.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function arrange(User $user, ?string $date, array $ids): array
    {
        return DB::transaction(function () use ($user, $date, $ids) {
            self::lockPlan($user);
            $entries = MyDayEntry::where('user_id', $user->id)->whereIn('id', $ids)->get()->keyBy('id');
            // linked work already on $date (outside the moved set it stays where it is, merged into)
            $onDate = self::onDate(MyDayEntry::where('user_id', $user->id), $date)->where('work_type', '!=', MyDayEntry::NOTE)
                ->get()->keyBy(fn (MyDayEntry $e) => "{$e->work_type}:{$e->work_id}");
            $dropped = [];
            $position = 0;
            foreach (array_values(array_unique($ids)) as $id) {
                $entry = $entries->get($id);
                if (! $entry) {
                    continue;
                }
                if (! $entry->isNote() && $entry->plan_date?->toDateString() !== $date) {
                    $twin = $onDate->get("{$entry->work_type}:{$entry->work_id}");
                    if ($twin && $twin->id !== $entry->id) {
                        $entry->delete();
                        $dropped[] = $id;

                        continue;
                    }
                }
                $entry->forceFill(['plan_date' => $date, 'position' => $position++])->save();
                if (! $entry->isNote()) {
                    $onDate->put("{$entry->work_type}:{$entry->work_id}", $entry);
                }
            }

            return $dropped;
        });
    }

    /** Move one of the user's lines to the end of `$date` (null = Someday; merging a task already planned there). */
    public static function moveToEnd(User $user, MyDayEntry $entry, ?string $date): void
    {
        $ids = self::onDate(MyDayEntry::where('user_id', $user->id), $date)
            ->orderBy('position')->orderBy('id')->pluck('id')->all();
        self::arrange($user, $date, [...$ids, $entry->id]);
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

    /**
     * "Bring over": unfinished work of earlier days onto today, at the end (copied — the earlier day
     * keeps its line). With `$withNotes` (the web) unfinished notes are *moved* to today too, so the same
     * note isn't offered again tomorrow. Returns how many lines came over.
     */
    public static function carryOver(User $user, bool $withNotes = false): int
    {
        return DB::transaction(function () use ($user, $withNotes) {
            self::lockPlan($user);
            $today = self::today($user);
            $position = self::nextPosition($user->id, $today);
            $room = self::LIST_LIMIT - self::onDate(MyDayEntry::where('user_id', $user->id), $today)->count();

            $rows = self::carryOverCandidates($user, $today)->take(max(0, $room));
            foreach ($rows as $row) {
                MyDayEntry::firstOrCreate(
                    ['user_id' => $user->id, 'work_type' => $row['entry']->work_type, 'work_id' => $row['entry']->work_id, 'plan_date' => $today],
                    ['position' => $position++],
                );
            }
            $notes = $withNotes ? self::carryOverNotes($user, $today)->take(max(0, $room - $rows->count())) : collect();
            foreach ($notes as $note) {
                $note->forceFill(['plan_date' => $today, 'position' => $position++])->save();
            }

            return $rows->count() + $notes->count();
        });
    }

    public static function nextPosition(int $userId, ?string $date): int
    {
        return (int) self::onDate(MyDayEntry::where('user_id', $userId), $date)->max('position') + 1;
    }

    /**
     * Serialise plan writes of one user (moves, carry-over): two requests moving the same task onto one
     * day would otherwise both miss each other's twin and collide on the unique index.
     */
    private static function lockPlan(User $user): void
    {
        User::whereKey($user->id)->lockForUpdate()->first();
    }

    /** Whether the day (null = Someday) has room for another line. */
    public static function hasRoom(User $user, ?string $date): bool
    {
        return self::onDate(MyDayEntry::where('user_id', $user->id), $date)->count() < self::LIST_LIMIT;
    }

    /** Lines of one day, or of Someday when `$date` is null. */
    private static function onDate($query, ?string $date)
    {
        return $date === null ? $query->whereNull('plan_date') : $query->where('plan_date', $date);
    }

    public static function isDone(Item|TaskListItem $work): bool
    {
        return $work instanceof Item ? $work->completed_at !== null : $work->status === 'done';
    }

    private static function validDate(?string $date): bool
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $y);
    }

    /**
     * Linked entries with the work they point at; notes only with `$withNotes` (work null).
     *
     * @param  Collection<int, MyDayEntry>  $entries
     * @return Collection<int, array{entry: MyDayEntry, work: Item|TaskListItem|null}>
     */
    private static function resolve(User $user, Collection $entries, array $boardWith = [], array $listWith = [], bool $withNotes = false): Collection
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
            ->map(fn (MyDayEntry $e) => ['entry' => $e, 'work' => match ($e->work_type) {
                'board' => $board->get($e->work_id),
                'list' => $list->get($e->work_id),
                default => null,
            }])
            ->filter(fn (array $row) => $row['work'] !== null || ($withNotes && $row['entry']->isNote()))
            ->values();
    }
}
