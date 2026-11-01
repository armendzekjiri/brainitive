<?php

namespace App\Http\Resources\AppApi;

use App\Models\ItemType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An issue type the project offers (Epic, Story, Bug, Subtask …).
 *
 * @mixin ItemType
 */
class ItemTypeResource extends JsonResource
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
            // new issues get this type when none is given
            'is_default' => (bool) $this->is_default,
        ];
    }
}
