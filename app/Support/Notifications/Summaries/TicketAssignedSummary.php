<?php

namespace App\Support\Notifications\Summaries;

/** `ticket_assigned`: "Ada assigned you to ticket SUP-3". */
final class TicketAssignedSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        return self::who($data).' assigned you to ticket '.(self::value($data, 'ticket_key') ?? '');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'ticket_url');
    }
}
