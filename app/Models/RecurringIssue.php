<?php

namespace App\Models;

use App\Support\RecurringIssues\RecurrenceSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring issue template on a board (v1.40.0, BRAI-123): the issue to create plus its schedule
 * (RecurrenceSchedule). `app:create-recurring-issues` (RecurringIssueRunner) creates the due
 * occurrences as the template's creator. Dates are calendar dates in the template's `timezone`.
 *
 * State: `active_from` (the local day it was created / its schedule changed / it was resumed: nothing
 * earlier is ever created), `last_occurrence_date` + `occurrences_count` (what was created),
 * `next_occurrence_date` (cached for the scheduler query and the list; null = finished).
 */
class RecurringIssue extends Model
{
    /** The schedule columns: a change resets `active_from`. */
    public const SCHEDULE_FIELDS = [
        'frequency', 'interval', 'weekdays', 'monthly_mode', 'month_day', 'month_week', 'month_weekday',
        'start_date', 'end_date', 'max_occurrences',
    ];

    protected $fillable = [
        'board_id', 'created_by', 'title', 'description', 'item_type_id', 'priority_id', 'assigned_to',
        'label_ids', 'column_id', 'due_in_days', 'frequency', 'interval', 'weekdays', 'monthly_mode',
        'month_day', 'month_week', 'month_weekday', 'start_date', 'end_date', 'max_occurrences',
        'timezone', 'active_from', 'paused_at', 'occurrences_count', 'last_occurrence_date',
        'next_occurrence_date', 'last_error',
    ];

    protected $appends = ['badge_label'];

    protected function casts(): array
    {
        return [
            'label_ids' => 'array',
            'weekdays' => 'array',
            'interval' => 'integer',
            'month_day' => 'integer',
            'month_week' => 'integer',
            'month_weekday' => 'integer',
            'due_in_days' => 'integer',
            'max_occurrences' => 'integer',
            'occurrences_count' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'active_from' => 'date:Y-m-d',
            'last_occurrence_date' => 'date:Y-m-d',
            'next_occurrence_date' => 'date:Y-m-d',
            'paused_at' => 'datetime',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(RecurringIssueOccurrence::class);
    }

    /** "Repeats weekly" — needs only `frequency` and `interval` (what the board cards load). */
    public function getBadgeLabelAttribute(): ?string
    {
        $frequency = $this->attributes['frequency'] ?? null;

        return $frequency ? RecurrenceSchedule::badge($frequency, (int) ($this->attributes['interval'] ?? 1)) : null;
    }

    public function schedule(): RecurrenceSchedule
    {
        return RecurrenceSchedule::fromArray([
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'weekdays' => $this->weekdays,
            'monthly_mode' => $this->monthly_mode,
            'month_day' => $this->month_day,
            'month_week' => $this->month_week,
            'month_weekday' => $this->month_weekday,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ]);
    }

    /** Today's date in the template's time zone (as a midnight-UTC calendar date). */
    public function localToday(?CarbonInterface $now = null): CarbonImmutable
    {
        $zone = in_array($this->timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $this->timezone : config('app.timezone');

        return RecurrenceSchedule::date(($now ?? now())->copy()->setTimezone($zone)->format('Y-m-d'));
    }

    public function isFinished(): bool
    {
        return $this->max_occurrences !== null && $this->occurrences_count >= $this->max_occurrences;
    }

    /** The first date the next run may create: after the last one, not before `active_from`. */
    public function earliestNext(): CarbonImmutable
    {
        $from = RecurrenceSchedule::date($this->active_from);
        if ($this->last_occurrence_date) {
            $from = $from->max(RecurrenceSchedule::date($this->last_occurrence_date)->addDay());
        }

        return $from;
    }

    /** Recompute the cached `next_occurrence_date` (not saved). */
    public function refreshNextOccurrence(): static
    {
        $this->next_occurrence_date = $this->isFinished() ? null : $this->schedule()->firstOnOrAfter($this->earliestNext())?->toDateString();

        return $this;
    }

    /**
     * The next $count dates the scheduler will create (respecting the end date and the remaining count).
     *
     * @return list<string>
     */
    public function upcomingDates(int $count = 5, ?CarbonInterface $now = null): array
    {
        $remaining = $this->max_occurrences === null ? $count : max(0, $this->max_occurrences - $this->occurrences_count);
        $from = $this->earliestNext()->max($this->localToday($now));

        return array_map(
            fn (CarbonImmutable $date) => $date->toDateString(),
            $this->schedule()->upcoming($from, min($count, $remaining)),
        );
    }
}
