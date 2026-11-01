<?php

namespace App\Http\Resources\AppApi;

/** `GET /projects/{id}/plans`: in the web's order. */
class PlanCollection extends CursorCollection
{
    public $collects = PlanResource::class;
}
