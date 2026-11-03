<?php

namespace App\Http\Resources\AppApi;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project the user may log time on.
 *
 * @mixin Project
 */
class TimesheetProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'key' => $this->key,
        ];
    }
}
