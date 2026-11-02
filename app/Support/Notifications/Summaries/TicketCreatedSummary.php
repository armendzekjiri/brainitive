<?php

namespace App\Support\Notifications\Summaries;

/** `ticket_created`: "Ada opened ticket SUP-3". */
final class TicketCreatedSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        return self::who($data).' opened ticket '.(self::value($data, 'ticket_key') ?? '');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'ticket_url');
    }
}
