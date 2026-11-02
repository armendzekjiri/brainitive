<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sprint's burndown (SprintReport::burndown, the web's Reports page): remaining work per sprint day in story
 * points (or issues when no issue has points), the ideal line, `remaining: null` for days still to come. A
 * completed sprint answers the snapshot taken when it was completed.
 *
 * @property-read array{sprint_id: int, unit: string, total: int, days: list<array{date: string, ideal: float, remaining: int|null}>} $resource
 */
class BurndownResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $days = [];
        foreach ($this->resource['days'] as $day) {
            $days[] = [
                'date' => (string) $day['date'],
                'ideal' => (float) $day['ideal'],
                'remaining' => $day['remaining'] === null ? null : (int) $day['remaining'],
            ];
        }

        return [
            'sprint_id' => (int) $this->resource['sprint_id'],
            // points | issues
            'unit' => (string) $this->resource['unit'],
            'total' => (int) $this->resource['total'],
            'days' => $days,
        ];
    }
}
