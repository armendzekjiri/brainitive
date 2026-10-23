<?php

namespace App\Console\Commands;

use App\Models\TaskListItem;
use App\Models\User;
use App\Notifications\TaskDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Morning summary of open task-list tasks that are due today or overdue, sent once per
 * user per local day from 08:00 in the user's time zone (runs every few minutes, so a
 * scheduler outage only delays it). Recipient = assignee, else the task's creator; users
 * can turn it off (`users.task_digest`). Only tasks in projects the user can open count.
 */
class SendTaskDigest extends Command
{
    protected $signature = 'app:send-task-digest';

    protected $description = 'Send each user a morning summary of list tasks due today or overdue';

    public const SEND_FROM_HOUR = 8;

    public function handle(): int
    {
        $now = now();
        // Due by tomorrow (UTC) — "today" differs per time zone.
        $dueBy = $now->copy()->addDay()->toDateString();
        $open = fn (): Builder => TaskListItem::query()
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $dueBy);

        // Narrow to people first, so a run only loads tasks for users who still need today's summary.
        $recipientIds = $open()->selectRaw('distinct coalesce(assignee_id, created_by) as recipient_id')->pluck('recipient_id')->filter();
        $users = User::whereIn('id', $recipientIds)->where('task_digest', true)->get();

        $sent = 0;
        foreach ($users as $user) {
            $local = $now->copy()->setTimezone($user->localTimezone());
            if ($local->hour < self::SEND_FROM_HOUR) {
                continue;
            }
            $key = 'task_digest:'.$local->toDateString();
            if (DB::table('sent_item_reminders')->where('user_id', $user->id)->where('reminder_key', $key)->exists()) {
                continue;
            }

            $today = $local->toDateString();
            $items = $open()
                ->where(fn ($q) => $q->where('assignee_id', $user->id)->orWhere(fn ($q) => $q->whereNull('assignee_id')->where('created_by', $user->id)))
                ->whereHas('taskList', fn ($q) => $q->whereIn('project_id', $user->accessibleProjectIds()))
                ->where('due_date', '<=', $today)
                ->orderBy('due_date')->orderBy('position')->orderBy('id')
                ->get(['id', 'title', 'due_date']);
            $overdue = $items->filter(fn (TaskListItem $i) => $i->due_date->toDateString() < $today);
            $dueToday = $items->filter(fn (TaskListItem $i) => $i->due_date->toDateString() === $today);

            // Mark the day done either way: it's a morning summary, not a feed.
            DB::table('sent_item_reminders')->insertOrIgnore(['user_id' => $user->id, 'reminder_key' => $key, 'created_at' => now()]);
            if ($items->isEmpty()) {
                continue;
            }

            $user->notify(new TaskDigestNotification(
                $dueToday->count(),
                $overdue->count(),
                $overdue->concat($dueToday)->take(3)->pluck('title')->values()->all(),
                route('my-work.index'),
            ));
            $sent++;
        }

        $this->info("Sent {$sent} task digest(s).");

        return self::SUCCESS;
    }
}
