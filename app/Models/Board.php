<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Board extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Done column retention (BRAI-346, like Jira's "hide completed issues older than…"): how many
     * days a finished card stays on a Kanban board. 0 = Off (show all).
     */
    public const DONE_RETENTION_OPTIONS = [0, 7, 14, 30, 60, 90];

    /** New boards start like Jira (2 weeks); boards without the setting (all existing ones) are Off. */
    public const DEFAULT_DONE_RETENTION_DAYS = 14;

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
            // A caller that brings the setting (template, archive restore) keeps its value
            $settings = $board->settings ?? [];
            if (! array_key_exists('done_retention_days', $settings)) {
                $board->settings = [...$settings, 'done_retention_days' => self::DEFAULT_DONE_RETENTION_DAYS];
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

    /** Saved views (BRAI-78) — use BoardView::visibleTo() for what a user may see. */
    public function views(): HasMany
    {
        return $this->hasMany(BoardView::class)->orderBy('position')->orderBy('id');
    }

    /** Route-binding relation for {boardView} under {board} (scoped bindings). */
    public function boardViews(): HasMany
    {
        return $this->views();
    }

    /**
     * Get all items in this board.
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /** Recurring issue templates on this board (BRAI-123). */
    public function recurringIssues(): HasMany
    {
        return $this->hasMany(RecurringIssue::class);
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

    /**
     * Sprints of this board (Scrum boards only use them), in planning order.
     */
    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The running sprint, if any (there is at most one per board).
     */
    public function activeSprint(): HasOne
    {
        return $this->hasOne(Sprint::class)->where('status', Sprint::STATUS_ACTIVE);
    }

    public function isScrum(): bool
    {
        return $this->type === 'scrum';
    }

    /** The stored done retention (0 = Off); boards from before the setting existed read as Off. */
    public function doneRetentionSetting(): int
    {
        $days = (int) ($this->settings['done_retention_days'] ?? 0);

        return in_array($days, self::DONE_RETENTION_OPTIONS, true) ? $days : 0;
    }

    /** Days a done card stays on the board, 0 = all of them. Kanban only: Scrum shows the running sprint. */
    public function doneRetentionDays(): int
    {
        return $this->isScrum() ? 0 : $this->doneRetentionSetting();
    }

    /** Done cards completed before this leave the board (null = none do). */
    public function doneCutoff(): ?Carbon
    {
        $days = $this->doneRetentionDays();

        return $days > 0 ? now()->subDays($days) : null;
    }
}
