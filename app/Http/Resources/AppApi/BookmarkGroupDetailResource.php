<?php

namespace App\Http\Resources\AppApi;

use App\Models\Bookmark;
use Illuminate\Http\Request;

/**
 * One bookmark group: the fields + what the user may do. The items are a list of their own
 * (`GET /bookmarks/{id}/items`).
 *
 * @mixin Bookmark
 */
class BookmarkGroupDetailResource extends BookmarkGroupResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::toArray($request),
            'can' => [
                // rename, icon, visibility, and add / change / delete items
                'update' => $user->can('update', $this->resource),
                // its creator or a workspace admin
                'delete' => $user->can('delete', $this->resource),
            ],
        ];
    }
}
