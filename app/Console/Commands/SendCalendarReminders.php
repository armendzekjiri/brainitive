<?php

namespace App\Console\Commands;

use App\Models\CalendarConnection;
use App\Notifications\CalendarReminderNotification;
use App\Services\GoogleCalendarService;
use App\Services\GoogleTasksService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendCalendarReminders extends Command
{
    protected $signature = 'app:send-calendar-reminders {--lead=15 : Minutes before start/due to remind}';

    protected $description = 'Push browser reminders for upcoming Google Calendar events and Google Tasks';

    public function handle(GoogleCalendarService $calendarService, GoogleTasksService $tasksService): int
    {
        $lead = max(1, (int) $this->option('lead'));
        $now = now();
        $windowEnd = $now->copy()->addMinutes($lead);

        $connections = CalendarConnection::where('provider', 'google')
            ->where('is_active', true)
            ->whereNotNull('access_token')
            ->with('user')
            ->get();

        $sent = 0;
        foreach ($connections as $connection) {
            if (! $connection->user) {
                continue;
            }

            $reminders = $connection->kind === 'tasks'
                ? $this->taskReminders($connection, $tasksService, $now, $windowEnd)
                : $this->eventReminders($connection, $calendarService, $now, $windowEnd);

            foreach ($reminders as $reminder) {
                if ($this->alreadySent($connection, $reminder['key'])) {
                    continue;
                }
                $connection->user->notify(new CalendarReminderNotification(
                    $reminder['title'],
                    $reminder['when'],
                    route('calendar.index'),
                ));
                $this->markSent($connection, $reminder['key']);
                $sent++;
            }
        }

        $this->info("Queued {$sent} reminder(s).");

        return self::SUCCESS;
    }

    /** Build reminders for timed calendar events starting within the window. */
    private function eventReminders(CalendarConnection $connection, GoogleCalendarService $service, Carbon $now, Carbon $windowEnd): array
    {
        $out = [];
        foreach ($service->listEvents($connection, $now, $windowEnd) as $event) {
            $startRaw = $event['start']['dateTime'] ?? null; // skip all-day (date only)
            if (! $startRaw) {
                continue;
            }
            $start = Carbon::parse($startRaw);
            if ($start->lte($now) || $start->gt($windowEnd)) {
                continue;
            }
            $out[] = [
                'key' => 'event:'.($event['id'] ?? $startRaw).':'.$start->timestamp,
                'title' => $event['summary'] ?? '(untitled event)',
                'when' => 'Starts in '.$this->minutesLabel($now, $start),
            ];
        }

        return $out;
    }

    /** Build reminders for tasks with a real due time within the window. */
    private function taskReminders(CalendarConnection $connection, GoogleTasksService $service, Carbon $now, Carbon $windowEnd): array
    {
        $out = [];
        foreach ($service->listTasks($connection, $now, $windowEnd) as $task) {
            if (($task['status'] ?? null) === 'completed') {
                continue;
            }
            $dueRaw = $task['due'] ?? null;
            if (! $dueRaw) {
                continue;
            }
            $due = Carbon::parse($dueRaw);
            // Google Tasks stores time-less due dates as UTC midnight — skip those
            // (no meaningful reminder time).
            if ($due->format('H:i:s') === '00:00:00' && $due->timezoneName === 'UTC') {
                continue;
            }
            if ($due->lte($now) || $due->gt($windowEnd)) {
                continue;
            }
            $out[] = [
                'key' => 'task:'.($task['id'] ?? $dueRaw).':'.$due->timestamp,
                'title' => $task['title'] ?? '(untitled task)',
                'when' => 'Due in '.$this->minutesLabel($now, $due),
            ];
        }

        return $out;
    }

    private function minutesLabel(Carbon $now, Carbon $when): string
    {
        $mins = (int) ceil($now->diffInSeconds($when) / 60);

        return $mins <= 1 ? '1 minute' : "{$mins} minutes";
    }

    private function alreadySent(CalendarConnection $connection, string $key): bool
    {
        return DB::table('sent_reminders')
            ->where('calendar_connection_id', $connection->id)
            ->where('reminder_key', $key)
            ->exists();
    }

    private function markSent(CalendarConnection $connection, string $key): void
    {
        DB::table('sent_reminders')->insertOrIgnore([
            'calendar_connection_id' => $connection->id,
            'reminder_key' => $key,
            'created_at' => now(),
        ]);
    }
}
