<?php

namespace App\Http\Resources\AppApi;

/** The bookmark groups of a workspace the user can see, in the web's order (position). */
class BookmarkGroupCollection extends CursorCollection
{
    public $collects = BookmarkGroupResource::class;
}
