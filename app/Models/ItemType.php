<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ItemType extends Model
{
    protected $appends = ['icon_display'];

    protected $fillable = [
        'project_id',
        'name',
        'slug',
        'icon',
        'color',
        'description',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ItemType $type) {
            if (empty($type->slug)) {
                $type->slug = Str::slug($type->name);
            }
        });
    }

    /**
     * For frontend: emoji as-is, or full URL when icon is a storage path.
     */
    public function getIconDisplayAttribute(): ?string
    {
        $icon = $this->attributes['icon'] ?? null;
        if (! $icon) {
            return null;
        }
        if (str_contains($icon, '/') || strlen($icon) > 50) {
            return asset('storage/'.$icon);
        }

        return $icon;
    }

    /**
     * Get the project this type belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get all items with this type.
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Scope to get default types (global).
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('project_id');
    }

    /**
     * Scope to get types available for a project: active global types + all project-specific types.
     */
    public function scopeForProject($query, Project $project)
    {
        $disabled = $project->disabledItemTypeIds();

        return $query->where(function ($q) use ($project, $disabled) {
            $q->where(function ($q2) use ($disabled) {
                $q2->whereNull('project_id')->where('is_active', true)->whereNotIn('id', $disabled);
            })->orWhere('project_id', $project->id);
        });
    }

    /**
     * Scope for project settings: all global types (active and inactive) + project-specific types.
     */
    public function scopeForProjectSettings($query, Project $project)
    {
        return $query->where(function ($q) use ($project) {
            $q->whereNull('project_id')->orWhere('project_id', $project->id);
        });
    }
}
