<?php

namespace App\Http\Resources\AppApi;

use App\Models\ItemPriority;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A priority the project offers, in the web's order (highest first).
 *
 * @mixin ItemPriority
 */
class PriorityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'color' => $this->color,
            'is_default' => (bool) $this->is_default,
        ];
    }
}
