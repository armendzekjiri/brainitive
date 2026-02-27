<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'board_id',
        'column_id',
        'parent_id',
        'item_type_id',
        'priority_id',
        'uuid',
        'key',
        'title',
        'description',
        'created_by',
        'assigned_to',
        'reporter_id',
        'position',
        'story_points',
        'original_estimate',
        'time_spent',
        'start_date',
        'due_date',
        'completed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Item $item) {
            if (empty($item->uuid)) {
                $item->uuid = (string) Str::uuid();
            }
        });

        // Auto-watch: add reporter to watchers after creation
        static::created(function (Item $item) {
            if ($item->reporter_id) {
                $item->watchers()->syncWithoutDetaching([$item->reporter_id]);
            }
        });

        // Auto-watch: add assignee when item is assigned/updated
        static::updated(function (Item $item) {
            if ($item->wasChanged('assigned_to') && $item->assigned_to) {
                $item->watchers()->syncWithoutDetaching([$item->assigned_to]);
            }
        });
    }

    /**
     * Get the board this item belongs to.
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * Get the column this item is in.
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'column_id');
    }

    /**
     * Get the parent item (for subtasks).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'parent_id');
    }

    /**
     * Get child items (subtasks).
     */
    public function children(): HasMany
    {
        return $this->hasMany(Item::class, 'parent_id');
    }

    /**
     * Get the item type.
     */
    public function itemType(): BelongsTo
    {
        return $this->belongsTo(ItemType::class);
    }

    /**
     * Get the priority.
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(ItemPriority::class, 'priority_id');
    }

    /**
     * Get the user who created this item.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the assigned user.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the reporter.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * Get the users watching this item.
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'item_watchers')
            ->withPivot('created_at');
    }

    /**
     * Add a user to the watch list (idempotent).
     */
    public function addWatcher(User $user): void
    {
        $this->watchers()->syncWithoutDetaching([$user->id]);
    }

    /**
     * Remove a user from the watch list.
     */
    public function removeWatcher(User $user): void
    {
        $this->watchers()->detach($user->id);
    }

    /**
     * Get the labels for this item.
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    /**
     * Get outgoing links from this item (where this item is the source).
     */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(ItemLink::class, 'source_item_id');
    }

    /**
     * Get incoming links to this item (where this item is the target).
     */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(ItemLink::class, 'target_item_id');
    }

    /**
     * Get comments for this item.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at');
    }

    /**
     * Get attachments for this item.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Get activity logs for this item.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'loggable_id')
            ->where('loggable_type', self::class)
            ->orderByDesc('created_at');
    }

    /**
     * Move item to a different column.
     */
    public function moveToColumn(BoardColumn $column, int $position = 0): void
    {
        $wasInDoneColumn = $this->column?->is_done_column ?? false;
        $isNowInDoneColumn = $column->is_done_column;

        $this->update([
            'column_id' => $column->id,
            'position' => $position,
            'completed_at' => $isNowInDoneColumn ? now() : null,
        ]);

        // If moving out of done column, clear completed_at
        if ($wasInDoneColumn && !$isNowInDoneColumn) {
            $this->update(['completed_at' => null]);
        }
    }

    /**
     * Assign this item to a user.
     */
    public function assignTo(?User $user): void
    {
        $this->update(['assigned_to' => $user?->id]);
    }

    /**
     * Check if the item is completed.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Check if the item is overdue.
     */
    public function isOverdue(): bool
    {
        if (!$this->due_date || $this->isCompleted()) {
            return false;
        }

        return $this->due_date->isPast();
    }

    /**
     * Get the project through the board.
     */
    public function getProjectAttribute(): ?Project
    {
        return $this->board?->project;
    }

    /**
     * Get the workspace through the board and project.
     */
    public function getWorkspaceAttribute(): ?Workspace
    {
        return $this->board?->project?->workspace;
    }
}
