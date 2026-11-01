<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Logged minutes on one day of the timesheet week.
 *
 * @property-read array{date: string, minutes: int} $resource
 */
class TimesheetDayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'date' => (string) $this->resource['date'],
            'minutes' => (int) $this->resource['minutes'],
        ];
    }
}
