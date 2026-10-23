<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The morning summary of the user's open list tasks: due today and overdue
 * (`app:send-task-digest`). One per user per local day, to the bell and the browser.
 */
class TaskDigestNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    /**
     * @param  list<string>  $titles  a few task titles, most urgent first
     */
    public function __construct(
        public readonly int $dueToday,
        public readonly int $overdue,
        public readonly array $titles,
        public readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->withWebPush($notifiable, ['database']);
    }

    public function summary(): string
    {
        return collect([
            $this->dueToday ? "{$this->dueToday} due today" : null,
            $this->overdue ? "{$this->overdue} overdue" : null,
        ])->filter()->implode(' · ');
    }

    public function toWebPush(object $notifiable, $notification)
    {
        return $this->webPush('Your tasks: '.$this->summary(), implode(', ', $this->titles), $this->url);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'task_digest',
            'due_today' => $this->dueToday,
            'overdue' => $this->overdue,
            'titles' => $this->titles,
            'url' => $this->url,
        ];
    }
}
