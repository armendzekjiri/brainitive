<?php

namespace App\Support\Notifications\Summaries;

/** `ticket_reply`: "Ada replied to ticket SUP-3". */
final class TicketReplySummary extends NotificationSummary
{
    public function text(array $data): string
    {
        return self::who($data).' replied to ticket '.(self::value($data, 'ticket_key') ?? '');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'ticket_url');
    }
}
