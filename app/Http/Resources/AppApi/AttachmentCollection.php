<?php

namespace App\Http\Resources\AppApi;

/** `{data: [attachment], next_cursor}` */
class AttachmentCollection extends CursorCollection
{
    public $collects = AttachmentResource::class;
}
