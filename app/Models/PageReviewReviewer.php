<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reviewer of a page review and their decision (BRAI-80). `user_name` stays on the history
 * when the account is deleted (user_id becomes null and the reviewer drops out of the status).
 */
class PageReviewReviewer extends Model
{
    public const DECISIONS = ['approved', 'changes_requested'];

    protected $fillable = [
        'page_review_id',
        'user_id',
        'user_name',
        'decision',
        'comment',
        'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PageReview::class, 'page_review_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
