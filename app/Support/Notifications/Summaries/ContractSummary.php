<?php

namespace App\Support\Notifications\Summaries;

/**
 * Every catalog type (`CatalogNotification`: the server words the sentence as `title`) and the safe fallback
 * for a type nobody knows: the title, else "Ada updated BRAI-12" / "Someone sent you a notification" — the
 * bell's `genericLine`. The link is the payload's `url`.
 */
final class ContractSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        $title = self::str($data, 'title');
        if ($title !== null) {
            return $title;
        }
        $subject = self::str($data, 'subject_key') ?? self::str($data, 'item_key') ?? self::str($data, 'ticket_key') ?? self::str($data, 'page_name');

        return self::who($data).($subject !== null ? ' updated '.$subject : ' sent you a notification');
    }
}
