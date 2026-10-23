<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A read-only public link to exactly one doc (Page) or issue (Item). Anyone with the link
 * sees that item only — never comments, people, other content or drafts.
 */
class ShareLink extends Model
{
    public const TYPES = ['page' => Page::class, 'item' => Item::class];

    protected $fillable = [
        'token',
        'shareable_type',
        'shareable_id',
        'created_by',
        'expires_at',
        'revoked_at',
        'view_count',
        'last_viewed_at',
    ];

    protected $hidden = ['shareable_type', 'shareable_id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShareLink $link) {
            $link->token ??= Str::random(40);
        });
    }

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Not revoked and not expired. */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function url(): string
    {
        return route('share.show', $this->token);
    }
}
