<?php

namespace App\Http\Resources\AppApi;

/** The tasks of a list, in the list's order (position). */
class TaskCollection extends CursorCollection
{
    public $collects = TaskResource::class;
}
