<?php

namespace App\Models;

use App\Observers\TaskListItemObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[ObservedBy([TaskListItemObserver::class])]
class TaskListItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'task_list_id',
        'title',
        'description',
        'status',
        'assignee_id',
        'due_date',
        'due_time',
        'start_date',
        'start_time',
        'recurrence',
        'reminder_minutes',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'description' => 'array',
            // Plain dates on the wire, so <input type="date"> can show them.
            'due_date' => 'date:Y-m-d',
            'start_date' => 'date:Y-m-d',
            'reminder_minutes' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function taskList(): BelongsTo
    {
        return $this->belongsTo(TaskList::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** Reminder choices, in minutes before the due time (0 = at the due time). */
    public const REMINDER_OPTIONS = [0, 10, 60, 1440];

    /** Reminders go to the assignee, or to whoever created the task (a personal to-do). */
    public function reminderRecipient(): ?User
    {
        return $this->assignee ?? $this->creator;
    }

    /** Due moment in the given zone: due date + due time, or 09:00 when there is no time. */
    public function dueAt(string $timezone): ?Carbon
    {
        if (! $this->due_date) {
            return null;
        }

        $time = $this->due_time ? substr($this->due_time, 0, 5) : '09:00';

        return Carbon::parse($this->due_date->toDateString().' '.$time, $timezone);
    }

    /** When the reminder fires (absolute time), or null without a due date / reminder. */
    public function remindAt(string $timezone): ?Carbon
    {
        if ($this->reminder_minutes === null) {
            return null;
        }

        $due = $this->dueAt($timezone);

        // "1 day before" = same clock time the day before, also across a DST change.
        return $this->reminder_minutes === 1440 ? $due?->subDay() : $due?->subMinutes($this->reminder_minutes);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calculate the next due date based on recurrence rule.
     */
    public function nextDueDate(): ?Carbon
    {
        if (! $this->due_date || ! $this->recurrence) {
            return null;
        }

        $base = $this->due_date instanceof Carbon
            ? $this->due_date->copy()
            : Carbon::parse($this->due_date);

        return match ($this->recurrence) {
            'daily' => $base->addDay(),
            'weekly' => $base->addWeek(),
            // Jan 31 → Feb 28, not Mar 3 (same as the list page's date-fns addMonths).
            'monthly' => $base->addMonthNoOverflow(),
            'yearly' => $base->addYearNoOverflow(),
            default => null,
        };
    }
}
