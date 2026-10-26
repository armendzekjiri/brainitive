<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Moderator audit trail of a feedback portal (BRAI-60): who did what, when. Display values (names,
 * titles, keys) are stored, so the log stays readable after renames and deletes.
 */
class FeedbackEvent extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'portal_updated' => 'updated the portal settings',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'status_changed' => 'changed the status of',
        'merged' => 'merged',
        'card_created' => 'created a card for',
        'card_linked' => 'linked a card to',
        'card_unlinked' => 'unlinked the card from',
    ];

    protected $fillable = ['feedback_portal_id', 'feedback_post_id', 'user_id', 'actor_name', 'action', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedbackPost::class, 'feedback_post_id');
    }

    public static function record(FeedbackPortal $portal, ?FeedbackPost $post, User $actor, string $action, array $changes = []): self
    {
        return self::create([
            'feedback_portal_id' => $portal->id,
            'feedback_post_id' => $post?->id,
            'user_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => $action,
            'changes' => $changes ?: null,
        ]);
    }
}
