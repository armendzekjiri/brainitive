# Skill: Add a notification (in-app + browser push)

Notifications are stored in the DB (in-app bell) and optionally delivered as a browser web
push. Template: `app/Notifications/ItemAssignedNotification.php`.

## 1. Create the notification — `app/Notifications/WidgetReadyNotification.php`
```php
namespace App\Notifications;

use App\Models\User;
use App\Models\Widget;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class WidgetReadyNotification extends Notification
{
    use Queueable, SendsWebPush;

    public function __construct(
        public readonly Widget $widget,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        // 'database' = in-app bell; SendsWebPush adds browser push only for User notifiables
        return $this->withWebPush($notifiable, ['database']);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'         => 'widget_ready',
            'widget_name'  => $this->widget->name,
            'widget_url'   => route('widgets.show', [...]),
            'actor_name'   => $this->actor->name,
            'actor_avatar' => $this->actor->avatar_display,
        ];
    }

    public function toWebPush(object $notifiable, $notification)
    {
        return $this->webPush(
            "{$this->actor->name} finished a widget",
            $this->widget->name,
            route('widgets.show', [...]),
        );
    }
}
```

## 2. Conventions
- **Always queue** (`use Queueable`) so requests stay fast.
- **Browser push:** use the `App\Notifications\Concerns\SendsWebPush` trait. `withWebPush(
  $notifiable, ['database'])` appends `WebPushChannel` **only when the notifiable is a
  `User`** — never for `TicketContact` (external customers have no push subscriptions). Build
  the message with `$this->webPush($title, $body, $url)` (sets app icon/badge + click URL).
- **In-app payload** (`toDatabase`): include a `type` discriminator, display fields, a `*_url`
  to click through, and `actor_avatar` (use the model's `avatar_display` append). The frontend
  bell renders by `type`.
- Read notifications via `NotificationController` (`/api/notifications`, unread-count,
  mark-read). Push subscriptions are managed by `PushSubscriptionController`.

## 3. Send it
```php
$user->notify(new WidgetReadyNotification($widget, $request->user()));
```
Email-only notifiables (e.g. ticket contacts) just won't get the push channel.

## 4. Verify
Run the queue (`composer run dev` includes `queue:listen`), trigger the event, confirm the
bell shows the entry and (if push is enabled in the browser) a notification appears. Add a
test asserting the notification is sent with the right channels.
