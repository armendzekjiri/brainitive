<?php

namespace App\Notifications;

use App\Models\User;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Every in-app notification type and the category it belongs to (v1.39.0).
 *
 * Users choose channels per CATEGORY (not per type) on Profile → Notifications; the choice is
 * stored in users.notification_preferences as {"<category>": {"in_app": bool, "push": bool, "email": bool}}.
 * Missing keys fall back to the category defaults below. Contract: docs/ai/tasks/release-v1.39.0.md.
 *
 * A notification class picks its channels with Concerns\RespectsPreferences::channelsFor($notifiable, TYPE).
 * Mail-only messages to outsiders (ticket contacts, lead confirmations, invitations, email verification)
 * are not in the catalog and never consult preferences.
 */
final class NotificationCatalog
{
    public const CHANNELS = ['in_app', 'push', 'email'];

    /** category => [label, description, default channels] */
    public const CATEGORIES = [
        'mentions' => ['label' => 'Mentions', 'description' => 'Someone @mentions you in an issue, comment or page.', 'defaults' => ['in_app' => true, 'push' => true, 'email' => false]],
        'assignments' => ['label' => 'Assignments', 'description' => 'An issue, task, ticket or lead is assigned to you.', 'defaults' => ['in_app' => true, 'push' => true, 'email' => false]],
        'comments' => ['label' => 'Comments', 'description' => 'New comments on issues you watch.', 'defaults' => ['in_app' => true, 'push' => false, 'email' => false]],
        'status' => ['label' => 'Status changes', 'description' => 'Issues you watch move to another column or are completed.', 'defaults' => ['in_app' => true, 'push' => false, 'email' => false]],
        'reminders' => ['label' => 'Reminders & due dates', 'description' => 'Reminders, due soon, overdue and the daily task digest.', 'defaults' => ['in_app' => true, 'push' => true, 'email' => false]],
        'pages' => ['label' => 'Pages', 'description' => 'Pages you watch are published or changed.', 'defaults' => ['in_app' => true, 'push' => false, 'email' => false]],
        'sprints' => ['label' => 'Sprints', 'description' => 'A sprint with your issues starts or is completed.', 'defaults' => ['in_app' => true, 'push' => false, 'email' => false]],
        'helpdesk' => ['label' => 'Helpdesk', 'description' => 'New tickets, customer replies and ticket assignments.', 'defaults' => ['in_app' => true, 'push' => true, 'email' => true]],
        'leads' => ['label' => 'Leads', 'description' => 'New leads from your forms and leads assigned to you.', 'defaults' => ['in_app' => true, 'push' => true, 'email' => true]],
        'workspace' => ['label' => 'Workspace', 'description' => 'Invitations accepted, role changes and other membership news.', 'defaults' => ['in_app' => true, 'push' => false, 'email' => false]],
        // v1.42.0 "Work with clients"
        'reviews' => ['label' => 'Reviews & approvals', 'description' => 'Review requests, reviewer decisions, and client approvals or change requests.', 'defaults' => ['in_app' => true, 'push' => true, 'email' => true]],
        'feedback' => ['label' => 'Feedback portal', 'description' => 'New ideas waiting for moderation on your public feedback portals.', 'defaults' => ['in_app' => true, 'push' => false, 'email' => false]],
        // v1.45.0 (BRAI-158)
        'bookings' => ['label' => 'Bookings', 'description' => 'New, rescheduled and cancelled bookings on your booking pages, and calendars that couldn’t be checked.', 'defaults' => ['in_app' => true, 'push' => true, 'email' => true]],
    ];

    /**
     * type (the `type` key stored in notifications.data) => category.
     * Ticket 2 (notification coverage) adds its new types here; ticket 1 renders every type
     * (unknown types fall back to the generic payload keys — see the contract).
     */
    public const TYPES = [
        'mentioned' => 'mentions',
        'item_assigned' => 'assignments',
        'item_status_changed' => 'status',
        'item_reminder' => 'reminders',
        'task_digest' => 'reminders',
        'calendar_reminder' => 'reminders',
        'page_updated' => 'pages',
        // helpdesk (not assignments): ticket assignments have always been emailed; the inbox's Assigned tab still lists them
        'ticket_assigned' => 'helpdesk',
        'ticket_created' => 'helpdesk',
        'ticket_reply' => 'helpdesk',
        'lead_created' => 'leads',
        // BRAI-103 (notification coverage): CatalogNotification subclasses, payload contract keys
        'item_commented' => 'comments',
        'item_due' => 'reminders',
        'task_assigned' => 'assignments',
        'lead_assigned' => 'assignments',
        'sprint_started' => 'sprints',
        'sprint_completed' => 'sprints',
        'invitation_accepted' => 'workspace',
        'workspace_role_changed' => 'workspace',
        'ticket_status_changed' => 'helpdesk',
        'webhook_disabled' => 'workspace', // BRAI-104: a webhook was auto-disabled
        // BRAI-125 (helpdesk SLA): app:check-helpdesk-sla, to the assignee or the project admins
        'sla_warning' => 'helpdesk',
        'sla_breached' => 'helpdesk',
        'automation_notice' => 'status', // BRAI-63: the "Notify" action of an automation rule
        'export_ready' => 'workspace', // BRAI-81: a project export can be downloaded
        'data_export_ready' => 'workspace', // BRAI-156: "Download my data" is ready (in-app + email always; push per category)
        // BRAI-158 (booking pages): to the host
        'booking_created' => 'bookings',
        'booking_cancelled' => 'bookings',
        'booking_calendar_unreadable' => 'bookings',
        // v1.42.0 — one line per type, reserved by the foundation so the tickets don't collide
        'feedback_submitted' => 'feedback', // BRAI-60: a new post waits for moderation (project admins)
        'client_decision' => 'reviews', // BRAI-73: a client approved / requested changes on a deliverable
        'client_commented' => 'reviews', // BRAI-73: a client commented on a deliverable
        'review_requested' => 'reviews', // BRAI-80: you were asked to review a page revision
        'review_decided' => 'reviews', // BRAI-80: a reviewer approved / requested changes (to the author)
        'review_commented' => 'reviews', // BRAI-80: an inline review comment or reply on your page / thread
        'github_sync_failed' => 'workspace', // BRAI-61: the GitHub connection lost access (project admins)
    ];

    public static function category(string $type): string
    {
        return self::TYPES[$type] ?? throw new \InvalidArgumentException("Unknown notification type [{$type}] — add it to NotificationCatalog::TYPES.");
    }

    /** Effective {in_app, push, email} for a user and category (stored choice over defaults). */
    public static function preferences(User $user, string $category): array
    {
        $defaults = self::CATEGORIES[$category]['defaults'];
        $stored = $user->notification_preferences[$category] ?? [];

        return array_map('boolval', array_intersect_key(array_replace($defaults, is_array($stored) ? $stored : []), $defaults));
    }

    /** All categories with labels, defaults and the user's effective choice (for the settings UI). */
    public static function forUser(User $user): array
    {
        return collect(self::CATEGORIES)->map(fn (array $c, string $key) => [
            'key' => $key,
            'label' => $c['label'],
            'description' => $c['description'],
            'channels' => self::preferences($user, $key),
        ])->values()->all();
    }

    /** Laravel channel names for a type: 'database', WebPushChannel::class, 'mail'. */
    public static function channels(object $notifiable, string $type): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        $prefs = self::preferences($notifiable, self::category($type));

        return array_values(array_filter([
            $prefs['in_app'] ? 'database' : null,
            $prefs['push'] ? WebPushChannel::class : null,
            $prefs['email'] ? 'mail' : null,
        ]));
    }
}
