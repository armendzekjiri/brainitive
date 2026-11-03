<?php

namespace App\Http\Resources\AppApi;

/** `{data: [history entry], next_cursor}` */
class ActivityCollection extends CursorCollection
{
    public $collects = ActivityResource::class;
}
