<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ItemPriority extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'slug',
        'icon',
        'color',
        'order',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (ItemPriority $priority) {
            if (empty($priority->slug)) {
                $priority->slug = Str::slug($priority->name);
            }
        });
    }

    /**
     * Get the project this priority belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get all items with this priority.
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'priority_id');
    }

    /**
     * Scope to get default priorities (global).
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('project_id');
    }

    /**
     * Scope to get project-specific priorities.
     */
    public function scopeForProject($query, Project $project)
    {
        return $query->where(function ($q) use ($project) {
            $q->whereNull('project_id')
                ->orWhere('project_id', $project->id);
        });
    }
}
