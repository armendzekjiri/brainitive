<?php

namespace App\Support\Collab;

/**
 * Name of a page's live Y document: `page.<uuid>.<epoch>` for a document (BRAI-54),
 * `whiteboard.<uuid>.<epoch>` for a whiteboard (BRAI-153). The realtime server only ever sees this
 * name; a new epoch is a new document.
 */
final class CollabDocument
{
    public const PAGE = 'page';

    public const WHITEBOARD = 'whiteboard';

    /**
     * @param  self::PAGE|self::WHITEBOARD  $kind
     */
    public static function name(string $pageUuid, int $epoch, string $kind = self::PAGE): string
    {
        return "{$kind}.{$pageUuid}.{$epoch}";
    }

    /**
     * @return array{kind: self::PAGE|self::WHITEBOARD, uuid: string, epoch: int}|null
     */
    public static function parse(string $name): ?array
    {
        if (! preg_match('/^(page|whiteboard)\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.(\d{1,9})$/', $name, $match)) {
            return null;
        }

        return ['kind' => $match[1], 'uuid' => $match[2], 'epoch' => (int) $match[3]];
    }
}
