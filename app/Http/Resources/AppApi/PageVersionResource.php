<?php

namespace App\Http\Resources\AppApi;

use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One published version of a page (its history, BRAI-413). Restoring a version: web only.
 *
 * @mixin PageVersion
 */
class PageVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $icon = (string) $this->icon;

        return [
            'version' => (int) $this->version,
            'name' => (string) $this->name,
            // the emoji; null for an uploaded image icon
            'icon' => $icon === '' || PageResource::isImageIcon($icon) ? null : $icon,
            // the note given when it was published
            'message' => $this->message,
            'author' => $this->author ? new PersonResource($this->author) : null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
