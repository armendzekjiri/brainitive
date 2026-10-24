# Skill: Add a notification (in-app + browser push + email)

Notifications are stored in the DB (in-app bell), and optionally delivered as a browser web push
and/or an email. **Which channels** a user gets is their choice per *category* (Profile →
Notifications), from `app/Notifications/NotificationCatalog.php` (v1.39.0). Template for a new
type: any subclass of `app/Notifications/CatalogNotification.php`, e.g.
`ItemCommentedNotification.php`.

## 1. Register the type in the catalog
`NotificationCatalog::TYPES` maps every stored `type` to one of the `CATEGORIES` (mentions,
assignments, comments, status, reminders, pages, sprints, helpdesk, leads, workspace).
**Every database notification type must be listed:** `NotificationCatalog::category()` throws on
an unknown type, and `CatalogNotificationContractTest` checks the new ones.

```php
'widget_ready' => 'status',
```

## 2. Create the class: extend `CatalogNotification`
```php
namespace App\Notifications;

use App\Models\User;
use App\Models\Widget;

/** A widget you own is ready (category: status). */
class WidgetReadyNotification extends CatalogNotification
{
    public function __construct(
        public readonly Widget $widget,
        public readonly User $actor,
    ) {}

    public function catalogType(): string
    {
        return 'widget_ready';
    }

    protected function message(object $notifiable): array
    {
        return [
            'title' => "{$this->actor->name} finished {$this->widget->name}", // one worded line
            'body' => $this->widget->summary,          // optional; HTML is stripped, ≤ 200 chars
            'url' => route('widgets.show', [...]),     // absolute app URL
            'actor' => $this->actor,                   // optional → actor_name / actor_avatar
            'project_name' => 'Website · Main Board',  // optional context line
            'subject_key' => 'WEB-12',                 // optional chip
        ];
    }
}
```

The base class gives you, for free:
- `ShouldQueue` + `Queueable` (staging/e2e run the sync queue);
- `via()` = `RespectsPreferences::channelsFor($notifiable, type)`: the user's choice for the
  category (`database`, `WebPushChannel`, `mail`); non-User notifiables get mail only;
- `toDatabase()` = **the payload contract**, exactly these keys:
  `type, title, body, url, actor_name, actor_avatar, project_name, subject_key`. The bell renders
  any type generically from them, so a new type needs no frontend change;
- `toWebPush()` (title, body or context, url) and a generic `toMail()` (Markdown-safe: user text
  goes through `plain()`).

Older types (`item_assigned`, `mentioned`, `ticket_*`, `lead_created`, …) keep their own keys:
rows in the DB must keep rendering. Don't change their payloads; new types use the contract.

## 3. Send it from ONE place
Put the dispatch where every path passes, not in each controller:

| What | Where it's sent |
|---|---|
| Issue assigned (`item_assigned`) | `ItemObserver` → `Support\Notifications\Assignments::item()` |
| Task assigned (`task_assigned`) | `TaskListItemObserver` → `Assignments::task()` |
| Lead assigned (`lead_assigned`) | `LeadController::update` → `Assignments::lead()` |
| Comment (`item_commented`) + comment mentions | `Support\Boards\ItemComments::add()` → `ItemCommentNotifications` |
| Issue description mentions (`mentioned`) | `BoardController` → `ItemMentions::notify($html, $item, $actor, $previousHtml)` |
| Page mentions (`mentioned`) | `Page::saveDraft()` → `Support\Notifications\PageMentions` |
| Due tomorrow / overdue (`item_due`) | `app:send-item-reminders` (`sent_item_reminders` dedup) |
| Sprints (`sprint_started/completed`) | `SprintLifecycle` → `SprintNotifications` |
| Workspace (`invitation_accepted`, `workspace_role_changed`) | `WorkspaceNotifications` (both invite-accept paths, `updateMemberRole`) |
| Ticket status (`ticket_status_changed`) | `TicketController::move` |
| Helpdesk SLA (`sla_warning`, `sla_breached`) | `app:check-helpdesk-sla` (`sent_item_reminders` dedup) |

Rules every sender follows (KNOWLEDGE → "Notification coverage"):
- **Never notify the actor**, and never someone who can't open the project (`hasProjectAccess`).
- **Never twice for one event:** a mentioned person gets the mention, not also "commented on";
  a re-save only notifies people who are new in the content.
- **The actor is the signed-in user.** No actor (seeders, console) → assignment notifications stay
  silent.
- Inside a transaction, send with `DB::afterCommit(...)` so a rollback tells nobody.

## 4. Test it
- PHPUnit with `Notification::fake()` (real web push needs GMP/BCMath): recipients, no
  self-notify, dedupe, and `via()` following preferences
  (`$user->update(['notification_preferences' => ['status' => ['in_app' => true, 'push' => false, 'email' => false]]])`).
  Add the class to `CatalogNotificationContractTest::notifications()`.
- e2e: assert on the bell's feed with `e2e/helpers/notifications.ts`
  (`expectNotification(page, n => n.type === 'widget_ready' && …)`), see
  `e2e/tests/notifications-coverage.spec.ts`.

## 5. How the inbox shows it (BRAI-102)
- `describeNotification()` in `components/NotificationMessage.tsx` renders contract types from
  `title` (the actor's name in bold when the title starts with `actor_name`), `body` (preview
  line), `subject_key` (chip), `project_name` (context) and `url` (opened in the same tab, app
  URLs only). Older types have their own case there.
- The icon is the **category's** (`components/notifications/categories.tsx`): the feed sends
  `category` per row, computed from `NotificationCatalog::TYPES` (`null` → neutral bell).
- The Mentions / Assigned tabs filter on the `mentions` / `assignments` categories, so a new
  type in those categories shows up there automatically.
- A class that must stay on one channel intersects `channelsFor()` (see
  `CalendarReminderNotification`, push only).
