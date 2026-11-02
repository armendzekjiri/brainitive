<?php

namespace App\Http\Resources\AppApi;

/** My Work: soonest due first, no due date last. */
class WorkItemCollection extends CursorCollection
{
    public $collects = WorkItemResource::class;
}
