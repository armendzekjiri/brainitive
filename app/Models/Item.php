<?php

namespace App\Models;

use App\Observers\ItemObserver;
use App\Support\References\ContentReferences;
use App\Support\References\SmartLinks;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[ObservedBy([ItemObserver::class])]
class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'board_id',
        'project_id',
        'column_id',
        'sprint_id',
        'parent_id',
        'item_type_id',
        'priority_id',
        'release_id',
        'uuid',
        'key',
        'title',
        'description',
        'created_by',
        'assigned_to',
        'reporter_id',
        'position',
        'backlog_position',
        'story_points',
        'original_estimate',
        'time_spent',
        'start_date',
        'start_time',
        'due_date',
        'due_time',
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

        // Smart links in the description are derived references (BRAI-143)
        static::saved(function (Item $item) {
            if ($item->wasRecentlyCreated ? (string) $item->description !== '' : $item->wasChanged('description')) {
                SmartLinks::syncItem($item);
            }
        });

        // Soft deletes keep linked content (back on restore); a hard delete prunes it (BRAI-71)
        static::forceDeleted(function (Item $item) {
            $projectId = Board::withTrashed()->whereKey($item->board_id)->value('project_id');
            if ($projectId) {
                ContentReferences::pruneMissing((int) $projectId);
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
     * Nesting limit: issue → subtask → sub-subtask (depth 0, 1, 2).
     */
    public const MAX_DEPTH = 2;

    /** 0 for a top-level issue, 1 for a subtask, 2 for a sub-subtask. */
    public function depth(): int
    {
        $depth = 0;
        $parentId = $this->parent_id;
        $seen = [$this->id];
        while ($parentId !== null && $depth <= self::MAX_DEPTH + 5) {
            $parent = static::withTrashed()->select('id', 'parent_id')->find($parentId);
            if (! $parent || in_array($parent->id, $seen, true)) {
                break;
            }
            $seen[] = $parent->id;
            $depth++;
            $parentId = $parent->parent_id;
        }

        return $depth;
    }

    /** Levels below this item (0 = no children). */
    public function subtreeHeight(): int
    {
        $height = 0;
        $level = [$this->id];
        while ($level !== [] && $height <= self::MAX_DEPTH + 5) {
            $level = static::whereIn('parent_id', $level)->pluck('id')->all();
            if ($level !== []) {
                $height++;
            }
        }

        return $height;
    }

    /**
     * All descendants' ids (children, grandchildren, …).
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $level = [$this->id];
        while ($level !== []) {
            $level = static::whereIn('parent_id', $level)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = array_merge($ids, $level);
        }

        return $ids;
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

    /** The recurring template that created this issue, through its occurrence row (BRAI-123). */
    public function recurringOccurrence(): HasOne
    {
        return $this->hasOne(RecurringIssueOccurrence::class);
    }

    /**
     * Get activity logs for this item.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'loggable_id')
            ->where('loggable_type', self::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
        if ($wasInDoneColumn && ! $isNowInDoneColumn) {
            $this->update(['completed_at' => null]);
        }
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    /**
     * Move this issue (and its subtasks) to a sprint, or to the backlog with null.
     * Lands at the end of the target list.
     */
    public function moveToSprint(?Sprint $sprint): void
    {
        $targetId = $sprint?->id;
        if ($this->sprint_id === $targetId) {
            return;
        }

        $last = static::where('board_id', $this->board_id)
            ->where('sprint_id', $targetId)
            ->whereNull('parent_id')
            ->max('backlog_position') ?? 0;

        $this->update(['sprint_id' => $targetId, 'backlog_position' => $last + 1]);

        // The whole subtree travels with its issue.
        static::whereIn('id', $this->descendantIds())->update(['sprint_id' => $targetId]);
    }

    /** Working-day / week length used by durations, as in Jira (1d = 8h, 1w = 5d). */
    public const MINUTES_PER_DAY = 8 * 60;

    public const MINUTES_PER_WEEK = 5 * self::MINUTES_PER_DAY;

    /** Validation for a logged duration ("45m", "1h 30m", "1d"; at most a week per entry). */
    public static function durationRules(): array
    {
        return ['required', 'string', 'max:50', function ($attribute, $value, $fail) {
            $minutes = self::parseDuration($value);
            if ($minutes === null || $minutes < 1 || $minutes > self::MINUTES_PER_WEEK) {
                $fail('Use a duration like 45m, 1h 30m, 2h or 1d (max. 1w per entry).');
            }
        }];
    }

    /**
     * Log worked minutes on the item, by the signed-in user: one TimeEntry (the v1.38.0 ledger,
     * whose model events add the minutes to `time_spent`) plus a `work_logged` history entry.
     * The one way to log work on an issue: "Log work", stopping a focus timer, the timesheet.
     * `$spentOn` defaults to today in the user's zone; `$billable` defaults from the activity.
     * Callers validate the activity (TimeLogActivities::resolve) and the right to log.
     */
    public function logWork(
        int $minutes,
        ?string $note = null,
        ?BillingActivity $activity = null,
        ?string $spentOn = null,
        string $source = TimeEntry::SOURCE_MANUAL,
        ?bool $billable = null,
    ): TimeEntry {
        $user = auth()->user();
        $projectId = $this->project_id ?? Board::withTrashed()->whereKey($this->board_id)->value('project_id');
        $zone = $user instanceof User ? $user->localTimezone() : config('app.timezone');

        return DB::transaction(function () use ($minutes, $note, $activity, $spentOn, $source, $billable, $user, $projectId, $zone) {
            $entry = TimeEntry::create([
                'workspace_id' => Project::withTrashed()->whereKey($projectId)->value('workspace_id'),
                'project_id' => $projectId,
                'item_id' => $this->id,
                'user_id' => $user?->getAuthIdentifier(),
                'billing_activity_id' => $activity?->id,
                'spent_on' => $spentOn ?? now($zone)->toDateString(),
                'minutes' => $minutes,
                'note' => $note,
                'billable' => $billable ?? ($activity ? (bool) $activity->billable : true),
                'source' => $source,
                'item_key' => $this->key,
                'item_title' => Str::limit((string) $this->title, 250, ''),
            ]);

            // the entry's event incremented the column with the query builder: reload the value
            $this->time_spent = (int) static::withTrashed()->whereKey($this->id)->value('time_spent');
            $this->syncOriginalAttribute('time_spent');

            $metadata = ['minutes' => $minutes, 'total_minutes' => $this->time_spent, 'note' => $note, 'time_entry_id' => $entry->id];
            if ($activity) {
                $metadata['activity'] = $activity->name; // a display value: stays readable after renames
            }
            ActivityLog::log($this, 'work_logged', null, $metadata);

            return $entry;
        });
    }

    /**
     * Parse a Jira-style duration ("1w 2d 3h 30m", "1.5h", "90") into minutes.
     * A bare number is minutes. Returns null when the input isn't a duration.
     */
    public static function parseDuration(?string $input): ?int
    {
        $input = strtolower(trim((string) $input));
        if ($input === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $input)) {
            return (int) $input;
        }
        if (! preg_match('/^(\s*\d+(?:[.,]\d+)?\s*[wdhm]\s*)+$/', $input)) {
            return null;
        }

        $unitMinutes = ['w' => self::MINUTES_PER_WEEK, 'd' => self::MINUTES_PER_DAY, 'h' => 60, 'm' => 1];
        preg_match_all('/(\d+(?:[.,]\d+)?)\s*([wdhm])/', $input, $parts, PREG_SET_ORDER);

        $minutes = 0.0;
        foreach ($parts as [, $amount, $unit]) {
            $minutes += (float) str_replace(',', '.', $amount) * $unitMinutes[$unit];
        }

        return (int) round($minutes);
    }

    /**
     * Format minutes as a Jira-style duration ("1d 2h 30m").
     */
    public static function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $parts = [];
        foreach (['w' => self::MINUTES_PER_WEEK, 'd' => self::MINUTES_PER_DAY, 'h' => 60, 'm' => 1] as $unit => $size) {
            if ($minutes >= $size) {
                $parts[] = intdiv($minutes, $size).$unit;
                $minutes %= $size;
            }
        }

        return implode(' ', $parts);
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
        if (! $this->due_date || $this->isCompleted()) {
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

    /**
     * Branches, commits, pull requests and deployments linked from GitHub.
     */
    public function devLinks(): HasMany
    {
        return $this->hasMany(ItemDevLink::class);
    }

    /**
     * Custom field values (one row per field that has a value).
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(ItemFieldValue::class);
    }

    /**
     * The fix version this issue ships in.
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }
}
