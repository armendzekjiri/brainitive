<?php

namespace App\Support\Search;

/**
 * The content types of the search index (BRAI-55) and the groups people filter by.
 *
 * A type is one source table (`search_entries.type` + `source_id`); a group is what the search
 * page shows as a tab / filter chip — "Files" are issue and ticket attachments together.
 */
final class SearchTypes
{
    public const ITEM = 'item';

    public const COMMENT = 'comment';

    public const PAGE = 'page';

    public const TICKET = 'ticket';

    public const PLAN = 'plan';

    public const TASK = 'task';

    public const SNIPPET = 'snippet';

    public const BOOKMARK = 'bookmark';

    public const CAPTURE = 'capture';

    public const ATTACHMENT = 'attachment';

    public const TICKET_ATTACHMENT = 'ticket_attachment';

    /** Content of a project: visible to people who can open the project. */
    public const PROJECT_TYPES = [self::ITEM, self::COMMENT, self::PAGE, self::TICKET, self::PLAN, self::TASK, self::ATTACHMENT, self::TICKET_ATTACHMENT];

    /** Group key => [label, types], in the order the search page shows them. */
    public const GROUPS = [
        'issues' => ['Issues', [self::ITEM]],
        'docs' => ['Docs', [self::PAGE]],
        'comments' => ['Comments', [self::COMMENT]],
        'tickets' => ['Tickets', [self::TICKET]],
        'tasks' => ['Tasks', [self::TASK]],
        'plans' => ['Plans', [self::PLAN]],
        'snippets' => ['Snippets', [self::SNIPPET]],
        'bookmarks' => ['Bookmarks', [self::BOOKMARK]],
        'captures' => ['Captures', [self::CAPTURE]],
        'files' => ['Files', [self::ATTACHMENT, self::TICKET_ATTACHMENT]],
    ];

    /** Types with an open / done status, an assignee or an issue type (filters apply to them). */
    public const STATUS_TYPES = [self::ITEM, self::TICKET, self::TASK, self::CAPTURE];

    public const ASSIGNEE_TYPES = [self::ITEM, self::TICKET, self::TASK];

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_merge(...array_map(fn (array $group) => $group[1], array_values(self::GROUPS))));
    }

    public static function groupOf(string $type): string
    {
        foreach (self::GROUPS as $key => [, $types]) {
            if (in_array($type, $types, true)) {
                return $key;
            }
        }

        throw new \InvalidArgumentException("Unknown search type {$type}");
    }

    /**
     * @param  list<string>  $groups
     * @return list<string>
     */
    public static function typesOf(array $groups): array
    {
        $types = [];
        foreach ($groups as $group) {
            array_push($types, ...(self::GROUPS[$group][1] ?? []));
        }

        return array_values(array_unique($types));
    }
}
