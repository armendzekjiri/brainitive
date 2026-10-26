<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An idea on a feedback portal (v1.42.0, BRAI-60). New posts wait for moderation; only approved,
 * unmerged posts are public. Public fields (title, body, status, status_note) are separate from the
 * linked board item, which never reaches the public side (FeedbackPresenter).
 */
class FeedbackPost extends Model
{
    public const MODERATION = ['pending', 'approved', 'rejected'];

    public const STATUSES = ['open', 'planned', 'building', 'released', 'declined'];

    public const STATUS_LABELS = [
        'open' => 'Open',
        'planned' => 'Planned',
        'building' => 'Building',
        'released' => 'Released',
        'declined' => 'Declined',
    ];

    /** Roadmap columns, in order. */
    public const ROADMAP = ['planned', 'building', 'released'];

    protected $fillable = [
        'feedback_portal_id', 'number', 'title', 'body', 'author_identity_id', 'moderation', 'status',
        'status_note', 'status_changed_at', 'votes_count', 'merged_into_id', 'item_id', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
            'approved_at' => 'datetime',
            'votes_count' => 'integer',
            'number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FeedbackPost $post) {
            $post->uuid ??= (string) Str::uuid();
        });
    }

    public function portal(): BelongsTo
    {
        return $this->belongsTo(FeedbackPortal::class, 'feedback_portal_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(PortalIdentity::class, 'author_identity_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(FeedbackVote::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(FeedbackSubscription::class);
    }

    /** Approved and not merged: what anonymous readers see. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('moderation', 'approved')->whereNull('merged_into_id');
    }

    public function isPublished(): bool
    {
        return $this->moderation === 'approved' && $this->merged_into_id === null;
    }

    /** The post a merged one points to (follows chains; bounded). */
    public function mergeTarget(): ?self
    {
        $post = $this;
        for ($hops = 0; $post->merged_into_id !== null && $hops < 10; $hops++) {
            $next = self::find($post->merged_into_id);
            if (! $next) {
                return null;
            }
            $post = $next;
        }

        return $post === $this ? null : $post;
    }

    public function publicUrl(?FeedbackPortal $portal = null): string
    {
        $portal ??= $this->portal;

        return route('feedback.post', [$portal->slug, $this->number]);
    }
}
