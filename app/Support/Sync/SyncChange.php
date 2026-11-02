<?php

namespace App\Support\Sync;

use Carbon\CarbonInterface;

/**
 * One entry of the App API's change feed (BRAI-425): something in the client's offline copy to add /
 * replace (`upsert`, with its record) or to drop (`delete`). `$record` is what the type's resource
 * renders (a model, or My Day's {date, items, carry_over_count}); `$context` extra input for it (a
 * capture's current location).
 */
final class SyncChange
{
    public const UPSERT = 'upsert';

    public const DELETE = 'delete';

    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $op,
        public readonly ?int $projectId = null,
        public readonly ?CarbonInterface $updatedAt = null,
        public readonly mixed $record = null,
        public readonly mixed $context = null,
    ) {}
}
