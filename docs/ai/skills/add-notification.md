# Skill: Add a notification (in-app + browser push + email)

Notifications are stored in the DB (the bell and the `/notifications` inbox) and optionally
delivered as a browser web push and an email. **Which channels a user gets is their choice per
category** (Profile → Notifications, `users.notification_preferences`), not the class's.
Template: `app/Notifications/ItemAssignedNotification.php`.

## 1. Register the type — `app/Notifications/NotificationCatalog.php`
Add the type to `TYPES` with its category (`mentions`, `assignments`, `comments`, `status`,
`reminders`, `pages`, `sprints`, `helpdesk`, `leads`, `workspace`). Every database notification
type must be listed — `NotificationCatalog::category()` throws on an unknown type. The category
decides the default channels, the inbox filter tab (Mentions / Assigned) and the row icon.

## 2. Create the notification — `app/Notifications/WidgetReadyNotification.php`
```php
namespace App\Notifications;

use App\Models\User;
use App\Models\Widget;
use App\Notifications\Concerns\RespectsPreferences;
use App\Notifications\Concerns\SendsWebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WidgetReadyNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences, SendsWebPush;

    public function __construct(
        public readonly Widget $widget,
        public readonly User $actor,
    ) {}

    public function via(object $notifiable): array
    {
        // the user's channels for the type's category; mail / push only if the class can render them
        return $this->channelsFor($notifiable, 'widget_ready');
    }

    public function toDatabase(object $notifiable): array
    {
        return [ // the payload contract: the inbox renders these keys without a frontend change
            'type'         => 'widget_ready',
            'title'        => "{$this->actor->name} finished {$this->widget->name}",
            'body'         => null,                       // optional preview, plain text ≤ 200 chars
            'url'          => route('widgets.show', [...]),
            'actor_name'   => $this->actor->name,
            'actor_avatar' => $this->actor->avatar_display,
            'project_name' => $this->widget->project->name, // context line
            'subject_key'  => null,                       // e.g. "BRAI-12", shown as a chip
        ];
    }

    public function toWebPush(object $notifiable, $notification)
    {
        return $this->webPush("{$this->actor->name} finished a widget", $this->widget->name, route('widgets.show', [...]));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->actor->name} finished ".NewLeadNotification::plain($this->widget->name))
            ->action('Open widget', route('widgets.show', [...]));
    }
}
```

## 3. Conventions
- **Queue** new classes (`ShouldQueue`) so requests stay fast.
- **`via()` = `channelsFor($notifiable, '<type>')`** (`Concerns\RespectsPreferences`). It drops
  `mail` / push when the class has no `toMail` / `toWebPush`, and gives non-User notifiables
  (ticket contacts) mail only. A class that must stay on one channel intersects the result (see
  `CalendarReminderNotification`, push only).
- **Payload** (`toDatabase`): use the contract keys above. The frontend (`describeNotification` in
  `components/NotificationMessage.tsx`) renders any type from them; only the older types have
  their own case. If `title` starts with `actor_name`, the name is shown in bold. URLs must be app
  URLs (`route(...)`) — the UI drops anything else.
- **Mail:** visitor- or user-written text goes through `NewLeadNotification::plain()` (Markdown
  would render links).
- Push messages: `$this->webPush($title, $body, $url)` (app icon/badge + click URL).
- The inbox API is `NotificationController` (feed, read / unread / archive, bulk, preferences); see
  ARCHITECTURE → Notifications.

## 4. Send it
```php
$user->notify(new WidgetReadyNotification($widget, $request->user()));
```
Don't notify the actor about their own action.

## 5. Verify
- PHPUnit: `Notification::fake()` + `assertSentTo(...)`; assert `via()` follows preferences
  (`tests/Feature/Notifications/NotificationPreferencesTest.php`).
- Run the queue (`composer run dev` includes `queue:listen`), trigger the event, open the bell:
  the row shows the category icon and the title, and opens the URL in the same tab.
