<?php

namespace App\Http\Resources\AppApi;

use App\Models\Item;
use App\Models\TaskListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * My Day of the user's local today: the planned work in their order, and how many unfinished tasks of
 * earlier days "Bring over" (POST /me/my-day/carry-over) would add — the web shows that as a button
 * and never moves them by itself.
 *
 * @property-read array{date: string, items: iterable<Item|TaskListItem>, carry_over_count: int} $resource
 */
class MyDayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            // the user's local date (Y-m-d) the plan is for
            'date' => (string) $this->resource['date'],
            'items' => WorkItemResource::collection($this->resource['items']),
            'carry_over_count' => (int) $this->resource['carry_over_count'],
        ];
    }
}
