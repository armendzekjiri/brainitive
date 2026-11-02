<?php

namespace App\Http\Resources\AppApi;

use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A label of a project (on issues and in the project config).
 *
 * @mixin Label
 */
class LabelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
        ];
    }
}
