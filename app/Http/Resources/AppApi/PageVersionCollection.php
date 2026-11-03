<?php

namespace App\Http\Resources\AppApi;

/** `GET /pages/{uuid}/versions`: newest first. */
class PageVersionCollection extends CursorCollection
{
    public $collects = PageVersionResource::class;
}
