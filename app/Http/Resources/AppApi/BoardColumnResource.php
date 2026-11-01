<?php

namespace App\Http\Resources\AppApi;

use App\Models\BoardColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A column (status) of a board, in the board's order. `cards_count` = the cards the board shows in it
 * (Scrum: the running sprint; done columns: within the done retention), set by the controller.
 *
 * @mixin BoardColumn
 */
class BoardColumnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'color' => $this->color,
            'position' => (int) $this->position,
            // cards here count as done (moving a card in completes it)
            'is_done' => (bool) $this->is_done_column,
            // null = no limit; the web warns (doesn't block) when a column is over it
            'wip_limit' => $this->wip_limit,
            'cards_count' => (int) $this->resource->getAttribute('cards_count'),
        ];
    }
}
