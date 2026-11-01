<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project's workload grid (BRAI-75, WorkloadGrid::build): per member and week the declared capacity, the
 * explicitly planned hours (allocations with their issue), load and state (ok | over | unset | unavailable), the
 * team's totals and the review hints. Minutes throughout.
 *
 * @property-read array<string, mixed> $resource
 */
class WorkloadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            // the Monday of the first week
            'from' => (string) $r['from'],
            'weeks_count' => (int) $r['span'],
            'timezone' => (string) $r['timezone'],
            'current_week' => (string) $r['currentWeek'],
            // [{week, label, …, is_current, is_past}]
            'weeks' => $r['weeks'],
            // [{id, name, avatar_display, capacity_minutes, work, cells: [{week, capacity_minutes, allocated_minutes, load_percent, state, allocations, …}]}]
            'members' => $r['members'],
            // [{week, capacity_minutes, allocated_minutes, unset, over}]
            'team' => $r['team'],
            // hints: over-planned, unestimated, …
            'review' => $r['review'],
            // planning (capacity, allocations): web only
            'can_plan' => (bool) $r['canPlan'],
            'url' => (string) $r['url'],
        ];
    }
}
