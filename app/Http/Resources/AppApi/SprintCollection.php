<?php

namespace App\Http\Resources\AppApi;

/** `{data: [sprint], next_cursor}` */
class SprintCollection extends CursorCollection
{
    public $collects = SprintResource::class;
}
