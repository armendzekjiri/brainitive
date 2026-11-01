<?php

namespace App\Http\Requests\AppApi;

use App\Support\Bookmarks\Bookmarks;

/**
 * `POST /api/v1/bookmarks/{id}/items`: a link, to-do, note or file (multipart `file`, ≤ 200 MB, the web's
 * allowed types; scripts and executables are refused).
 */
class StoreBookmarkItemRequest extends BookmarkGroupRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...Bookmarks::itemRules(),
            // type file: the upload (multipart/form-data)
            'file' => ['required_if:type,file', 'prohibited_unless:type,file', 'file'],
            // a uuid: a retry with the same one answers 200 with the item the first call created
            'request_id' => ['nullable', 'uuid'],
        ];
    }
}
