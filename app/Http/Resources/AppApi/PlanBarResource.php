<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bar of a plan row: a task bar (start … end) or a milestone (one day).
 *
 * @property array{id: string, start: string, end: string, type: string, color: string|null, label: string|null, progress: int|null} $resource
 */
class PlanBarResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $bar = $this->resource;

        return [
            'id' => (string) $bar['id'],
            // Y-m-d
            'start' => (string) $bar['start'],
            'end' => (string) $bar['end'],
            /** @var 'task'|'milestone' */
            'type' => (string) $bar['type'],
            // #RRGGBB
            'color' => $bar['color'] === null ? null : (string) $bar['color'],
            'label' => $bar['label'] === null ? null : (string) $bar['label'],
            // 0–100; null for milestones
            'progress' => $bar['progress'] === null ? null : (int) $bar['progress'],
        ];
    }
}
