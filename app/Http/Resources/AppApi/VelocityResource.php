<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One completed sprint in the velocity chart (SprintReport::velocity): committed vs. completed, oldest first.
 *
 * @property-read array{id: int, name: string, committed_points: int, completed_points: int, committed_issues: int, completed_issues: int} $resource
 */
class VelocityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'sprint_id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'committed_points' => (int) $this->resource['committed_points'],
            'completed_points' => (int) $this->resource['completed_points'],
            'committed_issues' => (int) $this->resource['committed_issues'],
            'completed_issues' => (int) $this->resource['completed_issues'],
        ];
    }
}
