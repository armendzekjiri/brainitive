<?php

namespace App\Http\Resources\AppApi;

/** The items of a bookmark group, in the web's order (position). */
class BookmarkItemCollection extends CursorCollection
{
    public $collects = BookmarkItemResource::class;
}
