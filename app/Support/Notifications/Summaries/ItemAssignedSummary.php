<?php

namespace App\Support\Notifications\Summaries;

/** `item_assigned`: "Ada assigned you to BRAI-12". */
final class ItemAssignedSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        return self::who($data).' assigned you to '.(self::value($data, 'item_key') ?? '');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'item_url');
    }
}
