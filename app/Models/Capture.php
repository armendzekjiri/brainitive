<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One idea in a user's private capture inbox (BRAI-70). Captured without a project, organized
 * later into an issue, list task, doc, bookmark or snippet (`CaptureConversion`). Only its
 * owner can see or change it; owning it grants nothing on any destination.
 *
 * State: inbox (neither processed nor archived) → processed (has a target) ; archived is
 * orthogonal (archive / restore only toggles `archived_at`).
 */
class Capture extends Model
{
    use HasFactory;

    /** Destination kinds, also the values of `intended_type` and `target_type`. */
    public const TYPES = ['issue', 'task', 'page', 'bookmark', 'snippet'];

    public const STATES = ['inbox', 'processed', 'archived'];

    public const MAX_TEXT = 20000;

    protected $fillable = [
        'uuid', 'user_id', 'text', 'url', 'intended_type', 'source', 'captured_at',
        'archived_at', 'processed_at', 'target_type', 'target_id', 'target_location',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'archived_at' => 'datetime',
            'processed_at' => 'datetime',
            'target_id' => 'integer',
            'target_location' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (Capture $capture) {
            $capture->uuid ??= (string) Str::uuid();
            $capture->captured_at ??= now();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Validation for what a person types into a capture (app dialog, edit, extension).
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'text' => 'required|string|max:'.self::MAX_TEXT,
            'url' => 'nullable|url:http,https|max:2048',
            'intended_type' => ['nullable', Rule::in(self::TYPES)],
            'source' => 'nullable|string|max:255',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function state(): string
    {
        return match (true) {
            $this->archived_at !== null => 'archived',
            $this->processed_at !== null => 'processed',
            default => 'inbox',
        };
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeInState(Builder $query, string $state): Builder
    {
        return match ($state) {
            'archived' => $query->whereNotNull('archived_at'),
            'processed' => $query->whereNull('archived_at')->whereNotNull('processed_at'),
            default => $query->whereNull('archived_at')->whereNull('processed_at'),
        };
    }
}
