<?php

namespace App\Support\Billing;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\Item;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workload\WorkloadWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Time entries a person logs by hand (v1.38.0, BRAI-99), the same way for "My timesheet" on the web
 * (`TimesheetController`) and the App API (`/api/v1/work-logs`, `/api/v1/me/timesheet`, BRAI-400): the
 * week, the form's rules, create / update / delete with the issues' history, and the guard (in the
 * workspace, the author or a workspace admin, not billed). Entries on an issue go through
 * Item::logWork(); TimeEntry's model events keep items.time_spent right. Never a rate or an amount.
 */
final class TimeEntries
{
    private const LOCKED = 'This time is on a finalized billing report and can’t be changed.';

    /** Relations an entry is shown with (project, activity, the issue where it is now). */
    public const WITH = [
        'project:id,name,key,slug',
        'activity:id,name,color,pricing_type,billable,archived_at',
        'item:id,board_id,key,title',
        'item.board:id,slug,project_id',
        'item.board.project:id,slug,workspace_id',
        'item.board.project.workspace:id,slug',
    ];

    /**
     * The week around `$requested` (any day; default: the user's current week), Monday–Sunday.
     *
     * @return array{start: string, end: string, previous: string, next: string, current: string, number: int, today: string}
     */
    public static function week(User $user, mixed $requested = null): array
    {
        $current = WorkloadWeeks::current($user);
        $monday = is_string($requested) && self::isDate($requested) ? WorkloadWeeks::monday($requested) : $current;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $monday, 'UTC');

