<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A review of a page draft (BRAI-80): an immutable snapshot (name + content + hash) that the chosen
 * reviewers approve or send back. Stale = the page's current draft hash differs from content_hash.
 * Lifecycle and rules: App\Support\PageReviews\PageReviews.
 */
class PageReview extends Model
{
    public const OPEN = ['pending', 'approved', 'changes_requested'];

    public const CLOSED = ['cancelled', 'superseded', 'published'];

    protected $fillable = [
        'uuid',
        'page_id',
        'requested_by',
        'requested_by_name',
        'message',
        'status',
        'snapshot_name',
        'snapshot_content',
        'content_hash',
        'edit_revision',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_content' => 'array',
            'edit_revision' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (PageReview $review) {
            $review->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewers(): HasMany
    {
        return $this->hasMany(PageReviewReviewer::class)->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PageComment::class);
    }

    /** @param  Builder<PageReview>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** The draft changed since the review was requested (the snapshot no longer matches it). */
    public function isStale(?Page $page = null): bool
    {
        $page ??= $this->page;

        return $this->content_hash !== $page->draftHash();
    }

    /** "#2": the review's position among the page's reviews (by id). */
    public function number(): int
    {
        return PageReview::where('page_id', $this->page_id)->where('id', '<=', $this->id)->count();
    }

    /** The snapshot's HTML (pages store Lexical HTML as {type: 'lexical', html}). */
    public function snapshotHtml(): string
    {
        $content = $this->snapshot_content;

        return is_array($content) && is_string($content['html'] ?? null) ? $content['html'] : '';
    }
}
