<?php

namespace App\Http\Resources\AppApi;

/** `{data: [comment], next_cursor}` */
class CommentCollection extends CursorCollection
{
    public $collects = CommentResource::class;
}
