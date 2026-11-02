<?php

namespace App\Http\Resources\AppApi;

/** The user's captures in one state, newest first. */
class CaptureCollection extends CursorCollection
{
    public $collects = CaptureResource::class;
}
