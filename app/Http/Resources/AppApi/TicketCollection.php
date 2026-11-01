<?php

namespace App\Http\Resources\AppApi;

class TicketCollection extends CursorCollection
{
    public $collects = TicketResource::class;
}
