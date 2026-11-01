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
     * Scope to the types a project's issues can be given: active global types the project didn't
     * switch off + the project's own types that are switched on. Cards that already have a
     * switched-off type keep it (and show it); only pickers and validation use this scope.
     */
    public function scopeForProject($query, Project $project)
    {
        $disabled = $project->disabledItemTypeIds();

        return $query->where(function ($q) use ($project, $disabled) {
            $q->where(function ($q2) use ($disabled) {
                $q2->whereNull('project_id')->where('is_active', true)->whereNotIn('id', $disabled);
            })->orWhere(function ($q2) use ($project) {
                $q2->where('project_id', $project->id)->where('is_active', true);
            });
        });
    }

    /** Whether new / edited issues of the project may get this type. */
    public function isEnabledFor(Project $project): bool
    {
        return $this->project_id === null
            ? $this->is_active && ! in_array($this->id, $project->disabledItemTypeIds(), true)
            : $this->project_id === $project->id && $this->is_active;
    }

    /**
     * Switch the type on or off for the project. A default (global) type is shared by every
     * workspace, so it's switched off in the project's settings; a project's own type on its row.
     */
    public function setEnabledFor(Project $project, bool $enabled): void
    {
        if ($this->project_id !== null) {
            $this->update(['is_active' => $enabled]);

            return;
        }

        $disabled = collect($project->disabledItemTypeIds())->reject(fn (int $id) => $id === $this->id);
        if (! $enabled) {
            $disabled->push($this->id);
        }
        $project->update(['settings' => array_merge($project->settings ?? [], ['disabled_item_types' => $disabled->values()->all()])]);
    }

    /**
     * The type a new issue of the project gets when none is chosen: the enabled default type, else
     * the first enabled type that isn't Subtask (subtasks need a parent), else none.
     */
    public static function defaultIdFor(Project $project): ?int
    {
        $types = static::forProject($project)->orderByRaw('project_id is not null')->orderBy('id')->get(['id', 'slug', 'is_default']);

        return ($types->firstWhere('is_default', true) ?? $types->first(fn (ItemType $t) => $t->slug !== 'subtask'))?->id;
    }

    /**
     * Type ids an issue of the project may be given: the enabled types, plus `$keep` (an issue's
     * current type, so editing other fields of a card with a switched-off type still works).
     *
     * @return list<int>
     */
    public static function selectableIdsFor(Project $project, ?int $keep = null): array
    {
        $ids = static::forProject($project)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $keep !== null && ! in_array($keep, $ids, true) ? [...$ids, $keep] : $ids;
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
