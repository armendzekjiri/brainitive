<?php

namespace App\Http\Resources\AppApi;

class FeedbackPostCollection extends CursorCollection
{
    public $collects = FeedbackPostResource::class;
}
