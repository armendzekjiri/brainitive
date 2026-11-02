<?php

namespace App\Http\Requests\AppApi;

use App\Support\Bookmarks\Bookmarks;

/** `PATCH /api/v1/bookmarks/{id}/items/{item}`: title, URL, content, done (to-dos). */
class UpdateBookmarkItemRequest extends BookmarkGroupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return Bookmarks::itemUpdateRules();
    }
}
