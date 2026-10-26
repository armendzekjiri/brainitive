<?php

namespace App\Support\Collab;

/**
 * Name of a page's live Y document (BRAI-54): `page.<uuid>.<epoch>`. The realtime server only ever
 * sees this name; a new epoch is a new document.
 */
final class CollabDocument
{
    public static function name(string $pageUuid, int $epoch): string
    {
        return "page.{$pageUuid}.{$epoch}";
    }

    /**
     * @return array{uuid: string, epoch: int}|null
     */
    public static function parse(string $name): ?array
    {
        if (! preg_match('/^page\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.(\d{1,9})$/', $name, $match)) {
            return null;
        }

        return ['uuid' => $match[1], 'epoch' => (int) $match[2]];
    }
}
