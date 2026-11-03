<?php

namespace App\Support\Offline\Appliers;

use App\Support\Notifications\NotificationInbox;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncAbort;
use App\Support\Offline\SyncOutcome;
use Illuminate\Support\Carbon;

/**
 * The notification inbox read while offline (App API sync): the same actions as `POST /notifications/{id}/read`
 * and `/read-all` (`NotificationInbox`, the user's own rows only; the web bell follows at once).
 *
 * - `notification.read {notification_id}` — someone else's / deleted → gone.
 * - `notifications.read_all {before}` — only notifications created until `before` (the device's "read all"
 *   moment), so what arrived later stays unread.
 */
final class NotificationMutations
{
    public static function read(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate(['notification_id' => 'required|uuid']);
        $id = (string) $data['notification_id'];
        if (! NotificationInbox::own($ctx->user)->whereKey($id)->exists()) {
            throw new SyncAbort(SyncOutcome::gone('notification', 'This notification is gone.'));
        }

        $unread = NotificationInbox::apply($ctx->user, [$id], 'read');

        return SyncOutcome::applied('notification', ['id' => $id, 'read' => true, 'unread_count' => $unread]);
    }

    public static function readAll(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate(['before' => 'required|date']);
        $unread = NotificationInbox::markAllRead($ctx->user, Carbon::parse($data['before']));

        return SyncOutcome::applied('notifications', ['unread_count' => $unread]);
    }
}
