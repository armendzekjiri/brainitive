<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One vote of one portal identity on one feedback post (unique pair; BRAI-60). Write through FeedbackVotes. */
class FeedbackVote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['feedback_post_id', 'portal_identity_id'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedbackPost::class, 'feedback_post_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(PortalIdentity::class, 'portal_identity_id');
    }
}
