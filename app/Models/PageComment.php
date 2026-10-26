<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A review comment on a page (BRAI-80). A root comment opens a thread, optionally anchored to a
 * text quote of the review snapshot (quote + a few characters of prefix / suffix — the page HTML is
 * never changed); replies have parent_id. Threads are resolved / reopened on the root.
 */
class PageComment extends Model
{
    protected $fillable = [
        'uuid',
        'page_id',
        'page_review_id',
        'parent_id',
        'user_id',
        'user_name',
        'body',
        'quote',
        'quote_prefix',
        'quote_suffix',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (PageComment $comment) {
            $comment->uuid ??= (string) Str::uuid();
        });
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PageReview::class, 'page_review_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(PageComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(PageComment::class, 'parent_id')->orderBy('id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
