<?php

namespace App\Support\Notifications\Summaries;

/** `mentioned`: "Ada mentioned you in BRAI-12" / "… on page Roadmap". */
final class MentionedSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        $where = self::value($data, 'context_type') === 'page' ? ' mentioned you on page ' : ' mentioned you in ';

        return self::who($data).$where.(self::value($data, 'context_title') ?? '');
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'context_url');
    }
}
