<?php

namespace App\Models;

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
