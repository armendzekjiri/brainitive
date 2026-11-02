<?php

namespace App\Http\Resources\AppApi;

/** `{data: [issue card], next_cursor}` */
class IssueCollection extends CursorCollection
{
    public $collects = IssueResource::class;
}
