<?php

namespace App\Support\Notifications\Summaries;

/** `item_reminder`: "BRAI-12 is due in 1 hour". */
final class ItemReminderSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        $when = self::value($data, 'when_label');

        return (self::value($data, 'item_key') ?? 'An item').($when ? ' '.$when : '');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'item_url');
    }
}
