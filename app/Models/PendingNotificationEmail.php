<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One activity email waiting for the notification email digest (BRAI-429). Written by
 * MailDigest::capture(), claimed + deleted by SendNotificationDigest. Rows that are still here after
 * 7 days (a digest that failed every retry and no later digest picked up) are pruned.
 */
class PendingNotificationEmail extends Model
{
    use MassPrunable;

    protected $fillable = ['user_id', 'workspace_id', 'type', 'subject_type', 'subject_id', 'dedupe_key', 'entry', 'due_at', 'claim_token', 'claimed_at'];

    protected function casts(): array
    {
        return [
            'entry' => 'array',
            'due_at' => 'datetime',
            'claimed_at' => 'datetime',
            'subject_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(7));
    }
}
