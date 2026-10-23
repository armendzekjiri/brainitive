<?php

namespace App\Support\Github;

/**
 * Finds a project's issue keys in commit messages, branch names and PR titles:
 * "fix(BRAI-123): …", "feat/brai-2423_fix_column", "Merge pull request #4 from x/BRAI-7".
 * Case-insensitive, never inside a longer word or number ("XBRAI-1", "BRAI-12" in "BRAI-123").
 */
class IssueKeys
{
    public const MAX_PER_TEXT = 10;

    /**
     * @return list<string> normalised keys, e.g. ["BRAI-123"]
     */
    public static function find(?string $text, string $projectKey): array
    {
        if ($text === null || $text === '' || $projectKey === '') {
            return [];
        }

        preg_match_all('/(?<![A-Za-z0-9])'.preg_quote($projectKey, '/').'-(\d{1,9})(?!\d)/i', $text, $matches);

        $keys = [];
        foreach ($matches[1] as $number) {
            $keys[strtoupper($projectKey).'-'.(int) $number] = true;
        }

        return array_slice(array_keys($keys), 0, self::MAX_PER_TEXT);
    }
}
