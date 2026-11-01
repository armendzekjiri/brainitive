<?php

namespace App\Http\Resources\AppApi;

/** The user's own work logs, newest day first. */
class WorkLogCollection extends CursorCollection
{
    public $collects = WorkLogResource::class;
}
