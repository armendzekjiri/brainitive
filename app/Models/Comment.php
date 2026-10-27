<?php

namespace App\Models;

use App\Support\Live\LiveThreads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'item_id',
        'user_id',
        'parent_id',
        'body',
    ];

    protected static function booted(): void
    {
        // Live comments (BRAI-151): open issue views refetch (ids only)
        static::created(fn (Comment $comment) => LiveThreads::comment($comment, 'created'));
        static::updated(function (Comment $comment) {
            if ($comment->wasChanged(['body', 'parent_id'])) {
                LiveThreads::comment($comment, 'updated');
            }
        });
        static::deleted(fn (Comment $comment) => LiveThreads::comment($comment, 'deleted'));
    }

    /**
     * Get the item this comment belongs to.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Get the user who wrote this comment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent comment (for nested comments).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /**
     * Get replies to this comment (recursive — loads all nesting levels).
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')
            ->with(['user:id,name,email', 'replies'])
            ->orderBy('created_at');
    }

    /**
     * Check if this is a reply to another comment.
     */
    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }
}
