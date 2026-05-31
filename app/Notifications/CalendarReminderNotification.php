<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A browser-push reminder that an upcoming Google Calendar event or Google Task
 * is starting / due soon. Push-only — these are time-sensitive and would just
 * clutter the in-app notification list.
 */
class CalendarReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly string $title,
        public readonly string $whenLabel,
        public readonly string $url = '/calendar',
    ) {}

    public function via(object $notifiable): array
    {
        return $this->withWebPush($notifiable, []);
    }

    public function toWebPush(object $notifiable, $notification)
    {
        return $this->webPush($this->title, $this->whenLabel, $this->url);
    }
}
