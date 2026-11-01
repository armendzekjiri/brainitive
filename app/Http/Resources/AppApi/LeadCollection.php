<?php

namespace App\Http\Resources\AppApi;

class LeadCollection extends CursorCollection
{
    public $collects = LeadResource::class;
}
