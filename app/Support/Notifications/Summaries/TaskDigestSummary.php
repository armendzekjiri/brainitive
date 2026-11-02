<?php

namespace App\Support\Notifications\Summaries;

/** `task_digest`: "Your tasks: 2 due today · 1 overdue". */
final class TaskDigestSummary extends NotificationSummary
{
    public function text(array $data): string
    {
        $parts = array_filter([
            ! empty($data['due_today']) ? ((int) $data['due_today']).' due today' : null,
            ! empty($data['overdue']) ? ((int) $data['overdue']).' overdue' : null,
        ]);

        return 'Your tasks: '.($parts === [] ? 'nothing due' : implode(' · ', $parts));
    }

    public function link(array $data): ?string
    {
        return self::value($data, 'url');
    }
}
