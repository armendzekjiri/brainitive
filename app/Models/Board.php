<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Board extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id',
        'uuid',
        'name',
        'slug',
        'description',
        'type',
        'position',
        'created_by',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Board $board) {
            if (empty($board->slug)) {
                $board->slug = Str::slug($board->name);
            }
            if (empty($board->uuid)) {
                $board->uuid = (string) Str::uuid();
            }
        });

        // Create default columns when a board is created
        static::created(function (Board $board) {
            $defaultColumns = [
                ['name' => 'To Do', 'slug' => 'to-do', 'position' => 0, 'color' => '#6b7280'],
                ['name' => 'In Progress', 'slug' => 'in-progress', 'position' => 1, 'color' => '#3b82f6'],
                ['name' => 'Done', 'slug' => 'done', 'position' => 2, 'color' => '#22c55e', 'is_done_column' => true],
            ];

            foreach ($defaultColumns as $column) {
                $board->columns()->create($column);
            }
        });
    }

    /**
     * Get the project this board belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the user who created this board.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all columns in this board.
     */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position');
    }

    /**
     * Get all items in this board.
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Get items grouped by column.
     */
    public function itemsByColumn(): array
    {
        $columns = $this->columns()->with(['items' => function ($query) {
            $query->orderBy('position');
        }])->get();

        return $columns->mapWithKeys(function ($column) {
            return [$column->id => $column->items];
        })->toArray();
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
