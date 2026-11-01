<?php

namespace App\Http\Resources\AppApi;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An issue in a release (the web's release page list).
 *
 * @property array<string, mixed> $resource
 */
class ReleaseItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => (int) $i['id'],
            'key' => (string) $i['key'],
            'title' => (string) $i['title'],
            /** @var 'todo'|'in_progress'|'done' */
            'state' => (string) $i['state'],
            // the column name
            'status' => $i['status'] === null ? null : (string) $i['status'],
            'type' => $i['type'] === null ? null : ['name' => (string) $i['type']['name'], 'icon' => $i['type']['icon'], 'color' => $i['type']['color']],
            'assignee' => $i['assignee'] instanceof User ? new PersonResource($i['assignee']) : null,
            'is_subtask' => (bool) $i['is_subtask'],
            'url' => $i['url'] === null ? null : (string) $i['url'],
        ];
    }
}
