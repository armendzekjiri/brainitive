<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dependency between two bars (finish → start).
 *
 * @property array{id: string, from: string, to: string} $resource
 */
class PlanDependencyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource['id'],
            // bar ids
            'from' => (string) $this->resource['from'],
            'to' => (string) $this->resource['to'],
        ];
    }
}
