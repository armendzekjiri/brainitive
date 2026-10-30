<?php

namespace App\Models;

use App\Support\Live\BoardLive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BoardColumn extends Model
{
    protected $fillable = [
        'board_id',
        'name',
        'slug',
        'color',
        'position',
        'wip_limit',
        'is_done_column',
    ];

    protected function casts(): array
    {
        return [
            'is_done_column' => 'boolean',
            'wip_limit' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (BoardColumn $column) {
            if (empty($column->slug)) {
                $column->slug = Str::slug($column->name);
            }
        });

        // Turning the done flag on or off completes / reopens the column's cards (BRAI-346): a card
        // in a done column always has a completed_at, so done retention can age it out.
        static::updated(function (BoardColumn $column) {
            if (! $column->wasChanged('is_done_column')) {
                return;
            }
            $ids = Item::where('column_id', $column->id)
                ->when($column->is_done_column, fn ($q) => $q->whereNull('completed_at'), fn ($q) => $q->whereNotNull('completed_at'))
                ->pluck('id')->all();
            if ($ids === []) {
                return;
            }
            Item::whereIn('id', $ids)->update(['completed_at' => $column->is_done_column ? now() : null]);
            BoardLive::itemIds($ids, ['completed_at'], (int) $column->board_id);
        });
    }

    /**
     * Get the board this column belongs to.
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * Get all items in this column.
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'column_id')->orderBy('position');
    }

    /**
     * Check if the column is at its WIP limit.
     */
    public function isAtWipLimit(): bool
    {
        if ($this->wip_limit === null) {
            return false;
        }

        return $this->items()->count() >= $this->wip_limit;
    }

    /**
     * Get the number of items in this column.
     */
    public function itemCount(): int
    {
        return $this->items()->count();
    }
}
