<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The `notifications` table as the inbox sees it (BRAI-102): adds the archive state and pruning.
 * Archived rows go after 90 days, every row after 180 days (`model:prune`, routes/console.php).
 */
class InboxNotification extends DatabaseNotification
{
    use MassPrunable;

    public const ARCHIVED_RETENTION_DAYS = 90;

    public const RETENTION_DAYS = 180;

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function prunable(): Builder
    {
        return static::query()
            ->where(fn (Builder $q) => $q
                ->where('archived_at', '<', now()->subDays(self::ARCHIVED_RETENTION_DAYS))
                ->orWhere('created_at', '<', now()->subDays(self::RETENTION_DAYS)));
    }
}
