<?php

namespace App\Http\Resources\AppApi;

class BookingCollection extends CursorCollection
{
    public $collects = BookingResource::class;
}
