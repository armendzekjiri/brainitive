<?php

namespace App\Http\Resources\AppApi;

/** `GET /projects/{id}/pages`: the page tree, flat (`parent_id`). */
class PageCollection extends CursorCollection
{
    public $collects = PageResource::class;
}
