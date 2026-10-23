<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A custom field for board issues (BRAI-77). Defined per project; `board_ids` limits it to
 * some boards (null = all). Archived fields keep their values but leave boards and issues.
 */
class ItemField extends Model
{
    public const TYPES = ['text', 'number', 'date', 'checkbox', 'select'];

    /** Active fields per project. */
    public const MAX_FIELDS = 50;

    public const MAX_OPTIONS = 100;

    protected $fillable = [
        'project_id',
        'name',
        'type',
        'board_ids',
        'position',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'board_ids' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * All options, archived ones included (existing values may still point at them).
     */
    public function options(): HasMany
    {
        return $this->hasMany(ItemFieldOption::class)->orderBy('position')->orderBy('id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ItemFieldValue::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function appliesToBoard(int $boardId): bool
    {
        return $this->board_ids === null || in_array($boardId, array_map('intval', $this->board_ids), true);
    }

    /**
     * Active fields of a project that show on the given board, in settings order, with options.
     *
     * @return Collection<int, ItemField>
     */
    public static function forBoard(Board $board)
    {
        return static::where('project_id', $board->project_id)
            ->active()
            ->with('options')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (ItemField $field) => $field->appliesToBoard($board->id))
            ->values();
    }
}
