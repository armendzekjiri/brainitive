<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'parent_id',
        'created_by',
        'name',
        'slug',
        'uuid',
        'icon',
        'type',
        'content',
        'position',
        'status',
        'published_at',
        'last_edited_by',
    ];

    protected $casts = [
        'content' => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($page) {
            // Generate UUID
            if (empty($page->uuid)) {
                $page->uuid = Str::uuid()->toString();
            }

            if (empty($page->slug)) {
                $page->slug = Str::slug($page->name);
            }

            // Make slug unique within the same parent
            $originalSlug = $page->slug;
            $count = 1;
            while (static::where('project_id', $page->project_id)
                ->where('parent_id', $page->parent_id)
                ->where('slug', $page->slug)
                ->exists()) {
                $page->slug = $originalSlug . '-' . $count++;
            }

            // Set position to last
            if ($page->position === 0) {
                $maxPosition = static::where('project_id', $page->project_id)
                    ->where('parent_id', $page->parent_id)
                    ->max('position') ?? -1;
                $page->position = $maxPosition + 1;
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id')->orderBy('position');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id')
            ->where('type', 'page')
            ->orderBy('position');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Page::class, 'parent_id')
            ->where('type', 'folder')
            ->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function isFolder(): bool
    {
        return $this->type === 'folder';
    }

    public function isPage(): bool
    {
        return $this->type === 'page';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function publish(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    // Get all descendants recursively
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    // Get breadcrumb path
    public function getPathAttribute(): array
    {
        $path = [];
        $current = $this;

        while ($current) {
            array_unshift($path, [
                'id' => $current->id,
                'uuid' => $current->uuid,
                'name' => $current->name,
                'slug' => $current->slug,
                'type' => $current->type,
            ]);
            $current = $current->parent;
        }

        return $path;
    }
}
