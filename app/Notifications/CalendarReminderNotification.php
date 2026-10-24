<?php

namespace App\Notifications;

use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * A browser-push reminder that an upcoming Google Calendar event or Google Task
 * is starting / due soon. Push-only — these are time-sensitive and would just
 * clutter the in-app notification list.
 */
class CalendarReminderNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly string $title,
        public readonly string $whenLabel,
        public readonly string $url = '/calendar',
    ) {}

    public function via(object $notifiable): array
    {
        // push-only on purpose (time-sensitive, would clutter the bell); the user can still turn push off
        return array_values(array_intersect($this->channelsFor($notifiable, 'calendar_reminder'), [WebPushChannel::class]));
    }

    public function toWebPush(object $notifiable, $notification)
    {
        return $this->webPush($this->title, $this->whenLabel, $this->url);
    }
}
