<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\Project;
use App\Models\TaskListItem;
use App\Models\User;
use App\Notifications\ItemDueNotification;
use App\Notifications\ItemReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Pushes reminders for the app's own scheduled work, to the in-app bell AND the browser:
 * - "Starts in N minutes" for board / task-list items with a start date + time;
 * - "Due …" for task-list items with a reminder (`reminder_minutes` before the due time);
 * - item_due for assigned board issues with a due date (BRAI-103): "due tomorrow" (from 08:00 local
 *   the day before) and "overdue" (once the due time, or the end of the due day, has passed; only
 *   for the last OVERDUE_DAYS days, so old issues don't flood anyone). Once per issue, kind and due date.
 *
 * Dates and times are wall-clock values in the recipient's time zone
 * (`User::localTimezone()`); the app itself runs in UTC. Dedup is via the shared
 * `sent_item_reminders` table, keyed by the absolute time, so a recurring task gets a new
 * reminder for every occurrence.
 */
class SendItemReminders extends Command
{
    protected $signature = 'app:send-item-reminders {--lead=15 : Minutes before start to remind}';

    protected $description = 'Push reminders for board / task-list items that start soon or are due';

    /** Due reminders that were missed by more than this (scheduler down) are skipped. */
    private const DUE_GRACE_MINUTES = 30;

    /** item_due "due tomorrow" goes out from this local hour on the day before. */
    private const DUE_TOMORROW_HOUR = 8;

    /** item_due "overdue" is only sent for issues that became overdue in the last N days. */
    private const OVERDUE_DAYS = 2;

    public function handle(): int
    {
        $lead = max(1, (int) $this->option('lead'));
        $now = now();

        $sent = $this->remindBoardItems($now, $lead)
            + $this->remindTaskListStarts($now, $lead)
            + $this->remindTaskListDue($now)
            + $this->remindBoardItemsDue($now);

        $this->info("Queued {$sent} item reminder(s).");

        return self::SUCCESS;
    }

    /** Date range that covers "now … now + lead" in every time zone. */
    private function dateRange(Carbon $now, int $minutesAhead): array
    {
        return [$now->copy()->subDay()->toDateString(), $now->copy()->addMinutes($minutesAhead)->addDay()->toDateString()];
    }

    private function remindBoardItems(Carbon $now, int $lead): int
    {
        $items = Item::query()
            ->whereNull('parent_id')
            ->whereNotNull('assigned_to')
            ->whereNotNull('start_date')
            ->whereNotNull('start_time')
            ->whereNull('completed_at')
            ->whereBetween('start_date', $this->dateRange($now, $lead))
            ->whereHas('column', fn ($q) => $q->where('is_done_column', false))
            ->with(['board.project.workspace', 'assignee'])
            ->get();

        $sent = 0;
        foreach ($items as $item) {
            $user = $item->assignee;
            $start = $user ? $this->startAt($item->start_date, $item->start_time, $user->localTimezone()) : null;
            if (! $start || $start->lte($now) || $start->gt($now->copy()->addMinutes($lead))) {
                continue;
            }

            $board = $item->board;
            $project = $board?->project;
            $workspace = $project?->workspace;
            if (! $board || ! $project || ! $workspace || ! $this->canOpen($user, $project)) {
                continue;
            }

            $sent += $this->send($user, "board_item:{$item->id}:{$start->timestamp}", new ItemReminderNotification(
                $item->key,
                $item->title,
                'Starts '.$this->inLabel($now, $start),
                route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $item->id]),
            ));
        }

        return $sent;
    }

    /** item_due: "due tomorrow" and "overdue" for assigned, open board issues (BRAI-103). */
    private function remindBoardItemsDue(Carbon $now): int
    {
        $items = Item::query()
            ->whereNotNull('assigned_to')
            ->whereNotNull('due_date')
            ->whereNull('completed_at')
            ->whereBetween('due_date', [$now->copy()->subDays(self::OVERDUE_DAYS + 1)->toDateString(), $now->copy()->addDays(2)->toDateString()])
            ->whereHas('column', fn ($q) => $q->where('is_done_column', false))
            ->with(['board.project.workspace', 'assignee'])
            ->get();

        $sent = 0;
        foreach ($items as $item) {
            $user = $item->assignee;
            $project = $item->board?->project;
            if (! $user || ! $project?->workspace || ! $this->canOpen($user, $project)) {
                continue;
            }

            $zone = $user->localTimezone();
            $localNow = $now->copy()->setTimezone($zone);
            $dueDay = Carbon::parse($item->due_date->toDateString(), $zone)->startOfDay();
            $time = $item->due_time ? substr((string) $item->due_time, 0, 5) : null;
            $dueAt = $time ? Carbon::parse($dueDay->toDateString().' '.$time, $zone) : $dueDay->copy()->addDay();
            $dateKey = $dueDay->toDateString();

            if ($now->gte($dueAt)) {
                if ($dueAt->lt($now->copy()->subDays(self::OVERDUE_DAYS))) {
                    continue;
                }
                $label = 'Was due '.($time ? $dueDay->format('D, M j').' at '.$time : $dueDay->format('D, M j'));
                $sent += $this->send($user, "item_due:{$item->id}:overdue:{$dateKey}", new ItemDueNotification($item, ItemDueNotification::OVERDUE, $label));
            } elseif ($localNow->copy()->addDay()->isSameDay($dueDay) && $localNow->hour >= self::DUE_TOMORROW_HOUR) {
                $label = $time ? "Due tomorrow at {$time}" : 'Due tomorrow';
                $sent += $this->send($user, "item_due:{$item->id}:due_tomorrow:{$dateKey}", new ItemDueNotification($item, ItemDueNotification::DUE_TOMORROW, $label));
            }
        }

        return $sent;
    }

    private function remindTaskListStarts(Carbon $now, int $lead): int
    {
        $items = TaskListItem::query()
            ->whereNotNull('start_date')
            ->whereNotNull('start_time')
            ->where('status', '!=', 'done')
            ->whereBetween('start_date', $this->dateRange($now, $lead))
            ->with(['taskList.project.workspace', 'assignee', 'creator'])
            ->get();

        $sent = 0;
        foreach ($items as $tli) {
            $user = $tli->reminderRecipient();
            $start = $user ? $this->startAt($tli->start_date, $tli->start_time, $user->localTimezone()) : null;
            if (! $start || $start->lte($now) || $start->gt($now->copy()->addMinutes($lead))) {
                continue;
            }

            $sent += $this->sendForListItem($user, $tli, "task_list_item:{$tli->id}:{$start->timestamp}", 'Starts '.$this->inLabel($now, $start));
        }

        return $sent;
    }

    private function remindTaskListDue(Carbon $now): int
    {
        // The longest reminder is a day ahead; ±1 day more covers every time zone.
        $items = TaskListItem::query()
            ->whereNotNull('reminder_minutes')
            ->whereNotNull('due_date')
            ->where('status', '!=', 'done')
            ->whereBetween('due_date', [$now->copy()->subDays(1)->toDateString(), $now->copy()->addDays(3)->toDateString()])
            ->with(['taskList.project.workspace', 'assignee', 'creator'])
            ->get();

        $sent = 0;
        foreach ($items as $tli) {
            $user = $tli->reminderRecipient();
            if (! $user) {
                continue;
            }
            $zone = $user->localTimezone();
            $remindAt = $tli->remindAt($zone);
            // Fire once the reminder time is reached (the scheduler runs every few minutes).
            if (! $remindAt || $remindAt->gt($now) || $remindAt->lt($now->copy()->subMinutes(self::DUE_GRACE_MINUTES))) {
                continue;
            }

            $sent += $this->sendForListItem($user, $tli, "task_list_item_due:{$tli->id}:{$remindAt->timestamp}", $this->dueLabel($now, $tli->dueAt($zone), (bool) $tli->due_time));
        }

        return $sent;
    }

    private function sendForListItem(User $user, TaskListItem $tli, string $key, string $label): int
    {
        $list = $tli->taskList;
        $project = $list?->project;
        $workspace = $project?->workspace;
        // The title goes out in the notification: only to people who can still open the list
        // (assignees outside the project, creators who left, rows written by older code).
        if (! $list || ! $project || ! $workspace || ! $this->canOpen($user, $project)) {
            return 0;
        }

        return $this->send($user, $key, new ItemReminderNotification(
            $project->key.'-L'.$tli->id,
            $tli->title,
            $label,
            route('task-lists.show', [$workspace->slug, $project->slug, $list->slug]).'#task-'.$tli->id,
        ));
    }

    /** @var array<string, bool> project access per "user:project" for this run */
    private array $access = [];

    private function canOpen(User $user, Project $project): bool
    {
        return $this->access["{$user->id}:{$project->id}"] ??= $user->hasProjectAccess($project);
    }

    private function send(User $user, string $key, Notification $notification): int
    {
        if ($this->alreadySent($user->id, $key)) {
            return 0;
        }

        $user->notify($notification);
        $this->markSent($user->id, $key);

        return 1;
    }

    /** Combine a date column and an "HH:MM[:SS]" wall-clock time in the given zone. */
    private function startAt(?Carbon $date, ?string $time, string $timezone): ?Carbon
    {
        if (! $date || ! $time) {
            return null;
        }

        return Carbon::parse($date->toDateString().' '.$time, $timezone);
    }

    private function inLabel(Carbon $now, Carbon $when): string
    {
        $mins = (int) ceil($now->diffInSeconds($when) / 60);

        return $mins <= 1 ? 'in 1 minute' : "in {$mins} minutes";
    }

    /** "Due now", "Due in 10 minutes", "Due in 1 hour", "Due today at 14:00", "Due tomorrow". */
    private function dueLabel(Carbon $now, Carbon $due, bool $hasTime): string
    {
        $mins = (int) round($now->diffInSeconds($due, false) / 60);
        $localNow = $now->copy()->setTimezone($due->getTimezone());

        if ($mins <= 1) {
            return $hasTime ? 'Due now' : 'Due today';
        }
        if ($mins < 60) {
            return "Due in {$mins} minutes";
        }
        if ($hasTime && $mins < 180) {
            $hours = (int) round($mins / 60);

            return $hours === 1 ? 'Due in 1 hour' : "Due in {$hours} hours";
        }
        if ($due->isSameDay($localNow)) {
            return $hasTime ? 'Due today at '.$due->format('H:i') : 'Due today';
        }
        if ($due->isSameDay($localNow->copy()->addDay())) {
            return $hasTime ? 'Due tomorrow at '.$due->format('H:i') : 'Due tomorrow';
        }

        return 'Due '.$due->format('D, M j');
    }

    private function alreadySent(int $userId, string $key): bool
    {
        return DB::table('sent_item_reminders')
            ->where('user_id', $userId)
            ->where('reminder_key', $key)
            ->exists();
    }

    private function markSent(int $userId, string $key): void
    {
        DB::table('sent_item_reminders')->insertOrIgnore([
            'user_id' => $userId,
            'reminder_key' => $key,
            'created_at' => now(),
        ]);
    }
}
