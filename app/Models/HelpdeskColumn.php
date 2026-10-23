<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class HelpdeskColumn extends Model
{
    use HasFactory;

    protected $fillable = [
        'helpdesk_id',
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

        static::creating(function (HelpdeskColumn $column) {
            if (empty($column->slug)) {
                $column->slug = Str::slug($column->name);
            }
        });
    }

    /**
     * Get the helpdesk this column belongs to.
     */
    public function helpdesk(): BelongsTo
    {
        return $this->belongsTo(Helpdesk::class);
    }

    /**
     * Get all tickets in this column.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'column_id')->orderBy('position');
    }

    /**
     * Check if the column is at its WIP limit.
     */
    public function isAtWipLimit(): bool
    {
        if ($this->wip_limit === null) {
            return false;
        }

        return $this->tickets()->count() >= $this->wip_limit;
    }

    /**
     * Get the number of tickets in this column.
     */
    public function ticketCount(): int
    {
        return $this->tickets()->count();
    }
}
