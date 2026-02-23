<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Bookmark extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'uuid',
        'name',
        'slug',
        'description',
        'icon',
        'visibility',
        'position',
        'created_by',
        'settings',
    ];

    protected $appends = ['icon_display'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** For frontend: emoji or full URL when icon is a stored image path. */
    public function getIconDisplayAttribute(): string
    {
        $icon = $this->attributes['icon'] ?? null;
        if (! $icon) {
            return '';
        }
        if (str_contains($icon, '/') || strlen($icon) > 30) {
            return asset('storage/'.$icon);
        }
        return $icon;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Bookmark $bookmark) {
            if (empty($bookmark->slug)) {
                $bookmark->slug = Str::slug($bookmark->name);
            }
            if (empty($bookmark->uuid)) {
                $bookmark->uuid = (string) Str::uuid();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Users invited to this bookmark (same workspace) with viewer or editor role. */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'bookmark_collaborators')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookmarkItem::class)->orderBy('position');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isEditableBy(User $user): bool
    {
        if ($user->id === $this->created_by) {
            return true;
        }
        $pivot = $this->collaborators()->where('user_id', $user->id)->first()?->pivot;
        return $pivot && $pivot->role === 'editor';
    }

    public function isViewableBy(User $user): bool
    {
        if ($user->id === $this->created_by) {
            return true;
        }
        if ($this->visibility === 'public') {
            return true;
        }
        return $this->collaborators()->where('user_id', $user->id)->exists();
    }
}
