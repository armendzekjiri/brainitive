<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of a plan, in outline order (`level` 0 = top, deeper = indented under the row above). `issue` is the
 * linked board issue, only when the user can open it.
 *
 * @property array{id: string, name: string, level: int, who: string|null, status: string|null, notes: string|null, collapsed: bool, issue: array{id: int, key: string, title: string}|null, bars: list<array<string, mixed>>} $resource
 */
class PlanRowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'level' => (int) $row['level'],
            // the "Who" column (free text)
            'who' => $row['who'] === null ? null : (string) $row['who'],
            'status' => $row['status'] === null ? null : (string) $row['status'],
            'notes' => $row['notes'] === null ? null : (string) $row['notes'],
            'collapsed' => (bool) $row['collapsed'],
            'issue' => $row['issue'] === null ? null : ['id' => (int) $row['issue']['id'], 'key' => (string) $row['issue']['key'], 'title' => (string) $row['issue']['title']],
            'bars' => PlanBarResource::collection($row['bars']),
        ];
    }
}
