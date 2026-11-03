<?php

namespace App\Http\Requests\AppApi;

use App\Support\Bookmarks\Bookmarks;

/** `PATCH /api/v1/bookmarks/{id}`: only the sent fields change; the icon as an emoji. */
class UpdateBookmarkGroupRequest extends BookmarkGroupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...Bookmarks::groupRules(update: true),
            // an emoji (image icons: web only); null removes it
            'icon' => ['sometimes', 'nullable', 'string', 'max:10', 'not_regex:#[/\\\\]#'],
        ];
    }
}
