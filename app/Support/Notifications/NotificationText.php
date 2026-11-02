<?php

namespace App\Support\Notifications;

use App\Models\InboxNotification;
use App\Models\User;
use App\Support\Notifications\Summaries\ContractSummary;
use App\Support\Notifications\Summaries\ItemAssignedSummary;
use App\Support\Notifications\Summaries\ItemReminderSummary;
use App\Support\Notifications\Summaries\ItemStatusChangedSummary;
use App\Support\Notifications\Summaries\LeadCreatedSummary;
use App\Support\Notifications\Summaries\MentionedSummary;
use App\Support\Notifications\Summaries\NotificationSummary;
use App\Support\Notifications\Summaries\PageUpdatedSummary;
use App\Support\Notifications\Summaries\TaskDigestSummary;
use App\Support\Notifications\Summaries\TicketAssignedSummary;
use App\Support\Notifications\Summaries\TicketCreatedSummary;
use App\Support\Notifications\Summaries\TicketReplySummary;

/**
 * A notification as one line of plain text and a link, for clients that can't run the web bell's renderer
 * (App API `summary` / `url`: tray, native notifications, mobile, TV). The wording is the bell's (English,
 * the app has no translations): one `Summaries\*` class per type the bell words itself, `ContractSummary`
 * for catalog types and as the fallback for unknown ones. The text is plain (HTML and Markdown stripped,
 * `@[Name](user:<id>)` → `@Name`, nothing escaped) and at most 200 characters; the link is only given when
 * the user can open that page now (NotificationLinks).
 */
final class NotificationText
{
    public const MAX = 200;

    /** type => summary class; everything else is ContractSummary. */
    private const TYPES = [
        'task_digest' => TaskDigestSummary::class,
        'item_reminder' => ItemReminderSummary::class,
        'mentioned' => MentionedSummary::class,
        'page_updated' => PageUpdatedSummary::class,
        'item_assigned' => ItemAssignedSummary::class,
        'item_status_changed' => ItemStatusChangedSummary::class,
        'ticket_created' => TicketCreatedSummary::class,
        'ticket_reply' => TicketReplySummary::class,
        'ticket_assigned' => TicketAssignedSummary::class,
        'lead_created' => LeadCreatedSummary::class,
    ];

    public static function for(?string $type): NotificationSummary
    {
        $class = self::TYPES[$type ?? ''] ?? ContractSummary::class;

        return new $class;
    }

    public static function summary(InboxNotification $notification): string
    {
        $data = NotificationInbox::data($notification);

        try {
            $text = self::plain(self::for(NotificationInbox::type($notification))->text($data));
        } catch (\Throwable $e) {
            report($e);
            $text = '';
        }

        return $text !== '' ? $text : 'You have a new notification';
    }

    /** The absolute link of the notification on this server, or null (none, foreign, or not visible to the user now). */
    public static function url(User $user, InboxNotification $notification, ?NotificationLinks $links = null): ?string
    {
        $link = self::for(NotificationInbox::type($notification))->link(NotificationInbox::data($notification));

        return ($links ?? new NotificationLinks($user))->visible($link);
    }

    /** One line of plain text: HTML and Markdown out, mentions as @Name, whitespace collapsed, ≤ MAX characters. */
    public static function plain(string $text): string
    {
        $text = str_replace(['</p>', '<br>', '<br/>', '<br />'], ' ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/@\[([^\]\n]+)\]\(user:\d+\)/u', '@$1', $text);
        $text = (string) preg_replace('/!\[([^\]\n]*)\]\([^)\s]*\)/u', '$1', $text); // image → its alt text
        $text = (string) preg_replace('/\[([^\]\n]+)\]\([^)\s]*\)/u', '$1', $text); // link → its text
        $text = (string) preg_replace('/^[ \t]{0,3}(?:#{1,6}|>|[-*+]|\d+\.)[ \t]+/mu', '', $text); // headings, quotes, list markers
        $text = (string) preg_replace('/\*\*|__|~~|`+/u', '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::MAX ? rtrim(mb_substr($text, 0, self::MAX - 1)).'…' : $text;
    }
}