        return [
            'start' => $monday,
            'end' => $date->addDays(6)->toDateString(),
            'previous' => WorkloadWeeks::add($monday, -1),
            'next' => WorkloadWeeks::add($monday, 1),
            'current' => $current,
            'number' => (int) $date->isoWeek(),
            'today' => now($user->localTimezone())->toDateString(),
        ];
    }

    /**
     * The user's own entries of a workspace between two days, oldest first, with what the timesheet shows.
     *
     * @return Collection<int, TimeEntry>
     */
    public static function between(User $user, Workspace $workspace, string $from, string $to): Collection
    {
        return TimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereBetween('spent_on', [$from, $to])
            ->with(self::WITH)
            ->orderBy('spent_on')->orderBy('id')
            ->get();
    }

    /**
     * The entry's issue while the user can still open it (where it is now — issues move between projects);
     * null otherwise, and the entry shows its snapshot (item_key / item_title).
     *
     * @param  array<int, int>  $accessible  project ids the user can open, as keys
     */
    public static function visibleIssue(TimeEntry $entry, array $accessible): ?Item
    {
        $item = $entry->item;
        $board = $item?->board;

        return $item && $board && $board->project?->workspace && isset($accessible[(int) $board->project_id]) ? $item : null;
    }

    /**
     * The form's rules. `$entry`: an entry may stay in its project even if the user may no longer log there.
     *
     * @return array<string, mixed>
     */
    public static function rules(User $user, Workspace $workspace, ?TimeEntry $entry = null): array
    {
        $projects = TimeLog::loggableProjectIds($user, $workspace);
        if ($entry) {
            $projects[] = (int) $entry->project_id;
        }

        return [
            'project_id' => ['required', 'integer', Rule::in($projects)],
            'item_id' => 'nullable|integer',
            'spent_on' => TimeLog::spentOnRules($user, true),
            'time' => Item::durationRules(),
            'activity_id' => 'nullable',
            'note' => 'nullable|string|max:255',
            'billable' => 'nullable|boolean',
        ];
    }

    /**
     * Log time (validated rules()): on an issue through Item::logWork(), else on the project.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function create(User $user, Workspace $workspace, array $data): TimeEntry
    {
        $activity = TimeLogActivities::resolve($workspace, $data['activity_id'] ?? null);
        $item = self::loggableItem((int) $data['project_id'], $data['item_id'] ?? null);
        $minutes = (int) Item::parseDuration($data['time']);
        $note = self::note($data['note'] ?? null);
        // only workspace admins choose billable; everyone else gets the activity's default (v1.39.0)
        $billable = isset($data['billable']) && Gate::forUser($user)->allows('manageBilling', $workspace) ? (bool) $data['billable'] : null;

        if ($item) {
            return $item->logWork($minutes, $note, $activity, $data['spent_on'], TimeEntry::SOURCE_MANUAL, $billable);
        }

        return TimeEntry::create([
            'workspace_id' => $workspace->id,
            'project_id' => (int) $data['project_id'],
            'user_id' => $user->id,
            'billing_activity_id' => $activity?->id,
            'spent_on' => $data['spent_on'],
            'minutes' => $minutes,
            'note' => $note,
            'billable' => $billable ?? ($activity ? (bool) $activity->billable : true),
            'source' => TimeEntry::SOURCE_MANUAL,
        ]);
    }

    /**
     * Change an entry (validated rules() — the whole form). Call guard() first.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function update(User $user, Workspace $workspace, TimeEntry $timeEntry, array $data): TimeEntry
    {
        $activity = TimeLogActivities::resolve($workspace, $data['activity_id'] ?? null, $timeEntry->billing_activity_id);
        $minutes = (int) Item::parseDuration($data['time']);
        $projectId = (int) $data['project_id'];
        $itemId = isset($data['item_id']) ? (int) $data['item_id'] : null;

        $currentItemId = $timeEntry->item_id !== null ? (int) $timeEntry->item_id : null;
        $moved = $projectId !== (int) $timeEntry->project_id || $itemId !== $currentItemId;
        if ($timeEntry->source === TimeEntry::SOURCE_LEGACY && ($moved || $minutes !== $timeEntry->minutes)) {
            throw ValidationException::withMessages(['entry' => 'This time was logged before the timesheet existed: only its date, activity and note can change.']);
        }

        $item = null;
        if ($moved) {
            if (! in_array($projectId, TimeLog::loggableProjectIds($user, $workspace), true)) {
                throw ValidationException::withMessages(['project_id' => 'You can’t log time on this project.']);
            }
            $item = self::loggableItem($projectId, $itemId);
        }

        $billable = match (true) {
            isset($data['billable']) && Gate::forUser($user)->allows('manageBilling', $workspace) => (bool) $data['billable'],
            $activity?->id !== $timeEntry->billing_activity_id => $activity ? (bool) $activity->billable : true,
            default => $timeEntry->billable,
        };

        return DB::transaction(function () use ($timeEntry, $data, $activity, $minutes, $projectId, $itemId, $moved, $item, $billable) {
            $timeEntry = self::lockUnbilled($timeEntry);
            $oldItemId = $timeEntry->item_id;
            $oldMinutes = $timeEntry->minutes;

            $timeEntry->fill([
                'project_id' => $projectId,
                'item_id' => $itemId,
                'billing_activity_id' => $activity?->id,
                'spent_on' => $data['spent_on'],
                'minutes' => $minutes,
                'note' => self::note($data['note'] ?? null),
                'billable' => $billable,
            ]);
            if ($moved) {
                $timeEntry->item_key = $item?->key;
                $timeEntry->item_title = $item ? Str::limit((string) $item->title, 250, '') : null;
            }
            $timeEntry->save(); // the model events move the minutes between the issues' totals

            // the issues' history shows what happened to logged work
            if ($oldItemId && $oldItemId !== $itemId) {
                self::history($oldItemId, 'work_log_removed', ['minutes' => $oldMinutes, 'moved_to' => $item?->key]);
            }
            if ($itemId && $oldItemId !== $itemId) {
                self::history($itemId, 'work_logged', ['minutes' => $minutes, 'note' => $timeEntry->note, 'activity' => $activity?->name, 'time_entry_id' => $timeEntry->id]);
            } elseif ($itemId && $oldMinutes !== $minutes) {
                self::history($itemId, 'work_log_updated', ['from_minutes' => $oldMinutes, 'minutes' => $minutes]);
            }

            return $timeEntry;
        });
    }

    /**
     * Delete an entry (call guard() first); time logged before the timesheet existed stays.
     *
     * @throws ValidationException
     */
    public static function delete(TimeEntry $timeEntry): void
    {
        if ($timeEntry->source === TimeEntry::SOURCE_LEGACY) {
            throw ValidationException::withMessages(['entry' => 'Time logged before the timesheet existed can’t be deleted — mark it as not billable instead.']);
        }

        DB::transaction(function () use ($timeEntry) {
            $timeEntry = self::lockUnbilled($timeEntry);
            $timeEntry->delete(); // the model event takes the minutes off the issue's total
            if ($timeEntry->item_id) {
                self::history($timeEntry->item_id, 'work_log_removed', ['minutes' => $timeEntry->minutes]);
            }
        });
    }

    /**
     * The entry is in this workspace (404), the user's or they are a workspace admin (403), and not
     * billed (422 `entry`). Workspace membership is the caller's check (former members can't change
     * what is billed there).
     *
     * @throws ValidationException
     */
    public static function guard(User $user, Workspace $workspace, TimeEntry $entry): void
    {
        abort_unless((int) $entry->workspace_id === $workspace->id, 404);
        abort_unless(TimeLog::canChange($user, $entry), 403);
        if ($entry->isLocked()) {
            throw ValidationException::withMessages(['entry' => self::LOCKED]);
        }
    }

    /** Re-read the row under a lock: a report finalized meanwhile wins, the entry stays as billed. */
    private static function lockUnbilled(TimeEntry $entry): TimeEntry
    {
        $fresh = TimeEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
        if ($fresh->isLocked()) {
            throw ValidationException::withMessages(['entry' => self::LOCKED]);
        }

        return $fresh;
    }

    /** An issue of the project that the user may edit (= log work on), or a validation error. */
    private static function loggableItem(int $projectId, mixed $itemId): ?Item
    {
        if ($itemId === null) {
            return null;
        }
        $item = Item::whereKey((int) $itemId)
            ->whereIn('board_id', Board::where('project_id', $projectId)->select('id'))
            ->first();
        if (! $item || Gate::denies('update', $item)) {
            throw ValidationException::withMessages(['item_id' => 'You can’t log time on this issue.']);
        }

        return $item;
    }

    /** @param  array<string, mixed>  $metadata */
    private static function history(int $itemId, string $action, array $metadata): void
    {
        $item = Item::withTrashed()->find($itemId);
        if ($item) {
            ActivityLog::log($item, $action, null, array_filter($metadata, fn ($v) => $v !== null));
        }
    }

    private static function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    private static function isDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date !== null && $date->toDateString() === $value;
    }
}
