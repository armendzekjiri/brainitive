<?php

namespace App\Support\Notifications\Summaries;

/** `lead_created`: "New lead #4 from Jane Doe". */
final class LeadCreatedSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        $name = self::str($data, 'lead_name');

        return 'New lead #'.(self::value($data, 'lead_number') ?? '').($name !== null ? ' from '.$name : '');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'lead_url');
    }
}
