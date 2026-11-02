<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Something that left a user's offline copy without a row to show it (BRAI-425): a capture deleted
 * for good, work reassigned away from them. Read by the App API's change feed
 * (`App\Support\Sync\ChangeFeed`); soft-deleted issues / tasks / lists need no row. Kept 30 days —
 * the feed starts a full sync for older cursors.
 */
class SyncTombstone extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    public const CAPTURE = 'capture';

    public const WORK = 'work';

    public const DELETED = 'deleted';

    public const UNASSIGNED = 'unassigned';

    /** Days a tombstone is kept (= the oldest cursor the feed continues). */
    public const KEEP_DAYS = 30;

    protected $fillable = ['user_id', 'entity_type', 'entity_id', 'reason'];

    /** Record it, in the transaction of the write that caused it (a rolled-back delete leaves none). */
    public static function record(?int $userId, string $type, string $id, string $reason): void
    {
        if ($userId) {
            static::create(['user_id' => $userId, 'entity_type' => $type, 'entity_id' => $id, 'reason' => $reason]);
        }
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
