<?php

namespace App\Http\Resources\AppApi;

/** `{data: [board], next_cursor}` */
class BoardCollection extends CursorCollection
{
    public $collects = BoardResource::class;
}
