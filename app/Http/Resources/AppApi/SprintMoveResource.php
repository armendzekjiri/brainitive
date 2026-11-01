<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer of a sprint move (BRAI-408): how many issues changed their sprint, and where they went.
 *
 * @property-read array{moved: int, sprint_id: int|null} $resource
 */
class SprintMoveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // issues that changed their sprint (the ones already there don't count)
            'moved' => (int) $this->resource['moved'],
            // the target sprint; null = the backlog
            'sprint_id' => $this->resource['sprint_id'] === null ? null : (int) $this->resource['sprint_id'],
        ];
    }
}
