<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\TaskListItem;
use App\Notifications\ItemReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pushes a reminder to the assignee when a board item or task-list item shown
 * on the calendar is about to start. Mirrors SendCalendarReminders (Google),
 * but for the app's own scheduled work. Reminders go to the in-app bell AND
 * the browser; dedup is via the shared `sent_reminders` table, scoped by user.
 */
class SendItemReminders extends Command
{
    protected $signature = 'app:send-item-reminders {--lead=15 : Minutes before start to remind}';

    protected $description = 'Push reminders for board / task-list items reaching their start time';

    public function handle(): int
    {
        $lead = max(1, (int) $this->option('lead'));
        $now = now();
        $windowEnd = $now->copy()->addMinutes($lead);

        $sent = $this->remindBoardItems($now, $windowEnd)
            + $this->remindTaskListItems($now, $windowEnd);

        $this->info("Queued {$sent} item reminder(s).");

        return self::SUCCESS;
    }

    private function remindBoardItems(Carbon $now, Carbon $windowEnd): int
    {
        $items = Item::query()
            ->whereNull('parent_id')
            ->whereNotNull('assigned_to')
            ->whereNotNull('start_date')
            ->whereNotNull('start_time')
            ->whereNull('completed_at')
            ->whereBetween('start_date', [$now->toDateString(), $windowEnd->toDateString()])
            ->whereHas('column', fn ($q) => $q->where('is_done_column', false))
            ->with(['board.project.workspace', 'assignee'])
            ->get();

        $sent = 0;
        foreach ($items as $item) {
            $start = $this->startAt($item->start_date, $item->start_time);
            if (! $start || $start->lte($now) || $start->gt($windowEnd)) {
                continue;
            }

            $board = $item->board;
            $project = $board?->project;
            $workspace = $project?->workspace;
            if (! $board || ! $project || ! $workspace) {
                continue;
            }

            $key = "board_item:{$item->id}:{$start->timestamp}";
            if ($this->alreadySent($item->assigned_to, $key)) {
                continue;
            }

            $item->assignee->notify(new ItemReminderNotification(
                $item->key,
                $item->title,
                'Starts '.$this->whenLabel($now, $start),
                route('boards.items.show', [$workspace->slug, $project->slug, $board->slug, $item->id]),
            ));
            $this->markSent($item->assigned_to, $key);
            $sent++;
        }

        return $sent;
    }

    private function remindTaskListItems(Carbon $now, Carbon $windowEnd): int
    {
        $items = TaskListItem::query()
            ->whereNotNull('assignee_id')
            ->whereNotNull('start_date')
            ->whereNotNull('start_time')
            ->where('status', '!=', 'done')
            ->whereBetween('start_date', [$now->toDateString(), $windowEnd->toDateString()])
            ->with(['taskList.project.workspace', 'assignee'])
            ->get();

        $sent = 0;
        foreach ($items as $tli) {
            $start = $this->startAt($tli->start_date, $tli->start_time);
            if (! $start || $start->lte($now) || $start->gt($windowEnd)) {
                continue;
            }

            $list = $tli->taskList;
            $project = $list?->project;
            $workspace = $project?->workspace;
            if (! $list || ! $project || ! $workspace) {
                continue;
            }

            $key = "task_list_item:{$tli->id}:{$start->timestamp}";
            if ($this->alreadySent($tli->assignee_id, $key)) {
                continue;
            }

            $tli->assignee->notify(new ItemReminderNotification(
                $project->key.'-L'.$tli->id,
                $tli->title,
                'Starts '.$this->whenLabel($now, $start),
                route('task-lists.show', [$workspace->slug, $project->slug, $list->slug]),
            ));
            $this->markSent($tli->assignee_id, $key);
            $sent++;
        }

        return $sent;
    }

    /** Combine a date column and an "HH:MM[:SS]" time string into a datetime. */
    private function startAt(?Carbon $date, ?string $time): ?Carbon
    {
        if (! $date || ! $time) {
            return null;
        }

        return Carbon::parse($date->toDateString().' '.$time);
    }

    private function whenLabel(Carbon $now, Carbon $when): string
    {
        $mins = (int) ceil($now->diffInSeconds($when) / 60);

        if ($mins <= 1) {
            return 'in 1 minute';
        }

        return "in {$mins} minutes";
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
