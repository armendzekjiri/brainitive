<?php

namespace App\Http\Resources\AppApi;

use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What stopping the timer did: `logged` (+ the new work log), `already_stopped` (no such running timer:
 * a retry, or stopped on another device — nothing logged) or `no_access` (the user can no longer log on
 * the issue: the timer is stopped, nothing logged).
 *
 * @property-read array{result: string, entry: TimeEntry|null} $resource
 */
class TimerStopResource extends JsonResource
{
    /** @param  array<int, int>  $accessible */
    public function __construct(array $result, private readonly array $accessible = [])
    {
        parent::__construct($result);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entry = $this->resource['entry'];

        return [
            // logged | already_stopped | no_access
            'result' => (string) $this->resource['result'],
            'work_log' => $entry instanceof TimeEntry ? new WorkLogResource($entry, $this->accessible) : null,
        ];
    }
}
