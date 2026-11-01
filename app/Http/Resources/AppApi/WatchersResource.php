<?php

namespace App\Http\Resources\AppApi;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Who watches an issue (BRAI-405) and whether the user does. Watchers hear about comments and status
 * changes. Needs `watchers`.
 *
 * @mixin Item
 */
class WatchersResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $watchers = $this->resource->watchers->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return [
            'item_id' => (int) $this->id,
            'watching' => $watchers->contains('id', $request->user()->id),
            'watchers' => PersonResource::collection($watchers),
        ];
    }
}
