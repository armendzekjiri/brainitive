<?php

namespace App\Models;

use App\Support\Helpdesks\Sla\BusinessCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A helpdesk's service levels (BRAI-125): first-response and resolution targets per priority
 * (key = priority id, or "none" for tickets without a priority), business hours or 24/7, the columns
 * that pause the resolution clock, and when to warn. Tickets get their SLA dates from
 * App\Support\Helpdesks\Sla\TicketSla.
 */
class HelpdeskSlaPolicy extends Model
{
    /** Units a target can be written in; a "day" is one business day (24 h when 24/7). */
    public const UNITS = ['minutes', 'hours', 'days'];

    public const TARGETS = ['first_response', 'resolution'];

    protected $fillable = [
        'helpdesk_id',
        'enabled',
        'targets',
        'business_hours',
        'timezone',
        'pause_column_ids',
        'warn_percent',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'targets' => 'array',
            'business_hours' => 'array',
            'pause_column_ids' => 'array',
            'warn_percent' => 'integer',
        ];
    }

    public function helpdesk(): BelongsTo
    {
        return $this->belongsTo(Helpdesk::class);
    }

    public function calendar(): BusinessCalendar
    {
        $hours = $this->business_hours;
        if (! is_array($hours)) {
            return BusinessCalendar::always();
        }

        try {
            return BusinessCalendar::hours((array) ($hours['days'] ?? []), (string) ($hours['from'] ?? ''), (string) ($hours['to'] ?? ''), (string) $this->timezone);
        } catch (InvalidArgumentException) {
            return BusinessCalendar::always(); // never stored by the controller; old / hand-edited rows
        }
    }

    /**
     * Target lengths in business minutes for a ticket's priority (null = no target).
     *
     * @return array{first_response: int|null, resolution: int|null}
     */
    public function targetMinutes(?int $priorityId, ?BusinessCalendar $calendar = null): array
    {
        $entry = ($this->targets ?? [])[$priorityId ? (string) $priorityId : 'none'] ?? [];
        $calendar ??= $this->calendar();

        $minutes = function (mixed $target) use ($calendar): ?int {
            if (! is_array($target) || ! is_numeric($target['value'] ?? null) || (float) $target['value'] <= 0) {
                return null;
            }

            return (int) round((float) $target['value'] * match ($target['unit'] ?? 'minutes') {
                'hours' => 60,
                'days' => $calendar->dayMinutes(),
                default => 1,
            });
        };

        return [
            'first_response' => $minutes($entry['first_response'] ?? null),
            'resolution' => $minutes($entry['resolution'] ?? null),
        ];
    }

    public function isPauseColumn(?int $columnId): bool
    {
        return $columnId !== null && in_array($columnId, array_map('intval', $this->pause_column_ids ?? []), true);
    }
}
