<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A planned or shipped version of a project ("fix version" on issues), with private
 * release notes and an optional published snapshot.
 */
class Release extends Model
{
    use HasFactory;

    public const STATUS_UNRELEASED = 'unreleased';

    public const STATUS_RELEASED = 'released';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'project_id', 'name', 'description', 'status', 'start_date', 'release_date', 'released_at',
        'notes', 'published_notes', 'published_at', 'public_token', 'created_by',
    ];

    protected $hidden = ['public_token', 'published_notes'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'release_date' => 'date',
            'released_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function isUnreleased(): bool
    {
        return $this->status === self::STATUS_UNRELEASED;
    }

    public function isOverdue(): bool
    {
        return $this->isUnreleased() && $this->release_date !== null && $this->release_date->lt(today());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->public_token !== null;
    }

    /**
     * done = completed; to do = still in its board's first column; anything else is in progress.
     *
     * @return array{total: int, done: int, in_progress: int, todo: int}
     */
    public function progress(): array
    {
        $items = $this->items()->with('column:id,board_id,position')->get(['id', 'column_id', 'completed_at']);
        $firstColumns = BoardColumn::whereIn('board_id', $items->pluck('column.board_id')->filter()->unique())
            ->orderBy('position')->get(['id', 'board_id'])
            ->unique('board_id')->pluck('id', 'board_id');

        $done = $items->whereNotNull('completed_at')->count();
        $todo = $items->whereNull('completed_at')
            ->filter(fn (Item $i) => $i->column && $firstColumns->get($i->column->board_id) === $i->column_id)
            ->count();

        return ['total' => $items->count(), 'done' => $done, 'in_progress' => $items->count() - $done - $todo, 'todo' => $todo];
    }
}
