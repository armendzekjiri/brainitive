<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A portal identity follows a feedback post: mailed once per public status change (BRAI-60). */
class FeedbackSubscription extends Model
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
