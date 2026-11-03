<?php

namespace App\Http\Resources\AppApi;

use App\Models\ItemFieldOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An option of a select field. Archived options stay on issues that have them but aren't offered for new values.
 *
 * @mixin ItemFieldOption
 */
class FieldOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'label' => (string) $this->label,
            'color' => $this->color,
            'archived' => $this->archived_at !== null,
        ];
    }
}
