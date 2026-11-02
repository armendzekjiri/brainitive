<?php

namespace App\Support\Notifications\Summaries;

/** `page_updated`: "Ada updated page Roadmap". */
final class PageUpdatedSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        return self::who($data).' updated page '.(self::value($data, 'page_name') ?? '');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'page_url');
    }
}
