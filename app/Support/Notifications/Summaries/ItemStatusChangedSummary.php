<?php

namespace App\Support\Notifications\Summaries;

/** `item_status_changed`: "Ada moved BRAI-12 from To Do → Done". */
final class ItemStatusChangedSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        return self::who($data).' moved '.(self::value($data, 'item_key') ?? '')
            .' from '.(self::value($data, 'old_column') ?? '?').' → '.(self::value($data, 'new_column') ?? '?');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'item_url');
    }
}
