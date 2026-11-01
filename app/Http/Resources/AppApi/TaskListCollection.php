<?php

namespace App\Http\Resources\AppApi;

/** The task lists of a project, in the web's order (position). */
class TaskListCollection extends CursorCollection
{
    public $collects = TaskListResource::class;
}
