<?php

namespace App\Support\ApiKeys;

/**
 * The "what" of a personal API key (Developer API, v1.52.0): areas of the App API, each None / Read /
 * Read + write, derived from the /api/v1 route groups (ApiKeyRoutes maps every route to one). Deleting is a
 * separate flag on the key (`can_delete`), off by default.
 */
final class ApiKeyAreas
{
    public const NONE = 'none';

    public const READ = 'read';

    public const WRITE = 'write';

    /** @var list<string> */
    public const LEVELS = [self::NONE, self::READ, self::WRITE];

    /**
     * area => [label, description, read only?]. The order is the order of the create form.
     *
     * @var array<string, array{label: string, description: string, read_only: bool}>
     */
    public const AREAS = [
        'issues' => ['label' => 'Issues & boards', 'description' => 'Boards, issues, comments, attachments, links, sprints, backlog, project health', 'read_only' => false],
        'docs' => ['label' => 'Docs & whiteboards', 'description' => 'Docs, reviews, versions, whiteboards, polls in docs', 'read_only' => false],
        'plans' => ['label' => 'Plans & releases', 'description' => 'Gantt plans and releases', 'read_only' => false],
        'work' => ['label' => 'My Work, tasks & captures', 'description' => 'My Work, My Day, task lists and to-dos, your capture inbox', 'read_only' => false],
        'time' => ['label' => 'Time tracking', 'description' => 'Your timer, work logs and timesheet', 'read_only' => false],
        'calendar' => ['label' => 'Calendar & bookings', 'description' => 'Calendar events, meetings, bookings', 'read_only' => false],
        'helpdesk' => ['label' => 'Helpdesk, leads & feedback', 'description' => 'Tickets, leads, feedback and client portals', 'read_only' => false],
        'library' => ['label' => 'Snippets & bookmarks', 'description' => 'Snippet and bookmark collections (whole workspaces only)', 'read_only' => false],
        'members' => ['label' => 'Members', 'description' => 'Workspace and project members', 'read_only' => true],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::AREAS);
    }

    /**
     * The areas whose content search finds (issues, docs, plans, tasks + captures, tickets, snippets + bookmarks).
     *
     * @return list<string>
     */
    public static function contentAreas(): array
    {
        return ['issues', 'docs', 'plans', 'work', 'helpdesk', 'library'];
    }

    public static function label(string $area): string
    {
        return self::AREAS[$area]['label'] ?? $area;
    }

    /**
     * Whitelisted abilities from user input: every known area, unknown keys dropped, read-only areas capped at
     * read.
     *
     * @param  array<mixed>  $input
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        $abilities = [];
        foreach (self::AREAS as $area => $meta) {
            $level = is_string($input[$area] ?? null) && in_array($input[$area], self::LEVELS, true) ? $input[$area] : self::NONE;
            if ($meta['read_only'] && $level === self::WRITE) {
                $level = self::READ;
            }
            $abilities[$area] = $level;
        }

        return $abilities;
    }

    /** Does `$have` cover `$need` (none < read < write)? */
    public static function covers(string $have, string $need): bool
    {
        return array_search($have, self::LEVELS, true) >= array_search($need, self::LEVELS, true);
    }

    /** "read" / "write" for messages: "This key can't write issues and boards". */
    public static function verb(string $level): string
    {
        return $level === self::WRITE ? 'write' : 'read';
    }
}
