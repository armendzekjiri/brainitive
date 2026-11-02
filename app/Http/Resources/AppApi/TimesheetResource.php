<?php

namespace App\Http\Resources\AppApi;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * My timesheet of one workspace and week (BRAI-400): the web's "My timesheet" — the user's own entries,
 * the totals per day, the projects they can log on and the activities. Never a rate or an amount.
 *
 * @property-read array{workspace: Workspace, week: array<string, mixed>, entries: Collection<int, TimeEntry>, accessible: array<int, int>, projects: Collection<int, Project>, activities: list<array<string, mixed>>, activity_required: bool, default_activity_id: int|null} $resource
 */
class TimesheetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $week = $this->resource['week'];
        $entries = $this->resource['entries'];
        $minutesByDay = $entries->groupBy(fn (TimeEntry $entry) => $entry->spent_on->toDateString())->map(fn ($day) => (int) $day->sum('minutes'));
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $week['start'], 'UTC')->addDays($i)->toDateString();
            $days[] = ['date' => $date, 'minutes' => (int) ($minutesByDay[$date] ?? 0)];
        }

        return [
            'workspace' => [
                'slug' => (string) $this->resource['workspace']->slug,
                'name' => (string) $this->resource['workspace']->name,
            ],
            'week' => [
                // Monday and Sunday (Y-m-d)
                'start' => (string) $week['start'],
                'end' => (string) $week['end'],
                // Mondays of the weeks before / after / of today
                'previous' => (string) $week['previous'],
                'next' => (string) $week['next'],
                'current' => (string) $week['current'],
                // ISO week number
                'number' => (int) $week['number'],
                // the user's today
                'today' => (string) $week['today'],
            ],
            'total_minutes' => (int) $entries->sum('minutes'),
            'days' => TimesheetDayResource::collection($days),
            'entries' => new WorkLogCollection($entries->map(fn (TimeEntry $entry) => new WorkLogResource($entry, $this->resource['accessible']))->values()),
            // where the user may log time (POST /work-logs → project_id)
            'projects' => TimesheetProjectResource::collection($this->resource['projects']),
            'activities' => TimeActivityResource::collection($this->resource['activities']),
            'activity_required' => (bool) $this->resource['activity_required'],
            'default_activity_id' => self::id($this->resource['default_activity_id']),
        ];
    }

    private static function id(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
