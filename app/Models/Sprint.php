<?php

namespace App\Models;

use App\Support\Boards\SprintReport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A Scrum sprint on a board: planned → active (one per board) → completed.
 */
class Sprint extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    /** Default sprint length when started without an end date. */
    public const DEFAULT_LENGTH_DAYS = 14;

    protected $fillable = [
        'board_id',
        'name',
        'goal',
        'status',
        'start_date',
        'end_date',
        'started_at',
        'completed_at',
        'position',
        'committed_points',
        'committed_issues',
        'completed_points',
        'completed_issues',
        'burndown',
        'created_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_PLANNED,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'burndown' => 'array',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /**
     * Issues in the sprint (subtasks follow their parent and are included).
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function isPlanned(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Start the sprint and snapshot what the team committed to.
     */
    public function start(?string $startDate = null, ?string $endDate = null, ?string $goal = null): void
    {
        $start = $startDate ? now()->parse($startDate) : now();
        $end = $endDate ? now()->parse($endDate) : $start->copy()->addDays(self::DEFAULT_LENGTH_DAYS);
        $scope = SprintReport::scope($this);

        $this->update([
            'status' => self::STATUS_ACTIVE,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'started_at' => now(),
            'goal' => $goal ?? $this->goal,
            'committed_points' => $scope->sum('story_points'),
            'committed_issues' => $scope->count(),
        ]);
    }

    /**
     * Close the sprint: record what got done, store the final burndown and move unfinished
     * issues (with their subtasks) to another sprint or back to the backlog.
     */
    public function complete(?Sprint $moveOpenTo = null): int
    {
        return DB::transaction(function () use ($moveOpenTo) {
            $done = SprintReport::scope($this)->filter(fn (Item $item) => $item->completed_at !== null);
            $open = $this->items()->whereNull('parent_id')->whereNull('completed_at')->get();

            $this->update([
                'status' => self::STATUS_COMPLETED,
                'completed_at' => now(),
                'completed_points' => $done->sum('story_points'),
                'completed_issues' => $done->count(),
                'burndown' => SprintReport::burndown($this),
            ]);

            foreach ($open as $item) {
                $item->moveToSprint($moveOpenTo);
            }

            return $open->count();
        });
    }
}
